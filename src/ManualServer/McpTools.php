<?php
/**
 * The MCP tools of a dedicated server: status, restart and power, the rescue system, reverse DNS, the firewall and the reinstall.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;
use BeltoftServices\Mcp\ToolSets;
use BeltoftServices\Mcp\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Every tool calls the route of the dashboard (ControlRoutes): the owner check, the limits and the checks of the input are the same.
 * - Read: dedicated_info (the controls the server offers, its rescue state without a password, reverse DNS, the firewall, the systems a
 *   reinstall offers).
 * - Actions, no confirmation: dedicated_restart (the software restart only), dedicated_power_on (Wake on LAN), dedicated_set_rdns,
 *   dedicated_rescue (with the customer's SSH key only: without one the rescue password is shown in the dashboard behind the emailed code).
 * - Full access, each with the customer's confirmation (the summary and the confirm value, as for every tool that cannot be undone):
 *   dedicated_hard_reset (hardware reset and the power button), dedicated_set_firewall, dedicated_reinstall. The dashboard asks for an emailed
 *   code and the typed server name for these; here the confirmation is the customer's yes, and the routes know it (filter
 *   beltoft_services_confirmed_by_tool).
 * Nothing here returns a password.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/** Read tools. */
	public const READS = array( 'dedicated_info' );

	/** Everyday actions. */
	public const ACTS = array( 'dedicated_restart', 'dedicated_power_on', 'dedicated_set_rdns', 'dedicated_rescue' );

	/** Full access tools, each with a confirmation. */
	public const FULL = array( 'dedicated_hard_reset', 'dedicated_set_firewall', 'dedicated_reinstall' );

	/**
	 * Joins the set to the MCP server, and tells the routes that the confirmation of a full tool was accepted.
	 */
	public static function register(): void {
		add_filter( ToolSets::FILTER, array( __CLASS__, 'add' ) );
		add_filter( 'beltoft_services_confirmed_by_tool', array( __CLASS__, 'confirmed' ) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'dedicated_info'   => Definitions::read( 'Shows one dedicated server: which restart and power actions it offers, whether its rescue system is on (never its password), the reverse DNS names, the incoming firewall rules, and the systems a reinstall offers (reinstall_systems, exact names for dedicated_reinstall).' . Definitions::DATA, Definitions::by_id(), 30 ),
			'dedicated_restart' => Definitions::act( 'Restarts a dedicated server with a software restart: the system shuts down normally and starts again. Ask the customer first. A hardware reset needs dedicated_hard_reset.', Definitions::by_id(), true ),
			'dedicated_power_on' => Definitions::act( 'Sends the Wake on LAN signal that switches on a dedicated server that is off. Only for servers that offer it (dedicated_info).', Definitions::by_id(), false, true ),
			'dedicated_set_rdns' => Definitions::act( 'Sets, or with an empty name removes, the reverse DNS name of one address of a dedicated server: its IPv4 address, or an address inside its IPv6 network. The name must be a host name such as server.example.com.', Definitions::obj( array( 'id' => Definitions::id(), 'ip' => array( 'type' => 'string', 'maxLength' => 45, 'description' => 'The address.' ), 'ptr' => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'The host name. Empty removes the name.' ) ), array( 'id', 'ip' ) ), false, true ),
			'dedicated_rescue' => Definitions::act( 'Switches the rescue system of a dedicated server on or off, optionally restarting the server into it with a software restart. It works only for servers where the customer gave an SSH key: the customer then logs in as root with the key. Without a key the rescue password is shown only in the dashboard, behind a code we email to the customer. Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'on' => array( 'type' => 'boolean', 'description' => 'True to switch it on, false to switch it off.' ), 'restart' => array( 'type' => 'boolean', 'description' => 'With on: restart the server now so it starts in the rescue system. Default false: it starts in it the next time the server starts.' ) ), array( 'id', 'on' ) ), true ),
			'dedicated_hard_reset' => Definitions::full(
				'Resets a dedicated server by force: a hardware reset, or the power button (press or hold). The server stops at once, like a power failure, and unsaved data is lost. Use dedicated_info for the actions the server offers. A normal restart is dedicated_restart.',
				Definitions::obj( array( 'id' => Definitions::id(), 'type' => array( 'type' => 'string', 'enum' => array( 'hw', 'power', 'power_long' ), 'description' => 'hw: hardware reset. power: press the power button. power_long: hold the power button.' ) ), array( 'id', 'type' ), true )
			),
			'dedicated_set_firewall' => Definitions::full(
				'Replaces the incoming firewall rules of a dedicated server and switches the firewall on or off. The rules it has now are removed, so read dedicated_info first and send the rules to keep. A set of rules that does not accept SSH (TCP port 22) can lock the customer out.',
				Definitions::obj(
					array(
						'id'            => Definitions::id(),
						'status'        => array( 'type' => 'string', 'enum' => array( 'active', 'disabled' ) ),
						'whitelist_hos' => array( 'type' => 'boolean', 'description' => 'Let the supplier\'s own services through. Optional: left as it is.' ),
						'filter_ipv6'   => array( 'type' => 'boolean', 'description' => 'Filter IPv6 too. Optional: left as it is.' ),
						'input'         => array(
							'type'        => 'array',
							'maxItems'    => 100,
							'description' => 'The incoming rules. Each: action (accept or discard, required), optional name, ip_version (ipv4 or ipv6), src_ip and dst_ip (IPv4 only, address or network), src_port, dst_port (a port or a range such as 8000-8100), protocol (tcp, udp, gre, icmp, ipip, ah, esp; needs ip_version).',
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'action'     => array( 'type' => 'string', 'enum' => array( 'accept', 'discard' ) ),
									'name'       => array( 'type' => 'string', 'maxLength' => 80 ),
									'ip_version' => array( 'type' => 'string', 'enum' => array( 'ipv4', 'ipv6' ) ),
									'src_ip'     => array( 'type' => 'string', 'maxLength' => 45 ),
									'dst_ip'     => array( 'type' => 'string', 'maxLength' => 45 ),
									'src_port'   => array( 'type' => 'string', 'maxLength' => 11 ),
									'dst_port'   => array( 'type' => 'string', 'maxLength' => 11 ),
									'protocol'   => array( 'type' => 'string', 'enum' => array( 'tcp', 'udp', 'gre', 'icmp', 'ipip', 'ah', 'esp' ) ),
								),
								'required'             => array( 'action' ),
							),
						),
					),
					array( 'id', 'status', 'input' ),
					true
				),
				false,
				true
			),
			'dedicated_reinstall' => Definitions::full(
				'Erases all disks of a dedicated server and installs a system again. Everything on the server is lost and the server is down for about 15 minutes. The system is one of the exact names in dedicated_info (reinstall_systems). The customer then logs in with their SSH key, or with a new root password that is shown only in the dashboard.',
				Definitions::obj( array( 'id' => Definitions::id(), 'image' => array( 'type' => 'string', 'maxLength' => 128, 'description' => 'The system, an exact name from dedicated_info (reinstall_systems).' ) ), array( 'id', 'image' ), true )
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'dedicated_info'           => array( 'GET #^/resources/\d+/dedicated$#D', 'GET #^/resources/\d+/dedicated/images$#D' ),
			'dedicated_restart'        => array( 'POST #^/resources/\d+/dedicated/reset$#D' ),
			'dedicated_power_on'       => array( 'POST #^/resources/\d+/dedicated/wol$#D' ),
			'dedicated_set_rdns'       => array( 'PUT #^/resources/\d+/dedicated/rdns$#D', 'DELETE #^/resources/\d+/dedicated/rdns$#D' ),
			'dedicated_rescue'         => array( 'POST #^/resources/\d+/dedicated/rescue$#D', 'DELETE #^/resources/\d+/dedicated/rescue$#D' ),
			'dedicated_hard_reset'     => array( 'POST #^/resources/\d+/dedicated/reset$#D' ),
			'dedicated_set_firewall'   => array( 'PUT #^/resources/\d+/dedicated/firewall$#D' ),
			'dedicated_reinstall'      => array( 'POST #^/resources/\d+/dedicated/reinstall$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'dedicated_hard_reset'     => 'dedicated_hard_reset',
			'dedicated_firewall'       => 'dedicated_set_firewall',
			'dedicated_reinstall'      => 'dedicated_reinstall',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array {
		return array(
			'dedicated_rescue_password'  => 'never: the rescue password of a dedicated server needs the code emailed to the account address, so it is only available in the dashboard',
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
		if ( in_array( $name, self::READS, true ) ) {
			return self::read( $args );
		}

		if ( in_array( $name, self::ACTS, true ) ) {
			return self::act( $name, $args );
		}

		return self::run_full( $name, $args );
	}

	/**
	 * Is the running tool one whose confirmation was accepted (the routes then do not ask for the code and the typed name)?
	 *
	 * @param mixed $confirmed Answer so far.
	 */
	public static function confirmed( $confirmed ): bool {
		return (bool) $confirmed || in_array( Tools::running(), self::FULL, true );
	}

	/**
	 * A dedicated server of the customer, or the refusal.
	 *
	 * @param int $id Service id.
	 * @return \BeltoftServices\Resource|\WP_Error
	 */
	public static function server( int $id ) {
		$r = Tools::owned( $id, true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( ManualServerDriver::ID !== $r->get( 'driver' ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'That service is not a dedicated server.', 'beltoft-services' ) );
		}

		return ServerControls::number( $r ) > 0 ? $r : new \WP_Error( 'beltoft_services_tool', __( 'These controls work once the server is delivered.', 'beltoft-services' ) );
	}

	/**
	 * Runs the read tool.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read( array $args ) {
		$id   = (int) $args['id'];
		$info = Tools::route( 'GET', '/resources/' . $id . '/dedicated', array(), null, $id, 'dedicated server read' );

		if ( $info instanceof \WP_Error ) {
			return $info;
		}

		$images = Tools::route( 'GET', '/resources/' . $id . '/dedicated/images', array(), null, $id, '' );

		return $info + array( 'reinstall_systems' => $images instanceof \WP_Error ? array() : (array) ( $images['images'] ?? array() ) );
	}

	/**
	 * Runs an action tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function act( string $name, array $args ) {
		$id   = (int) $args['id'];
		$base = '/resources/' . $id . '/dedicated';

		switch ( $name ) {
			case 'dedicated_restart':
				$done = Tools::route( 'POST', $base . '/reset', array(), array( 'type' => 'sw' ), $id, 'server restarted' );

				return $done instanceof \WP_Error ? $done : array( 'restarted' => true );

			case 'dedicated_power_on':
				$done = Tools::route( 'POST', $base . '/wol', array(), array(), $id, 'server switched on (Wake on LAN)' );

				return $done instanceof \WP_Error ? $done : array( 'signal_sent' => true );

			case 'dedicated_set_rdns':
				if ( '' === trim( (string) ( $args['ptr'] ?? '' ) ) ) {
					$done = Tools::route( 'DELETE', $base . '/rdns', array( 'ip' => (string) $args['ip'] ), null, $id, 'reverse DNS removed' );

					return $done instanceof \WP_Error ? $done : array( 'removed' => (string) $args['ip'] );
				}

				$done = Tools::route( 'PUT', $base . '/rdns', array(), array( 'ip' => (string) $args['ip'], 'ptr' => (string) $args['ptr'] ), $id, 'reverse DNS set' );

				return $done instanceof \WP_Error ? $done : array( 'ip' => (string) $args['ip'], 'name' => (string) $args['ptr'] );
		}

		// dedicated_rescue.
		if ( empty( $args['on'] ) ) {
			$done = Tools::route( 'DELETE', $base . '/rescue', array(), null, $id, 'rescue system switched off' );

			return $done instanceof \WP_Error ? $done : array( 'rescue' => 'off' );
		}

		$r = self::server( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		if ( '' === trim( (string) $r->get( 'ssh_key' ) ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This server has no SSH key of the customer, so the rescue system would need a password. That password is shown only in the dashboard, after a code we email to the customer. Ask the customer to switch the rescue system on there.', 'beltoft-services' ) );
		}

		$done = Tools::route( 'POST', $base . '/rescue', array(), array( 'boot' => ! empty( $args['restart'] ) ), $id, ! empty( $args['restart'] ) ? 'rescue system on, server restarted' : 'rescue system on' );

		return $done instanceof \WP_Error ? $done : array( 'rescue' => 'on', 'restarted' => ! empty( $done['rebooting'] ), 'login' => 'root, with the SSH key of the customer' ) + ( isset( $done['note'] ) ? array( 'note' => (string) $done['note'] ) : array() );
	}

	/**
	 * The summary of a full tool: what it does in plain words. Nothing is changed.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		$r = self::server( (int) $args['id'] );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$label = Irreversible::name( $r );

		if ( 'dedicated_hard_reset' === $name ) {
			$type = (string) $args['type'];

			return array(
				/* translators: 1: action, 2: name of the service. */
				'text'  => sprintf( __( '%1$s on %2$s: the server stops at once, like a power failure. Anything not saved to disk is lost and a file system may need a repair when it starts.', 'beltoft-services' ), ServerControls::reset_label( $type ), $label ),
				'state' => $r->id() . '|dedicated_hard_reset|' . $type,
			);
		}

		if ( 'dedicated_set_firewall' === $name ) {
			$rules = FirewallRules::clean( (array) ( $args['input'] ?? array() ) );

			if ( is_wp_error( $rules ) ) {
				return new \WP_Error( 'beltoft_services_tool', $rules->get_error_message() );
			}

			$status = (string) $args['status'];
			$risky  = ServerControls::firewall_risky( $status, $rules );

			return array(
				'text'  => sprintf(
					/* translators: 1: name of the service, 2: on or off, 3: number of rules, 4: extra warning. */
					__( 'Replace the incoming firewall rules of %1$s: the firewall is %2$s with %3$d rules, and the rules it has now are removed. %4$s', 'beltoft-services' ),
					$label,
					'active' === $status ? __( 'active', 'beltoft-services' ) : __( 'off', 'beltoft-services' ),
					count( $rules ),
					$risky ? __( 'No rule accepts SSH (TCP port 22) from anywhere: if the customer logs in over SSH, this can lock them out.', 'beltoft-services' ) : ''
				),
				'state' => $r->id() . '|dedicated_set_firewall|' . md5( (string) wp_json_encode( array( $status, $rules ) ) ),
			);
		}

		$image  = (string) $args['image'];
		$images = \BeltoftServices\ManualServer\DedicatedReinstall::images();

		if ( ! in_array( $image, $images, true ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'That system is not in the list. Use dedicated_info (reinstall_systems).', 'beltoft-services' ) );
		}

		$key = '' !== trim( (string) $r->get( 'ssh_key' ) );

		return array(
			'text'  => sprintf(
				/* translators: 1: name of the service, 2: system file name, 3: how the customer logs in. */
				__( 'Erase all disks of %1$s and install %2$s. Everything on the server is lost and cannot be brought back. It takes about 15 minutes and the server is down meanwhile. %3$s', 'beltoft-services' ),
				$label,
				$image,
				$key ? __( 'The customer logs in as root with their SSH key.', 'beltoft-services' ) : __( 'The customer logs in as root with a new password that is shown only in the dashboard, after a code we email to them.', 'beltoft-services' )
			),
			'state' => $r->id() . '|dedicated_reinstall|' . $image,
		);
	}

	/**
	 * Runs a full tool. The confirmation was accepted before this.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_full( string $name, array $args ) {
		$id   = (int) $args['id'];
		$base = '/resources/' . $id . '/dedicated';

		if ( 'dedicated_hard_reset' === $name ) {
			$done = Tools::route( 'POST', $base . '/reset', array(), array( 'type' => (string) $args['type'] ), $id, 'server reset (' . (string) $args['type'] . ')' );

			return $done instanceof \WP_Error ? $done : array( 'done' => true, 'type' => (string) $args['type'] );
		}

		if ( 'dedicated_set_firewall' === $name ) {
			$body = array( 'status' => (string) $args['status'], 'input' => (array) ( $args['input'] ?? array() ) );

			foreach ( array( 'whitelist_hos', 'filter_ipv6' ) as $flag ) {
				if ( isset( $args[ $flag ] ) ) {
					$body[ $flag ] = (bool) $args[ $flag ];
				}
			}

			$done = Tools::route( 'PUT', $base . '/firewall', array(), $body, $id, 'firewall changed' );

			return $done instanceof \WP_Error ? $done : array( 'applied' => true, 'message' => 'The supplier applies the rules within a minute: dedicated_info shows the state.' );
		}

		$done = Tools::route( 'POST', $base . '/reinstall', array(), array( 'image' => (string) $args['image'] ), $id, 'reinstall requested' );

		return $done instanceof \WP_Error ? $done : array( 'queued' => true, 'message' => 'The reinstall runs in the background and takes about 15 minutes. dedicated_info shows when it is done. The login is in the dashboard.' );
	}
}
