<?php
/** Customer email: outgoing traffic is open again. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class QuarantineLifted extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_quarantine_lifted';
		$this->title = __( 'Outgoing traffic open again', 'beltoft-services' );
		$this->description = __( 'Sent when the block on the outgoing network traffic of a server is lifted.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-quarantine-lifted.php';
		$this->template_plain = 'emails/plain/beltoft-services-quarantine-lifted.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Outgoing traffic from your server is open again', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Outgoing traffic is open again', 'beltoft-services' ); }
}
