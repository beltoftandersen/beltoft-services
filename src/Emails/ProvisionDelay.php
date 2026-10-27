<?php
/**
 * Customer email: a delay setting up the service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once to the customer when setting up their server, domain or DNS hosting failed and an
 * admin has to look at it. It carries no technical detail.
 */
final class ProvisionDelay extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_provision_delay';
		$this->title          = __( 'Service setup delayed', 'beltoft-services' );
		$this->description    = __( 'Sent once to the customer when setting up their server, domain or DNS hosting failed and needs attention.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-provision-delay.php';
		$this->template_plain = 'emails/plain/beltoft-services-provision-delay.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'A delay setting up your {product_name}', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'There is a delay setting up your service', 'beltoft-services' );
	}
}
