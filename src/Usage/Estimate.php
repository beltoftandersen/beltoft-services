<?php
/**
 * What the customer's pay-as-you-go usage costs so far: the estimate shown in My Account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Billing\TaxNote;
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

		$statement = Renewal::statement( Ledger::unbilled( $user_id, Ledger::hour( time() ), (string) $account->get_currency() ) );
		$resources = array();

		foreach ( $statement['lines'] as $line ) {
			$id = (int) $line['resource_id'];

			if ( ! isset( $resources[ $id ] ) ) {
				$resources[ $id ] = array(
					'label' => self::name( $id ),
					'total' => 0.0,
					'lines' => array(),
				);
			}

			$resources[ $id ]['total']  += (float) $line['charge'];
			$resources[ $id ]['lines'][] = array(
				'item'   => (string) $line['item'],
				'subject' => self::subject( (string) $line['item'], $resources[ $id ]['label'] ),
				'label'  => Renewal::item_label( (string) $line['item'] ),
				'hours'  => (int) $line['hours'],
				'charge' => (float) $line['charge'],
				'capped' => (bool) $line['capped'],
			);
		}

		return array(
			'total'     => (float) $statement['total'],
			'currency'  => (string) $account->get_currency(),
			'carried'   => round( (int) $account->get_meta( Minimum::META_AMOUNT ) / 100, 2 ),
			'minimum'   => Minimum::amount( (string) $account->get_currency() ),
			'next'      => $account->has_status( 'active' ) ? (int) $account->get_time( 'next_payment', 'gmt' ) : 0,
			'resources' => $resources,
		);
	}

	/**
	 * What a service is called in the estimate: its own name (server name or host name), so two services of one product
	 * differ. A database has no name of its own: its product name and its address, as its card shows them.
	 *
	 * @param int $resource_id Activation id.
	 */
	private static function name( int $resource_id ): string {
		$label    = Renewal::label( $resource_id );
		$resource = Resource::find( $resource_id );

		if ( $resource && \BeltoftServices\Proxmox\Db\DbAccess::is_db( $resource ) && '' === (string) $resource->get( 'server_name' ) ) {
			$address = (string) $resource->get( 'db_address' );

			if ( false !== filter_var( $address, FILTER_VALIDATE_IP ) ) {
				/* translators: 1: product name, 2: the database's address. */
				return sprintf( __( '%1$s, %2$s', 'beltoft-services' ), $label, $address );
			}
		}

		return $label;
	}

	/**
	 * The row's name: the service itself for a server or database, "Backups for web1" for what is added to it.
	 *
	 * @param string $item  Item.
	 * @param string $label Service name.
	 */
	private static function subject( string $item, string $label ): string {
		return in_array( $item, array( 'server', 'database' ), true ) ? $label : Renewal::line_subject( $item, $label );
	}

	/**
	 * An amount as the shop shows prices ("€3.21"), in plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	public static function money( float $amount, string $currency ): string {
		return Rates::money( $amount, 2, $currency );
	}

	/**
	 * "Usage so far: €X (estimate)" for the customer, or '' without a usage account.
	 *
	 * @param int $user_id Customer.
	 */
	public static function so_far( int $user_id ): string {
		$estimate = self::for_user( $user_id );

		if ( ! $estimate ) {
			return '';
		}

		$amount = self::money( self::shown_total( $estimate ), $estimate['currency'] );

		return self::incl() ? sprintf( /* translators: %s: amount with currency. */ __( 'Usage so far, incl. VAT: %s (estimate)', 'beltoft-services' ), $amount ) : sprintf( /* translators: %s: amount with currency. */ __( 'Usage so far: %s (estimate)', 'beltoft-services' ), $amount );
	}

	/**
	 * "Cost so far this month: €X" for a pay-as-you-go resource, '' for any other (a monthly one, or none).
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

		$cost = self::shown_resource( $estimate, $resource_id );

		return self::incl() ? sprintf( /* translators: %s: amount with currency. */ __( 'Cost so far this month, incl. VAT: %s', 'beltoft-services' ), self::money( $cost, $estimate['currency'] ) ) : sprintf( /* translators: %s: amount with currency. */ __( 'Cost so far this month: %s', 'beltoft-services' ), self::money( $cost, $estimate['currency'] ) );
	}

	/**
	 * The same line with the net amount, for machine readable answers (MCP): never taxed.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function cost_line_net( int $resource_id ): string {
		return TaxNote::in( 'excl', static fn() => self::cost_line( $resource_id ) );
	}

	/**
	 * Whether the customer of this request is shown prices including VAT (the display TaxNote gives).
	 */
	public static function incl(): bool {
		return 'incl' === TaxNote::display();
	}

	/**
	 * One estimate line as shown: the net charge, or what the invoice line comes to including VAT.
	 *
	 * @param float $charge Net charge of a line.
	 */
	public static function shown_line( float $charge ): float {
		return self::incl() && $charge > 0 ? round( TaxNote::invoice_line( $charge ), 2 ) : $charge;
	}

	/**
	 * The total as shown (see shown_line()).
	 *
	 * @param array<string, mixed> $estimate for_user().
	 */
	public static function shown_total( array $estimate ): float {
		return self::incl() ? self::gross( self::charges( $estimate, 0 ) ) : (float) $estimate['total'];
	}

	/**
	 * The total of one service as shown.
	 *
	 * @param array<string, mixed> $estimate    for_user().
	 * @param int                  $resource_id Activation id.
	 */
	public static function shown_resource( array $estimate, int $resource_id ): float {
		return self::incl() ? self::gross( self::charges( $estimate, $resource_id ) ) : (float) ( $estimate['resources'][ $resource_id ]['total'] ?? 0.0 );
	}

	/**
	 * What the invoice for this estimate comes to including VAT, in cents, whatever the display (credit pays the invoice total).
	 *
	 * @param array<string, mixed> $estimate for_user().
	 */
	public static function invoice_cents( array $estimate ): int {
		return (int) round( self::gross( self::charges( $estimate, 0 ) ) * 100 );
	}

	/**
	 * Net charges of the lines of the estimate (of one service, or all with 0).
	 *
	 * @param array<string, mixed> $estimate    for_user().
	 * @param int                  $resource_id Activation id, 0 for all.
	 * @return float[]
	 */
	private static function charges( array $estimate, int $resource_id ): array {
		$out = array();

		foreach ( (array) $estimate['resources'] as $id => $resource ) {
			if ( 0 === $resource_id || (int) $id === $resource_id ) {
				foreach ( (array) $resource['lines'] as $line ) {
					$out[] = (float) $line['charge'];
				}
			}
		}

		return $out;
	}

	/**
	 * Invoice lines taxed one by one as WooCommerce taxes them; the sum rounded once.
	 *
	 * @param float[] $charges Net charges.
	 */
	private static function gross( array $charges ): float {
		$sum = 0.0;

		foreach ( $charges as $charge ) {
			$sum += $charge > 0 ? TaxNote::invoice_line( $charge ) : 0.0;
		}

		return round( $sum, 2 );
	}
}
