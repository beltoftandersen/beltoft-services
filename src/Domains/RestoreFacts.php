<?php
/** What the registrar tells about a domain's expiry, quarantine and restore, and each extension's restore price and window. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Read only. Openprovider says (spec 1.0.0-beta, GET /domains/{id}) that renewal_date is the one date to process renewals by and that
 * expiration_date "is not the primary reference". The quarantine and restore fields are stored exactly as the registrar gives them: their
 * meaning is not documented (plan 2026-11-19-unpaid-domains.md, Q-OP1), so nothing here interprets them. The restore window of an
 * extension is counted from the renewal date with the extension's soft quarantine and quarantine days (GET /tlds), never from those fields.
 */
final class RestoreFacts {

	/** Days a .dk domain stays suspended before the registry deletes it (Punktum dk terms, clauses 1.6, 5.5 and 5.7; plan section 2.3). */
	public const DK_SUSPENSION_DAYS = 30;

	/** Registrar field => resource meta key. Stored as given, only when the answer has the field. */
	private const FIELDS = array(
		'renewal_date'                => 'registrar_renewal_date',
		'expiration_date'             => 'registrar_expiration_date',
		'registry_expiration_date'    => 'registry_expires_at',
		'soft_quarantine_expiry_date' => 'soft_quarantine_until',
		'hard_quarantine_expiry_date' => 'hard_quarantine_until',
		'quarantine_expiration_date'  => 'quarantine_until',
		'restorable_until'            => 'restorable_until',
		'delete_status'               => 'registrar_delete_status',
		'deleted_at'                  => 'registrar_deleted_at',
		'autorenew'                   => 'registrar_autorenew',
	);

	/**
	 * The date a domain must be renewed by: the registrar's renewal_date, else its expiration_date (an answer that has no renewal_date).
	 *
	 * @param array<string, mixed> $reply GET /domains/{id}.
	 */
	public static function expiry_of( array $reply ): string {
		$renewal = trim( (string) ( $reply['renewal_date'] ?? '' ) );

		return '' !== $renewal ? $renewal : trim( (string) ( $reply['expiration_date'] ?? '' ) );
	}

	/**
	 * The meta to store from a domain answer.
	 *
	 * @param array<string, mixed> $reply GET /domains/{id}.
	 * @return array<string, mixed>
	 */
	public static function registrar_meta( array $reply ): array {
		$meta = array();

		foreach ( self::FIELDS as $field => $key ) {
			if ( array_key_exists( $field, $reply ) && is_scalar( $reply[ $field ] ) ) {
				$meta[ $key ] = (string) $reply[ $field ];
			}
		}

		if ( array_key_exists( 'is_deleted', $reply ) ) {
			$meta['registrar_deleted'] = (bool) $reply['is_deleted'];
		}

		return $meta;
	}

	/**
	 * What a price-table row keeps of a registrar extension entry (GET /tlds with prices): the reseller restore prices in EUR and the
	 * quarantine periods. A field the answer lacks is left out, so "not stated" is never stored as 0.
	 *
	 * @param array<string, mixed> $entry One extension.
	 * @return array<string, int|float>
	 */
	public static function from_tld_entry( array $entry ): array {
		$extra  = array();
		$prices = is_array( $entry['prices'] ?? null ) ? $entry['prices'] : array();

		foreach ( array( 'restore' => 'restore_price', 'soft_restore' => 'soft_restore_price' ) as $key => $field ) {
			$price = $prices[ $field ]['reseller'] ?? null;

			if ( is_array( $price ) && is_numeric( $price['price'] ?? null ) && 'EUR' === strtoupper( (string) ( $price['currency'] ?? '' ) ) && (float) $price['price'] >= 0 ) {
				$extra[ $key ] = (float) $price['price'];
			}
		}

		foreach ( array( 'soft_quarantine_days' => 'soft_quarantine_period', 'quarantine_days' => 'quarantine_period' ) as $key => $field ) {
			if ( isset( $entry[ $field ] ) && is_numeric( $entry[ $field ] ) && (int) $entry[ $field ] >= 0 ) {
				$extra[ $key ] = (int) $entry[ $field ];
			}
		}

		return $extra;
	}

	/**
	 * The price-table row of the extension a full domain name ends with ('' key when it has none).
	 *
	 * @param string $domain Full domain name.
	 * @return array{0: string, 1: array<string, mixed>} Extension and its row.
	 */
	public static function row( string $domain ): array {
		$table = get_option( Prices::OPTION, array() );

		if ( ! is_array( $table ) ) {
			return array( '', array() );
		}

		$domain = strtolower( $domain );
		$tlds   = array_map( 'strval', array_keys( $table ) );
		usort( $tlds, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		foreach ( $tlds as $tld ) {
			if ( str_ends_with( $domain, '.' . $tld ) ) {
				return array( $tld, is_array( $table[ $tld ] ) ? $table[ $tld ] : array() );
			}
		}

		return array( '', array() );
	}

	/**
	 * Days after the renewal date during which an unpaid domain can still be restored: .dk is the registry's 30 days of suspension, any other
	 * extension its soft quarantine plus quarantine days from the price table. Null when neither is known.
	 *
	 * @param string $domain Full domain name.
	 */
	public static function window_days( string $domain ): ?int {
		list( $tld, $row ) = self::row( $domain );

		if ( 'dk' === $tld ) {
			return self::DK_SUSPENSION_DAYS;
		}

		$days = (int) ( $row['soft_quarantine_days'] ?? 0 ) + (int) ( $row['quarantine_days'] ?? 0 );

		return $days > 0 ? $days : null;
	}
}
