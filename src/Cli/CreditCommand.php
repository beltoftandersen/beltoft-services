<?php
/**
 * WP-CLI: account credit of a customer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Grant;
use BeltoftServices\Credit\Topup;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services credit add <user> <amount> --reason=<text>` and `credit balance <user>`.
 */
final class CreditCommand {

	/**
	 * Adds credit without a payment (a negative amount is a correction).
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login or email.
	 *
	 * <amount>
	 * : Amount in the store currency, without VAT, such as 10 or -2.50.
	 *
	 * --reason=<text>
	 * : Why (required). The customer sees it.
	 *
	 * [--allow-negative]
	 * : Let a correction take the balance below 0.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, mixed>  $assoc_args Options.
	 */
	public function add( array $args, array $assoc_args ): void {
		$user  = self::user( $args[0] );
		$cents = Grant::parse( $args[1] );

		if ( null === $cents ) {
			\WP_CLI::error( 'The amount must be a number with at most two decimals, not 0.' );
		}

		$result = Grant::add( (int) $user->ID, $cents, (string) ( $assoc_args['reason'] ?? '' ), 0, wp_generate_uuid4(), ! empty( $assoc_args['allow-negative'] ) );

		if ( true !== $result ) {
			\WP_CLI::error( (string) $result );
		}

		\WP_CLI::success( sprintf( 'Credit saved. Balance: %s.', Topup::label_amount( Balance::get( (int) $user->ID ), Balance::currency_or_user( (int) $user->ID ) ) ) );
	}

	/**
	 * Shows a balance and the latest rows.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login or email.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function balance( array $args ): void {
		$user = self::user( $args[0] );

		\WP_CLI::line( 'Balance: ' . Topup::label_amount( Balance::get( (int) $user->ID ), Balance::currency_or_user( (int) $user->ID ) ) );
		\WP_CLI\Utils\format_items( 'table', Balance::history( (int) $user->ID, 10 ), array( 'created', 'kind', 'amount_cents', 'balance_after_cents', 'note' ) );
	}

	/**
	 * The user of an id, login or email.
	 *
	 * @param string $who Id, login or email.
	 */
	private static function user( string $who ): \WP_User {
		$user = is_numeric( $who ) ? get_userdata( (int) $who ) : ( get_user_by( 'login', $who ) ?: get_user_by( 'email', $who ) );

		if ( ! $user instanceof \WP_User ) {
			\WP_CLI::error( 'No such user.' );
		}

		return $user;
	}
}
