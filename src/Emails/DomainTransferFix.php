<?php
/**
 * Customer email: a transfer waits for something the customer can fix.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once for a refused transfer code, and once for a domain that is locked at the current registrar.
 */
final class DomainTransferFix extends ServiceEmail {

	/**
	 * Set up.
	 */
	public function __construct() {
		$this->id             = 'beltoft_services_domain_transfer_fix';
		$this->title          = __( 'Domain transfer needs a fix', 'beltoft-services' );
		$this->description    = __( 'Sent once to the customer when a transfer waits for a new transfer code or for the domain to be unlocked.', 'beltoft-services' );
		$this->template_html  = 'emails/beltoft-services-domain-transfer-fix.php';
		$this->template_plain = 'emails/plain/beltoft-services-domain-transfer-fix.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Your transfer of {domain_name} needs your action', 'beltoft-services' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your transfer needs your action', 'beltoft-services' );
	}
}
