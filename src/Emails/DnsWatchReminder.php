<?php
/** Customer email: DNS records are still missing for an email domain. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class DnsWatchReminder extends ServiceEmail {

	protected const ID = 'beltoft_services_dns_reminder';
}
