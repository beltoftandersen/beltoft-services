<?php
/**
 * The tools of a dedicated server: status, restart and power, the rescue system, reverse DNS, the firewall and the reinstall.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\ManualServer\FirewallRules;
use BeltoftServices\ManualServer\ManualServerDriver;
use BeltoftServices\ManualServer\ServerControls;

defined( 'ABSPATH' ) || exit;

/**
 * Every tool calls the route of the dashboard (ManualServer\ControlRoutes): the owner check, the limits and the checks of the input are the same.
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
final class DedicatedTools {

	/** Read tools. */
	public const READS = array( 'dedicated_info' );

	/** Everyday actions. */
	public const ACTS = array( 'dedicated_restart', 'dedicated_power_on', 'dedicated_set_rdns', 'dedicated_rescue' );

	/** Full access tools, each with a confirmation. */
	public const FULL = array( 'dedicated_hard_reset', 'dedicated_set_firewall', 'dedicated_reinstall' );

	/** Hooks. */
	public static function register(): void {
		add_filter( 'beltoft_services_confirmed_by_tool', array( __CLASS__, 'confirmed' ) );
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
	public static function summary( string $name, array $args ) {
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
	public static function run( string $name, array $args ) {
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
