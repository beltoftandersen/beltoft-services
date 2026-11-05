<?php
/** Customer email: a notice about a hosting account (storage, PHP version). @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class HostingNotice extends ServiceEmail {
	public const KINDS = array( 'storage_warning', 'storage_over', 'storage_restricted', 'storage_restored', 'php_ending', 'php_switched' );

	public function __construct() {
		$this->id = 'beltoft_services_hosting_notice';
		$this->title = __( 'Hosting notice', 'beltoft-services' );
		$this->description = __( 'Sent when a hosting account is close to or over its storage, and before and when a PHP version loses security support.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-hosting-notice.php';
		$this->template_plain = 'emails/plain/beltoft-services-hosting-notice.php';
		parent::__construct();
	}
	public function get_default_subject() { return __( 'About your hosting {product_name}', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'About your hosting', 'beltoft-services' ); }
}
