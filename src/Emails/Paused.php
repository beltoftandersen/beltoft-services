<?php
/**
 * Customer email: Service paused.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service is paused.
 */
final class Paused extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_paused';
		$this->title          = __( 'Service paused', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when their service is paused.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-paused.php';
		$this->template_plain = 'emails/plain/beltoft-services-paused.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your {product_name} is paused', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your service is paused', 'beltoft-services' );
	}
}
