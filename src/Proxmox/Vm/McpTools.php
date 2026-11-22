<?php
/**
 * The MCP tools of servers: status, usage, snapshots and backups, the power, the name, the password, the plans and prices,
 * and the order of a new server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\Pricing;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Mcp\ServerActions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;
use BeltoftServices\Mcp\ToolSets;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDriver;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Snapshot\PanelData;
use BeltoftServices\Proxmox\Snapshot\SnapshotCart;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of the servers. The tools that change or show something go through the dashboard's own routes and checks
 * (Tools::route, Tools::action, ServerActions); the password is shown through the platform's one reveal (Secrets), which is the
 * gate for a tool that shows a secret. Ordering a server is the purchase machinery of this folder (McpPurchase): a customer
 * who has to finish the first order in the browser gets a link and nothing to confirm.
 *
 * Besides the tools the set answers for the platform's catalogue (the plans, the systems and the add-ons), for what
 * service_details says about a server, for the dashboard's own check of an action of a server or a database, and for how a
 * service is called in a summary.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * Tool name => the action route it posts to, for the three power tools.
	 */
	private const POWER = array(
		'reboot'   => 'reboot',
		'start'    => 'start',
		'shutdown' => 'shutdown',
	);

	/**
	 * Joins the set to the MCP server, and answers for the platform's catalogue, details, action checks and names.
	 */
	public static function register(): void {
		add_filter( ToolSets::FILTER, array( __CLASS__, 'add' ) );
		add_filter( 'beltoft_services_mcp_action_refusal', array( __CLASS__, 'action_refusal' ), 10, 4 );
		add_filter( 'beltoft_services_mcp_service_details', array( __CLASS__, 'service_details' ), 10, 3 );
		add_filter( 'beltoft_services_mcp_service_page', array( __CLASS__, 'service_page' ), 10, 5 );
		add_filter( 'beltoft_services_mcp_service_name', array( __CLASS__, 'service_name' ), 10, 3 );
		add_filter( 'beltoft_services_mcp_catalog', array( __CLASS__, 'catalog' ), 10, 4 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		$id    = Definitions::id();
		$by_id = Definitions::by_id();
		$none  = Definitions::none();

		return array(
			'server_status'    => Definitions::read( 'Shows one server: whether it is running, CPU, memory and disk use as percent, its IP address, system and size, and what it is doing now.' . Definitions::DATA, $by_id ),
			'server_usage'     => Definitions::read(
				'Shows the load of one server or hosting account over the last hour, day or month: average, highest and latest CPU, memory (in use without the file cache, in percent) and network figures.',
				Definitions::obj(
					array(
						'id'    => $id,
						'range' => array( 'type' => 'string', 'enum' => array( 'hour', 'day', 'month' ), 'description' => 'The period. Default hour.' ),
					),
					array( 'id' )
				)
			),
			'list_recovery_points' => Definitions::read( 'Lists the snapshots (with free slots) and the backups of one server, or the backups of one database, with their ids and dates.' . Definitions::DATA, $by_id, 30 ),
			'price_quote'      => Definitions::read( 'Prices a configuration the way the checkout does, and orders nothing: a server plan, with daily backups if wanted, or a database plan with extra storage. A server plan can also get an extra disk. Pay as you go: the lines per month, the max per month, and the price per hour, excluding VAT. Pick the plan and the extra storage steps from catalog (database_plans).', Definitions::obj( array( 'plan' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The plan variation_id from catalog server_plans or database_plans.' ), 'backups' => array( 'type' => 'boolean', 'description' => 'Server only: daily backups.' ), 'extra_disk_gb' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'Server only: an extra disk in GB, one of the sizes listed for the plan in catalog server_plans (options.extra_disk).' ), 'public_ipv4' => array( 'type' => 'boolean', 'description' => 'Database only: with a public IPv4 address, billed by the hour with the database. For a server it is added in the dashboard after the server is ready.' ), 'extra_storage_gb' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'Database only: extra storage in GB, one of the steps listed for the plan in catalog database_plans.' ) ), array( 'plan' ) ), 20 ),
			'list_server_plans' => Definitions::read( 'Lists the server plans that can be ordered through Claude, pay as you go: id, name, CPU, memory and disk, price per hour and the max per month (excluding VAT), what daily backups add, the extra disk sizes with their price, the systems and the apps each plan offers. Use it before order_server.', $none, 20 ),
			'console_link'     => Definitions::read( 'Gives the address of the console page of one server in the customer\'s dashboard. The customer opens it in the browser and logs in there. No console session is made here.', $by_id, 30 ),
			'reboot'           => Definitions::act( 'Restarts a server. Running programs stop. Ask the customer first. The restart runs in the background.', $by_id, true ),
			'start'            => Definitions::act( 'Starts a server or a managed database that is stopped. The start runs in the background.', $by_id, false, true ),
			'shutdown'         => Definitions::act( 'Shuts a server or a managed database down. It stays stopped until it is started, and a stopped service is still billed until it is deleted. A database cannot be reached while it is stopped. Ask the customer first. The shutdown runs in the background.', $by_id, true, true ),
			'create_snapshot'  => Definitions::act(
				'Takes a snapshot of a server, if the customer has a free snapshot slot. It cannot restore or delete one.',
				Definitions::obj( array( 'id' => $id, 'label' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'A name for the snapshot. Letters, numbers, spaces, dots, dashes and underscores. Optional.' ) ), array( 'id' ) ),
				false
			),
			'rename_server'    => Definitions::act( 'Gives a server a new name. The name is shown in the dashboard and used as its host name.', Definitions::obj( array( 'id' => $id, 'name' => array( 'type' => 'string', 'maxLength' => 63, 'description' => 'The new name: letters, numbers and dashes.' ) ), array( 'id', 'name' ) ), true, true ),
			'set_usage_alerts' => Definitions::act( 'Turns the e-mails about high usage of one server on or off.', Definitions::obj( array( 'id' => $id, 'on' => array( 'type' => 'boolean' ) ), array( 'id', 'on' ) ), false, true ),
			'delete_snapshot'  => Definitions::full(
				'Deletes one snapshot of a server for good. Get the snapshot id from list_recovery_points.',
				Definitions::obj( array( 'id' => $id, 'snapshot' => array( 'type' => 'string', 'maxLength' => 20, 'description' => 'The snapshot id, from list_recovery_points.' ) ), array( 'id', 'snapshot' ), true )
			),
			'rollback_snapshot' => Definitions::full(
				'Rolls a server back to one of its snapshots. Everything on the server changed since the snapshot is lost, and the server is stopped while it runs. Get the snapshot id from list_recovery_points.',
				Definitions::obj( array( 'id' => $id, 'snapshot' => array( 'type' => 'string', 'maxLength' => 20, 'description' => 'The snapshot id, from list_recovery_points.' ) ), array( 'id', 'snapshot' ), true )
			),
			'restore_backup'   => Definitions::full(
				'Restores a server or a managed database from one of its backups. It replaces everything on it with the backup, and it is unavailable while this runs. Get the backup id from list_recovery_points.',
				Definitions::obj( array( 'id' => $id, 'backup' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The backup id, from list_recovery_points.' ) ), array( 'id', 'backup' ), true )
			),
			'reinstall_server' => Definitions::full(
				'Erases a server and installs it again, with a system and optionally an app. Everything on the server is lost. All choices are optional; what is left out stays as it is. An app that has a licence is not bought here: the answer then says where the customer finishes it.',
				Definitions::obj(
					array(
						'id'          => $id,
						'os'          => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The system, an id from catalog (os_and_apps, systems).' ),
						'app'         => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'keep (the same app and version), none (no app), a version id of the server\'s own app, or app_id/version_id for another app, from catalog (os_and_apps).' ),
						'tier'        => array( 'type' => 'string', 'maxLength' => 40, 'description' => 'The tier of an app that has a licence.' ),
						'domain_mode' => array( 'type' => 'string', 'enum' => array( 'none', 'keep', 'change', 'own' ), 'description' => 'For an app that uses a domain: none, keep the one it has, change to a name below one of the customer\'s domains, or own for a domain hosted elsewhere.' ),
						'domain_zone' => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'For domain_mode change: the domain the name is below.' ),
						'domain_sub'  => array( 'type' => 'string', 'maxLength' => 63, 'description' => 'For domain_mode change: the part before the domain.' ),
						'domain_own'  => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'For domain_mode own: the full name.' ),
					),
					array( 'id' ),
					true
				)
			),
			'replace_ssh_key'  => Definitions::full(
				'Adds or replaces the SSH key of a server. The old key stops working, so the customer must have the matching private key or they are locked out.',
				Definitions::obj( array( 'id' => $id, 'key' => array( 'type' => 'string', 'maxLength' => 2000, 'description' => 'The public key, one line, for example ssh-ed25519 AAAA... name.' ) ), array( 'id', 'key' ), true )
			),
			'order_server'     => Definitions::full(
				'Orders a new pay as you go server. Choose the plan, system or app, name, backups, extra disk and SSH key with the customer first (list_server_plans). Nothing is charged now: the hours are billed monthly to the card saved on the customer\'s usage account (a saved card is required, credit alone is not enough; VAT is added on the invoice and credit is applied first). A customer who has no usage account yet gets a link to finish the first order once in the browser, and nothing is ordered.',
				Definitions::obj(
					array(
						'plan'        => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'The plan id or its exact name, from list_server_plans.' ),
						'os'          => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'The system: an id or exact name from list_server_plans. Not needed when an app is chosen.' ),
						'app'         => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'An app from list_server_plans: app_id, or app_id/version_id. Optional; the app runs on its own system.' ),
						'server_name' => array( 'type' => 'string', 'maxLength' => 63, 'description' => 'The host name of the server: letters, digits and hyphens. Optional.' ),
						'backups'     => array( 'type' => 'boolean', 'description' => 'Daily backups, if the plan offers them. Default false.' ),
						'extra_disk_gb' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'An extra disk in GB, one of the sizes listed for the plan in list_server_plans (options.extra_disk). Billed by the hour with the server. Default 0, none.' ),
						'ssh_key'     => array( 'type' => 'string', 'maxLength' => 8192, 'description' => 'A public SSH key, one line. Optional.' ),
					),
					array( 'plan' ),
					true
				),
				false,
				false,
				false
			),
			'reveal_server_password' => Definitions::full(
				'Shows the password of a server once. The value then is in this conversation and we no longer have it. If there is none to show, the customer makes a new password in the dashboard.',
				Definitions::obj( array( 'id' => $id ), array( 'id' ), true ),
				true
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'server_status'          => array( 'GET #^/resources/\d+$#D' ),
			'server_usage'           => array( 'GET #^/resources/\d+/metrics$#D' ),
			'set_usage_alerts'       => array( 'POST #^/resources/\d+/usage-alerts$#D' ),
			'reveal_server_password' => array( 'POST #^/resources/\d+/reveal$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'start'             => 'start',
			'shutdown'          => 'shutdown',
			'reboot'            => 'reboot',
			'rename'            => 'rename_server',
			'snapshot_take'     => 'create_snapshot',
			'snapshot_delete'   => 'delete_snapshot',
			'snapshot_rollback' => 'rollback_snapshot',
			'restore'           => 'restore_backup',
			'reinstall'         => 'reinstall_server',
			'replace_ssh_key'   => 'replace_ssh_key',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'reveal_server_password' => 'reveal_server_password',
			'order_server'           => 'order_server',
		);
	}

	/**
	 * Runs one of the tools of this set.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'server_status':
				return self::server_status( (int) $args['id'] );

			case 'server_usage':
				return self::server_usage( (int) $args['id'], (string) ( $args['range'] ?? 'hour' ) );

			case 'list_recovery_points':
				return self::recovery_points( (int) $args['id'] );

			case 'list_server_plans':
				return McpPurchase::plans();

			case 'price_quote':
				return McpPricing::quote( $args );

			case 'console_link':
				$r = Tools::owned( (int) $args['id'] );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				$driver = Registry::get( (string) $r->get( 'driver' ) );
				$url    = $driver && 'vm' === $driver->kind() ? ServerLinks::url( (array) $r->license(), 'console' ) : '';

				return '' === $url
					? new \WP_Error( 'beltoft_services_tool', __( 'This service has no console.', 'beltoft-services' ) )
					: array( 'console_page' => $url, 'note' => 'The customer opens this page in the browser and logs in. No console session is started here.' );

			case 'create_snapshot':
				return Tools::action( (int) $args['id'], 'snapshot_take', isset( $args['label'] ) ? array( 'label' => (string) $args['label'] ) : array(), 'snapshot requested' );

			case 'reboot':
			case 'start':
			case 'shutdown':
				return Tools::action( (int) $args['id'], self::POWER[ $name ], array(), $name . ' requested' );

			case 'rename_server':
				return Tools::action( (int) $args['id'], 'rename', array( 'name' => (string) $args['name'] ), 'rename requested' );

			case 'set_usage_alerts':
				$id   = (int) $args['id'];
				$done = Tools::route( 'POST', '/resources/' . $id . '/usage-alerts', array(), array( 'on' => (bool) $args['on'] ), $id, 'usage e-mails turned ' . ( $args['on'] ? 'on' : 'off' ) );

				return $done instanceof \WP_Error ? $done : array( 'usage_emails' => ! empty( $done['on'] ) ? 'on' : 'off' );

			case 'delete_snapshot':
			case 'rollback_snapshot':
			case 'restore_backup':
			case 'reinstall_server':
			case 'replace_ssh_key':
				return McpServerActions::run( $name, $args );

			case McpPurchase::NAME:
				return McpPurchase::run( $args, $user_id );

			case 'reveal_server_password':
				return Secrets::run( $name, $args );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * The summary of a call that needs the confirmation: the snapshot, backup, reinstall and key tools, the order and the password.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case McpPurchase::NAME:
				return McpPurchase::summary( $args, $user_id );

			case 'reveal_server_password':
				return self::summary_password( $args );

			case 'delete_snapshot':
			case 'rollback_snapshot':
			case 'restore_backup':
			case 'reinstall_server':
			case 'replace_ssh_key':
				$r = Tools::owned( (int) $args['id'], true );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				$action = (string) array_search( $name, ActionMap::actions(), true );
				$bad    = ServerActions::not_offered( $r, $action );

				if ( null !== $bad ) {
					return $bad;
				}

				return McpServerActions::summary( $name, $args, $r, $action, Irreversible::name( $r ) );
		}

		return null;
	}

	/**
	 * A customer who has to finish the first order in the browser gets a link and nothing is done: no confirmation to ask for.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function needs_confirmation( string $name, array $args ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- interface.
		return McpPurchase::NAME === $name && ! McpPurchase::goes_direct( get_current_user_id() ) ? false : null;
	}

	/**
	 * The summary of the password reveal: there must be a password to show.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_password( array $args ) {
		$id = (int) $args['id'];
		$r  = Tools::owned( $id, true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( ! Access::revealable( $r ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'There is no password to show. It has been shown already or it is no longer available. The customer can make a new one in the dashboard.', 'beltoft-services' ) );
		}

		/* translators: %s: server name. */
		return Secrets::wrap( sprintf( __( 'Show the password of server %s. It is shown once, then we no longer have it.', 'beltoft-services' ), Irreversible::name( $r ) ), $id . '|reveal_server_password' );
	}

	/**
	 * The dashboard's own check of an action of a server or a database, with the words the dashboard shows (filter beltoft_services_mcp_action_refusal).
	 *
	 * @param mixed                $why    Refusal so far.
	 * @param Resource             $r      Service.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Action arguments, with the typed word the dashboard asks for.
	 * @return mixed
	 */
	public static function action_refusal( $why, $r, $action, $args ) {
		if ( null !== $why || ! $r instanceof Resource || ! in_array( (string) $r->get( 'driver' ), array( ProxmoxDriver::ID, 'proxmox-db' ), true ) ) {
			return $why;
		}

		return Actions::check( $r, (string) $action, (array) $args );
	}

	/**
	 * What service_details says about a server (filter beltoft_services_mcp_service_details).
	 *
	 * @param mixed  $extra What the features added so far.
	 * @param mixed  $r     Service.
	 * @param string $kind  Driver kind.
	 * @return mixed
	 */
	public static function service_details( $extra, $r, $kind ) {
		if ( 'vm' !== $kind || ! $r instanceof Resource || ! is_array( $extra ) ) {
			return $extra;
		}

		return $extra + array( 'server' => McpDetails::vm( $r ) + McpDetails::extras( $r ) );
	}

	/**
	 * The address of the page of a server in the dashboard (filter beltoft_services_mcp_service_page).
	 *
	 * @param mixed                $url     Address so far.
	 * @param mixed                $r       Service.
	 * @param string               $kind    Driver kind.
	 * @param array<string, mixed> $license License.
	 * @param string               $tab     A tab of the page, or ''.
	 * @return mixed
	 */
	public static function service_page( $url, $r, $kind, $license, $tab ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		return 'vm' === $kind ? ServerLinks::url( (array) $license, (string) $tab ) : $url;
	}

	/**
	 * What the customer calls a service in a summary: the name of a server, a disk or a database (filter beltoft_services_mcp_service_name).
	 *
	 * @param mixed                $name    Name so far.
	 * @param mixed                $r       Service.
	 * @param array<string, mixed> $license License.
	 * @return mixed
	 */
	public static function service_name( $name, $r, $license ) {
		return $r instanceof Resource ? ServerName::email_label( $r, (array) $license ) : $name;
	}

	/**
	 * The server plans, the systems and apps of a plan and the add-ons of a service (filter beltoft_services_mcp_catalog).
	 *
	 * @param mixed  $answer       Answer so far, or null.
	 * @param string $kind         server_plans, database_plans, os_and_apps, addons or domain_prices.
	 * @param int    $variation_id A server plan, for os_and_apps.
	 * @param int    $id           One of the customer's services, for addons.
	 * @return mixed
	 */
	public static function catalog( $answer, $kind, $variation_id, $id ) {
		if ( null !== $answer ) {
			return $answer;
		}

		switch ( $kind ) {
			case 'server_plans':
				return array(
					'currency' => get_woocommerce_currency(),
					'note'     => Pricing::note(),
					'plans'    => McpPricing::server_plans(),
				);

			case 'os_and_apps':
				$plan = (int) $variation_id > 0 ? Offers::find( (int) $variation_id ) : null;

				if ( null === $plan ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'Give the variation_id of a server plan from server_plans.', 'beltoft-services' ) );
				}

				$cluster = (array) Clusters::get( (string) $plan['cluster'] );
				$apps    = array();

				foreach ( Apps::offered( $cluster ) as $app_id => $app ) {
					$apps[] = array(
						'id'       => (string) $app_id,
						'name'     => (string) ( $app['name'] ?? $app_id ),
						'versions' => array_values( array_map( static fn( $v ) => array( 'id' => (string) $v['version_id'], 'name' => Apps::version_label( $v ) ), (array) $app['choices'] ) ),
					);
				}

				$systems = array();

				foreach ( OsField::options_for( (string) $plan['cluster'] ) as $template => $label ) {
					$systems[] = array( 'id' => (int) $template, 'name' => (string) $label );
				}

				return array( 'systems' => $systems, 'apps' => $apps );

			case 'addons':
				$r = (int) $id > 0 ? Tools::owned( (int) $id ) : new \WP_Error( 'beltoft_services_tool', __( 'Give the id of one of your services.', 'beltoft-services' ) );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				return self::addons( $r );
		}

		return null;
	}

	/**
	 * One server's state.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function server_status( int $id ) {
		$status = Tools::route( 'GET', '/resources/' . $id, array(), null, $id, 'server status read' );

		if ( $status instanceof \WP_Error ) {
			return $status;
		}

		$r   = Resource::find( $id );
		$out = array(
			'id'      => $id,
			'state'   => (string) $status['state'],
			'status'  => (string) $status['label'],
			'message' => (string) $status['message'],
			'working' => (bool) $status['working'],
		);

		if ( $r && ProxmoxDriver::ID === $r->get( 'driver' ) ) {
			$out += McpDetails::vm( $r );
			$out['app_state'] = (string) $status['app_state'];
		}

		return $out;
	}

	/**
	 * A server's load, summarised so the answer stays short.
	 *
	 * @param int    $id    Service id.
	 * @param string $range hour, day or month.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function server_usage( int $id, string $range ) {
		$series = Tools::route( 'GET', '/resources/' . $id . '/metrics', array( 'range' => $range ), null, $id, 'server usage read' );

		if ( $series instanceof \WP_Error ) {
			return $series;
		}

		$out = array(
			'range'     => (string) $series['range'],
			'available' => ! empty( $series['available'] ),
			'samples'   => count( (array) $series['points'] ),
			'units'     => array( 'cpu' => 'percent', 'mem_percent' => 'percent of memory in use, without the file cache', 'netin' => 'bytes per second', 'netout' => 'bytes per second', 'diskread' => 'bytes per second', 'diskwrite' => 'bytes per second' ),
		);

		foreach ( array( 'cpu', 'netin', 'netout', 'diskread', 'diskwrite' ) as $field ) {
			$values = array();

			foreach ( (array) $series['points'] as $point ) {
				if ( isset( $point[ $field ] ) && is_numeric( $point[ $field ] ) ) {
					$values[] = (float) $point[ $field ];
				}
			}

			$out[ $field ] = $values ? array(
				'average' => round( array_sum( $values ) / count( $values ), 1 ),
				'highest' => round( max( $values ), 1 ),
				'latest'  => round( (float) end( $values ), 1 ),
			) : null;
		}

		// Memory comes from the guest's own readings kept by the plugin (never the hypervisor's figure).
		$mem  = array();
		$peak = array();

		foreach ( (array) ( $series['memory']['points'] ?? array() ) as $point ) {
			if ( isset( $point['percent'] ) && is_numeric( $point['percent'] ) ) {
				$mem[]  = (float) $point['percent'];
				$peak[] = (float) ( $point['peak_percent'] ?? $point['percent'] );
			}
		}

		$out['mem_percent'] = $mem ? array(
			'average' => round( array_sum( $mem ) / count( $mem ), 1 ),
			'highest' => round( max( $peak ), 1 ),
			'latest'  => round( (float) end( $mem ), 1 ),
		) : null;

		if ( isset( $series['disk']['used'], $series['disk']['total'] ) && $series['disk']['total'] > 0 ) {
			$out['disk_percent'] = (int) round( $series['disk']['used'] / $series['disk']['total'] * 100 );
		}

		return $out;
	}

	/**
	 * Snapshots and backups of one server, or the backups of one database.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function recovery_points( int $id ) {
		$r = Tools::owned( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$driver = (string) $r->get( 'driver' );

		if ( ProxmoxDriver::ID !== $driver && DbDriver::ID !== $driver ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This service has no snapshots or backups.', 'beltoft-services' ) );
		}

		$backups = Backups::listing( $r );
		$out     = array(
			'backups' => array(
				'enabled' => Backups::enabled( $r ),
				'days'    => Backups::days( $r ),
				'last_ok' => $backups['last_ok'],
				'list'    => array_map( static fn( $b ) => array( 'id' => (string) $b['volid'], 'when' => (string) $b['when'], 'size' => (string) $b['size'] ), $backups['list'] ),
			),
		);

		if ( ProxmoxDriver::ID === $driver ) {
			$panel = PanelData::panel( $r );

			$out['snapshots'] = null === $panel ? null : array(
				'enabled' => $panel['enabled'],
				'slots'   => $panel['slots'],
				'used'    => $panel['used'],
				'free'    => max( 0, (int) $panel['slots'] - (int) $panel['used'] ),
				'days'    => $panel['days'],
				'list'    => array_map(
					static fn( $e ) => array(
						'id'      => $e['id'],
						'label'   => $e['label'],
						'taken'   => $e['taken_at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $e['taken_at'] ) : '',
						'expires' => $e['expires_at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $e['expires_at'] ) : '',
						'state'   => $e['state'],
					),
					$panel['entries']
				),
			);
		}

		return $out;
	}

	/**
	 * What can be added to one service.
	 *
	 * @param Resource $r Service.
	 * @return array<string, mixed>
	 */
	private static function addons( Resource $r ): array {
		$driver   = (string) $r->get( 'driver' );
		$currency = get_woocommerce_currency();
		$out      = array(
			'currency' => $currency,
			'note'     => Pricing::note() . ' ' . __( 'Add-ons are priced by the hour and capped at the max per month shown.', 'beltoft-services' ),
		);
		$current  = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );

		if ( ProxmoxDriver::ID === $driver ) {
			$out['snapshot_packs'] = array_values(
				array_map(
					static fn( $v ) => array( 'variation_id' => $v->get_id(), 'slots' => SnapshotCart::slots_of( $v ), 'price' => Pricing::variation_price( $v ) ),
					SnapshotCart::packs()
				)
			);
			$out['extra_disks']    = array_values(
				array_map(
					static fn( $v ) => array( 'variation_id' => $v->get_id(), 'price' => Pricing::variation_price( $v ) ),
					DiskField::sizes_for( 'usage' === (string) ( $r->license()['source'] ?? '' ) )
				)
			);
		}

		if ( ProxmoxDriver::ID === $driver || DbDriver::ID === $driver ) {
			$with           = $current instanceof \WC_Product_Variation && ! Backups::enabled( $r ) ? Backups::sibling( $current, true ) : null;
			$out['backups'] = Backups::enabled( $r ) ? array( 'enabled' => true ) : array( 'enabled' => false, 'adds' => $with ? Pricing::addon_between( $current, $with ) : null );
		}

		if ( DbDriver::ID === $driver && $current instanceof \WC_Product_Variation ) {
			$own  = DbDisk::extra( (array) $r->get( 'plan' ) );
			$step = array();

			foreach ( DbDisk::ladder( $current ) as $gb => $variation ) {
				if ( $gb > $own ) {
					$step[] = array( 'extra_gb' => (int) $gb, 'more_gb' => (int) $gb - $own, 'adds' => Pricing::addon_between( $current, $variation ) );
				}
			}

			$out['extra_storage'] = array(
				'current_extra_gb' => $own,
				'max_data_disk_gb' => DbDisk::max_gb(),
				'steps'            => $step,
				'note'             => __( 'Storage can be made larger from the database page in the dashboard, never smaller. It is billed by the hour with the database and capped. Claude cannot grow it yet.', 'beltoft-services' ),
			);

			$out['public_ipv4'] = McpDetails::ipv4_of( $r );
		}

		return $out;
	}
}
