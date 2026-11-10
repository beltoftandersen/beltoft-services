<?php
/** Customer email: a long renewal is coming. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class RenewalReminder extends ServiceEmail {

	protected const ID = 'beltoft_services_renewal_reminder';

	public function __construct() {
		parent::__construct();
		$this->placeholders['{renewal_date}'] = '';
	}
}
