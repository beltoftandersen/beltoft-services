<?php
/**
 * The MCP tools of databases.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Db;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\Pricing;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Mcp\ServerActions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;
use BeltoftServices\Mcp\ToolSets;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of the databases: how to connect, the access list, the memory-full policy, the CA certificate and the password
 * (shown once, through the platform's reveal in Secrets) and ordering a new database (order_database, McpPurchase: a customer who has
 * to finish the first order in the browser gets a link and nothing to confirm). The set also answers for the database plans of the
 * catalogue and for what service_details says about a database.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * Joins the set to the MCP server, and answers for the platform's catalogue and the details of a database.
	 */
	public static function register(): void {
		add_filter( ToolSets::FILTER, array( __CLASS__, 'add' ) );
		add_filter( 'beltoft_services_mcp_service_details', array( __CLASS__, 'service_details' ), 10, 3 );
		add_filter( 'beltoft_services_mcp_catalog', array( __CLASS__, 'catalog' ), 10, 4 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'database_info'    => Definitions::read( 'Shows how to connect to one managed database: engine, host, port, database name, user name and the TLS fingerprint. The password is never shown here.', Definitions::by_id() ),
			'get_database_access' => Definitions::read( 'Shows which addresses may connect to one managed database that has a public IP address, whether it is open to everyone, and whether a change is being applied.' . Definitions::DATA, Definitions::by_id(), 30 ),
			'set_database_access' => Definitions::act(
				'Adds an address to, or removes one from, the list of addresses that may connect to a managed database with a public IP address, or closes the database again after it was open to everyone (world_off). The address is a public IPv4 address or range such as 203.0.113.7 or 203.0.113.0/24. Opening the database to everyone (world_on) needs Full access and a confirmation: the first call returns a summary and a confirm value, show the summary to the customer and send the value only after their clear yes. Ask the customer first.',
				Definitions::obj(
					array(
						'id'      => Definitions::id(),
						'op'      => array( 'type' => 'string', 'enum' => array( 'add', 'remove', 'world_on', 'world_off' ), 'description' => 'add, remove, world_on or world_off.' ),
						'address' => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'For add and remove.' ),
						'label'   => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'For add: a name the customer recognises. Optional.' ),
					),
					array( 'id', 'op' ),
					true
				),
				true
			),
			'set_database_eviction_policy' => Definitions::full(
				'Sets what a Valkey database does when its memory is full: the choice decides whether writes fail or data is dropped. Read database details with service_details first.',
				Definitions::obj( array( 'id' => Definitions::id(), 'policy' => array( 'type' => 'string', 'maxLength' => 40, 'description' => 'The policy, for example noeviction or allkeys-lru, as the database offers it.' ) ), array( 'id', 'policy' ), true ),
				false,
				true
			),
			'reveal_database_password' => Definitions::full(
				'Shows the password of a managed database once. The value then is in this conversation and we no longer have it. If there is none to show, the customer makes a new password in the dashboard.',
				Definitions::obj( array( 'id' => Definitions::id() ), array( 'id' ), true ),
				true
			),
			'order_database'   => Definitions::full(
				'Orders a new pay as you go managed database. Choose the plan (it fixes the engine and version), the public IPv4 address and the extra storage with the customer first, with catalog (database_plans) and price_quote. Nothing is charged now: the hours are billed monthly to the card saved on the customer\'s usage account (a saved card is required, credit alone is not enough; VAT is added on the invoice and credit is applied first). The confirmation summary shows the monthly lines, the max per month and the price per hour. A customer who has no usage account yet gets a link to finish the first order once in the browser, and nothing is ordered.',
				Definitions::obj(
					array(
						'plan'             => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'The plan variation_id or its exact name, from catalog database_plans.' ),
						'public_ipv4'      => array( 'type' => 'boolean', 'description' => 'With a public IPv4 address, billed by the hour with the database (a cart line of its own, price in price_quote).' ),
						'extra_storage_gb' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'Extra storage in GB, one of the steps listed for the plan in catalog database_plans. Default 0.' ),
					),
					array( 'plan' ),
					true
				),
				false,
				false,
				false
			),
			'get_database_ca_certificate' => Definitions::full(
				'Shows the CA certificate of a managed database, the text the customer saves as a file to connect over TLS. It is a public certificate, not a password.',
				Definitions::obj( array( 'id' => Definitions::id() ), array( 'id' ), true ),
				true,
				true,
				false
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'database_info'      => array( 'GET #^/resources/\d+$#D' ),
			'get_database_access' => array( 'GET #^/resources/\d+/db-access$#D' ),
			'set_database_access' => array( 'POST #^/resources/\d+/db-access$#D' ),
			'reveal_database_password' => array( 'POST #^/resources/\d+/db-reveal$#D' ),
			'get_database_ca_certificate' => array( 'GET #^/resources/\d+/db-ca$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'db_set_policy'      => 'set_database_eviction_policy',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		return array(
			'db_private_move'   => 'never: moving a database to another private network changes the address the customer\'s applications use; it is done in the dashboard, on the Network tab',
			'db_reset_password' => 'never: a new database password needs the code emailed to the account address, so it is only available in the dashboard',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'database_open_to_everyone' => 'set_database_access',
			'order_database'           => 'order_database',
			'reveal_database_password' => 'reveal_database_password',
			'database_ca_certificate'  => 'get_database_ca_certificate',
		);
	}

	/**
	 * Runs one of the tools of this set. The two that show something that is not a plain read go through the platform's one
	 * reveal (Secrets), which is the gate for a tool that shows a secret.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'database_info':
				return self::database_info( (int) $args['id'] );

			case 'get_database_access':
				$id    = (int) $args['id'];
				$state = Tools::route( 'GET', '/resources/' . $id . '/db-access', array(), null, $id, 'database access list read' );
				$r     = $state instanceof \WP_Error ? null : Tools::owned( $id );

				if ( $state instanceof \WP_Error || $r instanceof \WP_Error ) {
					return $state instanceof \WP_Error ? $state : $r;
				}

				return $state + self::access( $r );

			case 'set_database_access':
				$id   = (int) $args['id'];
				$body = array( 'op' => (string) $args['op'] );

				// Opening to everyone went through the confirmation: the dashboard's typed phrase is supplied by the server.
				if ( 'world_on' === $body['op'] ) {
					$body['confirm'] = DbAccess::CONFIRM_WORLD;
				}

				foreach ( array( 'address' => 'cidr', 'label' => 'label' ) as $from => $to ) {
					if ( isset( $args[ $from ] ) ) {
						$body[ $to ] = (string) $args[ $from ];
					}
				}

				$done = Tools::route( 'POST', '/resources/' . $id . '/db-access', array(), $body, $id, 'database access list: ' . str_replace( '_', ' ', (string) $args['op'] ) );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Requested. It is applied in the background. Use get_database_access to follow it.' );

			case 'set_database_eviction_policy':
				return Tools::action( (int) $args['id'], DbPolicy::ACTION, array( 'policy' => (string) $args['policy'] ), 'memory-full policy change requested' );

			case McpPurchase::NAME:
				return McpPurchase::run( $args, $user_id );
		}

		return Secrets::run( $name, $args );
	}

	/**
	 * The summary of a call that needs the confirmation: opening a database to everyone, the memory-full policy, the
	 * password and the CA certificate.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		if ( McpPurchase::NAME === $name ) {
			return McpPurchase::summary( $args, $user_id );
		}

		if ( 'set_database_access' === $name ) {
			return self::summary_world( $args );
		}

		$id = (int) $args['id'];
		$r  = Tools::owned( $id, 'get_database_ca_certificate' !== $name );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( 'set_database_eviction_policy' === $name ) {
			$bad = ServerActions::not_offered( $r, DbPolicy::ACTION );

			return null !== $bad ? $bad : self::summary_policy( $r, (string) $args['policy'], Irreversible::name( $r ) );
		}

		$label = Irreversible::name( $r );

		if ( 'reveal_database_password' === $name ) {
			if ( DbDriver::ID !== $r->get( 'driver' ) || ! DbRoutes::revealable( $r ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'There is no password to show. It has been shown already or it is no longer available. The customer can make a new one in the dashboard.', 'beltoft-services' ) );
			}

			/* translators: %s: database name. */
			return Secrets::wrap( sprintf( __( 'Show the password of database %s. It is shown once, then we no longer have it.', 'beltoft-services' ), $label ), $id . '|' . $name );
		}

		if ( DbDriver::ID !== $r->get( 'driver' ) || ! DbGuest::pem( (string) $r->get( 'db_ca_pem' ) ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The certificate is not available.', 'beltoft-services' ) );
		}

		return array(
			/* translators: %s: database name. */
			'text'  => sprintf( __( 'Show the CA certificate of database %s. It is a public certificate, not a password: it is the text to save as a file so a client can check the database over TLS.', 'beltoft-services' ), $label ),
			'state' => $id . '|ca',
		);
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
	 * Is this call worse than the tool usually is: a database opened to everyone?
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 */
	public static function risky( string $name, array $args ): ?bool {
		return 'set_database_access' === $name ? 'world_on' === ( $args['op'] ?? null ) : null;
	}

	/**
	 * How to connect to one database: no password, no certificate.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function database_info( int $id ) {
		$status = Tools::route( 'GET', '/resources/' . $id, array(), null, $id, 'database details read' );

		if ( $status instanceof \WP_Error ) {
			return $status;
		}

		$r = Resource::find( $id );

		if ( ! $r || DbDriver::ID !== $r->get( 'driver' ) ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not a database.', 'beltoft-services' ) );
		}

		$card = DbCard::view( $r );

		return array(
			'id'              => $id,
			'engine'          => (string) $card['engine'],
			'version'         => (int) $card['version'],
			'state'           => (string) $card['kind'],
			'host'            => (string) $card['host'],
			'port'            => (int) $card['port'],
			'database'        => (string) $card['database'],
			'user'            => (string) $card['user'],
			'tls_fingerprint' => (string) $card['fingerprint'],
			'power'           => (string) $card['power']['state'],
			'password'        => 'Not shown here. The customer reveals it, or makes a new one, in the dashboard.',
		);
	}

	/**
	 * What a Valkey database does when its memory is full.
	 *
	 * @param Resource $r      Database.
	 * @param string   $policy Policy.
	 * @param string   $label  The database's name.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_policy( Resource $r, string $policy, string $label ) {
		$engine  = DbPolicy::engine( $r );
		$choices = null === $engine ? array() : $engine->eviction_choices();

		if ( ! isset( $choices[ $policy ] ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Choose one of the listed settings.', 'beltoft-services' ) );
		}

		$now     = DbPolicy::current( $r );
		$current = isset( $choices[ $now ] ) ? (string) $choices[ $now ][0] : $now;

		return array(
			'text'  => sprintf(
				/* translators: 1: database name, 2: the new setting, 3: what the new setting does, 4: the setting it has now. */
				__( 'Change what database %1$s does when its memory is full to "%2$s". %3$s It is "%4$s" now. This decides whether writes fail or data is dropped, and it applies at once to the running database.', 'beltoft-services' ),
				$label,
				(string) $choices[ $policy ][0],
				(string) $choices[ $policy ][1],
				$current
			),
			'state' => $r->id() . '|policy|' . $policy . '|' . $now,
		);
	}

	/**
	 * A database opened to everyone.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_world( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( ! DbAccess::editable( $r ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The access list can be changed when the database is ready and has a public IP address.', 'beltoft-services' ) );
		}

		return array(
			'text'  => sprintf(
				/* translators: %s: database name. */
				__( 'Open database %s to everyone. Anyone on the internet can then try to log in to it, and attacks on the password are likely. It stays open until it is closed again (world_off), and the customer gets an e-mail about it. Prefer adding the addresses that need access.', 'beltoft-services' ),
				Irreversible::name( $r )
			),
			'state' => $r->id() . '|world|' . (string) wp_json_encode( $r->get( DbAccess::WORLD ) ),
		);
	}

	/**
	 * What service_details says about a database (filter beltoft_services_mcp_service_details).
	 *
	 * @param mixed  $extra What the features added so far.
	 * @param mixed  $r     Service.
	 * @param string $kind  Driver kind.
	 * @return mixed
	 */
	public static function service_details( $extra, $r, $kind ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( ! $r instanceof Resource || DbDriver::ID !== $r->get( 'driver' ) || ! is_array( $extra ) ) {
			return $extra;
		}

		return $extra + array( 'database' => self::details( $r ) );
	}

	/**
	 * The database plans of the catalogue (filter beltoft_services_mcp_catalog).
	 *
	 * @param mixed  $answer       Answer so far, or null.
	 * @param string $kind         What to list.
	 * @param int    $variation_id Unused here.
	 * @param int    $id           Unused here.
	 * @return mixed
	 */
	public static function catalog( $answer, $kind, $variation_id, $id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- filter signature.
		if ( null !== $answer || 'database_plans' !== $kind ) {
			return $answer;
		}

		return array(
			'currency' => get_woocommerce_currency(),
			'note'     => Pricing::note() . ' ' . __( 'A database is ordered with order_database (Full access, after the customer confirms). Each plan lists its options: the public IPv4 address and the extra storage steps, with their price per month and per hour.', 'beltoft-services' ),
			'plans'    => McpPricing::database_plans(),
		);
	}

	/**
	 * A database: how to connect, backups and the access list. No password, no certificate, no link.
	 *
	 * @param Resource $r Database.
	 * @return array<string, mixed>
	 */
	private static function details( Resource $r ): array {
		$card   = DbCard::view( $r );
		$access = $card['access'];

		return array(
			'engine'          => (string) $card['engine'],
			'version'         => (int) $card['version'],
			'state'           => (string) $card['kind'],
			'host'            => (string) $card['host'],
			'port'            => (int) $card['port'],
			'database'        => (string) $card['database'],
			'user'            => (string) $card['user'],
			'tls_fingerprint' => (string) $card['fingerprint'],
			'size'            => (string) $card['size'],
			'cost_so_far'     => (string) ( $card['cost_net'] ?? $card['cost'] ),
			'backups'         => array(
				'enabled'   => (bool) $card['backups']['enabled'],
				'days'      => (int) $card['backups']['days'],
				'last_ok'   => (string) $card['backups']['last_ok'],
				'last_dump' => (string) $card['backups']['last_dump'],
			),
			'public_access'   => array(
				'address'  => (string) $access['address'],
				'open_to_everyone' => (bool) $access['world'],
				'state'    => (string) $access['state'],
				'editable' => (bool) $access['editable'],
				'entries'  => array_map( static fn( $e ) => array( 'address' => (string) $e['cidr'], 'label' => (string) $e['label'] ), (array) $access['entries'] ),
				'max'      => (int) $access['max'],
			),
		);
	}

	/**
	 * The access list of a database, the part get_database_access adds to the route's state.
	 *
	 * @param Resource $r Database.
	 * @return array<string, mixed>
	 */
	private static function access( Resource $r ): array {
		$view = DbAccess::view( $r );

		return array(
			'public_address'   => (string) $view['address'],
			'open_to_everyone' => (bool) $view['world'],
			'editable'         => (bool) $view['editable'],
			'max'              => (int) $view['max'],
			'entries'          => array_map( static fn( $e ) => array( 'address' => (string) $e['cidr'], 'label' => (string) $e['label'], 'added' => (string) $e['added'] ), (array) $view['entries'] ),
		);
	}
}
