<?php
/**
 * The tools of the MCP endpoint.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Account\Overview;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Domains\Search;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Each tool is a thin wrapper. The ones that exist as a REST route call that route inside the same request
 * (rest_do_request), so the owner checks, the state checks, the per-route limits and the DNS safe-write rules are the
 * code the dashboard uses, not a copy. The rest read stored data through the same classes the account pages use
 * (Reads, Details, Acts).
 *
 * Tools::call is the one gate. A tool reaches the REST routes from inside the request, which skips the token gate of the
 * REST API (Api\Tokens::gate), so every call goes through the same steps here, in order: the level of the connection
 * (read, actions or full) against the level the tool needs for these arguments, the arguments against the tool's
 * schema, the customer's limit, whether the call needs a confirmation (Confirmations: a summary first, the tool only with the token
 * of that summary), and then the tool. A route or action a tool
 * posts is checked against the table in ActionMap, so a tool can reach only what it is named for.
 *
 * Never in a result, except for the four tools that show a secret on purpose (Secrets: Full access, the customer's
 * confirmation, shown once): a password, a transfer code, a console ticket, a card number or payment token, a link that
 * logs in or carries a nonce. There is no tool for domain transfers, transfer codes or console tickets at any level.
 *
 * Every call on a service writes a line to that service's history ("Claude: reboot requested"), but only after the
 * service was found and is the customer's.
 */
final class Tools {

	/**
	 * Tool name => the action route it posts to, for the three power tools.
	 */
	private const POWER = array(
		'reboot'   => 'reboot',
		'start'    => 'start',
		'shutdown' => 'shutdown',
	);

	/**
	 * Names that Reads, Details and Acts run.
	 */
	private const READS = array( 'service_details', 'usage_summary', 'list_recovery_points', 'get_firewall', 'get_database_access', 'list_orders', 'get_order', 'list_subscriptions', 'list_payment_methods', 'get_account', 'catalog', 'list_dns_claims', 'console_link', 'list_licenses', 'get_license_key' );

	/**
	 * The tool that is running, for the allow-list of the routes it calls.
	 *
	 * @var string
	 */
	private static $running = '';

	/**
	 * Is there a tool of this name?
	 *
	 * @param string $name Name.
	 */
	public static function exists( string $name ): bool {
		return isset( self::definitions()[ $name ] );
	}

	/**
	 * The lowest level that may call a tool with these arguments. It is the tool's own level, raised by arguments that
	 * make the call worse than the tool usually is.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 * @return string read, actions or full.
	 */
	public static function required_level( string $name, array $args ): string {
		$level = (string) ( self::definitions()[ $name ]['level'] ?? Tokens::FULL );

		return self::risky( $name, $args ) ? Tokens::FULL : $level;
	}

	/**
	 * Does this call need the customer's confirmation first? The tools of Full access that cannot be undone, show a
	 * secret or change where money goes always do; an everyday tool does when its arguments make it worse than usual.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function confirmation_needed( string $name, array $args ): bool {
		return ! empty( self::definitions()[ $name ]['confirm'] ) || self::risky( $name, $args );
	}

	/**
	 * Does this call make things worse than the tool usually does, so that it needs Full access and a confirmation?
	 * A firewall turned off, or one that opens a wide range of ports to anyone, is the same exposure; so is a database
	 * opened to everyone, and so is moving a domain to name servers that are not ours.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 */
	private static function risky( string $name, array $args ): bool {
		if ( 'set_database_access' === $name ) {
			return 'world_on' === ( $args['op'] ?? null );
		}

		if ( 'set_firewall' === $name ) {
			return false === ( $args['on'] ?? null ) || self::wide_open( $args['rules'] ?? null );
		}

		return 'domain_settings' === $name && 'set_nameservers' === ( $args['action'] ?? null ) && 'custom' === ( $args['mode'] ?? null );
	}

