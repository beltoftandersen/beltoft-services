<?php
/** Customer email: the app is live on the customer's own domain. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class AppDomainLive extends ServiceEmail {

	protected const ID = 'beltoft_services_app_domain_live';
}
