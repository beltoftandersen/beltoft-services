<?php
/**
 * Customer email: Service ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service is ready to use.
 */
final class Ready extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_ready';
		$this->title          = __( 'Service ready', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when their service is ready to use.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-ready.php';
		$this->template_plain = 'emails/plain/beltoft-services-ready.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your {product_name} is ready', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your service is ready', 'beltoft-services' );
	}
}
