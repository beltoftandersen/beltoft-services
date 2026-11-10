<?php
/**
 * Customer email: Service paused.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service is paused.
 */
final class Paused extends ServiceEmail {

	protected const ID = 'beltoft_services_paused';
}
