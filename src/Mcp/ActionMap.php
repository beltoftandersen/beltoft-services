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
 * tool, with the reason. A test (ToolsMapTest) reads what Proxmox\Actions, Snapshots, Firewall, PrivateAttach,
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
	);

	/**
	 * Action name => why no tool runs it. "never" is for good, "later" is a phase of the plan that is not built yet.
	 */
	public const EXCLUDED = array(
		'unlock'            => 'never: a domain transfer is only possible in the dashboard',
		'set_records'       => 'route: DNS records have their own tools (dns_upsert_record, dns_delete_record)',
		'fw_apply'          => 'route: the firewall has its own tool (set_firewall)',
		'fw_reset'          => 'later: wipes the rules, needs confirmation',
		'snapshot_delete'   => 'later: irreversible, needs confirmation',
		'snapshot_rollback' => 'later: irreversible, needs confirmation',
		'restore'           => 'later: irreversible, needs confirmation',
		'reinstall'         => 'later: irreversible, needs confirmation',
		'replace_ssh_key'   => 'later: lockout risk, needs confirmation',
		'reset_password'    => 'later: makes a secret, needs confirmation and the reveal',
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
