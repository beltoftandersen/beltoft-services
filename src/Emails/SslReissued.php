<?php
/** Customer email: ssl certificate reissued. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class SslReissued extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_ssl_reissued';
		$this->title = __( 'SSL certificate reissued', 'beltoft-services' );
		$this->description = __( 'Sent when a new certificate for an existing order is ready to install: reissued by the authority or at the customer request.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-ssl-reissued.php';
		$this->template_plain = 'emails/plain/beltoft-services-ssl-reissued.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'A new certificate for {domain_name} is ready to install', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'A new certificate is ready', 'beltoft-services' ); }
}
