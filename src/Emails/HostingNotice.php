<?php
/** Customer email: a notice about a hosting account (storage, PHP version). @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class HostingNotice extends ServiceEmail {

	protected const ID = 'beltoft_services_hosting_notice';

	public const KINDS = array( 'storage_warning', 'storage_over', 'storage_restricted', 'storage_restored', 'php_ending', 'php_switched' );
}
