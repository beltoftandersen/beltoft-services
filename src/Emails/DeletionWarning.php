<?php
/**
 * Customer email: Service deletion warning.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer before a paused service is deleted after its retention period.
 */
final class DeletionWarning extends ServiceEmail {

	protected const ID = 'beltoft_services_deletion_warning';
}
