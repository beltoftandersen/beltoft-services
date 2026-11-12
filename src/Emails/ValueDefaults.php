<?php
/**
 * The empty values a feature adds to the service emails.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Every template value that a feature adds through beltoft_services_email_values, with its empty default.
 * The templates are written against all of them, so the email class lays these under the real values: with
 * a feature's plugin code not loaded or its filter removed, a template still renders and shows nothing of
 * that feature. The features set every one of their keys themselves, so these never win in a normal send.
 */
final class ValueDefaults {

	/**
	 * The defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return array(
			'extra_disks'           => array(),
			'disk_server_url'       => '',
			'disk_guide_url'        => '',
			'monitor_reason'        => 'down',
			'monitor_disk_percent'  => 0,
			'monitor_cpu_percent'   => 0,
			'monitor_cpu_hours'     => 0,
			'monitor_upgrade_url'   => '',
			'monitor_grow_url'      => '',
			'monitor_disks_url'     => '',
			'monitor_cpu_since'     => '',
			'monitor_disk_used'     => 0,
			'monitor_disk_total'    => 0,
			'quarantine_signal'     => 'scan',
			'quarantine_counts'     => array(),
			'quarantine_at'         => '',
			'app_domain'            => '',
			'app_www'               => '',
			'app_www_state'         => '',
			'app_ip'                => '',
			'app_name'              => '',
			'snapshot_reason'       => 'expired',
			'mail_domain'           => '',
			'mail_external'         => false,
			'mail_records'          => array(),
			'mail_host'             => '',
			'mail_imap'             => 0,
			'mail_smtp'             => 0,
			'mail_guide_url'        => '',
			'domain_action_reason'  => 'transfer_approval',
			'transfer_fix_reason'   => 'locked',
			'domain_security_event' => 'unlocked',
			'dns_stays'             => false,
			'domain_renews'         => '',
			'registrar_name'        => '',
			'domains_url'           => '',
			'dns_external'          => false,
			'dns_choice'            => '',
			'our_nameservers'       => array(),
			'nameservers_shown'     => array(),
			'dns_only'              => false,
			'nameservers'           => array(),
			'hosting'               => array( 'site_name' => '', 'site_url' => '', 'admin_url' => '', 'admin_user' => '', 'admin_email' => '', 'sftp_host' => '', 'sftp_port' => 0, 'sftp_user' => 'web', 'ssh' => false, 'domains' => array(), 'dns_records' => array(), 'has_email' => false ),
			'hosting_notice'        => array( 'kind' => 'storage_warning', 'percent' => 0, 'used' => 0, 'limit' => 0, 'days' => 0, 'php' => '', 'php_default' => '', 'php_end' => '', 'site' => '' ),
			'hosting_restore'       => array( 'kind' => 'account', 'ok' => true, 'until' => '', 'site' => '' ),
			'ssl'                   => array( 'name' => '', 'smime' => false, 'reason' => 'dns', 'rows' => array(), 'approver' => '', 'valid_to' => '', 'by_customer' => false, 'pull_url' => '', 'key_waiting' => false ),
		);
	}
}
