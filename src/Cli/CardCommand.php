<?php
/**
 * WP-CLI: the saved card of the usage accounts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Card\Card;
use BeltoftServices\Usage\Account;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services card relink [--dry-run]`.
 */
final class CardCommand {

	/**
	 * Puts every usage account on its owner's current default saved card (the Stripe token the renewal charges)
	 * and removes the old payment provider's customer and mandate from the account and its first order. An owner
	 * with no default card leaves the account with no card. Prints one line per account.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Say what would change, write nothing.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function relink( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$dry = ! empty( $assoc_args['dry-run'] );
		$n   = 0;

		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			\WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
		}

		foreach ( wcs_get_subscriptions( array( 'subscription_status' => Account::LIVE, 'subscriptions_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $account ) {
			if ( ! Account::is_account( $account ) ) {
				continue;
			}

			++$n;
			\WP_CLI::log( Card::relink( $account, $dry ) );
		}

		\WP_CLI::success( sprintf( '%d usage account(s) checked%s.', $n, $dry ? ', nothing written (dry run)' : '' ) );
	}
}
