<?php
/**
 * Base of the customer service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\ServerName;
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
	 * Set up.
	 */
	public function __construct() {
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
			$this->values    = array(
				'product_name'  => $license ? ServerName::email_label( $r, $license ) : '',
				'customer_name' => $user ? (string) ( $user->first_name ? $user->first_name : $user->display_name ) : '',
				'account_url'   => self::account_url( $r, $license, $data ),
				'deletion_date' => (string) $date,
				'reason'        => self::customer_reason( (string) $r->get( 'paused_reason' ), $r->on_hold() ),
				'held'          => $r->on_hold(),
				'retention_days' => self::retention_days( $r, $license ),
				'deletes_on'    => self::deletes_on( $r, $license ),
				'details'       => $this->details( $r ),
				'extra_disks'   => self::extra_disks( $r ),
				'disk_server_url' => \BeltoftServices\Proxmox\DiskDriver::ID === $r->get( 'driver' ) ? \BeltoftServices\Proxmox\DiskGuide::server_url( $r ) : '',
				'disk_guide_url'  => \BeltoftServices\Proxmox\DiskDriver::ID === $r->get( 'driver' ) ? \BeltoftServices\Proxmox\DiskGuide::guide_url( $r ) : '',
				'used'          => $used,
				'limit'         => $limit,
				'usage'         => $driver ? $driver->unit_label( $used, $limit ) : sprintf( '%d / %d', $used, $limit ),
				'domain_name'   => in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) ? (string) $r->get( 'domain' ) : '',
				'domain_action_reason' => in_array( $data['domain_action_reason'] ?? '', array( 'registrant_check', 'transfer_approval' ), true ) ? $data['domain_action_reason'] : 'transfer_approval',
				'domain_security_event' => in_array( $data['domain_event'] ?? '', DomainSecurity::EVENTS, true ) ? $data['domain_event'] : 'unlocked',
				'dns_stays'     => 'domain' === $r->get( 'driver' ) && \BeltoftServices\Domains\Zone::served_for( strtolower( (string) $r->get( 'domain' ) ), (int) ( $license['user_id'] ?? 0 ) ),
				'domain_renews' => self::domain_renews( $r ),
				'domains_url'   => self::domains_url( $r, $license ),
				'dns_external'  => 'domain' === $r->get( 'driver' ) && (bool) $r->get( 'dns_external' ),
				'nameservers_shown' => 'domain' === $r->get( 'driver' ) && 'custom' !== $r->get( 'ns_mode' ) && ! $r->get( 'dns_external' ) ? \BeltoftServices\Domains\Zone::nameservers() : array(),
				'dns_only'      => 'dns' === $r->get( 'driver' ),
				'nameservers'   => 'dns' === $r->get( 'driver' ) ? \BeltoftServices\Domains\Zone::nameservers() : array(),
				'monitor_reason' => in_array( $data['reason'] ?? '', array( 'down', 'disk', 'disk_urgent', 'cpu', 'db_down', 'db_disk' ), true ) ? $data['reason'] : 'down',
				'monitor_disk_percent' => max( 0, min( 100, (int) ( $data['disk_percent'] ?? 0 ) ) ),
				'monitor_cpu_percent' => max( 0, min( 100, (int) ( $data['cpu_percent'] ?? 0 ) ) ),
				'monitor_cpu_hours' => max( 0, (int) ( $data['cpu_hours'] ?? 0 ) ),
				'monitor_upgrade_url' => 'proxmox' === $r->get( 'driver' ) ? self::upgrade_link( $r, (string) ( $data['reason'] ?? '' ) ) : '',
				'monitor_grow_url' => 'proxmox-db' === $r->get( 'driver' ) && 'db_disk' === ( $data['reason'] ?? '' ) ? \BeltoftServices\Proxmox\DbDisk::grow_url( $r ) : '',
				'monitor_disks_url' => 'proxmox' === $r->get( 'driver' ) ? \BeltoftServices\Proxmox\DiskGuide::card_url( $r ) : '',
				'monitor_cpu_since' => isset( $data['cpu_since'] ) ? wp_date( 'H:i', (int) $data['cpu_since'] ) : '',
				'monitor_disk_used' => max( 0, (int) ( $data['disk_used'] ?? 0 ) ),
				'monitor_disk_total' => max( 0, (int) ( $data['disk_total'] ?? 0 ) ),
				'quarantine_signal' => in_array( $data['quarantine_signal'] ?? '', array( 'scan', 'syn', 'udp' ), true ) ? $data['quarantine_signal'] : 'scan',
				'quarantine_counts' => is_array( $data['quarantine_counts'] ?? null ) ? array_map( 'intval', $data['quarantine_counts'] ) : array(),
				'quarantine_at' => isset( $data['quarantine_at'] ) ? wp_date( self::DATE_TIME, (int) $data['quarantine_at'] ) : '',
				'notice_kind'   => in_array( $data['notice_kind'] ?? '', Notice::KINDS, true ) ? $data['notice_kind'] : 'db_access',
				'notice_what'   => isset( $data['notice_what'] ) && is_string( $data['notice_what'] ) ? wp_strip_all_tags( $data['notice_what'] ) : '',
				'notice_app'    => isset( $data['notice_app'] ) && is_string( $data['notice_app'] ) ? wp_strip_all_tags( $data['notice_app'] ) : '',
				'app_domain'    => isset( $data['app_domain'] ) && is_string( $data['app_domain'] ) ? $data['app_domain'] : '',
				'app_www'       => isset( $data['app_www'] ) && is_string( $data['app_www'] ) ? $data['app_www'] : '',
				'app_www_state' => isset( $data['app_www_state'] ) && is_string( $data['app_www_state'] ) ? $data['app_www_state'] : '',
				'app_ip'        => isset( $data['app_ip'] ) && is_string( $data['app_ip'] ) && false !== filter_var( $data['app_ip'], FILTER_VALIDATE_IP ) ? $data['app_ip'] : '',
				'app_name'      => (string) ( \BeltoftServices\Proxmox\Apps::definition( (string) $r->get( 'app_id' ) )['name'] ?? '' ),
				'snapshot_reason' => in_array( $data['snapshot_reason'] ?? '', \BeltoftServices\Proxmox\SnapshotExpiry::REASONS, true ) ? $data['snapshot_reason'] : 'expired',
			);

			$this->placeholders['{product_name}']  = $this->values['product_name'];
			$this->placeholders['{deletion_date}'] = $this->values['deletion_date'];
			$this->placeholders['{domain_name}']   = $this->values['domain_name'];

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
	 * The upgrade link of a usage email: the larger plan for what ran short, '' when there is none.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $reason Monitoring reason.
	 */
	private static function upgrade_link( Resource $r, string $reason ): string {
		$need = array( 'disk' => 'disk', 'disk_urgent' => 'disk', 'cpu' => 'cpu' )[ $reason ] ?? '';

		return '' === $need ? '' : \BeltoftServices\Proxmox\Upgrades::link( $r, $need );
	}

	/**
	 * Where the "view in your account" link goes: the page of the server the email is about (Disks tab for an extra
	 * disk, Snapshots for a snapshot pack or a snapshot notice), else the license's tab. A removed server has no page,
	 * so its link goes to the tab.
	 *
	 * @param Resource                  $r       Resource.
	 * @param array<string, mixed>|null $license License row.
	 * @param array<string, mixed>      $data    Trigger data.
	 */
	private static function account_url( Resource $r, $license, array $data ): string {
		if ( ! $license ) {
			return wc_get_account_endpoint_url( Endpoint::slug() );
		}

		$driver = (string) $r->get( 'driver' );
		$tab    = '';
		$server = null;

		if ( \BeltoftServices\Proxmox\DiskDriver::ID === $driver || \BeltoftServices\Proxmox\SnapshotDriver::ID === $driver || \BeltoftServices\Proxmox\AppLicenceDriver::ID === $driver ) {
			$server = Resource::for_license( (int) $r->get( 'server_license' ) );
			$tab    = \BeltoftServices\Proxmox\DiskDriver::ID === $driver ? 'disks' : ( \BeltoftServices\Proxmox\SnapshotDriver::ID === $driver ? 'snapshots' : 'app' );
		} elseif ( \BeltoftServices\Proxmox\SshKeyField::DRIVER === $driver && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			$server = $r;
			$tab    = isset( $data['snapshot_reason'] ) ? 'snapshots' : ( isset( $data['app_domain'] ) ? 'app' : '' );
		}

		$server_license = $server ? $server->license() : null;

		if ( in_array( $driver, array( 'domain', 'dns' ), true ) ) {
			return self::domains_url( $r, $license );
		}

		if ( \BeltoftServices\Proxmox\DbDriver::ID === $driver && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Databases::class )->url( $license );
		}

		if ( 'mail' === $driver && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Email::class )->url( $license );
		}

		return $server_license ? \BeltoftServices\Account\Servers::url( $server_license, $tab ) : Endpoint::url_for_license( $license );
	}

	/**
	 * Where a domain email sends the customer: the domain's own page, or the list when there is no license or the service is deleted.
	 *
	 * @param Resource                  $r       Resource.
	 * @param array<string, mixed>|null $license License row.
	 */
	private static function domains_url( Resource $r, $license ): string {
		// A deleted service has no page: its email sends to the list.
		if ( is_array( $license ) && ! empty( $license['id'] ) && in_array( $r->get( 'driver' ), array( 'domain', 'dns' ), true ) && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return \BeltoftServices\Account\Domains::url( $license );
		}

		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'domains' ) : '';
	}

	/**
	 * The next renewal date of a domain, formatted, or ''. A prepaid transfer year counts from the
	 * prepaid expiry, as the billing dates do.
	 *
	 * @param Resource $r Resource.
	 */
	private static function domain_renews( Resource $r ): string {
		if ( 'domain' !== $r->get( 'driver' ) ) {
			return '';
		}

		$expiry = (string) ( $r->get( 'prepaid_expiry' ) ?: $r->get( 'expires_at' ) );
		$time   = '' !== $expiry ? strtotime( $expiry . ' UTC' ) : false;

		return $time ? wp_date( self::DATE, $time ) : '';
	}

	/**
	 * Sizes in GB of the extra disks that are deleted with a server, for its deletion warning.
	 *
	 * @param Resource $r Resource.
	 * @return int[]
	 */
	private static function extra_disks( Resource $r ): array {
		if ( \BeltoftServices\Proxmox\SshKeyField::DRIVER !== $r->get( 'driver' ) ) {
			return array();
		}

		$sizes = array();

		foreach ( \BeltoftServices\Proxmox\DiskMeta::for_server( $r ) as $disk ) {
			$size = max( (int) $disk->get( 'size_gb' ), (int) ( ( (array) $disk->get( 'plan' ) )['disk_gb'] ?? 0 ) );

			if ( $size > 0 ) {
				$sizes[] = $size;
			}
		}

		return $sizes;
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
			$notice = max( 0, (int) Settings::get( 'warning_days' ) ) * DAY_IN_SECONDS;
			$warned = $r->get( 'warned_at' );
			$warned = null !== $warned && $warned >= $paused_at ? $warned : time();
			$at     = $notice > 0 ? max( $paused_at + $days * DAY_IN_SECONDS, $warned + $notice ) : $paused_at + $days * DAY_IN_SECONDS;
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
