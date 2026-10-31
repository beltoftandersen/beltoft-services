<?php
/** Customer email: the email domain is ready. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class MailReady extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_mail_ready';
		$this->title = __( 'Email domain ready', 'beltoft-services' );
		$this->description = __( 'Sent when a Business email domain is set up. It lists the DNS records the customer must add when the domain is hosted elsewhere. It never holds a password.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-mail-ready.php';
		$this->template_plain = 'emails/plain/beltoft-services-mail-ready.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your email domain {domain_name} is ready', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your email domain is ready', 'beltoft-services' ); }
}
