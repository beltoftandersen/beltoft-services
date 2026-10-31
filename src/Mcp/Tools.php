<?php
/**
 * The tools of the MCP endpoint.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Servers;
use BeltoftServices\Api\Services;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Domains\Search;
use BeltoftServices\Proxmox\DbActions;
use BeltoftServices\Proxmox\DbCard;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\LiveUsage;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Each tool is a thin wrapper. The ones that exist as a REST route call that route inside the same request
 * (rest_do_request), so the owner checks, the state checks, the per-route limits and the DNS safe-write rules are the
 * code the dashboard uses, not a copy. The rest read stored data through the same classes the account pages use.
 *
 * Never in a result: a password, a CA certificate or key, a transfer code, a console ticket, a link that logs in. There
 * is no tool for restore, rollback, reinstall, delete, transfer codes, console or any reveal.
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
	 * Is there a tool of this name?
	 *
	 * @param string $name Name.
	 */
	public static function exists( string $name ): bool {
		return isset( self::definitions()[ $name ] );
	}

	/**
	 * The tools a scope can see, as tools/list answers them.
	 *
	 * @param string $scope read or actions.
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing( string $scope ): array {
		$out = array();

		foreach ( self::definitions() as $name => $tool ) {
			if ( $tool['write'] && Tokens::ACTIONS !== $scope ) {
				continue;
			}

			$out[] = array(
				'name'        => $name,
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
				'annotations' => array(
					'readOnlyHint'    => ! $tool['write'],
					'destructiveHint' => false,
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
		$tool = self::definitions()[ $name ];

		if ( $tool['write'] && Tokens::ACTIONS !== (string) $token['scope'] ) {
			return self::failure( __( 'This connection can only read. Reconnect and allow actions to change something.', 'beltoft-services' ) );
		}

		$problem = self::validate( $tool['schema'], $args );

		if ( '' !== $problem ) {
			return self::failure( $problem );
		}

		$done = Endpoint::nested( fn() => self::run( $name, $args ) );

		return $done instanceof \WP_Error ? self::failure( $done->get_error_message() ) : self::text( $done );
	}

	/**
	 * The tool table.
	 *
	 * @return array<string, array{description: string, schema: array<string, mixed>, write: bool}>
	 */
	public static function definitions(): array {
		$id      = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'The id of the service, from list_services.',
		);
		$by_id   = array(
			'type'                 => 'object',
			'properties'           => array( 'id' => $id ),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
		$dns_key = array(
			'id'   => $id,
			'type' => array(
				'type'        => 'string',
				'description' => 'The record type, for example A, AAAA, CNAME, MX or TXT.',
			),
			'name' => array(
				'type'        => 'string',
				'description' => 'The record name inside the domain: a subdomain such as www, or @ for the domain itself.',
			),
		);

		return array(
			'list_services'  => array(
				'description' => 'Lists the services of the customer: servers, databases, domains and apps, each with its id, name, state and renewal date.',
				'schema'      => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
				'write'       => false,
			),
			'server_status'  => array(
				'description' => 'Shows one server: whether it is running, CPU, memory and disk use as percent, its IP address, system and size, and what it is doing now.',
				'schema'      => $by_id,
				'write'       => false,
			),
			'server_usage'   => array(
				'description' => 'Shows the load of one server over the last hour, day or month: average, highest and latest CPU, memory and network figures.',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => $id,
						'range' => array( 'type' => 'string', 'enum' => array( 'hour', 'day', 'month' ), 'description' => 'The period. Default hour.' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'write'       => false,
			),
			'database_info'  => array(
				'description' => 'Shows how to connect to one managed database: engine, host, port, database name, user name and the TLS fingerprint. The password is never shown here.',
				'schema'      => $by_id,
				'write'       => false,
			),
			'list_dns_records' => array(
				'description' => 'Lists the DNS records of one domain or DNS service that we host.',
				'schema'      => $by_id,
				'write'       => false,
			),
			'domain_check'   => array(
				'description' => 'Checks whether a domain name can be registered and what it costs.',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array( 'name' => array( 'type' => 'string', 'description' => 'A full domain name, for example example.com.' ) ),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'write'       => false,
			),
			'billing_summary' => array(
				'description' => 'Shows the next payment of the customer: the date, the amount, what it covers and how it is paid.',
				'schema'      => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
				'write'       => false,
			),
			'reboot'         => array(
				'description' => 'Restarts a server. Running programs stop. Ask the customer first. The restart runs in the background.',
				'schema'      => $by_id,
				'write'       => true,
			),
			'start'          => array(
				'description' => 'Starts a server that is stopped. The start runs in the background.',
				'schema'      => $by_id,
				'write'       => true,
			),
			'shutdown'       => array(
				'description' => 'Shuts a server down. It stays stopped until it is started. Ask the customer first. The shutdown runs in the background.',
				'schema'      => $by_id,
				'write'       => true,
			),
			'create_snapshot' => array(
				'description' => 'Takes a snapshot of a server, if the customer has a free snapshot slot. It cannot restore or delete one.',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => $id,
						'label' => array( 'type' => 'string', 'description' => 'A name for the snapshot. Letters, numbers, spaces, dots, dashes and underscores. Optional.' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'write'       => true,
			),
			'dns_upsert_record' => array(
				'description' => 'Creates or replaces the records of one name and type in the domain, for example every A record of www. Other records stay as they are. Ask the customer first and read list_dns_records before.',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => $dns_key + array(
						'records' => array(
							'type'        => 'array',
							'description' => 'The records of this name and type after the change. Each has a value; MX also priority; SRV also priority, weight and port; CAA also flag and tag.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'value'    => array( 'type' => 'string' ),
									'priority' => array( 'type' => 'integer' ),
									'weight'   => array( 'type' => 'integer' ),
									'port'     => array( 'type' => 'integer' ),
									'flag'     => array( 'type' => 'integer' ),
									'tag'      => array( 'type' => 'string' ),
								),
								'required'   => array( 'value' ),
							),
						),
						'ttl'     => array( 'type' => 'integer', 'description' => 'Seconds. Optional.' ),
					),
					'required'             => array( 'id', 'type', 'name', 'records' ),
					'additionalProperties' => false,
				),
				'write'       => true,
			),
			'dns_delete_record' => array(
				'description' => 'Removes the records of one name and type, or only the record with the given value. Ask the customer first.',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => $dns_key + array( 'value' => array( 'type' => 'string', 'description' => 'Remove only the record with this value. Optional.' ) ),
					'required'             => array( 'id', 'type', 'name' ),
					'additionalProperties' => false,
				),
				'write'       => true,
			),
			'reset_database_password' => array(
				'description' => 'Makes a new password for a managed database. The old one stops working. The new one is never shown here: the customer reveals it once in the dashboard. Ask the customer first.',
				'schema'      => $by_id,
				'write'       => true,
			),
		);
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

		switch ( $name ) {
			case 'list_services':
				return array( 'services' => Services::for_user( $user_id ) );

			case 'billing_summary':
				$next = Overview::data()['next_payment'] ?? null;

				return null === $next
					? array( 'next_payment' => null, 'message' => 'There is no payment due.' )
					: array(
						'next_payment' => array(
							'date'   => (string) $next['date'],
							'amount' => html_entity_decode( wp_strip_all_tags( (string) $next['amount'] ), ENT_QUOTES, 'UTF-8' ),
							'covers' => (string) $next['covers'],
							'paid_by' => (string) $next['card'],
						),
					);

			case 'domain_check':
				// The search itself, not its public route: that route counts visitors by address, and every Claude user
				// comes from the same few addresses. The customer is counted instead.
				if ( RateLimit::exceeded( $user_id, 'beltoft-services:mcp-domain-check', 20, MINUTE_IN_SECONDS ) ) {
					return new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many searches. Please wait a minute.', 'beltoft-services' ) );
				}

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
				$done = self::action( $id, 'snapshot_take', isset( $args['label'] ) ? array( 'label' => (string) $args['label'] ) : array(), 'snapshot requested' );

				return $done;

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

		$r    = Resource::find( $id );
		$out  = array(
			'id'      => $id,
			'state'   => (string) $status['state'],
			'status'  => (string) $status['label'],
			'message' => (string) $status['message'],
			'working' => (bool) $status['working'],
		);

		if ( $r && 'proxmox' === $r->get( 'driver' ) ) {
			$plan   = (array) $r->get( 'plan' );
			$cached = Operations::cached_status( $id );
			$guest  = LiveUsage::cached_memory( $id );
			$disk   = GuestDisk::cached( $r );
			$memory = (int) ( $plan['memory_mb'] ?? 0 ) * 1048576;

			$out['power']      = null === $cached ? '' : (string) $cached['power'];
			$out['cpu_percent'] = null !== $cached && 'running' === $cached['power'] ? max( 0, min( 100, (int) round( (float) $cached['cpu'] * 100 ) ) ) : null;
			$out['memory_percent'] = null !== $guest ? $guest : ( null !== $cached && 'running' === $cached['power'] && $memory > 0 ? max( 0, min( 100, (int) round( (int) $cached['mem'] / $memory * 100 ) ) ) : null );
			$out['disk_percent']   = null !== $disk ? max( 0, min( 100, (int) round( $disk['used'] / $disk['total'] * 100 ) ) ) : null;
			$out['ip']         = false !== filter_var( (string) $r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? (string) $r->get( 'ip' ) : '';
			$out['system']     = Servers::os_label( $r );
			$out['size']       = sprintf( '%d vCPU, %s GB memory, %d GB disk', (int) ( $plan['cores'] ?? 0 ), (string) round( (int) ( $plan['memory_mb'] ?? 0 ) / 1024, 1 ), (int) ( $plan['disk_gb'] ?? 0 ) );
			$out['app']        = '' !== (string) $r->get( 'app_id' ) ? (string) $r->get( 'app_id' ) : '';
			$out['app_state']  = (string) $status['app_state'];
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
	private static function action( int $id, string $action, array $args, string $history, bool $secret = false ) {
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
	 * Calls a REST route of the plugin and returns its data, or the error in words for the customer.
	 *
	 * @param string                    $method  Method.
	 * @param string                    $path    Route below beltoft-services/v1.
	 * @param array<string, mixed>      $query   Query parameters.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int|null                  $id      Service id; the call is written to its history once it is the customer's.
	 * @param string                    $history What to write after "Claude: ".
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function route( string $method, string $path, array $query, ?array $body, ?int $id, string $history = '' ) {
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1' . $path );
		$request->set_query_params( $query );

		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		if ( null !== $id && '' !== $history ) {
			self::log( $id, $history, $response->get_status() < 400 );
		}

		if ( $response->get_status() >= 400 ) {
			$text = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'This did not work. Try again in a moment.', 'beltoft-services' );

			return new \WP_Error( 'beltoft_services_tool', $text );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Writes "Claude: ..." to the history of the service, if it is the customer's.
	 *
	 * @param int    $id   Service id.
	 * @param string $text Text.
	 * @param bool   $ok   Whether the call worked.
	 */
	private static function log( int $id, string $text, bool $ok ): void {
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
	 * Checks arguments against a tool's schema: the keys, their types, the enum and the required ones.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @param array<string, mixed> $args   Arguments.
	 * @return string The problem, or ''.
	 */
	private static function validate( array $schema, array $args ): string {
		$props = (array) $schema['properties'];

		foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				/* translators: %s: argument name */
				return sprintf( __( 'The argument %s is required.', 'beltoft-services' ), $key );
			}
		}

		foreach ( $args as $key => $value ) {
			if ( ! array_key_exists( $key, $props ) ) {
				/* translators: %s: argument name */
				return sprintf( __( 'The argument %s is not known.', 'beltoft-services' ), (string) $key );
			}

			$def  = (array) $props[ $key ];
			$type = (string) ( $def['type'] ?? '' );
			$ok   = 'integer' === $type ? is_int( $value ) : ( 'string' === $type ? is_string( $value ) : ( 'array' === $type ? is_array( $value ) && array_is_list( $value ) : true ) );

			if ( ! $ok || ( isset( $def['enum'] ) && ! in_array( $value, $def['enum'], true ) ) || ( 'integer' === $type && isset( $def['minimum'] ) && $value < $def['minimum'] ) || ( 'string' === $type && strlen( (string) $value ) > 500 ) ) {
				/* translators: %s: argument name */
				return sprintf( __( 'The argument %s is not valid.', 'beltoft-services' ), (string) $key );
			}

			if ( 'array' === $type && count( $value ) > 100 ) {
				/* translators: %s: argument name */
				return sprintf( __( 'The argument %s is not valid.', 'beltoft-services' ), (string) $key );
			}
		}

		return '';
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
