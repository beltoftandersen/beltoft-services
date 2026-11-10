<?php
/** Customer email about snapshots that were deleted or will be. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SnapshotNotice extends ServiceEmail {

	protected const ID = 'beltoft_services_snapshot_notice';
}
