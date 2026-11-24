<?php
/**
 * Answers the Domains parking command about the edge.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services parking push` asks two filters of Domains; this class answers them from ParkedEdge. Without Hosting
 * nothing answers and the command says so.
 */
final class ParkingBridge {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_parked_edge_hosts', array( __CLASS__, 'hosts' ), 10, 0 );
		add_filter( 'beltoft_services_parked_edge_push', array( __CLASS__, 'push' ), 10, 0 );
	}

	/**
	 * The names the edge would serve as parked.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		return ParkedEdge::hosts();
	}

	/**
	 * Sends the names now.
	 *
	 * @return array<string, string> Node id => result.
	 */
	public static function push(): array {
		return ParkedEdge::run();
	}
}
