<?php
/**
 * What the customer's pay-as-you-go usage costs so far: the estimate shown in My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the unbilled rows of the ledger only, with the same statement the renewal order uses (hours x rate,
 * capped), so the estimate matches the invoice. No Proxmox call. Built once per customer and request. Amounts
 * are excl. VAT. A customer without a usage account has no estimate (null).
 */
final class Estimate {

	/**
	 * The estimate by user id (false: no usage account).
	 *
	 * @var array<int, array<string, mixed>|false>
	 */
	private static $memo = array();

	/**
	 * Forgets what was built (tests; a request that changes the ledger).
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * The estimate of a customer.
	 *
	 * Keys: total (float), currency, next (unix time of the account's next payment, 0 when none), resources
	 * (resource id => label, total, lines of item, label, hours, charge, capped).
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|null
	 */
	public static function for_user( int $user_id ): ?array {
		if ( $user_id < 1 ) {
			return null;
		}

		if ( ! isset( self::$memo[ $user_id ] ) ) {
			self::$memo[ $user_id ] = self::build( $user_id ) ?? false;
		}

		return false === self::$memo[ $user_id ] ? null : self::$memo[ $user_id ];
	}

	/**
	 * Builds it.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|null
	 */
	private static function build( int $user_id ): ?array {
		$account = Account::for_user( $user_id );

		if ( ! $account ) {
			return null;
		}

		$statement = Renewal::statement( Ledger::unbilled( $user_id, Ledger::hour( time() ) ) );
		$resources = array();

		foreach ( $statement['lines'] as $line ) {
			$id = (int) $line['resource_id'];

			if ( ! isset( $resources[ $id ] ) ) {
				$resources[ $id ] = array(
					'label' => Renewal::label( $id ),
					'total' => 0.0,
					'lines' => array(),
				);
			}

			$resources[ $id ]['total']  += (float) $line['charge'];
			$resources[ $id ]['lines'][] = array(
				'item'   => (string) $line['item'],
				'label'  => Renewal::item_label( (string) $line['item'] ),
				'hours'  => (int) $line['hours'],
				'charge' => (float) $line['charge'],
				'capped' => (bool) $line['capped'],
			);
		}

		return array(
			'total'     => (float) $statement['total'],
			'currency'  => (string) $account->get_currency(),
			'next'      => $account->has_status( 'active' ) ? (int) $account->get_time( 'next_payment', 'gmt' ) : 0,
			'resources' => $resources,
		);
	}

	/**
	 * An amount with its currency code ("EUR 3.21").
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	public static function money( float $amount, string $currency ): string {
		return trim( $currency . ' ' . number_format( $amount, 2, '.', '' ) );
	}

	/**
	 * "Usage so far: EUR X (estimate)" for the customer, or '' without a usage account.
	 *
	 * @param int $user_id Customer.
	 */
	public static function so_far( int $user_id ): string {
		$estimate = self::for_user( $user_id );

		return $estimate ? sprintf( /* translators: %s: amount with currency. */ __( 'Usage so far: %s (estimate)', 'beltoft-services' ), self::money( $estimate['total'], $estimate['currency'] ) ) : '';
	}

	/**
	 * "Cost so far this month: EUR X" for a pay-as-you-go resource, '' for any other (a monthly one, or none).
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function cost_line( int $resource_id ): string {
		$resource = Resource::find( $resource_id );
		$license  = $resource ? $resource->license() : array();

		if ( ! $license || 'usage' !== ( $license['source'] ?? '' ) ) {
			return '';
		}

		$estimate = self::for_user( (int) $license['user_id'] );

		if ( ! $estimate ) {
			return '';
		}

		$cost = (float) ( $estimate['resources'][ $resource_id ]['total'] ?? 0.0 );

		return sprintf( /* translators: %s: amount with currency. */ __( 'Cost so far this month: %s', 'beltoft-services' ), self::money( $cost, $estimate['currency'] ) );
	}
}
