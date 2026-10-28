<?php
/** Customer email about snapshots that were deleted or will be. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SnapshotNotice extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_snapshot_notice';
		$this->title = __( 'Snapshot notice', 'beltoft-services' );
		$this->description = __( 'Sent when snapshots of a server are deleted with a removed disk, are shortened because paid snapshot slots ended, or (when the setting is on) have expired.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-snapshot-notice.php';
		$this->template_plain = 'emails/plain/beltoft-services-snapshot-notice.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'About your server snapshots', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your snapshots changed', 'beltoft-services' ); }
}
