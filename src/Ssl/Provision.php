<?php
/** The build of one certificate order. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Alerts;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\Contacts;
use BeltoftServices\Domains\LiveClient;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Steps, kept in meta `steps` so a retry never repeats one:
 *
 * csr      the key (made here and stored encrypted first) and the request, or the customer's own request checked again;
 * contact  the registrant handle from the billing details of the order;
 * order    the order at the registrar. The money is spent here: the paid line must cover the cost (SslGuard), and a crashed
 *          earlier attempt is found by its request text before anything is posted again;
 * request  the order is sent to the certificate authority (start_provision);
 * validate the validation data is read from the order and handled (the CNAME is published in a zone of ours, or the customer is
 *          told exactly what to add);
 * active   waits for the authority, with a hard stop that cancels the order;
 * deliver  the certificate is read, checked against the key and stored;
 * dates    the renewal charge is dated from the order's expiry.
 */
final class Provision {
	/** Registrar statuses that end an order without a certificate. */
	private const DEAD = array( 'FAI', 'REJ', 'EXP' );

	/**
	 * Runs the build.
	 *
	 * @param Resource $r Resource.
	 */
	public static function run( Resource $r ): StepResult {
		$result = self::build( $r );

		// A build that ends here holds no certificate: an order paid at the registrar is cancelled so it can be refunded.
		if ( $result->is_failed() && '' !== $r->get( 'remote_id' ) && ! in_array( 'deliver', (array) $r->get( 'steps' ), true ) ) {
			self::abandon( $r );
		}

		return $result;
	}

	/**
	 * Cancels the registrar order of a build that failed, and tells staff the customer needs a refund.
	 *
	 * @param Resource $r Resource.
	 */
	public static function abandon( Resource $r ): void {
		$id = (string) $r->get( 'remote_id' );

		if ( '' === $id || $r->get( 'ssl_abandoned' ) ) {
			return;
		}

		// A build that ends here keeps no key for a certificate that does not exist.
		Keys::purge( $r );

		$done = Clients::registrar()->request( 'POST', '/ssl/orders/' . $id . '/cancel', array( 'id' => (int) $id ) );

		if ( is_wp_error( $done ) ) {
			Alerts::admin( $r, 'The certificate order at the registrar could not be cancelled (' . $done->get_error_code() . '). Cancel it by hand and refund the customer.' );

			return;
		}

		$r->set( array( 'ssl_abandoned' => true ) );
		Alerts::admin( $r, 'A certificate build failed and the registrar order was cancelled. Refund the customer.' );
	}

