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
		$defaults = array(
			'extra_disks'           => array(),
			'disk_server_url'       => '',
			'disk_guide_url'        => '',
			'monitor_reason'        => 'down',
			'monitor_hosting'       => false,
			'monitor_disk_percent'  => 0,
			'monitor_cpu_percent'   => 0,
			'monitor_cpu_hours'     => 0,
			'monitor_mem_percent'   => 0,
			'monitor_mem_minutes'   => 0,
			'monitor_mem_used_mb'   => 0,
			'monitor_mem_total_mb'  => 0,
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
			'mail_records_needed'   => array(),
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
			'ssl'                   => array( 'name' => '', 'smime' => false, 'reason' => 'dns', 'rows' => array(), 'approver' => '', 'valid_to' => '', 'by_customer' => false, 'pull_url' => '', 'key_waiting' => false ),
		);

		/**
		 * The empty values of a feature's own template keys, laid after the core ones.
		 *
		 * @param array<string, mixed> $defaults Defaults so far.
		 */
		$defaults = apply_filters( 'beltoft_services_email_value_defaults', $defaults );

		return is_array( $defaults ) ? $defaults : array();
	}
}
