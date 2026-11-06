<?php
/** Offline SSL order model behind Domains\FakeClient, for staging. @package BeltoftServices */
namespace BeltoftServices\Ssl;

defined( 'ABSPATH' ) || exit;

/**
 * Follows the Openprovider SSL module as it was read from the sandbox and the public API guide, with made-up values:
 *
 * - POST /ssl/orders {product_id, period, csr, organization_handle, technical_handle, domain_validation_methods[], ...}
 *   gives {id}; the order is PAI (purchased, not sent to the CA). An empty csr is op_1003; an unknown product op_1000.
 * - PUT /ssl/orders/{id} merges fields; {start_provision: true} moves PAI to REQ (a csr without a country is op_1099 for every
 *   product, as the sandbox answers; an OV or EV csr without O, ST and C is op_1050). After a delay (filter beltoft_services_ssl_fake_delay, 60 s) a REQ order becomes ACT with a certificate,
 *   signed by a throwaway CA for the csr, valid for 199 days (the order itself for a year). When that certificate is older
 *   than 199 days the order is reissued in place, as the registrar does.
 * - GET /ssl/orders/{id} (unknown id: HTTP 400 op_1094) and GET /ssl/orders?common_name_pattern=&contact_handle=&status=.
 * - POST /ssl/orders/{id}/cancel: PAI goes to FAI, REQ and ACT to REJ.
 * - POST /ssl/orders/{id}/reissue {csr}: an empty csr is op_1003; the order goes to REQ and then ACT.
 * - POST /ssl/orders/{id}/renew: only an ACT order within 30 days of its expiry (else op_25003); adds a year.
 * - POST /ssl/orders/{id}/approver-email/resend, POST /ssl/orders/{id}/otp-tokens, GET /ssl/approver-emails.
 * - GET /ssl/products: the catalog below, with one year reseller prices in EUR.
 *
 * It never represents a real CA reply: the DNS validation values in additional_data are invented.
 */
final class FakeModel {
	public const LIFETIME_DAYS = 199;

	/**
	 * The made-up product list: id => name, category (as the registrar words it), validation method, wildcard, delivery, cost for one
	 * year, domains (max, included) and the cost of an extra name. Code signing (58) and S/MIME (60) name no host (max 0).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function products(): array {
		$p = static fn( string $name, string $category, string $method, bool $wild, string $delivery, float $cost, int $max = 1, int $included = 1, float $extra = 0.0 ) => compact( 'name', 'category', 'method', 'wild', 'delivery', 'cost', 'max', 'included', 'extra' );

		return array(
			41 => $p( 'Positive SSL', 'domain_validation', 'domain', false, '10m', 8.68 ),
			42 => $p( 'Positive SSL Wildcard', 'wildcard', 'domain', true, '10m', 77.20 ),
			20 => $p( 'InstantSSL', 'organization_validation', 'organization', false, 'up to 2 days', 33.77 ),
			24 => $p( 'EV SSL', 'extended_validation', 'extended', false, 'within 1 day', 86.85 ),
			31 => $p( 'EssentialSSL', 'domain_validation', 'domain', false, '10m', 8.68 ),
			40 => $p( 'Positive SSL multi-domain', 'multi_domain', 'domain', false, '10m', 19.29, 210, 3, 9.64 ),
			33 => $p( 'EVSSL multi-domain', 'multi_domain', 'extended', false, 'within 1 day', 193.02, 210, 3, 86.85 ),
			28 => $p( 'Unified Communications Certificate (UCC)', 'multi_domain', 'organization', false, 'up to 2 days', 38.60, 210, 3, 19.29 ),
			58 => $p( 'Code Signing', 'organization_validation', 'organization', false, '1-3 days', 86.85, 0, 0 ),
			60 => $p( 'S/MIME Personal', 'domain_validation', 'domain', false, '10m', 18.33, 0, 0 ),
		);
	}

	/**
	 * The current time, overridable so a test can move the clock.
	 */
	public static function now(): int {
		return (int) apply_filters( 'beltoft_services_ssl_fake_now', time() );
	}

