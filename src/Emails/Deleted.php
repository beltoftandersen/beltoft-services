<?php
/**
 * Customer email: Service deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service has been deleted.
 */
final class Deleted extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_deleted';
		$this->title          = __( 'Service deleted', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when their service has been deleted.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-deleted.php';
		$this->template_plain = 'emails/plain/beltoft-services-deleted.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your {product_name} has been deleted', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your service has been deleted', 'beltoft-services' );
	}
}
