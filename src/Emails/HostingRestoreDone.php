<?php
/** Customer email: a restore of a hosting account has ended. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

final class HostingRestoreDone extends ServiceEmail {

	protected const ID = 'beltoft_services_hosting_restore_done';

	public const KINDS = array( 'account', 'files', 'database', 'undo' );
}
