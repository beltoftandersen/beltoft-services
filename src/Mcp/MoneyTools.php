<?php
/**
 * Which MCP tools return or move money.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A tool that returns an amount, quotes a price or buys something runs in the currency of the token's owner and is refused when that
 * currency cannot be put in force; every other tool, and the protocol itself, works in the shop's currency. Every tool of
 * Definitions::all() is in exactly one of the two lists: MoneyToolsTest fails when a new tool is in neither.
 */
final class MoneyTools {

	/** Tools that return or move money. */
	public const MONEY = array(
		'domain_check',
		'billing_summary',
		'usage_summary',
		'list_orders',
		'get_order',
		'list_subscriptions',
		'catalog',
		'price_quote',
		'list_server_plans',
		'order_server',
		'order_database',
		'reactivate_subscription',
		'remove_addon',
		'hosting_info',
	);

	/** Tools that show or change no amount. */
	public const NO_MONEY = array(
		'list_services', 'server_status', 'server_usage', 'database_info', 'list_dns_records', 'service_details', 'list_recovery_points', 'get_firewall',
		'get_database_access', 'list_payment_methods', 'get_account', 'list_dns_claims', 'console_link', 'hosting_usage', 'list_hosting_backups',
		'email_domain_info', 'ssl_info', 'get_ssl_certificate', 'list_licenses', 'get_license_key', 'reboot', 'start', 'shutdown', 'create_snapshot',
		'dns_upsert_record', 'dns_delete_record', 'rename_server', 'set_firewall', 'set_database_access', 'private_network', 'set_usage_alerts',
		'retry_app_install', 'domain_settings', 'add_site_domain', 'set_php_version', 'clear_site_cache', 'create_hosting_backup', 'create_mailbox',
		'add_email_alias', 'remove_email_alias', 'ssl_resend_email', 'email_dns_check', 'set_auto_renew', 'free_license_site', 'cancel_unpaid_order',
		'update_billing_address', 'delete_snapshot', 'rollback_snapshot', 'restore_backup', 'reinstall_server', 'replace_ssh_key', 'reset_firewall',
		'reset_server_password', 'reset_database_password', 'set_database_eviction_policy', 'reset_mailbox_password', 'delete_mailbox',
		'restore_hosting_backup', 'delete_site', 'cancel_subscription', 'delete_server', 'delete_database', 'set_default_payment_method',
		'reveal_server_password', 'reveal_database_password', 'reveal_app_login', 'reveal_mailbox_password', 'get_database_ca_certificate',
	);

	/**
	 * Whether a tool returns or moves money.
	 *
	 * @param string $name Tool name.
	 */
	public static function is_money( string $name ): bool {
		return in_array( $name, self::MONEY, true );
	}
}
