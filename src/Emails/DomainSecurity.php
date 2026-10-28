<?php
/**
 * Customer email: a security notice about a domain.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once per event when a domain is unlocked for transfer, when its transfer code is shown,
 * when its name servers change and when it is locked again automatically. The text names the
 * event only: it never holds the transfer code or the new name servers' credentials.
 */
final class DomainSecurity extends ServiceEmail {

	/**
	 * Events the email can tell about.
	 */
	public const EVENTS = array( 'unlocked', 'code_viewed', 'ns_changed', 'relocked' );

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_domain_security';
		$this->title          = __( 'Domain security notice', 'beltoft-services' );
		$this->description    = __( 'Sent to the customer when a domain is unlocked, its transfer code is shown, its name servers change or it is locked again.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-domain-security.php';
		$this->template_plain = 'emails/plain/beltoft-services-domain-security.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Security notice for your domain {domain_name}', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'A change was made to your domain', 'beltoft-services' );
	}
}
