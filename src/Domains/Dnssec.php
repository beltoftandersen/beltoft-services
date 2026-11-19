<?php
/** The customer's DNSSEC switch and external key data. @package BeltoftServices */
namespace BeltoftServices\Domains;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Registrar fields (Openprovider API spec):
 *
 * - domainUpdateDomainRequest `is_dnssec_enabled` ("Enables or disables DNSSEC on domain") and `dnssec_keys` ("Array containing DNSSEC key
 *   information", items domainDnssecKey: alg, flags, protocol, pub_key, readonly).
 * - domainGetDomainResponseData `dnssec` (signedDelegation or unsigned), `dnssec_keys`, `is_dnssec_enabled`: what the registry holds.
 * - tldTld `dnssec_allowed`, `dnssec_keys_allowed`, `dnssec_digests_allowed`, `dnssec_algorithms`, `dnssec_max_records_amount` (TldFacts).
 *
 * On our name servers the order of work is the whole point. On: the zone is signed first, the keys go to the registry once every name
 * server serves them (Zone::sign). Off: the registry drops the keys first and is read back; the zone stays signed until the parent no
 * longer publishes the DS and the resolvers' cached copy has had time to go, then it is unsigned. There is never a time when the registry
 * holds keys the zone does not sign. The state is the service meta `dnssec_keys` (the keys of our signed zone, as before).
 *
 * On other name servers the customer enters the key data their DNS provider gives (a DNSKEY: flags, protocol, algorithm, public key); no
 * zone of ours is involved. The actions change registry data, so each needs the emailed code (purpose domain_dnssec).
 */
final class Dnssec {
	public const PURPOSE = 'domain_dnssec';

	public const ENABLE     = 'dnssec_enable';
	public const DISABLE    = 'dnssec_disable';
	public const KEY_ADD    = 'dnssec_key_add';
	public const KEY_REMOVE = 'dnssec_key_remove';

	/** Every action here, for the step-up filter and the dashboard buttons. */
	public const ACTIONS = array( self::ENABLE, self::DISABLE, self::KEY_ADD, self::KEY_REMOVE );

	/**
	 * Flags of a DNSKEY (RFC 4034 section 2.1: zone key 256, zone key with the secure entry point bit 257) and its protocol (RFC 4034 section
	 * 2.1.2: 3). The registrar's spec lists the fields but not their values: see the open questions in the architecture notes.
	 */
	public const FLAGS    = array( 256, 257 );
	public const PROTOCOL = 3;

	/** Seconds the zone stays signed after the parent zone shows no DS: the time a resolver may still hold the old DS. */
	public const GRACE = DAY_IN_SECONDS;

	public static function register(): void {
		add_filter( 'beltoft_services_stepup_purposes', array( __CLASS__, 'purposes' ) );
		add_filter( 'beltoft_services_stepup_text', array( __CLASS__, 'text' ), 10, 2 );
		add_filter( 'beltoft_services_action_step_up', array( __CLASS__, 'purpose_of' ), 10, 3 );
	}

	/**
	 * @param mixed $purposes Purposes so far.
	 * @return array<int, string>
	 */
	public static function purposes( $purposes ): array {
		return array_merge( (array) $purposes, array( self::PURPOSE ) );
	}

	/**
	 * What the code email says this purpose is for.
	 *
	 * @param mixed $text    Text so far.
	 * @param mixed $purpose Purpose.
	 */
	public static function text( $text, $purpose ): string {
		return self::PURPOSE === $purpose ? __( 'changing DNSSEC for a domain', 'beltoft-services' ) : (string) $text;
	}

	/**
	 * Listener of beltoft_services_action_step_up.
	 *
	 * @param mixed $purpose  Purpose so far.
	 * @param mixed $resource Resource.
	 * @param mixed $action   Action name.
	 * @return mixed
	 */
	public static function purpose_of( $purpose, $resource, $action ) {
		return is_string( $action ) && in_array( $action, self::ACTIONS, true ) ? self::PURPOSE : $purpose;
	}

	/**
	 * Is the domain's DNS ours (our name servers, our zone)?
	 *
	 * @param Resource $r Domain service.
	 */
	public static function ours( Resource $r ): bool {
		return ! $r->get( 'dns_external' ) && 'custom' !== $r->get( 'ns_mode' );
	}

	/**
	 * The keys the registry holds, as stored from its last answer.
	 *
	 * @param Resource $r Domain service.
	 * @return array<int, array<string, mixed>>
	 */
	public static function registry_keys( Resource $r ): array {
		return array_values( array_filter( (array) $r->get( 'registry_ds_keys' ), 'is_array' ) );
	}

	/**
	 * Meta from a registrar domain answer.
	 *
	 * @param array<string, mixed> $detail GET /domains/{id} data.
	 * @return array<string, mixed>
	 */
	public static function detail_meta( array $detail ): array {
		if ( ! array_key_exists( 'dnssec', $detail ) && ! array_key_exists( 'dnssec_keys', $detail ) ) { return array(); }
		return array(
			'registry_dnssec'   => 'signedDelegation' === ( $detail['dnssec'] ?? '' ) ? 'signedDelegation' : 'unsigned',
			'registry_ds_keys'  => array_values( array_filter( (array) ( $detail['dnssec_keys'] ?? array() ), 'is_array' ) ),
		);
	}

	/**
	 * A key's identity for a remove button: the same key always gives the same id.
	 *
	 * @param array<string, mixed> $key domainDnssecKey.
	 */
	public static function key_id( array $key ): string {
		return substr( sha1( (int) ( $key['alg'] ?? 0 ) . '|' . (int) ( $key['flags'] ?? 0 ) . '|' . (int) ( $key['protocol'] ?? 0 ) . '|' . preg_replace( '/\s+/', '', (string) ( $key['pub_key'] ?? '' ) ) ), 0, 16 );
	}

	/**
	 * The actions offered now, label by name. Only a ready domain of an extension that allows DNSSEC.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		if ( 'domain' !== $r->get( 'driver' ) || 'ready' !== $r->get( 'state' ) ) { return array(); }
		$domain = (string) $r->get( 'domain' );
		if ( ! TldFacts::dnssec_allowed( $domain ) ) { return array(); }
		if ( self::ours( $r ) ) {
			return empty( $r->get( 'dnssec_keys' ) )
				? array( self::ENABLE => __( 'Turn on DNSSEC', 'beltoft-services' ) )
				: array( self::DISABLE => __( 'Turn off DNSSEC', 'beltoft-services' ) );
		}
		if ( null === TldFacts::external_keys( $domain ) ) { return array(); }
		$out = array( self::KEY_ADD => __( 'Add DNSSEC key', 'beltoft-services' ) );
		if ( array() !== self::registry_keys( $r ) ) { $out[ self::KEY_REMOVE ] = __( 'Remove DNSSEC key', 'beltoft-services' ); }
		return $out;
	}

	/**
	 * The key a customer typed, checked against what the extension allows. The fields are domainDnssecKey (alg, flags, protocol, pub_key).
	 *
	 * @param string               $domain Full domain name.
	 * @param array<string, mixed> $args   flags, protocol, alg, pub_key as typed.
	 * @return array{alg: int, flags: int, protocol: int, pub_key: string}|\WP_Error
	 */
	public static function key_from_args( string $domain, array $args ): array|\WP_Error {
		$allowed = TldFacts::external_keys( $domain );
		if ( null === $allowed ) { return new \WP_Error( 'dnssec_unavailable', __( 'DNSSEC keys cannot be entered for this domain.', 'beltoft-services' ) ); }
		foreach ( array( 'flags', 'protocol', 'alg' ) as $field ) {
			if ( ! isset( $args[ $field ] ) || 1 !== preg_match( '/^\d{1,5}$/D', trim( (string) $args[ $field ] ) ) ) { return new \WP_Error( 'bad_dnssec_key', __( 'Enter the flags, protocol and algorithm as whole numbers.', 'beltoft-services' ) ); }
		}
		$flags    = (int) trim( (string) $args['flags'] );
		$protocol = (int) trim( (string) $args['protocol'] );
		$alg      = (int) trim( (string) $args['alg'] );
		if ( ! in_array( $flags, self::FLAGS, true ) ) { return new \WP_Error( 'bad_dnssec_flags', __( 'The flags must be 256 or 257.', 'beltoft-services' ) ); }
		if ( self::PROTOCOL !== $protocol ) { return new \WP_Error( 'bad_dnssec_protocol', __( 'The protocol must be 3.', 'beltoft-services' ) ); }
		if ( ! in_array( $alg, $allowed['algs'], true ) ) {
			/* translators: %s: the algorithm numbers this extension takes, such as 8, 13 */
			return new \WP_Error( 'bad_dnssec_alg', sprintf( __( 'This extension takes the algorithms %s.', 'beltoft-services' ), implode( ', ', $allowed['algs'] ) ) );
		}
		$key = preg_replace( '/\s+/', '', (string) ( $args['pub_key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 1024 || 1 !== preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/D', $key ) ) { return new \WP_Error( 'bad_dnssec_pub_key', __( 'Enter the public key as your DNS provider shows it (letters, digits, plus and slash).', 'beltoft-services' ) ); }
		return array( 'alg' => $alg, 'flags' => $flags, 'protocol' => $protocol, 'pub_key' => $key );
	}

	/**
	 * The refusal of a start: shape and fit, nothing sent.
	 *
	 * @param Resource             $r      Domain service.
	 * @param string               $action One of ACTIONS.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function validate( Resource $r, string $action, array $args ): bool|\WP_Error {
		if ( ! isset( self::offered( $r )[ $action ] ) ) { return new \WP_Error( 'unavailable', __( 'This action is unavailable.', 'beltoft-services' ) ); }
		if ( self::KEY_ADD === $action ) {
			$key = self::key_from_args( (string) $r->get( 'domain' ), $args );
			if ( is_wp_error( $key ) ) { return $key; }
			$allowed = TldFacts::external_keys( (string) $r->get( 'domain' ) );
			$have    = self::registry_keys( $r );
			foreach ( $have as $one ) { if ( self::key_id( $one ) === self::key_id( $key ) ) { return new \WP_Error( 'dnssec_key_exists', __( 'This key is already set.', 'beltoft-services' ) ); } }
			if ( null !== $allowed && count( $have ) >= $allowed['max'] ) {
				/* translators: %d: the number of keys the extension allows */
				return new \WP_Error( 'dnssec_too_many', sprintf( _n( 'This extension allows %d key. Remove one first.', 'This extension allows %d keys. Remove one first.', $allowed['max'], 'beltoft-services' ), $allowed['max'] ) );
			}
		}
		if ( self::KEY_REMOVE === $action ) {
			$found = self::find( self::registry_keys( $r ), (string) ( $args['key_id'] ?? '' ) );
			if ( null === $found ) { return new \WP_Error( 'dnssec_no_key', __( 'This key is not set.', 'beltoft-services' ) ); }
			if ( ! empty( $found['readonly'] ) ) { return new \WP_Error( 'dnssec_readonly', __( 'This key is managed by the registry and cannot be removed here.', 'beltoft-services' ) ); }
		}
		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $keys Keys.
	 * @param string                           $id   key_id().
	 * @return array<string, mixed>|null
	 */
	private static function find( array $keys, string $id ): ?array {
		foreach ( $keys as $key ) { if ( '' !== $id && self::key_id( $key ) === $id ) { return $key; } }
		return null;
	}

	/**
	 * The job.
	 *
	 * @param Resource             $r      Domain service.
	 * @param string               $action One of ACTIONS.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function run( Resource $r, string $action, array $args ): StepResult {
		// A resumed disable (the job was lost while it waited) is offered as the same action again; the checks hold for a fresh start.
		$valid = self::validate( $r, $action, $args );
		if ( is_wp_error( $valid ) ) { return StepResult::fail( $valid->get_error_message() ); }
		$id = rawurlencode( (string) $r->get( 'remote_id' ) );
		if ( '' === $id ) { return StepResult::fail( 'Registrar id is unavailable.' ); }
		if ( self::ENABLE === $action ) { return self::enable( $r ); }
		if ( self::DISABLE === $action ) { return self::disable( $r, $id ); }
		return self::change_keys( $r, $id, $action, $args );
	}

	/** On: sign the zone, then publish the keys (Zone::sign keeps that order). */
	private static function enable( Resource $r ): StepResult {
		$zone = Zone::sign( $r );
		if ( ! $zone->is_done() ) { return $zone; }
		$keys = (array) ( $zone->meta()['dnssec_keys'] ?? array() );
		return StepResult::done( $zone->meta() + array( 'dnssec_status' => '', 'registry_dnssec' => array() !== $keys ? 'signedDelegation' : 'unsigned', 'registry_ds_keys' => array_values( $keys ) ) );
	}

	/**
	 * Off: the registry first, read back; then the parent's DS and the resolvers' copy; only then the zone stops being signed.
	 */
	private static function disable( Resource $r, string $id ): StepResult {
		$name = (string) $r->get( 'domain' );
		$at   = (int) $r->get( 'dnssec_off_at' );
		if ( $at <= 0 ) {
			$reply = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'dnssec_keys' => array(), 'is_dnssec_enabled' => false ) );
			if ( is_wp_error( $reply ) ) { return StepResult::retry( 60, 'DNSSEC could not be removed at the registry.' ); }
			// Read back: the zone is not touched while the registry may still hold the keys.
			$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
			if ( is_wp_error( $detail ) ) { return StepResult::retry( 60, 'The registry could not be read.' ); }
			if ( self::registry_has_keys( (array) $detail ) ) { return StepResult::retry( 60, 'The registry still holds the DNSSEC keys.' ); }
			$at = time();
			if ( ! $r->set( array( 'dnssec_off_at' => $at, 'dnssec_status' => 'disabling', 'registry_dnssec' => 'unsigned', 'registry_ds_keys' => array() ) ) ) { return StepResult::retry( 60, 'DNSSEC status could not be saved.' ); }
		}
		$gone = (int) $r->get( 'dnssec_ds_gone_at' );
		if ( $gone <= 0 ) {
			$found = Delegation::lookup( $name );
			// The parent may be slow to publish the removal: as with a move to custom name servers, waiting stops after a day.
			if ( false !== ( $found['ds'] ?? null ) && time() < $at + DAY_IN_SECONDS + HOUR_IN_SECONDS ) {
				return StepResult::wait( 600, 'Waiting for the DNSSEC record to be removed at the registry.', $at + DAY_IN_SECONDS + HOUR_IN_SECONDS );
			}
			$gone = time();
			if ( ! $r->set( array( 'dnssec_ds_gone_at' => $gone ) ) ) { return StepResult::retry( 60, 'DNSSEC status could not be saved.' ); }
		}
		/**
		 * Seconds the zone stays signed after the parent zone no longer shows the DS.
		 *
		 * @param int      $seconds Default one day.
		 * @param Resource $r       Domain service.
		 */
		$until = $gone + max( 0, (int) apply_filters( 'beltoft_services_dnssec_off_grace', self::GRACE, $r ) );
		if ( time() < $until ) { return StepResult::wait( 900, 'Waiting for resolvers to forget the old DNSSEC record.', $until + HOUR_IN_SECONDS ); }
		$unsigned = Records::locked( $name, static fn() => Clients::dns()->unsecure( $name ) );
		if ( is_wp_error( $unsigned ) && 'zone_busy' === $unsigned->get_error_code() ) { return StepResult::retry( 30, 'The DNS zone is being changed, trying again shortly.' ); }
		if ( is_wp_error( $unsigned ) ) { return StepResult::retry( 300, 'DNSSEC signing could not be turned off.' ); }
		return StepResult::done( array( 'dnssec_keys' => array(), 'dnssec_off_at' => null, 'dnssec_ds_gone_at' => null, 'dnssec_status' => '' ) );
	}

	/**
	 * Does a registrar domain answer still show DNSSEC (dnssec signedDelegation, a key, or is_dnssec_enabled)?
	 *
	 * @param array<string, mixed> $detail GET /domains/{id} data.
	 */
	private static function registry_has_keys( array $detail ): bool {
		return 'signedDelegation' === ( $detail['dnssec'] ?? '' ) || array() !== (array) ( $detail['dnssec_keys'] ?? array() ) || true === ( $detail['is_dnssec_enabled'] ?? false );
	}

	/** External name servers: the full key list goes to the registry, built from what it holds now. */
	private static function change_keys( Resource $r, string $id, string $action, array $args ): StepResult {
		$domain = (string) $r->get( 'domain' );
		$detail = Clients::registrar()->request( 'GET', '/domains/' . $id );
		if ( is_wp_error( $detail ) ) { return StepResult::retry( 60, 'The registry could not be read.' ); }
		$current = array_values( array_filter( (array) ( $detail['dnssec_keys'] ?? array() ), 'is_array' ) );
		if ( self::KEY_ADD === $action ) {
			$key = self::key_from_args( $domain, $args );
			if ( is_wp_error( $key ) ) { return StepResult::fail( $key->get_error_message() ); }
			$allowed = TldFacts::external_keys( $domain );
			if ( null !== self::find( $current, self::key_id( $key ) ) ) { return StepResult::done( self::detail_meta( (array) $detail ) ); }
			if ( null === $allowed || count( $current ) >= $allowed['max'] ) { return StepResult::fail( __( 'The extension takes no more keys. Remove one first.', 'beltoft-services' ) ); }
			$list = array_merge( $current, array( $key ) );
		} else {
			$found = self::find( $current, (string) ( $args['key_id'] ?? '' ) );
			if ( null === $found ) { return StepResult::done( self::detail_meta( (array) $detail ) ); }
			if ( ! empty( $found['readonly'] ) ) { return StepResult::fail( __( 'This key is managed by the registry and cannot be removed here.', 'beltoft-services' ) ); }
			$list = array_values( array_filter( $current, static fn( $one ) => self::key_id( $one ) !== self::key_id( $found ) ) );
		}
		$reply = Clients::registrar()->request( 'PUT', '/domains/' . $id, array( 'dnssec_keys' => $list, 'is_dnssec_enabled' => array() !== $list ) );
		if ( is_wp_error( $reply ) ) { return StepResult::retry( 60, 'The DNSSEC keys could not be changed at the registry.' ); }
		return StepResult::done( array( 'registry_dnssec' => array() !== $list ? 'signedDelegation' : 'unsigned', 'registry_ds_keys' => $list ) );
	}

	/**
	 * What the domain page shows, or null when DNSSEC is not offered for this domain (the extension does not allow it).
	 *
	 * @param Resource $r Domain service.
	 * @return array<string, mixed>|null
	 */
	public static function view( Resource $r ): ?array {
		if ( 'domain' !== $r->get( 'driver' ) || ! TldFacts::dnssec_allowed( (string) $r->get( 'domain' ) ) ) { return null; }
		$ours    = self::ours( $r );
		$offered = self::offered( $r );
		$external = $ours ? null : TldFacts::external_keys( (string) $r->get( 'domain' ) );
		$keys    = array();
		foreach ( self::registry_keys( $r ) as $key ) {
			$keys[] = array( 'id' => self::key_id( $key ), 'alg' => (int) ( $key['alg'] ?? 0 ), 'flags' => (int) ( $key['flags'] ?? 0 ), 'protocol' => (int) ( $key['protocol'] ?? 0 ), 'pub_key' => (string) ( $key['pub_key'] ?? '' ), 'readonly' => ! empty( $key['readonly'] ) );
		}
		if ( $ours && array() === $offered && 'ready' !== $r->get( 'state' ) ) { return null; }
		if ( ! $ours && null === $external ) { return null; }
		return array(
			'ours'      => $ours,
			'on'        => $ours ? ! empty( $r->get( 'dnssec_keys' ) ) : array() !== $keys,
			'turning_off' => $ours && (int) $r->get( 'dnssec_off_at' ) > 0,
			'offered'   => $offered,
			'keys'      => $keys,
			'algs'      => $external ? $external['algs'] : array(),
			'max'       => $external ? $external['max'] : 0,
			'full'      => $external ? count( $keys ) >= $external['max'] : false,
		);
	}
}
