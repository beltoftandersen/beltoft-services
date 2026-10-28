<?php
/**
 * Customer email: your domain is registered.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once when a domain registration completes, instead of the generic service ready email.
 */
final class DomainRegistered extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_domain_registered';
		$this->title          = __( 'Domain registered', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer once when a new domain registration has completed.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-domain-registered.php';
		$this->template_plain = 'emails/plain/beltoft-services-domain-registered.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your domain {domain_name} is registered', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your domain is registered', 'beltoft-services' );
	}
}
