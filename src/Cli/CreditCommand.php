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
 * `wp beltoft-services credit add <user> <amount> --reason=<text>`, `credit balance <user>` and `credit move-to-cards [--apply]`.
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
	 * : Amount in the customer's credit currency, without VAT, such as 10 or -2.50.
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
	 * Moves every customer's account credit to gift cards of the gift card plugin (owner 2026-11-13): the free part becomes a free card
	 * (source promotion), the paid part a paid card (source paid_offline), both in the customer's credit currency and never expiring.
	 * The ledger gets one closing debit per part (reference type gift_card_move, the user id), so a second run moves nothing again.
	 * Without --apply it only lists what it would do.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Move the balances.
	 *
	 * @subcommand move-to-cards
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function move_to_cards( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		global $wpdb;

		if ( ! class_exists( '\\Bgca\\Wallet' ) || ! class_exists( '\\Bgcw\\GiftCard\\Source' ) ) {
			\WP_CLI::error( 'The gift card plugin and its account add-on must be active.' );
		}

		$apply = ! empty( $assoc_args['apply'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one read.
		$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i', Balance::table() ) ) );
		$moved = 0;

		foreach ( $users as $user_id ) {
			$balance  = Balance::get( $user_id );
			$promo    = min( max( 0, Balance::promo_left( $user_id ) ), max( 0, $balance ) );
			$paid     = max( 0, $balance - $promo );
			$currency = Balance::account_currency( $user_id );

			if ( $balance < 1 ) {
				continue;
			}

			\WP_CLI::log( sprintf( 'user %d: %s free, %s paid (%s)', $user_id, Topup::label_amount( $promo, $currency ), Topup::label_amount( $paid, $currency ), $currency ) );

			if ( ! $apply ) {
				continue;
			}

			foreach ( array( array( $promo, Balance::PROMO_USE, \Bgcw\GiftCard\Source::PROMOTION ), array( $paid, Balance::ADJUST, \Bgcw\GiftCard\Source::PAID_OFFLINE ) ) as list( $cents, $kind, $source ) ) {
				if ( $cents < 1 ) {
					continue;
				}

				// The closing debit first: its unique reference means a second run stops here before a second card is made.
				if ( ! Balance::debit( $user_id, $cents, $kind, 'gift_card_move', (string) $user_id, 'Moved to a gift card', $currency ) ) {
					\WP_CLI::warning( sprintf( 'user %d: the %s part was moved before (or the debit failed); no card made.', $user_id, $source ) );
					continue;
				}

				$card = \Bgca\Wallet::create( $user_id, $cents, $source, $currency, __( 'Moved from account credit', 'beltoft-services' ) );

				if ( $card < 1 ) {
					Balance::credit( $user_id, $cents, Balance::ADJUST, 'gift_card_move_back', (string) $user_id . '-' . $source, 'Gift card could not be made', $currency );
					\WP_CLI::warning( sprintf( 'user %d: the card could not be made; the credit was put back.', $user_id ) );
					continue;
				}

				\WP_CLI::log( sprintf( '  card %d (%s)', $card, $source ) );
				++$moved;
			}
		}

		\WP_CLI::success( $apply ? sprintf( '%d cards made.', $moved ) : 'Dry run. Run with --apply to move.' );
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
