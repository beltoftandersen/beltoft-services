<?php
/** Customer email: a restore of a hosting account has ended. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class HostingRestoreDone extends ServiceEmail {
	public const KINDS = array( 'account', 'files', 'database', 'undo' );

	public function __construct() {
		$this->id = 'beltoft_services_hosting_restore_done';
		$this->title = __( 'Hosting restore finished', 'beltoft-services' );
		$this->description = __( 'Sent when a restore or an undo of a hosting account has finished or has failed. It says what was restored and until when it can be undone.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-hosting-restore-done.php';
		$this->template_plain = 'emails/plain/beltoft-services-hosting-restore-done.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your hosting restore has finished', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your restore has finished', 'beltoft-services' ); }
}
