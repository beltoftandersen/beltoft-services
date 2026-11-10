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

	protected const ID = 'beltoft_services_domain_transferred';
}
