<?php
/**
 * Base of the customer service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Retention;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce customer email about one service. It is sent through WooCommerce's mailer
 * with its styling, has its own settings under WooCommerce → Settings → Emails, and its
 * templates can be overridden in the theme (yourtheme/woocommerce/emails/...). The template
 * gets display values only: product name, customer name, the My Account → Licenses link,
 * dates and usage counts. Never the license key, the resource's meta or any secret.
 */
abstract class ServiceEmail extends \WC_Email {

	/**
	 * Template values for the current send.
	 *
	 * @var array<string, mixed>
	 */
	protected array $values = array();

	/**
	 * Date format of the emails: the month is spelled out, so "3/4/2026" is never read two ways.
	 */
	public const DATE = 'j F Y';

	/**
	 * Date and time format of the emails.
	 */
	public const DATE_TIME = 'j F Y, H:i';

	/**
	 * The email's id, as in Definitions. A class that has one is described by its definition.
	 */
	protected const ID = '';

	/**
	 * Set up.
	 */
	public function __construct() {
		if ( '' !== static::ID ) {
			$definition           = Definitions::get( static::ID );
			$this->id             = static::ID;
			$this->title          = $definition['title'];
			$this->description    = $definition['description'];
			$this->template_html  = $definition['html'];
			$this->template_plain = $definition['plain'];
		}

		$this->customer_email = true;
		$this->template_base  = BELTOFT_SERVICES_PATH . 'templates/';
		$this->placeholders   = array(
			'{product_name}'  => '',
			'{deletion_date}' => '',
			'{domain_name}'   => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject: the definition's, unless the class follows what it is about.
	 */
	public function get_default_subject() {
		return '' === static::ID ? parent::get_default_subject() : Definitions::get( static::ID )['subject'];
	}

	/**
	 * Default heading: the definition's, unless the class follows what it is about.
	 */
	public function get_default_heading() {
		return '' === static::ID ? parent::get_default_heading() : Definitions::get( static::ID )['heading'];
	}

	/**
	 * Sends the email about a resource to its license's email address.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $data deletes_at (timestamp), used, limit.
	 * @return bool Whether it was sent.
	 */
	public function trigger( Resource $r, array $data = array() ): bool {
		$this->setup_locale();
		$sent = false;

		try {
			$license = $r->license();
			$user    = ! empty( $license['user_id'] ) ? get_userdata( (int) $license['user_id'] ) : false;
			$driver  = Registry::get( $r->get( 'driver' ) );
			$used    = (int) ( $data['used'] ?? 0 );
			$limit   = (int) ( $data['limit'] ?? 0 );
			$date    = isset( $data['deletes_at'] ) ? wp_date( self::DATE, (int) $data['deletes_at'] ) : '';

			$this->object    = $r;
			// The licence's address, else the customer's own: a mail about what a connected app did must not go nowhere.
			$this->recipient = (string) ( $license['email'] ?? '' );
			$this->recipient = '' !== $this->recipient ? $this->recipient : ( $user ? (string) $user->user_email : '' );
			$values          = array(
				'product_name'   => $license ? View::product_name( $license ) : '',
				'customer_name'  => $user ? (string) ( $user->first_name ? $user->first_name : $user->display_name ) : '',
				'account_url'    => self::account_url( $r, $license ),
				'deletion_date'  => (string) $date,
				'reason'         => self::customer_reason( (string) $r->get( 'paused_reason' ), $r->on_hold() ),
				'held'           => $r->on_hold(),
				'retention_days' => self::retention_days( $r, $license ),
				'deletes_on'     => self::deletes_on( $r, $license ),
				'details'        => $this->details( $r ),
				'used'           => $used,
				'limit'          => $limit,
				'usage'          => $driver ? $driver->unit_label( $used, $limit ) : sprintf( '%d / %d', $used, $limit ),
				'domain_name'    => '',
				'notice_kind'    => in_array( $data['notice_kind'] ?? '', Notice::KINDS, true ) ? $data['notice_kind'] : 'db_access',
				'notice_what'    => isset( $data['notice_what'] ) && is_string( $data['notice_what'] ) ? wp_strip_all_tags( $data['notice_what'] ) : '',
				'notice_app'     => isset( $data['notice_app'] ) && is_string( $data['notice_app'] ) ? wp_strip_all_tags( $data['notice_app'] ) : '',
				'renewal'        => is_array( $data['renewal'] ?? null ) ? array(
					'date'       => sanitize_text_field( (string) ( $data['renewal']['date'] ?? '' ) ),
					'plan'       => sanitize_text_field( (string) ( $data['renewal']['plan'] ?? '' ) ),
					'price_html' => wp_kses_post( (string) ( $data['renewal']['price_html'] ?? '' ) ),
					'view_url'   => esc_url_raw( (string) ( $data['renewal']['view_url'] ?? '' ) ),
				) : array( 'date' => '', 'plan' => '', 'price_html' => '', 'view_url' => '' ),
			);

			/**
			 * The template values of a service email. Each feature adds its own keys here, always all of
			 * them with their empty defaults, so a template never meets a missing key. Display values only:
			 * never a secret.
			 *
			 * @param array<string, mixed> $values Values so far.
			 * @param Resource             $r      The resource.
			 * @param array<string, mixed> $data   Trigger data.
			 * @param ServiceEmail         $email  The email.
			 */
			$values = apply_filters( 'beltoft_services_email_values', $values, $r, $data, $this );

			$this->values = is_array( $values ) ? $values : array();

			$this->placeholders['{product_name}']  = $this->values['product_name'];
			$this->placeholders['{deletion_date}'] = $this->values['deletion_date'];
			$this->placeholders['{domain_name}']   = $this->values['domain_name'];
			$this->placeholders['{renewal_date}']  = $this->values['renewal']['date'];

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
		} finally {
			// Also when a filter or the mailer throws.
			$this->restore_locale();
		}

		return $sent;
	}

	/**
	 * Where the "view in your account" link goes before a feature has its say (the filter
	 * beltoft_services_email_values): the licence's tab, the licence-key page for a licence key,
	 * the Licenses endpoint when there is no licence.
	 *
	 * @param Resource                  $r       Resource.
	 * @param array<string, mixed>|null $license License row.
	 */
	private static function account_url( Resource $r, $license ): string {
		if ( ! $license ) {
			return wc_get_account_endpoint_url( Endpoint::slug() );
		}

		if ( 'licence-key' === (string) $r->get( 'driver' ) && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Licences::class )->url( $license );
		}

		return Endpoint::url_for_license( $license );
	}

	/**
	 * Why the service was paused, in words the customer may read: a fixed text per cause, never
	 * the history text (it can name an admin, a license key or what Proxmox answered).
	 *
	 * @param string $reason History text of the pause (paused_reason).
	 * @param bool   $held   The service is on breach hold.
	 */
	public static function customer_reason( string $reason, bool $held = false ): string {
		if ( $held ) {
			return __( 'This server is suspended. Contact support.', 'beltoft-services' );
		}

		$reason = trim( wp_strip_all_tags( $reason ) );

		if ( '' === $reason ) {
			return '';
		}

		if ( 0 === strpos( $reason, 'Paused by ' ) ) {
			return __( 'Paused by an administrator.', 'beltoft-services' );
		}

		if ( 0 === strpos( $reason, 'Abuse suspected' ) ) {
			return __( 'Your server was using the processor heavily for a long time.', 'beltoft-services' );
		}

		$causes = array(
			'License expired'   => __( 'Your subscription period has ended.', 'beltoft-services' ),
			'License suspended' => __( 'Your subscription is on hold, for example because a payment is missing.', 'beltoft-services' ),
			'License revoked'   => __( 'Your subscription was cancelled or refunded.', 'beltoft-services' ),
		);

		return $causes[ $reason ] ?? __( 'Your subscription is not active.', 'beltoft-services' );
	}

	/**
	 * Details lines for the email (label => value), from the driver's plugin through the filter
	 * beltoft_services_email_details. Only plain strings are kept; whatever a listener returns is
	 * escaped by the template.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	private function details( Resource $r ): array {
		/**
		 * Lines for a service email, for example the address and the login command in the ready
		 * email. Never add a secret: emails are not private.
		 *
		 * @param array<string, string> $details  Label => value, empty by default.
		 * @param string                $email_id The email's id.
		 * @param Resource              $r        The resource.
		 */
		$details = apply_filters( 'beltoft_services_email_details', array(), $this->id, $r );
		$clean   = array();

		foreach ( is_array( $details ) ? $details : array() as $label => $value ) {
			if ( is_string( $label ) && is_scalar( $value ) && '' !== (string) $value ) {
				$clean[ $label ] = (string) $value;
			}
		}

		return $clean;
	}

