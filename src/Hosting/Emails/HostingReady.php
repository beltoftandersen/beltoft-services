<?php
/** Customer email: the hosting account is ready. @package BeltoftServices */
namespace BeltoftServices\Hosting\Emails;

use BeltoftServices\Emails\ServiceEmail;

defined( 'ABSPATH' ) || exit;

final class HostingReady extends ServiceEmail {

	protected const ID = 'beltoft_services_hosting_ready';
}