	/**
	 * Does a rule list open more than 100 ports in one rule (all of tcp or udp included) to anyone?
	 *
	 * @param mixed $rules Rules as sent.
	 */
	private static function wide_open( $rules ): bool {
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( ! is_array( $rule ) || ! in_array( $rule['proto'] ?? '', array( 'tcp', 'udp' ), true ) || ! empty( $rule['sources'] ) ) {
				continue;
			}

			$from = is_int( $rule['from'] ?? null ) ? $rule['from'] : 0;
			$to   = is_int( $rule['to'] ?? null ) ? $rule['to'] : $from;

			if ( $to - $from + 1 > 100 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The tools a level can see, as tools/list answers them.
	 *
	 * @param string $scope read, actions or full.
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing( string $scope ): array {
		$rank = Tokens::rank( Tokens::level( $scope ) );
		$out  = array();

		foreach ( self::definitions() as $name => $tool ) {
			if ( Tokens::rank( $tool['level'] ) > $rank ) {
				continue;
			}

			$out[] = array(
				'name'        => $name,
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
				'annotations' => array(
					'readOnlyHint'    => Tokens::READ === $tool['level'],
					'destructiveHint' => $tool['destructive'],
					'idempotentHint'  => $tool['idempotent'],
					'openWorldHint'   => false,
				),
			);
		}

		return $out;
	}

	/**
	 * Runs a tool.
	 *
	 * @param string               $name  Tool.
	 * @param array<string, mixed> $args  Arguments.
	 * @param array<string, mixed> $token Token of the request.
	 * @return array<string, mixed> MCP tool result.
	 */
	public static function call( string $name, array $args, array $token ): array {
		$tool  = self::definitions()[ $name ];
		$level = Tokens::level( (string) $token['scope'] );
		$need  = self::required_level( $name, $args );

		if ( Tokens::rank( $level ) < Tokens::rank( $need ) ) {
			return self::failure(
				Tokens::FULL === $need
					? __( 'This needs Full access. The customer reconnects and chooses Full access.', 'beltoft-services' )
					: __( 'This connection can only read. Reconnect and allow actions to change something.', 'beltoft-services' )
			);
		}

		$problem = self::validate( $tool['schema'], $args );

		if ( '' !== $problem ) {
			return self::failure( $problem );
		}

		if ( $tool['limit'] > 0 && RateLimit::exceeded( get_current_user_id(), 'beltoft-services:mcp-' . $name, $tool['limit'], MINUTE_IN_SECONDS ) ) {
			return self::failure( __( 'Too many requests. Please wait a minute.', 'beltoft-services' ) );
		}

		// The confirm value is not an argument of the tool: it is the answer to the summary, and it is left out of what runs.
		$given = (string) ( $args[ Definitions::CONFIRM_ARG ] ?? '' );
		unset( $args[ Definitions::CONFIRM_ARG ] );

		if ( self::confirmation_needed( $name, $args ) ) {
			$step = self::confirm( $name, $tool, $args, $given );

			if ( null !== $step ) {
				return $step;
			}
		}

		self::$running = $name;

		try {
			$done = Endpoint::nested( fn() => self::run( $name, $args ) );
		} finally {
			self::$running = '';
		}

		return $done instanceof \WP_Error ? self::failure( $done->get_error_message() ) : self::text( $done );
	}

	/**
	 * The two steps of a call that needs the customer's confirmation. Without a confirm value the call only describes
	 * itself: a summary and a token, nothing runs. With one, the token is checked and burned, and the call runs.
	 *
	 * @param string               $name  Tool.
	 * @param array<string, mixed> $tool  Its definition.
	 * @param array<string, mixed> $args  Arguments without the confirm value, as validated.
	 * @param string               $given The confirm value that came with the call, or ''.
	 * @return array<string, mixed>|null The result to answer with, or null when the tool may run.
	 */
	private static function confirm( string $name, array $tool, array $args, string $given ): ?array {
		$secret  = in_array( $name, Secrets::NAMES, true );
		$prepare = $secret ? Secrets::summary( $name, $args ) : Irreversible::summary( $name, $args, get_current_user_id() );
		$words   = Irreversible::label( $name );

		// A service that is not the customer's, a state that does not allow it, a choice the dashboard would refuse: said at
		// once, and no token is made for it.
		if ( $prepare instanceof \WP_Error ) {
			return self::failure( $prepare->get_error_message() );
		}

		if ( '' === $given ) {
			$issued = Confirmations::issue( $name, $args, $prepare['state'], ! empty( $tool['secret'] ) );

			if ( $issued instanceof \WP_Error ) {
				return self::failure( $issued->get_error_message() );
			}

			Irreversible::trail( $args, 'asked to ' . $words . ' (waiting for confirmation)' );

			return self::text(
				array(
					'requires_confirmation' => true,
					'summary'               => $prepare['text'],
					Definitions::CONFIRM_ARG => $issued['token'],
					'expires_in_seconds'    => $issued['expires_in'],
					'instruction'           => 'Nothing has been done yet. Show the summary to the customer exactly as written and ask whether to go ahead. Only if the customer clearly says yes, call this tool again with the same arguments and confirm set to the value above. If they say no or do not answer, do nothing.',
				)
			);
		}

		$ok = Confirmations::consume( $given, $name, $args, $prepare['state'] );

		if ( true !== $ok ) {
			Irreversible::trail( $args, $words . ' refused (' . ( 'expired' === $ok ? 'the confirmation expired' : ( 'used' === $ok ? 'the confirmation was used already' : 'the confirmation was not valid' ) ) . ')' );

			return self::failure( Confirmations::refusal( $ok ) );
		}

		Irreversible::trail( $args, $words . ' confirmed by the customer' );

		return null;
	}

	/**
	 * The tool table.
	 *
	 * @return array<string, array{description: string, schema: array<string, mixed>, level: string, destructive: bool, idempotent: bool, limit: int}>
	 */
	public static function definitions(): array {
		return Definitions::all();
	}

	/**
	 * Does the work of one tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run( string $name, array $args ) {
		$user_id = get_current_user_id();

		if ( in_array( $name, self::READS, true ) ) {
			return Reads::run( $name, $args, $user_id );
		}

		if ( in_array( $name, Acts::NAMES, true ) ) {
			return Acts::run( $name, $args, $user_id );
		}

		if ( in_array( $name, Irreversible::NAMES, true ) ) {
			return Irreversible::run( $name, $args, $user_id );
		}

		if ( in_array( $name, Secrets::NAMES, true ) ) {
			return Secrets::run( $name, $args );
		}

		switch ( $name ) {
			case 'list_services':
				return array( 'services' => Reads::services( $user_id, (string) ( $args['type'] ?? '' ) ) );

			case 'billing_summary':
				$next = Overview::data()['next_payment'] ?? null;

				return null === $next
					? array( 'next_payment' => null, 'message' => 'There is no payment due.' )
					: array(
						'next_payment' => array(
							'date'    => (string) $next['date'],
							'amount'  => html_entity_decode( wp_strip_all_tags( (string) $next['amount'] ), ENT_QUOTES, 'UTF-8' ),
							'covers'  => (string) $next['covers'],
							'paid_by' => (string) $next['card'],
						),
					);

			case 'domain_check':
				// The search itself, not its public route: that route counts visitors by address, and every Claude user
				// comes from the same few addresses. The customer is counted instead (the limit of the tool).
				$found = Search::find( (string) $args['name'] );

				if ( is_wp_error( $found ) ) {
					return new \WP_Error( 'beltoft_services_tool', $found->get_error_message() );
				}

				return array_is_list( $found ) ? array( 'results' => $found ) : $found;
		}

		$id = (int) $args['id'];

		switch ( $name ) {
			case 'server_status':
				return self::server_status( $id );

			case 'server_usage':
				return self::server_usage( $id, (string) ( $args['range'] ?? 'hour' ) );

			case 'database_info':
				return self::database_info( $id );

			case 'list_dns_records':
				return self::route( 'GET', '/resources/' . $id . '/dns-records', array(), null, $id, 'DNS records read' );

			case 'create_snapshot':
				return self::action( $id, 'snapshot_take', isset( $args['label'] ) ? array( 'label' => (string) $args['label'] ) : array(), 'snapshot requested' );

			case 'reset_database_password':
				return self::action( $id, DbActions::RESET, array( 'confirm' => DbActions::CONFIRM ), 'new database password requested', true );

			case 'dns_upsert_record':
				$body = array( 'records' => $args['records'] );

				if ( isset( $args['ttl'] ) ) {
					$body['ttl'] = (int) $args['ttl'];
				}

				return self::route( 'PUT', self::dns_path( $id, $args ), array(), $body, $id, 'DNS record set (' . self::dns_label( $args ) . ')' );

			case 'dns_delete_record':
				return self::route( 'DELETE', self::dns_path( $id, $args ), isset( $args['value'] ) ? array( 'value' => (string) $args['value'] ) : array(), null, $id, 'DNS record removed (' . self::dns_label( $args ) . ')' );
		}

		return self::action( $id, self::POWER[ $name ], array(), $name . ' requested' );
	}

	/**
	 * One server's state.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function server_status( int $id ) {
		$status = self::route( 'GET', '/resources/' . $id, array(), null, $id, 'server status read' );

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

		if ( $r && 'proxmox' === $r->get( 'driver' ) ) {
			$out += Details::vm( $r );
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
		$series = self::route( 'GET', '/resources/' . $id . '/metrics', array( 'range' => $range ), null, $id, 'server usage read' );

		if ( $series instanceof \WP_Error ) {
			return $series;
		}

		$out = array(
			'range'     => (string) $series['range'],
			'available' => ! empty( $series['available'] ),
			'samples'   => count( (array) $series['points'] ),
			'units'     => array( 'cpu' => 'percent', 'mem' => 'bytes', 'maxmem' => 'bytes', 'netin' => 'bytes per second', 'netout' => 'bytes per second', 'diskread' => 'bytes per second', 'diskwrite' => 'bytes per second' ),
		);

		foreach ( array( 'cpu', 'mem', 'netin', 'netout', 'diskread', 'diskwrite' ) as $field ) {
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

		if ( isset( $series['disk']['used'], $series['disk']['total'] ) && $series['disk']['total'] > 0 ) {
			$out['disk_percent'] = (int) round( $series['disk']['used'] / $series['disk']['total'] * 100 );
		}

		return $out;
	}

	/**
	 * How to connect to one database: no password, no certificate.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function database_info( int $id ) {
		$status = self::route( 'GET', '/resources/' . $id, array(), null, $id, 'database details read' );

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
			'password'        => 'Not shown here. The customer reveals it, or makes a new one, in the dashboard.',
		);
	}

	/**
	 * Posts an action to the route the dashboard uses.
	 *
	 * @param int                   $id      Service id.
	 * @param string                $action  Action.
	 * @param array<string, string> $args    Action arguments.
	 * @param string                $history Text for the history.
	 * @param bool                  $secret  The action makes a secret: the reply says where to get it.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function action( int $id, string $action, array $args, string $history, bool $secret = false ) {
		$done = self::route( 'POST', '/resources/' . $id . '/actions/' . $action, array(), array( 'args' => $args ), $id, $history );

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		return array(
			'requested' => true,
			'message'   => $secret
				? 'Done. The new password is being made. It is not shown here: the customer reveals it once in the dashboard.'
				: 'Requested. It runs in the background. Use server_status to follow it.',
		);
	}

	/**
	 * Calls a REST route of the plugin and returns its data, or the error in words for the customer. The route must be
	 * one the running tool is named for in ActionMap.
	 *
	 * @param string                    $method  Method.
	 * @param string                    $path    Route below beltoft-services/v1.
	 * @param array<string, mixed>      $query   Query parameters.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int|null                  $id      Service id; the call is written to its history once it is the customer's.
	 * @param string                    $history What to write after "Claude: ".
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function route( string $method, string $path, array $query, ?array $body, ?int $id, string $history = '' ) {
		if ( ! ActionMap::permits( self::$running, $method, $path ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This is not available through Claude.', 'beltoft-services' ) );
		}

		$request = new \WP_REST_Request( $method, '/beltoft-services/v1' . $path );
		$request->set_query_params( $query );

		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Reads leave no row in the history: only changes, and refused changes, do.
		if ( null !== $id && '' !== $history && 'GET' !== strtoupper( $method ) ) {
			self::log( $id, $history, $response->get_status() < 400 );
		}

		if ( $response->get_status() >= 400 ) {
			$text = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'This did not work. Try again in a moment.', 'beltoft-services' );

			return new \WP_Error( 'beltoft_services_tool', $text );
		}

		// A reply that is text (the CA certificate) is handed on as it is.
		return is_array( $data ) ? $data : ( is_string( $data ) ? array( 'text' => $data ) : array() );
	}

	/**
	 * A service of the customer: the one the dashboard's routes would give them (the same answer for someone else's, a
	 * missing and a paused one).
	 *
	 * @param int  $id    Service id.
	 * @param bool $ready Whether it must accept actions (else: show its status).
	 * @return Resource|\WP_Error
	 */
	public static function owned( int $id, bool $ready = false ) {
		$resource = Resource::find( $id );
		$license  = $resource ? $resource->license() : array();
		$user_id  = get_current_user_id();
		$owned    = $user_id > 0 && ! empty( $license['user_id'] ) && (int) $license['user_id'] === $user_id;

		if ( ! $owned || ! ( $ready ? $resource->accepts_actions() : $resource->shows_status() ) ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ) );
		}

		return $resource;
	}

