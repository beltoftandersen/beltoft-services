<?php
/**
 * Customer email: Service ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service is ready to use.
 */
final class Ready extends ServiceEmail {

	protected const ID = 'beltoft_services_ready';
}