	/**
	 * Handles one request.
	 *
	 * @param array<string, mixed> $state  Fake registrar state (changed in place; the caller saves it).
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $body   Body.
	 * @param array<string, mixed> $query  Query.
	 * @param bool                 $dirty  Set to true when $state changed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function handle( array &$state, string $method, string $path, array $body, array $query, bool &$dirty ): array|\WP_Error {
		$state['ssl_orders'] = isset( $state['ssl_orders'] ) && is_array( $state['ssl_orders'] ) ? $state['ssl_orders'] : array();
		$state['ssl_next']   = (int) ( $state['ssl_next'] ?? 500 );

		if ( 'GET' === $method && '/ssl/products' === $path ) {
			$results = array();

			foreach ( self::products() as $id => $p ) {
				$results[] = array(
					'id' => $id, 'name' => $p['name'], 'brand_name' => 'Fake CA', 'category' => $p['category'], 'validation_method' => $p['method'], 'is_wildcard_supported' => $p['wild'], 'is_extended_validation_supported' => 'extended' === $p['method'],
					'delivery_time' => $p['delivery'], 'free_refund_period' => '30d', 'free_reissue_period' => 'lifetime', 'max_period' => 5, 'max_domains' => $p['max'],
					'number_of_domains' => $p['included'], 'included_domains_count' => $p['included'],
					'prices' => array( array( 'period' => 1, 'price' => array( 'reseller' => array( 'price' => $p['cost'], 'currency' => 'EUR' ) ), 'extra_domain_price' => array( 'reseller' => array( 'price' => $p['extra'], 'currency' => 'EUR' ) ) ) ),
				);
			}

			return array( 'results' => $results, 'total' => count( $results ) );
		}

		if ( 'GET' === $method && '/ssl/approver-emails' === $path ) {
			$domain = strtolower( (string) ( $query['domain'] ?? '' ) );

			if ( '' === $domain || ! isset( $query['product_id'] ) ) {
				return self::error( 1000, 'Missing parameter.', 400 );
			}

			return array( 'results' => array_map( static fn( $l ) => $l . '@' . $domain, array( 'admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster' ) ) );
		}

		if ( 'POST' === $method && '/ssl/orders' === $path ) {
			return self::create( $state, $body, $dirty );
		}

		if ( 'GET' === $method && '/ssl/orders' === $path ) {
			$found = array();

			foreach ( array_keys( $state['ssl_orders'] ) as $id ) {
				$order = self::advance( $state, (int) $id, $dirty );
				$cn    = (string) $order['common_name'];
				$like  = (string) ( $query['common_name_pattern'] ?? '' );

				if ( '' !== $like && 1 !== preg_match( '/^' . str_replace( '\\*', '.*', preg_quote( $like, '/' ) ) . '$/Di', $cn ) ) {
					continue;
				}

				if ( isset( $query['contact_handle'] ) && (string) $query['contact_handle'] !== (string) $order['organization_handle'] ) {
					continue;
				}

				if ( isset( $query['status'] ) && '' !== (string) $query['status'] && (string) $query['status'] !== (string) $order['status'] ) {
					continue;
				}

				$found[] = $order;
			}

			return array( 'results' => $found, 'total' => count( $found ) );
		}

		if ( 1 === preg_match( '#^/ssl/orders/(\d+)(/cancel|/reissue|/renew|/approver-email/resend|/otp-tokens)?$#D', $path, $m ) ) {
			$id = (int) $m[1];

			if ( ! isset( $state['ssl_orders'][ $id ] ) ) {
				return self::error( 1094, 'Invalid order id', 400 );
			}

			$order = self::advance( $state, $id, $dirty );
			$tail  = $m[2] ?? '';

			if ( 'GET' === $method && '' === $tail ) {
				return $order;
			}

			if ( 'PUT' === $method && '' === $tail ) {
				return self::update( $state, $id, $order, $body, $dirty );
			}

			if ( 'POST' === $method && '/cancel' === $tail ) {
				$order['status'] = 'PAI' === $order['status'] ? 'FAI' : ( in_array( $order['status'], array( 'FAI', 'REJ' ), true ) ? $order['status'] : 'REJ' );
				$state['ssl_orders'][ $id ] = $order;
				$dirty = true;

				return array( 'id' => $id );
			}

			if ( 'POST' === $method && '/reissue' === $tail ) {
				if ( '' === trim( (string) ( $body['csr'] ?? '' ) ) ) {
					return self::error( 1003, 'CSR must be set', 400 );
				}

				if ( 'ACT' !== $order['status'] ) {
					return self::error( 25003, 'Reissue is not allowed', 400 );
				}

				$order['csr']          = (string) $body['csr'];
				$order['status']       = 'REQ';
				$order['requested_at'] = self::now();
				$state['ssl_orders'][ $id ] = $order;
				$dirty = true;

				return array( 'id' => $id );
			}

			if ( 'POST' === $method && '/renew' === $tail ) {
				$expires = strtotime( (string) $order['expiration_date'] . ' UTC' );

				if ( 'ACT' !== $order['status'] || false === $expires || self::now() < $expires - 30 * DAY_IN_SECONDS ) {
					return self::error( 25003, 'Renew is not allowed', 400 );
				}

				$order['expiration_date'] = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $expires ) );
				$state['ssl_orders'][ $id ] = $order;
				$dirty = true;

				return array( 'id' => $id );
			}

			if ( 'POST' === $method && '/approver-email/resend' === $tail ) {
				return 'email' === ( $order['validation_method_kind'] ?? '' ) ? array( 'success' => true ) : self::error( 1099, 'dcvType: An email address is required', 400 );
			}

			if ( 'POST' === $method && '/otp-tokens' === $tail ) {
				return array( 'uri' => 'https://ssl-panel.example.invalid/otp/' . $id, 'token' => 'FAKE-OTP-' . $id, 'expire_at' => gmdate( 'Y-m-d H:i:s', self::now() + 900 ) );
			}
		}

		return new \WP_Error( 'fake_unconfigured', 'No fake registrar behavior is configured.' );
	}

	/**
	 * An error as the registrar answers it.
	 *
	 * @param int    $code   Registrar code.
	 * @param string $desc   Text.
	 * @param int    $status HTTP status.
	 */
	private static function error( int $code, string $desc, int $status ): \WP_Error {
		return new \WP_Error( 'op_' . $code, 'Registrar request failed.', array( 'status' => $status, 'desc' => $desc ) );
	}

