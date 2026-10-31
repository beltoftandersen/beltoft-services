<?php
/** Customer email: DNS records are still missing for an email domain. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class MailDnsReminder extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_mail_dns_reminder';
		$this->title = __( 'Email DNS records missing', 'beltoft-services' );
		$this->description = __( 'Sent once, a day after an email domain hosted elsewhere was set up, when DNS records are still missing.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-mail-dns-reminder.php';
		$this->template_plain = 'emails/plain/beltoft-services-mail-dns-reminder.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'DNS records are still missing for {domain_name}', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'DNS records are still missing', 'beltoft-services' ); }
}
