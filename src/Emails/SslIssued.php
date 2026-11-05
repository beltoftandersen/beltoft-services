<?php
/** Customer email: ssl certificate issued. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SslIssued extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_ssl_issued';
		$this->title = __( 'SSL certificate issued', 'beltoft-services' );
		$this->description = __( 'Sent when a certificate is issued. It links to the page where the certificate and the private key are downloaded. It never holds a key.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-ssl-issued.php';
		$this->template_plain = 'emails/plain/beltoft-services-ssl-issued.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your SSL certificate for {domain_name} is ready', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your SSL certificate is ready', 'beltoft-services' ); }
}
