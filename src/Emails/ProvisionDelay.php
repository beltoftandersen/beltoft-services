<?php
/**
 * Customer email: a delay setting up the service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent once to the customer when setting up their server, domain, DNS hosting or website hosting failed and an
 * admin has to look at it. It carries no technical detail.
 */
final class ProvisionDelay extends ServiceEmail {

	protected const ID = 'beltoft_services_provision_delay';
}