	/**
	 * Writes "Claude: ..." to the history of the service, if it is the customer's.
	 *
	 * @param int    $id   Service id.
	 * @param string $text Text.
	 * @param bool   $ok   Whether the call worked.
	 */
	public static function log( int $id, string $text, bool $ok ): void {
		$r       = Resource::find( $id );
		$license = $r ? $r->license() : array();

		if ( empty( $license['id'] ) || (int) ( $license['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}

		Events::add( (int) $license['id'], 'api', 'Claude: ' . $text . ( $ok ? '' : ' (refused)' ) );
	}

	/**
	 * The record route of a DNS tool.
	 *
	 * @param int                  $id   Service id.
	 * @param array<string, mixed> $args Arguments.
	 */
	private static function dns_path( int $id, array $args ): string {
		return '/resources/' . $id . '/dns-records/' . rawurlencode( preg_replace( '/[^A-Za-z]/', '', (string) $args['type'] ) ) . '/' . rawurlencode( (string) $args['name'] );
	}

	/**
	 * "A www" for the history.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private static function dns_label( array $args ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $args['type'] ) ) . ' ' . sanitize_text_field( (string) $args['name'] );
	}

	/**
	 * Checks arguments against a tool's schema: the keys, the types, enum, limits, nested items and the required ones.
	 * An argument that is not in the schema is refused, so nothing reaches a tool that it did not declare.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @param array<string, mixed> $args   Arguments.
	 * @return string The problem, or ''.
	 */
	private static function validate( array $schema, array $args ): string {
		$bad = self::check( $schema, $args, '' );

		if ( null === $bad ) {
			return '';
		}

		if ( str_starts_with( $bad, '!' ) ) {
			/* translators: %s: argument name */
			return sprintf( __( 'The argument %s is required.', 'beltoft-services' ), substr( $bad, 1 ) );
		}

		/* translators: %s: argument name */
		return '' === $bad ? __( 'The arguments are not valid.', 'beltoft-services' ) : sprintf( __( 'The argument %s is not valid.', 'beltoft-services' ), $bad );
	}

	/**
	 * Checks one value against a schema node.
	 *
	 * @param array<string, mixed> $schema Node.
	 * @param mixed                $value  Value.
	 * @param string               $path   Name of the value, for the message.
	 * @return string|null Null when fine, else the name of the first bad argument, with a leading ! when it is missing.
	 */
	private static function check( array $schema, $value, string $path ): ?string {
		$type = (string) ( $schema['type'] ?? '' );

		if ( 'object' === $type ) {
			if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) ) {
				return $path;
			}

			$props = (array) ( $schema['properties'] ?? array() );

			foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					return '!' . ( '' === $path ? '' : $path . '.' ) . $key;
				}
			}

			foreach ( $value as $key => $item ) {
				$name = ( '' === $path ? '' : $path . '.' ) . $key;

				if ( ! array_key_exists( $key, $props ) ) {
					return $name;
				}

				$bad = self::check( (array) $props[ $key ], $item, $name );

				if ( null !== $bad ) {
					return $bad;
				}
			}

			return null;
		}

