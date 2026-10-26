<?php
/** Customer email for a pending registry action. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class DomainActionNeeded extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_domain_action_needed';
		$this->title = __( 'Domain action needed', 'beltoft-services' );
		$this->description = __( 'Sent when a registration or transfer needs a customer approval.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-domain-action-needed.php';
		$this->template_plain = 'emails/plain/beltoft-services-domain-action-needed.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'Action needed for your domain', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your domain needs a confirmation', 'beltoft-services' ); }
}
