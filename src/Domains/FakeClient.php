<?php
/** Offline registrar with scriptable replies. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class FakeClient implements Client {
	public const OPTION = 'beltoft_services_fake_registrar';
	private array $calls = array();
	private array $replies = array();
	private bool $persist;

	public function __construct( bool $persist = false ) { $this->persist = $persist; }

	public function reply( string $method, string $path, array|\WP_Error $response ): void {
		$this->replies[ strtoupper( $method ) . ' ' . $path ][] = $response;
	}

	public function calls(): array {
		return $this->calls;
	}

	public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error {
		$method = strtoupper( $method );
		$this->calls[] = array( 'method' => $method, 'path' => $path, 'body' => $this->redact( $body ), 'query' => $query );
		$key = $method . ' ' . $path;
		if ( ! empty( $this->replies[ $key ] ) ) { return array_shift( $this->replies[ $key ] ); }
		return $this->persist ? $this->model( $method, $path, $body, $query ) : new \WP_Error( 'fake_unconfigured', 'No fake registrar reply is configured.' );
	}

	/** A small persistent model for staging. It never represents a real registry response. */
	private function model( string $method, string $path, array $body, array $query ): array|\WP_Error {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) { $state = array(); }
		$state += array( 'next_id' => 1000, 'domains' => array(), 'customers' => array() );
		if ( 'POST' === $method && '/domains/check' === $path ) {
			$results = array();
			foreach ( (array) ( $body['domains'] ?? array() ) as $domain ) {
				$name = strtolower( (string) ( $domain['name'] ?? '' ) . '.' . (string) ( $domain['extension'] ?? '' ) );
				$results[] = array( 'domain' => $name, 'status' => isset( $state['domains'][ $name ] ) ? 'taken' : 'free', 'is_premium' => false );
			}
			return array( 'results' => $results );
		}
		if ( 'POST' === $method && '/customers' === $path ) {
			$handle = 'FAKE' . ++$state['next_id'] . '-XX';
			$state['customers'][ $handle ] = $body;
			update_option( self::OPTION, $state, false );
			return array( 'handle' => $handle );
		}
		if ( 'GET' === $method && '/domains' === $path ) {
			$name = strtolower( (string) ( $query['full_name'] ?? '' ) );
			return array( 'results' => isset( $state['domains'][ $name ] ) ? array( $state['domains'][ $name ] ) : array() );
		}
		if ( 'POST' === $method && in_array( $path, array( '/domains', '/domains/transfer' ), true ) ) {
			$name = strtolower( (string) ( $body['domain']['name'] ?? '' ) . '.' . (string) ( $body['domain']['extension'] ?? '' ) );
			if ( isset( $state['domains'][ $name ] ) ) { return new \WP_Error( 'op_fake_taken', 'Fake domain already exists.' ); }
			$domain = array( 'id' => ++$state['next_id'], 'full_name' => $name, 'status' => 'ACT', 'expiration_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ), 'owner_handle' => (string) ( $body['owner_handle'] ?? '' ), 'is_locked' => false );
			$state['domains'][ $name ] = $domain;
			update_option( self::OPTION, $state, false );
			return $domain;
		}
		if ( preg_match( '#^/domains/(\d+)(/renew|/authcode)?$#D', $path, $match ) ) {
			foreach ( $state['domains'] as $name => $domain ) {
				if ( (int) $domain['id'] !== (int) $match[1] ) { continue; }
				if ( 'GET' === $method && '/authcode' === ( $match[2] ?? '' ) ) { return array( 'auth_code' => 'FAKE-CODE-' . $domain['id'] ); }
				if ( 'GET' === $method ) { return $domain; }
				if ( 'POST' === $method && '/renew' === ( $match[2] ?? '' ) ) { $domain['expiration_date'] = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', strtotime( $domain['expiration_date'] ) ) ); }
				elseif ( 'PUT' === $method && '' === ( $match[2] ?? '' ) ) { $domain = array_merge( $domain, $body ); }
				else { break; }
				$state['domains'][ $name ] = $domain;
				update_option( self::OPTION, $state, false );
				return $domain;
			}
			return new \WP_Error( 'http_404', 'Fake domain was not found.' );
		}
		if ( str_starts_with( $path, '/licenses/' ) ) { return $this->licence_model( $state, $method, $path, $body, $query ); }
		if ( str_starts_with( $path, '/ssl/' ) ) {
			$dirty  = false;
			$result = \BeltoftServices\Ssl\FakeModel::handle( $state, $method, $path, $body, $query, $dirty );
			if ( $dirty ) { update_option( self::OPTION, $state, false ); }
			return $result;
		}
		if ( 'GET' === $method && '/resellers' === $path ) { return array( 'balance' => 1000, 'currency' => 'EUR' ); }
		return new \WP_Error( 'fake_unconfigured', 'No fake registrar behavior is configured.' );
	}

	/**
	 * Plesk licences, modelled on the Openprovider license service (documented fields only, made-up values):
	 * POST /licenses/plesk {title, comment, items[], period, ip_address_binding} gives {key_id, attached_keys};
	 * GET /licenses/plesk (title_pattern, key_id) gives {results[], total}; GET /licenses/plesk/{key_id} gives the
	 * licence; PUT changes items, title, comment or ip_address_binding and gives {success}; DELETE gives {success};
	 * GET /licenses/plesk/key/{key_id} gives {key}; POST /licenses/hwids/reset/plesk/{key_id} gives {success}.
	 * Errors as the Openprovider sandbox answers them: a create without a period is op_3105 (400, "License period is
	 * required."), an item that is not an item id (a SKU such as PLSKVPS-ADM-M) op_3103 (400, "License request has invalid
	 * items."), a key id that does not exist op_3108 (404, "License not found."). The items are the ids of GET /licenses/items.
	 */
	private function licence_model( array $state, string $method, string $path, array $body, array $query ): array|\WP_Error {
		$state['licences'] = isset( $state['licences'] ) && is_array( $state['licences'] ) ? $state['licences'] : array();
		if ( 'POST' === $method && '/licenses/plesk' === $path ) {
			$items = array_values( array_filter( array_map( 'strval', (array) ( $body['items'] ?? array() ) ) ) );
			$ip    = (string) ( $body['ip_address_binding'] ?? '' );
			if ( ! isset( $body['period'] ) || (int) $body['period'] < 1 ) { return new \WP_Error( 'op_3105', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'License period is required.' ) ); }
			if ( array() === $items || array() !== array_filter( $items, static fn( $i ) => 1 !== preg_match( '/^[A-Z0-9][A-Z0-9-]{2,78}-(1M|1Y)$/D', $i ) || 0 === strpos( $i, 'PLSK' ) ) ) { return new \WP_Error( 'op_3103', 'Registrar request failed.', array( 'status' => 400, 'desc' => 'License request has invalid items.' ) ); }
			if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { return new \WP_Error( 'op_1000', 'Fake licence request is invalid.', array( 'status' => 400 ) ); }
			$parent = (int) ( $body['parent_key_id'] ?? 0 );
			if ( $parent > 0 && ! isset( $state['licences'][ $parent ] ) ) { return new \WP_Error( 'op_3108', 'Registrar request failed.', array( 'status' => 404, 'desc' => 'License not found.' ) ); }
			$id = ++$state['next_id'];
			$state['licences'][ $id ] = array( 'key_id' => $id, 'parent_key_id' => $parent, 'title' => (string) ( $body['title'] ?? '' ), 'comment' => (string) ( $body['comment'] ?? '' ), 'items' => $items, 'ip_address_binding' => $ip, 'period' => (int) ( $body['period'] ?? 1 ), 'status' => 'ACT', 'key_number' => 'FAKE-' . $id, 'activation_code' => '', 'hwid_resets' => 0 );
			update_option( self::OPTION, $state, false );
			return array( 'key_id' => $id, 'attached_keys' => array() );
		}
		if ( 'GET' === $method && '/licenses/plesk' === $path ) {
			$pattern = '/^' . str_replace( '\\*', '.*', preg_quote( (string) ( $query['title_pattern'] ?? '*' ), '/' ) ) . '$/Di';
			$found   = array_values( array_filter( $state['licences'], static fn( $l ) => 1 === preg_match( $pattern, (string) $l['title'] ) && ( ! isset( $query['key_id'] ) || (int) $query['key_id'] === (int) $l['key_id'] ) ) );
			return array( 'results' => $found, 'total' => count( $found ) );
		}
		if ( preg_match( '#^/licenses/plesk/(key/)?(\d+)$#D', $path, $match ) || preg_match( '#^/licenses/hwids/reset/plesk/(\d+)$#D', $path, $reset ) ) {
			$id = (int) ( $reset[1] ?? $match[2] );
			if ( ! isset( $state['licences'][ $id ] ) ) { return new \WP_Error( 'op_3108', 'Registrar request failed.', array( 'status' => 404, 'desc' => 'License not found.' ) ); }
			$licence = $state['licences'][ $id ];
			if ( isset( $reset[1] ) && 'POST' === $method ) { ++$licence['hwid_resets']; $result = array( 'success' => true ); }
			elseif ( 'GET' === $method && '' !== ( $match[1] ?? '' ) ) { return array( 'key' => base64_encode( '<?xml version="1.0"?><key id="FAKE-KEY-' . $id . '"/>' . str_repeat( ' ', 80 ) ) ); }
			elseif ( 'GET' === $method ) { return $licence; }
			elseif ( 'PUT' === $method ) {
				foreach ( array( 'items', 'title', 'comment', 'ip_address_binding' ) as $field ) { if ( isset( $body[ $field ] ) ) { $licence[ $field ] = $body[ $field ]; } }
				$result = array( 'success' => true );
			} elseif ( 'DELETE' === $method ) {
				unset( $state['licences'][ $id ] );
				update_option( self::OPTION, $state, false );
				return array( 'success' => true );
			} else { return new \WP_Error( 'fake_unconfigured', 'No fake registrar behavior is configured.' ); }
			$state['licences'][ $id ] = $licence;
			update_option( self::OPTION, $state, false );
			return $result;
		}
		return new \WP_Error( 'fake_unconfigured', 'No fake registrar behavior is configured.' );
	}

	private function redact( array $body ): array {
		foreach ( $body as $key => $value ) {
			if ( in_array( $key, array( 'password', 'auth_code', 'token', 'key' ), true ) ) {
				$body[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$body[ $key ] = $this->redact( $value );
			}
		}
		return $body;
	}
}
