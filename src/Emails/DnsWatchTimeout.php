<?php
/** Customer email from the DNS watch about the DNS records of a service. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class DnsWatchTimeout extends ServiceEmail {

	protected const ID = 'beltoft_services_dns_timeout';
}
