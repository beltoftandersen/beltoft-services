<?php
/** Customer email: a long renewal is coming. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class RenewalReminder extends ServiceEmail {
	public function __construct() {
		$this->id = 'beltoft_services_renewal_reminder';
		$this->title = __( 'Renewal reminder', 'beltoft-services' );
		$this->description = __( 'Sent a product-set number of days before a long renewal (for example 30 days before a yearly hosting renewal): the plan, the date, the full price and how to cancel.', 'beltoft-services' );
		$this->template_html = 'emails/beltoft-services-renewal-reminder.php';
		$this->template_plain = 'emails/plain/beltoft-services-renewal-reminder.php';
		parent::__construct();
		$this->placeholders['{renewal_date}'] = '';
	}
	public function get_default_subject() { return __( 'Your {product_name} renews on {renewal_date}', 'beltoft-services' ); }
	public function get_default_heading() { return __( 'Your {product_name} renews on {renewal_date}', 'beltoft-services' ); }
}
