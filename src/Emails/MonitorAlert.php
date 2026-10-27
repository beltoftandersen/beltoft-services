<?php
/** Customer email for server down or disk nearly full. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class MonitorAlert extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_monitor_alert';
		$this->title = __( 'Server monitoring alert', 'beltoft-services' );
		$this->description = __( 'Sent when a server is down unexpectedly or its disk is nearly full.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-monitor-alert.php';
		$this->template_plain = 'emails/plain/beltoft-services-monitor-alert.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Check your server', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your server needs attention', 'beltoft-services' ); }
}