	/**
	 * @param Resource $r Resource.
	 */
	private static function build( Resource $r ): StepResult {
		$plan    = (array) $r->get( 'plan' );
		$product = (int) ( $plan['ssl_product'] ?? 0 );
		$name    = (string) $r->get( 'ssl_name' );
		$base    = (string) $r->get( 'ssl_domain' );
		$steps   = (array) $r->get( 'steps' );

		if ( $product < 1 || '' === $name || null === SslNames::base( $base ) ) {
			return StepResult::fail( 'The order did not contain a valid certificate product and name.' );
		}

		$wildcard = '*.' === substr( $name, 0, 2 );

		if ( ! in_array( 'order', $steps, true ) ) {
			if ( ! SslSettings::enabled() ) {
				return StepResult::retry( 3600, 'SSL certificates are switched off in the settings.' );
			}

			$catalog = SslCatalog::get( $product );

			if ( null === $catalog ) {
				Alerts::admin( $r, 'The registrar product ' . $product . ' is not in the cost table. Read the costs under Settings, Services, SSL certificates.' );

				return StepResult::retry( 3600, 'The registrar cost for this certificate product is unknown.' );
			}

			if ( $wildcard !== (bool) $catalog['wildcard'] ) {
				Alerts::admin( $r, 'The product variation and the registrar product ' . $product . ' disagree about wildcard names.' );

				return StepResult::fail( 'The certificate product does not match the name ordered.' );
			}
		}

		$level = SslCatalog::level( $product );
		$level = '' !== $level ? $level : (string) ( $plan['ssl_level'] ?? 'dv' );

		if ( ! in_array( 'csr', $steps, true ) ) {
			$made = self::make_csr( $r, $name, $level );

			if ( $made instanceof StepResult ) {
				return $made;
			}

			$steps[] = 'csr';

			if ( ! $r->set( array_merge( $made, array( 'steps' => $steps ) ) ) ) {
				return StepResult::retry( 300, 'The certificate request could not be saved.' );
			}
		}

		if ( ! in_array( 'contact', $steps, true ) ) {
			$license = $r->license();
			$order   = wc_get_order( (int) ( $license['order_id'] ?? 0 ) );

			if ( ! $order ) {
				return StepResult::retry( 3600, 'The certificate order is unavailable.' );
			}

			$handle = Contacts::handle_for( (int) ( $license['user_id'] ?? 0 ), $order );

			if ( is_wp_error( $handle ) ) {
				return self::error( $r, $handle, $steps );
			}

			$steps[] = 'contact';

			if ( ! $r->set( array( 'contact_handle' => $handle, 'steps' => $steps ) ) ) {
				return StepResult::retry( 300, 'The registrant contact could not be saved.' );
			}
		}

		if ( ! in_array( 'order', $steps, true ) ) {
			$ordered = self::order( $r, $product, $name, $steps );

			if ( $ordered instanceof StepResult ) {
				return $ordered;
			}

			$steps[] = 'order';

			if ( ! $r->set( array( 'remote_id' => $ordered, 'steps' => $steps, 'ordered_at' => time() ) ) ) {
				return StepResult::retry( 300, 'The registrar order id could not be saved.' );
			}
		}

		$id = (string) $r->get( 'remote_id' );

		if ( ! in_array( 'request', $steps, true ) ) {
			$sent = Clients::registrar()->request( 'PUT', '/ssl/orders/' . $id, array( 'start_provision' => true ) );

			if ( is_wp_error( $sent ) ) {
				// An order already past PAI answers differently; read it back before judging.
				$have = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );

				if ( is_wp_error( $have ) || 'PAI' === ( $have['status'] ?? '' ) ) {
					return self::error( $r, $sent, $steps );
				}
			}

			$steps[] = 'request';

			if ( ! $r->set( array( 'steps' => $steps, 'ssl_requested_at' => time(), 'ssl_status' => 'REQ' ) ) ) {
				return StepResult::retry( 300, 'The request status could not be saved.' );
			}
		}

		$detail = Clients::registrar()->request( 'GET', '/ssl/orders/' . $id );

		if ( is_wp_error( $detail ) ) {
			return self::error( $r, $detail, $steps );
		}

		$status = (string) ( $detail['status'] ?? '' );

		if ( in_array( $status, self::DEAD, true ) ) {
			// Already ended at the registrar: there is nothing to cancel, only a customer to refund.
			$r->set( array( 'ssl_status' => $status, 'ssl_abandoned' => true ) );
			Alerts::admin( $r, 'The certificate authority ended this order (' . $status . '). Refund the customer.' );

			return StepResult::fail( 'The certificate authority ended this order (' . $status . ').', array( 'ssl_status' => $status ) );
		}

		if ( ! in_array( 'validate', $steps, true ) ) {
			$validated = 'ACT' === $status ? StepResult::done() : Validation::start( $r, $detail );

			if ( ! $validated->is_done() ) {
				return $validated;
			}

			$steps[] = 'validate';

			if ( ! $r->set( array_merge( $validated->meta(), array( 'steps' => $steps ) ) ) ) {
				return StepResult::retry( 300, 'The validation status could not be saved.' );
			}
		}

		if ( ! in_array( 'active', $steps, true ) ) {
			if ( 'ACT' !== $status ) {
				return self::waiting( $r, $level );
			}

			$steps[] = 'active';

			if ( ! $r->set( array( 'steps' => $steps, 'ssl_status' => 'ACT' ) ) ) {
				return StepResult::retry( 300, 'The certificate status could not be saved.' );
			}
		}

