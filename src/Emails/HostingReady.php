<?php
/** Customer email: the hosting account is ready. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class HostingReady extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_hosting_ready';
		$this->title = __( 'Hosting ready', 'beltoft-services' );
		$this->description = __( 'Sent when a WordPress hosting account is set up. It has the address of the site, the WordPress admin link, the SFTP details and the DNS records an external domain needs. It never holds a password.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-hosting-ready.php';
		$this->template_plain = 'emails/plain/beltoft-services-hosting-ready.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your hosting {product_name} is ready', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your hosting is ready', 'beltoft-services' ); }
}
