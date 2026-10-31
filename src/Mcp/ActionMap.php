<?php
/**
 * What a tool may call: the one allow-list of the MCP tools.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * The tools reach the dashboard's own REST routes from inside the request, which skips the token gate of the REST API
 * (Api\Tokens::gate), so the tools carry their own gate, and this is its table. A route call is let through only when
 * the tool that makes it is named for that route, and an action only when the action is mapped to that tool. Nothing
 * here is derived from what a customer sends.
 *
 * ACTIONS: every customer action the dashboard offers and the tool that runs it. EXCLUDED: every action that has no
 * tool, with the reason. CAPABILITIES and CAPABILITIES_EXCLUDED do the same for what is not an action of a service. A test (ToolsMapTest) reads what Proxmox\Actions, Snapshots, Firewall, PrivateAttach,
 * DbActions and Domains\Actions offer and fails when an action is in neither list, so a new dashboard action cannot
 * be forgotten.
 *
 * Domain transfers are never possible through a connected app: the unlock, and the transfer code, have no tool at any
 * level. Do not add them here.
 */
final class ActionMap {

	/**
	 * Action name => the tool that posts it.
	 */
	public const ACTIONS = array(
		'start'              => 'start',
		'shutdown'           => 'shutdown',
		'reboot'             => 'reboot',
		'rename'             => 'rename_server',
		'snapshot_take'      => 'create_snapshot',
		'app_retry'          => 'retry_app_install',
		'private_attach'     => 'private_network',
		'private_detach'     => 'private_network',
		'db_reset_password'  => 'reset_database_password',
		'set_nameservers'    => 'domain_settings',
		'lock'               => 'domain_settings',
		'check_delegation'   => 'domain_settings',
		'snapshot_delete'    => 'delete_snapshot',
		'snapshot_rollback'  => 'rollback_snapshot',
		'restore'            => 'restore_backup',
		'reinstall'          => 'reinstall_server',
		'replace_ssh_key'    => 'replace_ssh_key',
		'fw_reset'           => 'reset_firewall',
		'reset_password'     => 'reset_server_password',
		'db_set_policy'      => 'set_database_eviction_policy',
	);

	/**
	 * Action name => why no tool runs it. "never" is for good, "route" means a tool reaches it through its own REST route.
	 */
	public const EXCLUDED = array(
		'unlock'            => 'never: a domain transfer is only possible in the dashboard',
		'set_records'       => 'route: DNS records have their own tools (dns_upsert_record, dns_delete_record)',
		'fw_apply'          => 'route: the firewall has its own tool (set_firewall)',
	);

	/**
	 * What the dashboard lets a customer do that is not one of the actions above (a subscription, a card, a secret): the
	 * capability and the tool that does it. Every irreversible or secret capability of the dashboard is here or in
	 * CAPABILITIES_EXCLUDED, so none is missing without a reason.
	 */
	public const CAPABILITIES = array(
		'cancel_subscription'      => 'cancel_subscription',
		'remove_extra_disk'        => 'remove_addon',
		'remove_snapshot_slots'    => 'remove_addon',
		'remove_app_licence'       => 'remove_addon',
		'stop_dns_hosting'         => 'cancel_subscription',
		'firewall_off'             => 'set_firewall',
		'firewall_wide_open'       => 'set_firewall',
		'custom_nameservers'       => 'domain_settings',
		'database_open_to_everyone' => 'set_database_access',
		'set_default_card'         => 'set_default_payment_method',
		'reveal_server_password'   => 'reveal_server_password',
		'reveal_database_password' => 'reveal_database_password',
		'reveal_app_login'         => 'reveal_app_login',
		'database_ca_certificate'  => 'get_database_ca_certificate',
		'delete_mailbox'           => 'delete_mailbox',
		'reveal_mailbox_password'  => 'reveal_mailbox_password',
	);

