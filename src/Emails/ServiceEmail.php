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
use BeltoftServices\Resource;

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
	 * Set up.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = BELTOFT_SERVICES_PATH . 'templates/';
		$this->placeholders   = array(
			'{product_name}'  => '',
			'{deletion_date}' => '',
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

		$license = $r->license();
		$user    = ! empty( $license['user_id'] ) ? get_userdata( (int) $license['user_id'] ) : false;
		$driver  = Registry::get( $r->get( 'driver' ) );
		$used    = (int) ( $data['used'] ?? 0 );
		$limit   = (int) ( $data['limit'] ?? 0 );
		$date    = isset( $data['deletes_at'] ) ? wp_date( (string) get_option( 'date_format' ), (int) $data['deletes_at'] ) : '';

		$this->object    = $r;
		$this->recipient = (string) ( $license['email'] ?? '' );
		$this->values    = array(
			'product_name'  => $license ? View::product_name( $license ) : '',
			'customer_name' => $user ? (string) ( $user->first_name ? $user->first_name : $user->display_name ) : '',
			'account_url'   => wc_get_account_endpoint_url( Endpoint::slug() ),
			'deletion_date' => (string) $date,
			'used'          => $used,
			'limit'         => $limit,
			'usage'         => $driver ? $driver->unit_label( $used, $limit ) : sprintf( '%d / %d', $used, $limit ),
		);

		$this->placeholders['{product_name}']  = $this->values['product_name'];
		$this->placeholders['{deletion_date}'] = $this->values['deletion_date'];

		$sent = false;

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();

		return $sent;
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