	/**
	 * Creates an order (status PAI).
	 *
	 * @param array<string, mixed> $state Fake state.
	 * @param array<string, mixed> $body  Request body.
	 * @param bool                 $dirty Set when the state changed.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function create( array &$state, array $body, bool &$dirty ): array|\WP_Error {
		$product = (int) ( $body['product_id'] ?? 0 );

		if ( ! isset( self::products()[ $product ] ) ) {
			return self::error( 1000, 'Unknown product.', 400 );
		}

		$p       = self::products()[ $product ];
		$methods = (array) ( $body['domain_validation_methods'] ?? array() );
		$hosts   = array();
		$kind    = '';

		foreach ( $methods as $row ) {
			if ( is_array( $row ) && ! empty( $row['host_name'] ) ) {
				$hosts[] = strtolower( (string) $row['host_name'] );
				$kind    = (string) ( $row['method'] ?? '' );
			}
		}

		if ( array() === $hosts ) {
			return self::error( 1000, 'domain_validation_methods is empty.', 400 );
		}

		// The sandbox accepts any number of names; this model refuses what the product cannot hold, so a test sees a wrong order.
		$amount = (int) ( $body['domain_amount'] ?? count( $hosts ) );

		if ( count( $hosts ) > max( 1, (int) $p['max'] ) || $amount < count( $hosts ) || $amount > max( 1, (int) $p['max'] ) ) {
			return self::error( 1000, 'domain_amount does not match the names or the product.', 400 );
		}

		$id = ++$state['ssl_next'];

		$state['ssl_orders'][ $id ] = array(
			'id' => $id, 'product_id' => $product, 'common_name' => $hosts[0], 'product_name' => self::products()[ $product ]['name'], 'brand_name' => 'Fake CA',
			'status' => 'PAI', 'order_date' => gmdate( 'Y-m-d H:i:s', self::now() ), 'host_names' => $hosts,
			'organization_handle' => (string) ( $body['organization_handle'] ?? '' ), 'technical_handle' => (string) ( $body['technical_handle'] ?? '' ),
			'csr' => (string) ( $body['csr'] ?? '' ), 'period' => (int) ( $body['period'] ?? 1 ), 'software' => (string) ( $body['software_id'] ?? '' ),
			'certificate' => '', 'intermediate_certificate' => '', 'root_certificate' => '', 'active_date' => '', 'expiration_date' => '',
			'domain_validation_methods' => $methods, 'validation_method_kind' => $kind, 'approver_email' => (string) ( $body['approver_email'] ?? '' ),
			'additional_data' => array(), 'autorenew' => 'off', 'requested_at' => 0, 'issued_at' => 0,
		);
		$dirty = true;

		return array( 'id' => $id );
	}

	/**
	 * Updates an order; start_provision sends it to the CA.
	 *
	 * @param array<string, mixed> $state Fake state.
	 * @param int                  $id    Order id.
	 * @param array<string, mixed> $order The order.
	 * @param array<string, mixed> $body  Request body.
	 * @param bool                 $dirty Set when the state changed.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function update( array &$state, int $id, array $order, array $body, bool &$dirty ): array|\WP_Error {
		foreach ( array( 'csr', 'approver_email', 'domain_validation_methods', 'organization_handle', 'technical_handle' ) as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$order[ $field ] = $body[ $field ];
			}
		}

		if ( isset( $body['domain_validation_methods'][0]['method'] ) ) {
			$order['validation_method_kind'] = (string) $body['domain_validation_methods'][0]['method'];
		}

		if ( ! empty( $body['start_provision'] ) && 'PAI' === $order['status'] ) {
			if ( '' === trim( (string) $order['csr'] ) ) {
				return self::error( 1003, 'CSR must be set', 400 );
			}

			$level = self::products()[ (int) $order['product_id'] ]['method'];

			// The sandbox: a multi domain order needs alternative names (code 1029 at start).
			if ( 'multi_domain' === self::products()[ (int) $order['product_id'] ]['category'] && count( (array) $order['host_names'] ) < 2 ) {
				return self::error( 1029, 'Alternative names must be specified for multidomain certificate', 400 );
			}
			$dn    = openssl_csr_get_subject( (string) $order['csr'], true );

			// The sandbox refuses a request without a valid country, DV included (code 1099, the text keeps its %s).
			if ( ! is_array( $dn ) || 1 !== preg_match( '/^[A-Za-z]{2}$/D', (string) ( $dn['C'] ?? '' ) ) ) {
				return self::error( 1099, 'csr: The CSR contains an invalid country code %s', 400 );
			}

			if ( 'domain' !== $level && ( ! is_array( $dn ) || empty( $dn['O'] ) || empty( $dn['ST'] ) || empty( $dn['C'] ) ) ) {
				return self::error( 1050, 'CSR must contain company; Bad Region', 400 );
			}

			$order['status']       = 'REQ';
			$order['requested_at'] = self::now();
		}

		$state['ssl_orders'][ $id ] = $order;
		$dirty = true;

		return array( 'id' => $id );
	}

	/**
	 * Moves an order along with the clock: the DNS data appears with REQ, ACT follows after the delay, and an old
	 * certificate is replaced in place (the automatic reissue).
	 *
	 * @param array<string, mixed> $state Fake state.
	 * @param int                  $id    Order id.
	 * @param bool                 $dirty Set when the state changed.
	 * @return array<string, mixed>
	 */
	private static function advance( array &$state, int $id, bool &$dirty ): array {
		$order = $state['ssl_orders'][ $id ];
		$now   = self::now();
		$delay = max( 0, (int) apply_filters( 'beltoft_services_ssl_fake_delay', 60 ) );

		if ( 'REQ' === $order['status'] && empty( $order['additional_data'] ) && 'email' !== ( $order['validation_method_kind'] ?? '' ) ) {
			$hash = hash( 'sha256', (string) $order['csr'] );
			$data = array();

			// One entry per name, each with the method chosen for it (the registrar's `domain` field names the host).
			foreach ( (array) $order['domain_validation_methods'] as $row ) {
				$name = (string) ( $row['host_name'] ?? '' );
				$host = ltrim( $name, '*.' );
				$tag  = substr( md5( (string) $order['csr'] . ( $name === (string) $order['common_name'] ? '' : $name ) ), 0, 32 );

				$data[] = 'https' === ( $row['method'] ?? '' )
					? array( 'domain' => $name, 'file_location' => 'http://' . $host . '/.well-known/pki-validation/' . strtoupper( $tag ) . '.txt', 'file_contents' => substr( $hash, 0, 32 ) . "\nfakeca.example" )
					: array( 'domain' => $name, 'dns_record' => '_' . $tag . '.' . $host, 'dns_value' => substr( $hash, 0, 32 ) . '.' . substr( $hash, 32, 32 ) . '.fakeca.example' );
			}

			$order['additional_data'] = $data;
			$dirty                    = true;
		}

		if ( 'REQ' === $order['status'] && (int) $order['requested_at'] > 0 && $now >= (int) $order['requested_at'] + $delay ) {
			$order = self::issue( $order, $now, (int) $order['issued_at'] > 0 );
			$dirty = true;
		} elseif ( 'ACT' === $order['status'] && (int) $order['issued_at'] > 0 && $now >= (int) $order['issued_at'] + self::LIFETIME_DAYS * DAY_IN_SECONDS && $now < strtotime( (string) $order['expiration_date'] . ' UTC' ) ) {
			$order = self::issue( $order, $now, true );
			$dirty = true;
		}

		$state['ssl_orders'][ $id ] = $order;

		return $order;
	}

