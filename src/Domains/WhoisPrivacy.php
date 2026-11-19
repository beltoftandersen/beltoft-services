<?php
/** WHOIS privacy of a domain: free, on by default where the registrar allows it. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The registrar fields (Openprovider API spec):
 *
 * - tldTld.is_private_whois_allowed: the extension offers the service (stored with the price table, TldFacts). Used at checkout.
 * - domainCreateDomainRequest and domainTransferDomainRequest `is_private_whois_enabled`: "Enables or disables whois privacy protection
 *   (WPP) on domain", sent when the order is placed, only for an extension that allows it.
 * - domainGetDomainResponseData `is_private_whois_allowed` ("Indicates, if whois privacy protection allowed") and `is_private_whois_enabled`:
 *   what the registered domain says; kept on the service (meta whois_privacy_allowed, whois_privacy_enabled) and the dashboard goes by them.
 * - domainUpdateDomainRequest `is_private_whois_enabled`: the dashboard switch.
 *
 * Cost: the owner's Openprovider price sheet (/root/beltoft-site-scripts/prices/openprovider/domain-addons.csv, file dated 2026-11-19, owner
 * decision of that day) lists "Whois Privacy Protection service (WPP), All TLDs, create / renew / transfer, 1 year": non-members 2.99, members 0.
 * As a member the service costs us nothing, so it is free for the customer. The sheet is the owner's copy of the price list and can go
 * stale: if the membership or the list changes this must be looked at again (an open question in the architecture notes).
 * It costs the customer nothing and no price is shown. It hides data, so the switch needs no emailed code.
 */
final class WhoisPrivacy {
	public const ACTION = 'whois_privacy';

	/** Cart line and order meta key: did the customer want it. Absent means yes. */
	public const LINE_KEY = 'whois_privacy';

	/**
	 * May an order for this domain carry the option (tldTld.is_private_whois_allowed)?
	 *
	 * @param string $domain Full domain name.
	 */
	public static function order_allowed( string $domain ): bool {
		return TldFacts::whois_allowed( $domain );
	}

	/**
	 * What the order asks for. Default on; only an explicit no turns it off.
	 *
	 * @param Resource $r Domain service (the line's choice is in its meta).
	 */
	public static function wanted( Resource $r ): bool {
		return false !== $r->get( self::LINE_KEY );
	}

	/**
	 * Does the registered domain allow privacy (the registrar's own answer, domainGetDomainResponseData.is_private_whois_allowed)?
	 *
	 * @param Resource $r Domain service.
	 */
	public static function allowed( Resource $r ): bool {
		return true === $r->get( 'whois_privacy_allowed' );
	}

	/**
	 * Is it on at the registrar now (last answer)?
	 *
	 * @param Resource $r Domain service.
	 */
	public static function enabled( Resource $r ): bool {
		return true === $r->get( 'whois_privacy_enabled' );
	}

	/**
	 * Meta from a registrar domain answer.
	 *
	 * @param array<string, mixed> $detail GET /domains/{id} data.
	 * @return array<string, bool>
	 */
	public static function detail_meta( array $detail ): array {
		if ( ! array_key_exists( 'is_private_whois_allowed', $detail ) ) { return array(); }
		return array(
			'whois_privacy_allowed' => true === $detail['is_private_whois_allowed'],
			'whois_privacy_enabled' => true === ( $detail['is_private_whois_enabled'] ?? false ),
		);
	}

	/**
	 * The action the domain page offers: only for a domain the registrar says allows it.
	 *
	 * @param Resource $r Domain service.
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		if ( 'domain' !== $r->get( 'driver' ) || ! self::allowed( $r ) ) { return array(); }
		return array( self::ACTION => __( 'Change WHOIS privacy', 'beltoft-services' ) );
	}

	/**
	 * @param Resource             $r    Domain service.
	 * @param array<string, mixed> $args Arguments: enabled is 1 or 0.
	 */
	public static function validate( Resource $r, array $args ): bool|\WP_Error {
		if ( ! self::allowed( $r ) ) { return new \WP_Error( 'whois_unavailable', __( 'WHOIS privacy is not available for this domain.', 'beltoft-services' ) ); }
		if ( ! in_array( (string) ( $args['enabled'] ?? '' ), array( '0', '1' ), true ) ) { return new \WP_Error( 'bad_whois', __( 'Choose on or off.', 'beltoft-services' ) ); }
		return true;
	}

	/**
	 * The job: one update call, then one read to store what the registrar holds.
	 *
	 * @param Resource             $r    Domain service.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function run( Resource $r, array $args ): StepResult {
		$valid = self::validate( $r, $args );
		if ( is_wp_error( $valid ) ) { return StepResult::fail( $valid->get_error_message() ); }
		$id = rawurlencode( (string) $r->get( 'remote_id' ) );
		if ( '' === $id ) { return StepResult::fail( 'Registrar id is unavailable.' ); }
		$wanted = '1' === (string) $args['enabled'];
		$reply  = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'is_private_whois_enabled' => $wanted ) );
		if ( is_wp_error( $reply ) ) { return StepResult::retry( 60, 'WHOIS privacy could not be changed.' ); }
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( ! is_wp_error( $detail ) && is_array( $detail ) && array_key_exists( 'is_private_whois_enabled', $detail ) && $wanted !== (bool) $detail['is_private_whois_enabled'] ) {
			return StepResult::fail( 'The registrar did not apply the change.', self::detail_meta( $detail ) );
		}
		return StepResult::done( array( 'whois_privacy_enabled' => $wanted, self::LINE_KEY => $wanted ) );
	}
}
