<?php
/**
 * WP-CLI: one test order at the supplier web service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\ManualServer\DedicatedClients;
use BeltoftServices\ManualServer\ManualServerSettings;
use BeltoftServices\ManualServer\LiveRobotClient;

defined( 'ABSPATH' ) || exit;

/**
 * Sends ONE order with the documented parameter test=true ("The order will not be processed if set to true"). It orders
 * nothing. It needs the live setting, the login and BELTOFT_SERVICES_DEDICATED_LIVE; it never runs without them and never
 * sends a real order. The password sent is random and is not kept.
 */
final class DedicatedCommand {

	/**
	 * Sends one order with test=true and prints the answer. Nothing is ordered.
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : The supplier product id, for example AX42-1.
	 *
	 * [--location=<location>]
	 * : The location, for example FSN1.
	 *
	 * [--dist=<dist>]
	 * : A distribution name as the product lists it (optional).
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services dedicated test-order AX42-1 --location=FSN1
	 *
	 * @subcommand test-order
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function test_order( array $args, array $assoc_args ): void {
		if ( ! DedicatedClients::live_allowed() || 'live' !== DedicatedClients::kind() ) {
			\WP_CLI::error( 'Not live: set the connection to Live, save the login, and set BELTOFT_SERVICES_DEDICATED_LIVE to true in wp-config.php. Nothing was sent.' );
		}

		try {
			$client = new LiveRobotClient( ManualServerSettings::api_url(), ManualServerSettings::secret( 'dedicated_api_user' ), ManualServerSettings::secret( 'dedicated_api_password' ) );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( 'The address or login is not usable. Nothing was sent.' );
		}

		$fields = array(
			'product_id' => (string) ( $args[0] ?? '' ),
			'location'   => (string) ( $assoc_args['location'] ?? '' ),
			'dist'       => (string) ( $assoc_args['dist'] ?? '' ),
			'password'   => wp_generate_password( 24, false, false ),
			'addon'      => array( 'primary_ipv4' ),
			'test'       => true,
		);

		\WP_CLI::log( 'Sending POST /order/server/transaction with test=true: product_id, location, dist, password (random, not kept), addon[]=primary_ipv4.' );

		$answer = $client->order( $fields );

		if ( is_wp_error( $answer ) ) {
			\WP_CLI::error( 'The supplier answered: ' . $answer->get_error_code() . ' (' . (int) ( $answer->get_error_data()['status'] ?? 0 ) . ') ' . $answer->get_error_message() );
		}

		\WP_CLI::success( 'The supplier accepted the test order (nothing was ordered). Answer: ' . wp_json_encode( $answer ) );
	}
}
