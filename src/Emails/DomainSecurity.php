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

	protected const ID = 'beltoft_services_domain_security';

	/**
	 * Events the email can tell about.
	 */
	public const EVENTS = array( 'unlocked', 'code_viewed', 'ns_changed', 'relocked' );
}
