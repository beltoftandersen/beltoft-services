<?php
/** Customer email: ssl certificate ending. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SslExpiring extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_ssl_expiring';
		$this->title = __( 'SSL certificate ending', 'beltoft-services' );
		$this->description = __( 'Sent when the current certificate ends within days and no replacement has been issued yet.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-ssl-expiring.php';
		$this->template_plain = 'emails/plain/beltoft-services-ssl-expiring.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your certificate for {domain_name} ends soon', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your certificate ends soon', 'beltoft-services' ); }
}