	/**
	 * Signs the order's csr with a throwaway CA.
	 *
	 * @param array<string, mixed> $order   The order.
	 * @param int                  $now     Now.
	 * @param bool                 $in_year Keep the order's expiry (an automatic reissue) instead of starting a year.
	 * @return array<string, mixed>
	 */
	private static function issue( array $order, int $now, bool $in_year = false ): array {
		$config = array( 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
		$ca_key = openssl_pkey_new( $config );
		$ca_csr = $ca_key ? openssl_csr_new( array( 'commonName' => 'Fake Intermediate CA' ), $ca_key, array( 'digest_alg' => 'sha256' ) ) : false;
		$ca     = $ca_csr ? openssl_csr_sign( $ca_csr, null, $ca_key, 3650, array( 'digest_alg' => 'sha256' ), random_int( 1, 2000000000 ) ) : false;
		$leaf   = $ca && $ca_key ? openssl_csr_sign( (string) $order['csr'], $ca, $ca_key, self::LIFETIME_DAYS, array( 'digest_alg' => 'sha256' ), random_int( 1, 2000000000 ) ) : false;

		if ( ! $leaf ) {
			// A csr the model cannot sign leaves the order waiting.
			return $order;
		}

		openssl_x509_export( $leaf, $leaf_pem );
		openssl_x509_export( $ca, $ca_pem );

		$order['status']                   = 'ACT';
		$order['certificate']              = $leaf_pem;
		$order['intermediate_certificate'] = $ca_pem;
		$order['root_certificate']         = $ca_pem;
		$order['issued_at']                = $now;
		$order['active_date']              = gmdate( 'Y-m-d H:i:s', $now );

		if ( ! $in_year || '' === (string) $order['expiration_date'] ) {
			$order['expiration_date'] = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $now ) );
		}

		return $order;
	}
}
