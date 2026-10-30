<?php
/** Customer email: the app is live on the customer's own domain. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class AppDomainLive extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_app_domain_live';
		$this->title = __( 'App live on your own domain', 'beltoft-services' );
		$this->description = __( 'Sent when an app on a server is installed and reachable on a domain the customer registered elsewhere.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-app-domain-live.php';
		$this->template_plain = 'emails/plain/beltoft-services-app-domain-live.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Your app is live on your domain', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your app is live', 'beltoft-services' ); }
}
