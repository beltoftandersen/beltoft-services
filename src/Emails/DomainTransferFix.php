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

	protected const ID = 'beltoft_services_domain_transfer_fix';
}
