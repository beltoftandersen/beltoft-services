<?php
/** Customer email: outgoing traffic is open again. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class QuarantineLifted extends ServiceEmail {

	protected const ID = 'beltoft_services_quarantine_lifted';
}
