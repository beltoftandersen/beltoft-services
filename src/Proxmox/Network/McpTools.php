<?php
/**
 * The MCP tools of the network of a server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Network;

use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\ServerActions;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP tools of the network of a server: the firewall (read, set, start again) and the private network. A firewall turned
 * off, or one that opens a wide range of ports to anyone, is risky: it needs Full access and the customer's confirmation.
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'get_firewall'     => Definitions::read( 'Shows the firewall of one server: whether it is on, its rules (protocol, ports, allowed addresses, label) and whether a change is being applied.' . Definitions::DATA, Definitions::by_id() ),
			'set_firewall'     => Definitions::act(
				'Replaces the firewall rules of one server and turns the firewall on. Read get_firewall first and send the whole list you want. Ask the customer first. Through Claude, SSH (port 22) must stay open to anyone, or the rules that let SSH in must stay as they are, so the customer cannot lock themselves out. Turning the firewall off, or opening a wide range of ports to anyone, needs Full access and a confirmation: the first call returns a summary and a confirm value, show the summary to the customer and send the value only after their clear yes.',
				Definitions::obj(
					array(
						'id'    => Definitions::id(),
						'on'    => array( 'type' => 'boolean', 'description' => 'true to apply the rules.' ),
						'rules' => array(
							'type'        => 'array',
							'description' => 'The rules after the change. Each has proto (tcp, udp or icmp), from and to (ports; to is optional), sources (IPv4 addresses or ranges; empty means anyone) and an optional label.',
							'maxItems'    => 100,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'proto'   => array( 'type' => 'string', 'enum' => array( 'tcp', 'udp', 'icmp' ) ),
									'from'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 65535 ),
									'to'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 65535 ),
									'sources' => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'maxLength' => 64 ) ),
									'label'   => array( 'type' => 'string', 'maxLength' => 60 ),
								),
								'required'   => array( 'proto' ),
							),
						),
					),
					array( 'id', 'on', 'rules' ),
					true
				),
				true,
				true
			),
			'private_network'  => Definitions::act( 'Attaches a server to one of the customer\'s private networks, or detaches it. The customer can have up to five; without a network id the first one is used. The change runs in the background.', Definitions::obj( array( 'id' => Definitions::id(), 'action' => array( 'type' => 'string', 'enum' => array( 'attach', 'detach' ) ), 'network' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'For attach: the id of one of the customer\'s private networks (see the private_networks of service_details). Optional.' ) ), array( 'id', 'action' ) ), true ),
			'reset_firewall'   => Definitions::full(
				'Starts the firewall of a server again from its default rules. The rules the customer set are removed.',
				Definitions::obj( array( 'id' => Definitions::id() ), array( 'id' ), true ),
				false,
				true
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'get_firewall'       => array( 'GET #^/resources/\d+/firewall$#D' ),
			'set_firewall'       => array( 'PUT #^/resources/\d+/firewall$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function actions(): array {
		return array(
			'private_attach'     => 'private_network',
			'private_detach'     => 'private_network',
			'fw_reset'           => 'reset_firewall',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		return array(
			'fw_apply'          => 'route: the firewall has its own tool (set_firewall)',
			'ipv6_add'          => 'never: adding or removing IPv6 restarts the server and makes its SSH host keys again, so it is only done in the dashboard',
			'ipv6_remove'       => 'never: adding or removing IPv6 restarts the server and makes its SSH host keys again, so it is only done in the dashboard',
			'ipv4_guest'        => 'never: it runs a script inside the customer\'s server through the guest agent, so it is only done in the dashboard',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'firewall_off'             => 'set_firewall',
			'firewall_wide_open'       => 'set_firewall',
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
			case 'get_firewall':
				$view = Tools::route( 'GET', '/resources/' . (int) $args['id'] . '/firewall', array(), null, (int) $args['id'], 'firewall read' );

				if ( $view instanceof \WP_Error ) {
					return $view;
				}

				// The page's own address, button text and clock are not for a result.
				unset( $view['my_ip'], $view['now'] );

				return $view;

			case 'set_firewall':
				$id   = (int) $args['id'];
				$body = array( 'on' => (bool) $args['on'], 'rules' => $args['rules'] );

				// Turning it off is a call that went through the confirmation (Tools::call): the dashboard's own "are you sure" is answered.
				if ( ! $body['on'] ) {
					$body['confirm_off'] = true;
				}

				$done = Tools::route( 'PUT', '/resources/' . $id . '/firewall', array(), $body, $id, 'firewall change requested' );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Requested. It is applied in the background. Use get_firewall to follow it.' );

			case 'private_network':
				return Tools::action( (int) $args['id'], 'attach' === $args['action'] ? PrivateAttach::ATTACH : PrivateAttach::DETACH, 'attach' === $args['action'] && ! empty( $args['network'] ) ? array( 'net' => (string) (int) $args['network'] ) : array(), 'private network ' . (string) $args['action'] . ' requested' );

			case 'reset_firewall':
				return Tools::action( (int) $args['id'], 'fw_reset', array(), 'firewall reset requested' );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * The summary of a call that needs the confirmation: a firewall turned off or opened wide, and the firewall started again.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments without the confirm value, as validated.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error|null
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		if ( 'set_firewall' === $name ) {
			return self::summary_firewall( $args );
		}

		if ( 'reset_firewall' !== $name ) {
			return null;
		}

		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = ServerActions::not_offered( $r, 'fw_reset' );

		if ( null !== $bad ) {
			return $bad;
		}

		return ServerActions::checked( $r, 'fw_reset', array(), sprintf(
			/* translators: %s: server name. */
			__( 'Start the firewall of server %s again from its default rules. The rules the customer has set are removed. Anything the default rules do not allow is blocked, and the change runs in the background.', 'beltoft-services' ),
			Irreversible::name( $r )
		) );
	}

	/**
	 * Is this call worse than the tool usually is: a firewall turned off, or one that opens a wide range of ports to anyone?
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments (not yet validated).
	 */
	public static function risky( string $name, array $args ): ?bool {
		return 'set_firewall' === $name ? ( false === ( $args['on'] ?? null ) || self::wide_open( $args['rules'] ?? null ) ) : null;
	}

	/**
	 * Does a rule list open more than 100 ports, all rules together, to anyone?
	 *
	 * @param mixed $rules Rules as sent.
	 */
	private static function wide_open( $rules ): bool {
		return self::open_ports( $rules )['total'] > 100;
	}

	/**
	 * The ports a rule list opens to anyone, with the rules put together: a port opened by several rules counts once, and
	 * many small rules count as much as one big one. "Anyone" is no source at all, or a source broader than /8 (IPv4) or
	 * /32 (IPv6), in any of the rule's sources.
	 *
	 * @param mixed $rules Rules as sent.
	 * @return array{total: int, ranges: string[]} The number of ports and the merged ranges, such as "tcp 1-65535".
	 */
	public static function open_ports( $rules ): array {
		$by_proto = array();

		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( ! is_array( $rule ) || ! in_array( $rule['proto'] ?? '', array( 'tcp', 'udp' ), true ) || ! self::open_to_anyone( $rule['sources'] ?? array() ) ) {
				continue;
			}

			$from = is_int( $rule['from'] ?? null ) ? $rule['from'] : 0;
			$to   = is_int( $rule['to'] ?? null ) ? $rule['to'] : $from;

			if ( $from > 0 && $to >= $from ) {
				$by_proto[ $rule['proto'] ][] = array( $from, $to );
			}
		}

		$total  = 0;
		$ranges = array();

		foreach ( $by_proto as $proto => $list ) {
			usort( $list, static fn( $a, $b ) => $a[0] <=> $b[0] );
			$merged = array();

			foreach ( $list as $range ) {
				$last = count( $merged ) - 1;

				if ( $last >= 0 && $range[0] <= $merged[ $last ][1] + 1 ) {
					$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
				} else {
					$merged[] = $range;
				}
			}

			foreach ( $merged as $range ) {
				$total   += $range[1] - $range[0] + 1;
				$ranges[] = $proto . ' ' . $range[0] . '-' . $range[1];
			}
		}

		return array( 'total' => $total, 'ranges' => $ranges );
	}

	/**
	 * Is a rule's list of sources "anyone": empty, or with an address range broader than /8 (IPv4) or /32 (IPv6)?
	 *
	 * @param mixed $sources Sources as sent.
	 */
	private static function open_to_anyone( $sources ): bool {
		if ( ! is_array( $sources ) || ! $sources ) {
			return true;
		}

		foreach ( $sources as $source ) {
			$parts  = explode( '/', trim( (string) $source ), 2 );
			$v6     = str_contains( $parts[0], ':' );
			$prefix = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : ( $v6 ? 128 : 32 );

			if ( $prefix < ( $v6 ? 32 : 8 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The firewall turned off, or rules that open many ports to anyone.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_firewall( array $args ) {
		$r = Tools::owned( (int) $args['id'], true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$bad = ServerActions::not_offered( $r, 'fw_apply' );

		if ( null !== $bad ) {
			return $bad;
		}

		$label = Irreversible::name( $r );

		if ( false === $args['on'] ) {
			return array(
				'text'  => sprintf(
					/* translators: %s: server name. */
					__( 'Turn the firewall of server %s off. Every port of the server can then be reached from the whole internet, whatever the rules say. The rules are kept and can be turned on again.', 'beltoft-services' ),
					$label
				),
				'state' => $r->id() . '|fw_off|' . (string) wp_json_encode( $r->get( Firewall::ON ) ),
			);
		}

		$wide = self::open_ports( $args['rules'] )['ranges'];

		return array(
			'text'  => sprintf(
				/* translators: 1: server name, 2: the wide rules, for example tcp 1-65535. */
				__( 'Change the firewall of server %1$s so that these rules let anyone on the internet reach many ports: %2$s. Anything listening on those ports, including software the customer forgot, can then be reached from everywhere. All the rules in the call replace the rules now in force.', 'beltoft-services' ),
				$label,
				implode( ', ', $wide )
			),
			'state' => $r->id() . '|fw_wide|' . (string) wp_json_encode( $wide ),
		);
	}
}
