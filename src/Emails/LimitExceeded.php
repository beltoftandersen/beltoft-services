<?php
/**
 * Customer email: Service limit exceeded.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when the daily usage check finds their service over its limit.
 */
final class LimitExceeded extends ServiceEmail {

	protected const ID = 'beltoft_services_limit_exceeded';
}
