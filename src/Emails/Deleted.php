<?php
/**
 * Customer email: Service deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when their service has been deleted.
 */
final class Deleted extends ServiceEmail {

	protected const ID = 'beltoft_services_deleted';
}