		$ok = true;

		switch ( $type ) {
			case 'integer':
				$ok = is_int( $value ) && ( ! isset( $schema['minimum'] ) || $value >= $schema['minimum'] ) && ( ! isset( $schema['maximum'] ) || $value <= $schema['maximum'] );
				break;

			case 'number':
				$ok = ( is_int( $value ) || is_float( $value ) ) && ( ! isset( $schema['minimum'] ) || $value >= $schema['minimum'] ) && ( ! isset( $schema['maximum'] ) || $value <= $schema['maximum'] );
				break;

			case 'boolean':
				$ok = is_bool( $value );
				break;

			case 'string':
				$ok = is_string( $value ) && strlen( $value ) <= (int) ( $schema['maxLength'] ?? 500 ) && ( ! isset( $schema['pattern'] ) || 1 === preg_match( (string) $schema['pattern'], $value ) );
				break;

			case 'array':
				$ok = is_array( $value ) && array_is_list( $value ) && count( $value ) <= (int) ( $schema['maxItems'] ?? 100 );

				if ( $ok && isset( $schema['items'] ) ) {
					foreach ( $value as $i => $item ) {
						$bad = self::check( (array) $schema['items'], $item, $path . '[' . $i . ']' );

						if ( null !== $bad ) {
							return $bad;
						}
					}
				}
				break;
		}

		return $ok && ( ! isset( $schema['enum'] ) || in_array( $value, $schema['enum'], true ) ) ? null : $path;
	}

	/**
	 * A successful result.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private static function text( array $data ): array {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
			'isError' => false,
		);
	}

	/**
	 * A failed tool call: a result the model can read, not a protocol error.
	 *
	 * @param string $message Words for the customer.
	 * @return array<string, mixed>
	 */
	private static function failure( string $message ): array {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => $message ) ),
			'isError' => true,
		);
	}
}
