<?php
/** Customer email: ssl validation needed. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SslActionNeeded extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_ssl_action_needed';
		$this->title = __( 'SSL validation needed', 'beltoft-services' );
		$this->description = __( 'Sent when the customer has to do something to validate a domain: add a DNS record, put a file on the website or approve an email.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-ssl-action-needed.php';
		$this->template_plain = 'emails/plain/beltoft-services-ssl-action-needed.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Action needed to validate {domain_name}', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your certificate needs a validation step', 'beltoft-services' ); }
}
