<?php
/**
 * The two checks every irreversible tool on a service shares: is the action offered now, and does the dashboard's own check
 * pass. The summaries and the execution are in the feature that owns the tool (its McpTools).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the McpTools of the features. Every tool does what the dashboard does and goes through the same code: the
 * action route of the service, which answers with the dashboard's own checks.
 */
final class ServerActions {

	/**
	 * Is the action not offered for the service now? The same list the dashboard draws its buttons from.
	 *
	 * @param Resource $r      Service.
	 * @param string   $action Action.
	 */
	public static function not_offered( Resource $r, string $action ): ?\WP_Error {
		$driver  = Registry::get( (string) $r->get( 'driver' ) );
		$offered = $driver ? $driver->customer_actions( $r ) : array();

		return array_key_exists( $action, $offered ) ? null : new \WP_Error( 'beltoft_services_tool', __( 'This is not available for this service now.', 'beltoft-services' ) );
	}

	/**
	 * The dashboard's own check of an action, without counting a request or storing anything: the filter
	 * beltoft_services_mcp_action_refusal answers for a feature that has one (the Proxmox actions do, with the words the
	 * dashboard shows). Other drivers answer at the route.
	 *
	 * @param Resource             $r      Service.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Action arguments, with the typed word the dashboard asks for.
	 */
	private static function refusal( Resource $r, string $action, array $args ): ?string {
		$why = apply_filters( 'beltoft_services_mcp_action_refusal', null, $r, $action, $args );

		return is_string( $why ) ? $why : null;
	}

	/**
	 * A summary when the dashboard's check passes, else its refusal.
	 *
	 * @param Resource             $r      Service.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Action arguments.
	 * @param string               $text   Summary.
	 * @param string               $state  What the summary depends on, besides the service's state.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function checked( Resource $r, string $action, array $args, string $text, string $state = '' ) {
		$why = self::refusal( $r, $action, $args );

		if ( null !== $why ) {
			return new \WP_Error( 'beltoft_services_tool', $why );
		}

		return array( 'text' => $text, 'state' => $r->id() . '|' . $action . '|' . $r->get( 'state' ) . '|' . $state );
	}
}