		if ( ! in_array( 'deliver', $steps, true ) ) {
			$stored = self::store_certificate( $r, $detail );

			if ( ! $stored->is_done() ) {
				return $stored;
			}

			$steps[] = 'deliver';

			// ssl_first_issued_at is set here only, once: it starts the refund window.
			if ( ! $r->set( array_merge( $stored->meta(), array( 'steps' => $steps, 'ssl_first_issued_at' => time() ) ) ) ) {
				return StepResult::retry( 300, 'The certificate could not be saved.' );
			}

			// The customer's seven days for the key start with the first certificate.
			Keys::start_window( Resource::find( $r->id() ) ?? $r );

			Emails::send( 'beltoft_services_ssl_issued', Resource::find( $r->id() ) ?? $r );
		}

		if ( ! in_array( 'dates', $steps, true ) ) {
			$dates = SslRenewals::set_dates( Resource::find( $r->id() ) ?? $r );

			if ( ! $dates->is_done() ) {
				return $dates;
			}

			$steps[] = 'dates';

			if ( ! $r->set( array_merge( $dates->meta(), array( 'steps' => $steps ) ) ) ) {
				return StepResult::retry( 300, 'The billing dates could not be saved.' );
			}
		}

		return StepResult::done( array( 'steps' => $steps ), 'SSL certificate issued' );
	}

	/**
	 * The request: made here from a new key (stored encrypted in the same save), or the customer's own, checked again.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $name  Common name.
	 * @param string   $level dv, ov or ev.
	 * @return array<string, mixed>|StepResult Meta to save, or the result to return.
	 */
	private static function make_csr( Resource $r, string $name, string $level ) {
		if ( 'own' === $r->get( 'ssl_key_mode' ) ) {
			$read = Csr::check( (string) $r->get( 'ssl_csr' ), $name, $level );

			return is_wp_error( $read ) ? StepResult::fail( $read->get_error_message() ) : array();
		}

		// A key made by an earlier run whose request was kept: nothing is made again.
		if ( null !== Keys::existing( $r ) && '' !== (string) $r->get( 'ssl_csr' ) ) {
			return array();
		}

		$org = self::organization( $r, $level );

		if ( $org instanceof StepResult ) {
			return $org;
		}

		$made = Csr::generate( $name, $org );

		if ( is_wp_error( $made ) ) {
			return StepResult::retry( 300, $made->get_error_message() );
		}

		$window = ( SslSettings::wait_days() + Keys::REVEAL_DAYS ) * DAY_IN_SECONDS;
		$keep   = Keys::meta( $made['key'], $window );

		if ( null === $keep ) {
			return StepResult::fail( 'The encryption key is not set, so no private key can be stored.' );
		}

		return array_merge( $keep, array( 'ssl_csr' => $made['csr'] ) );
	}

	/**
	 * The subject entries of a request, from the billing details of the order: the country for every level, and the company, state and city for OV and EV.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $level dv, ov or ev.
	 * @return array<string, string>|StepResult The entries, or the result to return.
	 */
	public static function organization( Resource $r, string $level ) {
		$order = wc_get_order( (int) ( $r->license()['order_id'] ?? 0 ) );

		if ( ! $order ) {
			return StepResult::retry( 3600, 'The certificate order is unavailable.' );
		}

		$country = strtoupper( trim( (string) $order->get_billing_country() ) );

		// The registrar refuses a request without a valid country, whatever the level.
		if ( 1 !== preg_match( '/^[A-Z]{2}$/D', $country ) ) {
			return StepResult::fail( 'The billing details need a country for the certificate request.' );
		}

		if ( ! in_array( $level, array( 'ov', 'ev' ), true ) ) {
			return array( 'countryName' => $country );
		}

		$company = trim( (string) $order->get_billing_company() );
		$state   = trim( (string) $order->get_billing_state() );
		$city    = trim( (string) $order->get_billing_city() );

		if ( '' === $company || ( '' === $state && '' === $city ) ) {
			return StepResult::fail( 'This certificate type needs the company name, city and country in the billing details.' );
		}

		return array( 'organizationName' => $company, 'stateOrProvinceName' => '' !== $state ? $state : $city, 'localityName' => $city, 'countryName' => $country );
	}

	/**
	 * Places the order, and never twice. The money is spent by the POST, so a marker (`ssl_post_attempted`, the time) is saved
	 * BEFORE it. While the marker is set and no order id was saved, the registrar is asked for the order first: candidates are
	 * the orders of this registrant handle for this name, made after the marker, each read in full (`GET /ssl/orders/{id}`) and
	 * adopted only when its request text is ours. When none matches and the marker is set, nothing is posted: staff are told and
	 * decide (`wp beltoft-services ssl order-retry`, which can also adopt an order by id). A definite refusal clears the marker,
	 * because it means no order exists.
	 *
	 * @param Resource $r       Resource.
	 * @param int      $product Registrar product id.
	 * @param string   $name    Common name.
	 * @param string[] $steps   Steps.
	 * @return string|StepResult The registrar order id, or the result to return.
	 */
	private static function order( Resource $r, int $product, string $name, array $steps ) {
		$client = Clients::registrar();
		$handle = (string) $r->get( 'contact_handle' );
		$csr    = trim( (string) $r->get( 'ssl_csr' ) );
		$marker = (int) $r->get( 'ssl_post_attempted' );

		if ( $marker > 0 ) {
			$found = $client->request( 'GET', '/ssl/orders', array(), array( 'common_name_pattern' => $name, 'contact_handle' => $handle, 'limit' => 50 ) );

			if ( is_wp_error( $found ) ) {
				return StepResult::retry( 3600, 'The registrar could not check for an earlier order.' );
			}

			foreach ( (array) ( $found['results'] ?? array() ) as $candidate ) {
				if ( ! is_array( $candidate ) || empty( $candidate['id'] ) || in_array( (string) ( $candidate['status'] ?? '' ), self::DEAD, true ) || ! self::made_after( (string) ( $candidate['order_date'] ?? '' ), $marker ) ) {
					continue;
				}

				// The list is not trusted for the request text: the order itself is read.
				$detail = $client->request( 'GET', '/ssl/orders/' . (int) $candidate['id'] );

				if ( is_wp_error( $detail ) ) {
					return StepResult::retry( 3600, 'The registrar could not check an earlier order.' );
				}

				if ( trim( (string) ( $detail['csr'] ?? '' ) ) === $csr && (string) ( $detail['organization_handle'] ?? '' ) === $handle ) {
					return (string) (int) $candidate['id'];
				}
			}

			// An order may have been placed and not be found: never post a second paid order by itself.
			Alerts::admin( $r, 'A certificate order was sent to the registrar and its answer was lost, and no matching order was found. Nothing more is ordered until staff look: check the registrar, then run wp beltoft-services ssl order-retry ' . $r->id() . ' (add --adopt=<registrar order id> to take an order that exists).' );

			return StepResult::retry( 3600, 'Waiting for staff: an order may already have been placed at the registrar.' );
		}

		$blocked = SslGuard::guard( $r, $product, SslGuard::item_of_resource( $r ) );

		if ( $blocked ) {
			return $blocked;
		}

		// Saved before the money is spent.
		if ( ! $r->set( array( 'ssl_post_attempted' => time() ) ) ) {
			return StepResult::retry( 300, 'The order marker could not be saved.' );
		}

		$method = Validation::registrar_method( $r );
		$body   = array(
			'product_id'                => $product,
			'period'                    => 1,
			'csr'                       => (string) $r->get( 'ssl_csr' ),
			'organization_handle'       => $handle,
			'technical_handle'          => $handle,
			'signature_hash_algorithm'  => 'sha2',
			'software_id'               => 'linux',
			'domain_amount'             => 1,
			'domain_validation_methods' => array( array( 'host_name' => $name, 'method' => $method ) ),
			'autorenew'                 => 'off',
			'start_provision'           => false,
		);

		if ( 'email' === $method ) {
			$body['approver_email'] = (string) $r->get( 'ssl_approver' );
		}

		$ordered = $client->request( 'POST', '/ssl/orders', $body );

		if ( is_wp_error( $ordered ) ) {
			// A refusal that reached the registrar means no order exists. A timeout or a 5xx may have gone through: the marker stays.
			if ( ! LiveClient::is_transient( $ordered ) ) {
				$r->set( array( 'ssl_post_attempted' => null ) );
			}

			return self::error( $r, $ordered, $steps );
		}

		$id = (string) ( $ordered['id'] ?? '' );

		return '' !== $id && ctype_digit( $id ) ? $id : StepResult::retry( 3600, 'The registrar did not return an order id.' );
	}

	/**
	 * Was the registrar's order date at or after the marker (with an hour of slack)? The registrar's dates are in its own local
	 * time (seen in the sandbox: Europe/Amsterdam), so that zone is assumed.
	 *
	 * @param string $date   Order date, "Y-m-d H:i:s".
	 * @param int    $marker Marker time.
	 */
	private static function made_after( string $date, int $marker ): bool {
		try {
			$at = ( new \DateTimeImmutable( $date, new \DateTimeZone( 'Europe/Amsterdam' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return false;
		}

		return $at >= $marker - HOUR_IN_SECONDS;
	}

	/**
	 * Takes a registrar order as this service's order (staff, after checking at the registrar), or lets the order be placed again.
	 *
	 * @param Resource $r        Resource.
	 * @param int      $order_id Registrar order id to adopt, or 0 to clear the marker so the next run orders afresh.
	 * @return true|\WP_Error
	 */
	public static function resolve_marker( Resource $r, int $order_id ) {
		if ( '' !== $r->get( 'remote_id' ) ) {
			return new \WP_Error( 'has_order', 'This service already has a registrar order.' );
		}

		if ( 0 === $order_id ) {
			return $r->set( array( 'ssl_post_attempted' => null ) ) ? true : new \WP_Error( 'save', 'Could not save.' );
		}

		$detail = Clients::registrar()->request( 'GET', '/ssl/orders/' . $order_id );

		if ( is_wp_error( $detail ) ) {
			return new \WP_Error( 'registrar', 'The registrar order could not be read: ' . $detail->get_error_code() );
		}

		if ( strtolower( (string) ( $detail['common_name'] ?? '' ) ) !== strtolower( (string) $r->get( 'ssl_name' ) ) || in_array( (string) ( $detail['status'] ?? '' ), self::DEAD, true ) ) {
			return new \WP_Error( 'mismatch', 'That order is not a live order for this name.' );
		}

		$steps = array_values( array_unique( array_merge( (array) $r->get( 'steps' ), array( 'order' ) ) ) );

		return $r->set( array( 'remote_id' => (string) $order_id, 'steps' => $steps, 'ordered_at' => time(), 'ssl_post_attempted' => null, 'ssl_csr' => (string) ( $detail['csr'] ?? $r->get( 'ssl_csr' ) ) ) ) ? true : new \WP_Error( 'save', 'Could not save.' );
	}

	/**
	 * The wait for the authority: a soft alert after the usual time of the product, a hard stop that fails (and so cancels the order).
	 *
	 * @param Resource $r     Resource.
	 * @param string   $level dv, ov or ev.
	 */
	private static function waiting( Resource $r, string $level ): StepResult {
		$since = (int) ( $r->get( 'ssl_requested_at' ) ?: time() );
		$until = $since + SslSettings::wait_days() * DAY_IN_SECONDS;
		$soft  = 'dv' === $level ? 6 * HOUR_IN_SECONDS : 5 * DAY_IN_SECONDS;

		if ( time() >= $until ) {
			return StepResult::fail( 'The certificate was not issued within ' . SslSettings::wait_days() . ' days.' );
		}

		if ( time() >= $since + $soft && ! $r->get( 'ssl_slow_alerted' ) ) {
			Alerts::admin( $r, 'The certificate authority has not issued this certificate within the usual time. Validation is probably not complete.' );
			$r->set( array( 'ssl_slow_alerted' => true ) );
		}

		return StepResult::wait( time() - $since < 2 * HOUR_IN_SECONDS ? 600 : 3600, 'Waiting for the certificate authority.', $until );
	}

	/**
	 * Reads the issued certificate from the order, checks it against the key and stores it.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $detail The registrar order.
	 * @param string|null          $csr    The request the certificate must belong to (default: the stored one).
	 */
	public static function store_certificate( Resource $r, array $detail, ?string $csr = null ): StepResult {
		$pem  = trim( (string) ( $detail['certificate'] ?? '' ) );
		$info = '' !== $pem ? Csr::certificate( $pem ) : new \WP_Error( 'ssl_cert', 'empty' );

		if ( is_wp_error( $info ) ) {
			// The order says active a moment before the text is there: look again.
			return StepResult::retry( 300, 'The certificate text is not available yet.' );
		}

		$wanted = Csr::parse( null !== $csr ? $csr : (string) $r->get( 'ssl_csr' ) );

		if ( is_array( $wanted ) && trim( $wanted['public'] ) !== trim( $info['public'] ) ) {
			Alerts::admin( $r, 'The issued certificate does not belong to the key of the request. It was not stored.' );

			return StepResult::fail( 'The issued certificate does not match the request.' );
		}

		$expires = (string) ( $detail['expiration_date'] ?? '' );

		return StepResult::done(
			array(
				'ssl_cert'         => $pem,
				'ssl_chain'        => Csr::fullchain( '', (string) ( $detail['intermediate_certificate'] ?? '' ) ),
				'ssl_root'         => trim( (string) ( $detail['root_certificate'] ?? '' ) ),
				'ssl_serial'       => $info['serial'],
				'ssl_valid_from'   => gmdate( 'Y-m-d H:i:s', $info['from'] ),
				'ssl_valid_to'     => gmdate( 'Y-m-d H:i:s', $info['to'] ),
				'ssl_order_expires' => '' !== $expires ? $expires : gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
				'ssl_issued_at'    => time(),
				'ssl_status'       => 'ACT',
			)
		);
	}

	/**
	 * Maps a registrar error to a result. Low balance waits (and tells staff once), a temporary failure retries, anything else
	 * fails with the registrar's text for staff only.
	 *
	 * @param Resource  $r     Resource.
	 * @param \WP_Error $error Error.
	 * @param string[]  $steps Steps.
	 */
	public static function error( Resource $r, \WP_Error $error, array $steps ): StepResult {
		$code = $error->get_error_code();

		if ( 'op_920' === $code ) {
			if ( ! $r->get( 'balance_alerted' ) ) {
				Alerts::admin( $r, 'Registrar balance is too low.' );
			}

			return StepResult::retry( 3600, 'Waiting for registrar account funds.', array( 'steps' => $steps, 'balance_alerted' => true ) );
		}

		if ( 'contact_incomplete' === $code ) {
			if ( ! $r->get( 'billing_alerted' ) ) {
				Alerts::admin( $r, 'The order has incomplete billing details; add them to the order.' );
			}

			return StepResult::retry( 3600, 'Waiting for complete billing details.', array( 'steps' => $steps, 'billing_alerted' => true ) );
		}

		$data = $error->get_error_data();
		$desc = is_array( $data ) ? mb_substr( sanitize_text_field( (string) ( $data['desc'] ?? '' ) ), 0, 200 ) : '';

		if ( LiveClient::is_transient( $error ) ) {
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			return StepResult::retry( 3600, 'The registrar is temporarily unavailable (' . $code . ( $status > 0 ? ', HTTP ' . $status : '' ) . ').', array( 'steps' => $steps ) );
		}

		$why = $code . ( '' !== $desc ? ' (' . $desc . ')' : '' );
		Alerts::admin( $r, 'Registrar rejected the certificate order: ' . $why );

		return StepResult::fail( 'This certificate order needs staff attention. Registrar answer: ' . $why, array( 'steps' => $steps ) );
	}
}