	/**
	 * The date a paused service will be deleted, formatted, when deletion is on and a period
	 * applies; otherwise ''. A service on breach hold counts the breach days from the hold.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 */
	private static function deletes_on( Resource $r, array $license ): string {
		if ( 'on' !== (string) Settings::get( 'deletion_mode' ) || $r->get( 'protected' ) ) {
			return '';
		}

		if ( $r->on_hold() ) {
			$at = (int) $r->get( 'breach_at' ) + Retention::breach_days() * DAY_IN_SECONDS;
		} else {
			$days      = Product::retention_days( (int) ( $license['product_id'] ?? 0 ) );
			$paused_at = $r->get( 'paused_at' );

			if ( $days <= 0 || null === $paused_at ) {
				return '';
			}

			// As Retention decides: not before the period is over, and never with less notice than
			// the warning days (a warning already sent counts from when it was sent).
			$notice = Retention::warning_days() * DAY_IN_SECONDS;
			$warned = $r->get( 'warned_at' );
			$warned = null !== $warned && $warned >= $paused_at ? $warned : time();
			$at     = max( $paused_at + $days * DAY_IN_SECONDS, $warned + $notice );
		}

		return wp_date( self::DATE, $at );
	}

	/**
	 * Days a paused service is kept before it is deleted, when deleting is on ("on") and the
	 * product has a retention period; otherwise 0 (no period is promised).
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $license License row.
	 */
	private static function retention_days( Resource $r, array $license ): int {
		if ( 'on' !== (string) Settings::get( 'deletion_mode' ) || $r->get( 'protected' ) ) {
			return 0;
		}

		return Product::retention_days( (int) ( $license['product_id'] ?? 0 ) );
	}

	/**
	 * Template arguments.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		return array_merge(
			ValueDefaults::all(),
			$this->values,
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => $plain_text,
				'email'              => $this,
			)
		);
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
