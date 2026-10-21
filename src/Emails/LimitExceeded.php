<?php
/**
 * Customer email: Service limit exceeded.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when the daily usage check finds their service over its limit.
 */
final class LimitExceeded extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_limit_exceeded';
		$this->title          = __( 'Service limit exceeded', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when the daily usage check finds their service over its limit.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-limit-exceeded.php';
		$this->template_plain = 'emails/plain/beltoft-services-limit-exceeded.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your {product_name} is over its limit', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your service is over its limit', 'beltoft-services' );
	}
}
