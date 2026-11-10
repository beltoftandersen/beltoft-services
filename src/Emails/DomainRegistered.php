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

	protected const ID = 'beltoft_services_domain_registered';
}