	/**
	 * Capabilities of the dashboard that have no tool, with the reason. "never" is for good; "later" is a phase of the plan
	 * that is not built; "dashboard" is a flow that cannot be done without the customer's own browser.
	 */
	public const CAPABILITIES_EXCLUDED = array(
		'domain_transfer_code'        => 'never: the transfer code of a domain is only available in the dashboard, behind its password step',
		'domain_unlock'               => 'never: a domain transfer is only possible in the dashboard',
		'console_ticket'              => 'never: the console is a browser session bound to the visitor; console_link gives the page',
		'cancel_domain_registration'  => 'never: a registered domain is not cancelled, auto-renew is turned off instead (set_auto_renew)',
		'remove_backups_addon'        => 'later: a plan change that goes through the cart and checkout, bought and switched in the dashboard',
		'remove_public_ip_addon'      => 'later: a plan change that goes through the cart and checkout, bought and switched in the dashboard',
		'set_subscription_payment_method' => 'later: WooCommerce Subscriptions changes it with its pay page flow, which needs the purchase sandbox; dashboard only',
		'delete_saved_card'           => 'dashboard: a saved card is deleted in the dashboard',
		'add_card'                    => 'dashboard: entering a card needs the customer\'s browser and the bank\'s authentication',
		'account_password_and_email'  => 'never: changing how the customer logs in is not for a connected app',
		'connected_apps_and_limits'   => 'never: a connected app cannot change its own access',
	);

	/**
	 * Tool => the REST routes below beltoft-services/v1 it may call: "METHOD pattern". The generic action route is
	 * allowed only through ACTIONS.
	 */
	public const ROUTES = array(
		'server_status'      => array( 'GET #^/resources/\d+$#D' ),
		'database_info'      => array( 'GET #^/resources/\d+$#D' ),
		'server_usage'       => array( 'GET #^/resources/\d+/metrics$#D' ),
		'list_dns_records'   => array( 'GET #^/resources/\d+/dns-records$#D' ),
		'dns_upsert_record'  => array( 'PUT #^/resources/\d+/dns-records/[A-Za-z]+/[^/]+$#D' ),
		'dns_delete_record'  => array( 'DELETE #^/resources/\d+/dns-records/[A-Za-z]+/[^/]+$#D' ),
		'get_firewall'       => array( 'GET #^/resources/\d+/firewall$#D' ),
		'set_firewall'       => array( 'PUT #^/resources/\d+/firewall$#D' ),
		'get_database_access' => array( 'GET #^/resources/\d+/db-access$#D' ),
		'set_database_access' => array( 'POST #^/resources/\d+/db-access$#D' ),
		'set_usage_alerts'   => array( 'POST #^/resources/\d+/usage-alerts$#D' ),
		'usage_summary'      => array( 'GET #^/usage$#D' ),
		'list_dns_claims'    => array( 'GET #^/domains/dns-claims$#D' ),
		'reveal_server_password'   => array( 'POST #^/resources/\d+/reveal$#D' ),
		'reveal_database_password' => array( 'POST #^/resources/\d+/db-reveal$#D' ),
		'reveal_app_login'         => array( 'POST #^/resources/\d+/app-reveal$#D' ),
		'get_database_ca_certificate' => array( 'GET #^/resources/\d+/db-ca$#D' ),
		'email_domain_info'        => array( 'GET #^/resources/\d+/mail$#D' ),
		'create_mailbox'           => array( 'POST #^/resources/\d+/mail/mailboxes$#D' ),
		'reset_mailbox_password'   => array( 'POST #^/resources/\d+/mail/mailboxes/[^/]+/password$#D' ),
		'delete_mailbox'           => array( 'DELETE #^/resources/\d+/mail/mailboxes/[^/]+$#D' ),
		'add_email_alias'          => array( 'POST #^/resources/\d+/mail/aliases$#D' ),
		'remove_email_alias'       => array( 'DELETE #^/resources/\d+/mail/aliases/[^/]+$#D' ),
		'email_dns_check'          => array( 'POST #^/resources/\d+/mail/dns/check$#D' ),
		'reveal_mailbox_password'  => array( 'POST #^/resources/\d+/mail-reveal$#D' ),
	);

	/**
	 * May this tool make this call?
	 *
	 * @param string $tool   Tool name.
	 * @param string $method HTTP method.
	 * @param string $path   Route below beltoft-services/v1.
	 */
	public static function permits( string $tool, string $method, string $path ): bool {
		$method = strtoupper( $method );

		if ( 'POST' === $method && 1 === preg_match( '#^/resources/\d+/actions/([a-z0-9_-]+)$#D', $path, $m ) ) {
			return ( self::ACTIONS[ $m[1] ] ?? null ) === $tool;
		}

		foreach ( self::ROUTES[ $tool ] ?? array() as $allowed ) {
			list( $verb, $pattern ) = explode( ' ', $allowed, 2 );

			if ( $verb === $method && 1 === preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
