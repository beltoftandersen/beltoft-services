<?php
/**
 * The billing tools of Full access: cancelling a subscription, removing an add-on, choosing the default card.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Account\Cards;
use BeltoftServices\Billing\PaymentMethods;
use BeltoftServices\Billing\Subscriptions;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Thin: the rules and the words are in BeltoftServices\Billing, which the dashboard uses too. This class adds the
 * customer's trail ("Claude: ...") and the shape of the tool answers.
 */
final class BillingActions {

	/**
	 * The tools this class runs.
	 */
	public const NAMES = array( 'cancel_subscription', 'remove_addon', 'set_default_payment_method' );

	/**
	 * The summary of one of the tools.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'cancel_subscription':
				return self::summary_cancel( $user_id, (int) $args['subscription_id'] );

			case 'remove_addon':
				return self::summary_addon( $user_id, (int) $args['id'] );

			default:
				return self::summary_card( $user_id, (int) $args['token_id'] );
		}
	}

	/**
	 * Runs one of the tools after the confirmation.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'cancel_subscription':
				return self::cancel( $user_id, (int) $args['subscription_id'], '' );

			case 'remove_addon':
				$r = self::addon( $user_id, (int) $args['id'] );

				return $r instanceof \WP_Error ? $r : self::cancel( $user_id, (int) ( $r->license()['subscription_id'] ?? 0 ), 'add-on removed' );

			default:
				return self::set_card( $user_id, (int) $args['token_id'] );
		}
	}

	/**
	 * The text of a cancellation, with the customer's names for the services.
	 *
	 * @param \WC_Subscription $s        Subscription.
	 * @param Resource[]       $services What it pays for.
	 * @return array{text: string, state: string}
	 */
	private static function cancel_text( \WC_Subscription $s, array $services ): array {
		return Subscriptions::cancel_text( $s, $services, array_map( array( Irreversible::class, 'name' ), $services ) );
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_cancel( int $user_id, int $id ) {
		$found = Subscriptions::cancellable( $user_id, $id );

		return $found instanceof \WP_Error ? $found : self::cancel_text( $found[0], $found[1] );
	}

	/**
	 * An add-on of the customer: a disk, snapshot slots or an app licence.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Service id.
	 * @return Resource|\WP_Error
	 */
	private static function addon( int $user_id, int $id ) {
		$r = Tools::owned( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$why = Subscriptions::addon_blocker( $r );

		if ( '' !== $why ) {
			return new \WP_Error( 'beltoft_services_tool', $why );
		}

		return $user_id > 0 ? $r : new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ) );
	}

	/**
	 * Remove an add-on.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Service id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_addon( int $user_id, int $id ) {
		$r = self::addon( $user_id, $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$found = Subscriptions::cancellable( $user_id, (int) ( $r->license()['subscription_id'] ?? 0 ) );

		return $found instanceof \WP_Error ? $found : self::cancel_text( $found[0], $found[1] );
	}

	/**
	 * Cancels a subscription after the confirmation, as the dashboard's Cancel button does.
	 *
	 * @param int    $user_id Customer.
	 * @param int    $id      Subscription id.
	 * @param string $what    Words for the trail, or '' for "subscription cancelled".
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function cancel( int $user_id, int $id, string $what ) {
		$found = Subscriptions::cancellable( $user_id, $id );

		if ( $found instanceof \WP_Error ) {
			return $found;
		}

		$done = Subscriptions::cancel( $found[0], array( Notices::class, 'capture' ) );

		if ( ! $done['ok'] ) {
			return new \WP_Error( 'beltoft_services_tool', implode( ' ', $done['errors'] ) ?: __( 'The subscription could not be cancelled.', 'beltoft-services' ) );
		}

		Acts::log_subscription( $done['subscription'], '' !== $what ? $what : 'subscription cancelled' );

		return array(
			'subscription_id' => $id,
			'status'          => (string) $done['subscription']->get_status(),
			'ends'            => $done['ends'],
			'message'         => $done['pending'] ? 'Cancelled. It keeps working until the end of the period that is paid.' : 'Cancelled. It ended now.',
		);
	}

	/**
	 * The summary of a new default card.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	private static function summary_card( int $user_id, int $token_id ) {
		$token = PaymentMethods::usable( $user_id, $token_id );

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		$old = \WC_Payment_Tokens::get_customer_default_token( $user_id );

		return array(
			'text'  => sprintf(
				/* translators: 1: the new card, for example Visa ending 4242, 2: the card that is the default now. */
				__( 'Make %1$s the default payment method. Renewals that use the default payment method, and payments made without choosing a method, are charged to it from now on. The default now is: %2$s. Subscriptions that have a payment method of their own keep it.', 'beltoft-services' ),
				PaymentMethods::label( $token ),
				$old instanceof \WC_Payment_Token ? PaymentMethods::label( $old ) : __( 'none', 'beltoft-services' )
			),
			'state' => $token_id . '|' . ( $old instanceof \WC_Payment_Token ? $old->get_id() : 0 ),
		);
	}

	/**
	 * Sets the default card.
	 *
	 * @param int $user_id  Customer.
	 * @param int $token_id Payment method id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function set_card( int $user_id, int $token_id ) {
		$token = PaymentMethods::usable( $user_id, $token_id );

		if ( $token instanceof \WP_Error ) {
			return $token;
		}

		PaymentMethods::make_default( $user_id, $token_id );

		Audit::add( $user_id, 'default payment method set to ' . PaymentMethods::label( $token ) );

		return array( 'token_id' => $token_id, 'default' => true );
	}
}
