<?php
/**
 * Customer email: your domain was transferred to us.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once when a domain transfer completes, instead of the generic service ready email.
 */
final class DomainTransferred extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_domain_transferred';
		$this->title          = __( 'Domain transferred', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer once when a domain transfer has completed.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-domain-transferred.php';
		$this->template_plain = 'emails/plain/beltoft-services-domain-transferred.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your domain {domain_name} is now with us', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your domain is now with us', 'beltoft-services' );
	}
}
