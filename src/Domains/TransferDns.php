<?php
/**
 * Where the DNS of a transferred domain lives: the customer's choice, the copy of the records and the wait for the name servers.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Alerts;
use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A domain that is moved here must not lose its website or its email. The customer chooses at the order:
 *
 * - first: our zone is made and filled from the current name servers, the transfer is sent only once the domain uses our
 *   name servers (and our zone answers), and it is sent with them.
 * - after: the zone is made and filled, the transfer is sent at once with the current name servers, and the name servers
 *   are switched to ours when the transfer is done.
 * - keep: the name servers stay as they are and no zone is made.
 *
 * An order with no choice (placed before this existed) keeps the DNS where it is.
 */
final class TransferDns {

	public const FIRST = 'first';
	public const AFTER = 'after';
	public const KEEP  = 'keep';

	/**
	 * The choices a customer can make.
	 */
	public const CHOICES = array( self::FIRST, self::AFTER, self::KEEP );

	/**
	 * Seconds the order waits for the name servers before it gives up (as a transfer the registry does not complete).
	 */
	public const GIVE_UP = 14 * DAY_IN_SECONDS;

	/**
	 * Seconds between two customer checks.
	 */
	public const CHECK_EVERY = MINUTE_IN_SECONDS;

	/**
	 * Name servers of registrars that may switch their DNS off when a domain leaves them. A default for the filter
	 * beltoft_services_transfer_dns_registrar_domains, not a rule.
	 */
	private const REGISTRAR_DNS = array(
		'domaincontrol.com',
		'godaddy.com',
		'worldnic.com',
		'one.com',
		'simply.com',
		'registrar-servers.com',
		'namecheap.com',
		'porkbun.com',
		'name.com',
		'ui-dns.com',
		'ui-dns.de',
		'ui-dns.org',
		'ui-dns.biz',
		'squarespacedns.com',
		'googledomains.com',
		'gandi.net',
		'hover.com',
	);

	/**
	 * The record fields kept from a read zone.
	 */
	private const ROW_KEYS = array( 'name', 'type', 'ttl', 'value', 'priority', 'weight', 'port', 'flag', 'tag' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'beltoft_services_progress_wait', array( __CLASS__, 'progress_wait' ), 10, 4 );
	}

	/**
	 * The routes: the recommendation for the order form, and the customer's "Check now".
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/domains/transfer-dns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_recommend' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'domain' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/transfer-registry-check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_registry_check' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
			)
		);
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/transfer-dns-check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_check' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
			)
		);
	}

	/**
	 * The choice stored for a transfer; none (an older order) keeps the DNS where it is.
	 *
	 * @param Resource $r Resource.
	 */
	public static function choice( Resource $r ): string {
		$choice = (string) $r->get( 'transfer_dns' );

		return in_array( $choice, self::CHOICES, true ) ? $choice : self::KEEP;
	}

	/**
	 * What the transfer does with the DNS: none (not a transfer), served (our DNS already serves the domain for this customer, so
	 * nothing changes), or the customer's choice.
	 *
	 * @param Resource $r Resource.
	 */
	public static function plan( Resource $r ): string {
		if ( 'domain' !== $r->get( 'driver' ) || 'transfer' !== $r->get( 'mode' ) ) {
			return 'none';
		}

		if ( array() === DomainDriver::stored_nameservers( $r ) && ! $r->get( 'zone_claimed' ) && Zone::served_for( strtolower( (string) $r->get( 'domain' ) ), (int) ( $r->license()['user_id'] ?? 0 ) ) ) {
			return 'served';
		}

		return self::choice( $r );
	}

	/**
	 * Is this a transfer that copies the DNS (first or after)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function copies( Resource $r ): bool {
		return in_array( self::plan( $r ), array( self::FIRST, self::AFTER ), true );
	}

	/**
	 * Registrable domain of a host name, or ''.
	 *
	 * @param string $host Host name.
	 */
	private static function apex( string $host ): string {
		return (string) PublicSuffix::registrable( strtolower( rtrim( trim( $host ), '.' ) ) );
	}

	/**
	 * The registrar of a domain as its RDAP record names it: name and url, or null. A test or a site can answer with the filter
	 * beltoft_services_transfer_registrar (an array, or false for none); otherwise RDAP is asked once a day per domain.
	 *
	 * @param string $domain Domain.
	 * @return array{name: string, url: string}|null
	 */
	public static function registrar( string $domain ): ?array {
		/**
		 * Replaces the RDAP lookup of the current registrar.
		 *
		 * @param array|false|null $found  Array with name and url, false for none, null to ask RDAP.
		 * @param string           $domain Domain.
		 */
		$found = apply_filters( 'beltoft_services_transfer_registrar', null, $domain );

		if ( is_array( $found ) ) {
			return array( 'name' => (string) ( $found['name'] ?? '' ), 'url' => (string) ( $found['url'] ?? '' ) );
		}

		if ( false === $found ) {
			return null;
		}

		$key    = 'beltoft_services_rdap_' . md5( strtolower( $domain ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached ? array( 'name' => (string) ( $cached['name'] ?? '' ), 'url' => (string) ( $cached['url'] ?? '' ) ) : null;
		}

		$reply = wp_remote_get(
			'https://rdap.org/domain/' . rawurlencode( strtolower( $domain ) ),
			array(
				'timeout'     => 4,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/rdap+json' ),
			)
		);
		$data  = is_wp_error( $reply ) || 200 !== (int) wp_remote_retrieve_response_code( $reply ) ? null : json_decode( (string) wp_remote_retrieve_body( $reply ), true );
		$found = is_array( $data ) ? self::registrar_from_rdap( $data ) : null;

		set_transient( $key, $found ? $found : array(), $found ? DAY_IN_SECONDS : HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * The registrar as far as it is already known (the filter or the stored RDAP answer). Never asks the network, so a page can use it.
	 *
	 * @param string $domain Domain.
	 * @return array{name: string, url: string}|null
	 */
	public static function cached_registrar( string $domain ): ?array {
		/** This filter is documented in TransferDns::registrar(). */
		$found = apply_filters( 'beltoft_services_transfer_registrar', null, $domain );

		if ( is_array( $found ) ) {
			return array( 'name' => (string) ( $found['name'] ?? '' ), 'url' => (string) ( $found['url'] ?? '' ) );
		}

		$cached = false === $found ? false : get_transient( 'beltoft_services_rdap_' . md5( strtolower( $domain ) ) );

		return is_array( $cached ) && $cached ? array( 'name' => (string) ( $cached['name'] ?? '' ), 'url' => (string) ( $cached['url'] ?? '' ) ) : null;
	}

	/**
	 * The registrar entity of an RDAP domain record.
	 *
	 * @param array<string, mixed> $data RDAP record.
	 * @return array{name: string, url: string}|null
	 */
	public static function registrar_from_rdap( array $data ): ?array {
		foreach ( (array) ( $data['entities'] ?? array() ) as $entity ) {
			if ( ! is_array( $entity ) || ! in_array( 'registrar', (array) ( $entity['roles'] ?? array() ), true ) ) {
				continue;
			}

			$name = '';
			$url  = '';

			foreach ( (array) ( $entity['vcardArray'][1] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || ! isset( $item[0], $item[3] ) || ! is_string( $item[3] ) ) {
					continue;
				}

				if ( 'fn' === $item[0] ) {
					$name = $item[3];
				} elseif ( 'url' === $item[0] && '' === $url ) {
					$url = $item[3];
				}
			}

			if ( '' === $url ) {
				foreach ( (array) ( $entity['links'] ?? array() ) as $link ) {
					if ( is_array( $link ) && ! empty( $link['href'] ) && is_string( $link['href'] ) && 'about' === ( $link['rel'] ?? '' ) ) {
						$url = $link['href'];
						break;
					}
				}
			}

			if ( '' !== $name || '' !== $url ) {
				return array( 'name' => $name, 'url' => $url );
			}
		}

		return null;
	}

	/**
	 * Do the current name servers look like the current registrar's own DNS, which may stop when the domain leaves them?
	 *
	 * @param string[] $hosts  Name server host names.
	 * @param string   $domain The domain.
	 */
	public static function at_risk( array $hosts, string $domain ): bool {
		/**
		 * Registrable domains of name servers that belong to a registrar (their DNS may stop when a domain leaves).
		 *
		 * @param string[] $domains Default list.
		 */
		$known     = array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'beltoft_services_transfer_dns_registrar_domains', self::REGISTRAR_DNS ) ) );
		$registrar = $hosts ? self::registrar( $domain ) : null;
		$reg_apex  = $registrar && '' !== $registrar['url'] ? self::apex( (string) wp_parse_url( false !== strpos( $registrar['url'], '//' ) ? $registrar['url'] : '//' . $registrar['url'], PHP_URL_HOST ) ) : '';
		$reg_name  = $registrar ? (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $registrar['name'] ) ) : '';
		$risk      = false;

		foreach ( $hosts as $host ) {
			$apex = self::apex( (string) $host );

			if ( '' === $apex ) {
				continue;
			}

			$label = (string) strtok( $apex, '.' );

			if ( in_array( $apex, $known, true ) || ( '' !== $reg_apex && $apex === $reg_apex ) || ( strlen( $label ) >= 4 && '' !== $reg_name && str_starts_with( $reg_name, $label ) ) ) {
				$risk = true;
				break;
			}
		}

		/**
		 * Whether the current name servers are the current registrar's own, so the customer is steered to move the DNS first.
		 *
		 * @param bool       $risk      The answer of the default rule.
		 * @param string[]   $hosts     Name server host names.
		 * @param string     $domain    Domain.
		 * @param array|null $registrar Name and url from RDAP, or null.
		 */
		return (bool) apply_filters( 'beltoft_services_transfer_dns_at_risk', $risk, $hosts, $domain, $registrar );
	}

	/**
	 * What the order form shows for a domain: the name servers it uses now, whether they look at risk, and the choice to preselect.
	 *
	 * @param string $domain  Domain.
	 * @param int    $user_id Customer (0 for a visitor).
	 * @return array{served: bool, hosts: string[], at_risk: bool|null, choice: string, ours: string[]}
	 */
	public static function recommend( string $domain, int $user_id = 0 ): array {
		$domain = strtolower( $domain );
		$ours   = Zone::nameservers();

		if ( Zone::served_for( $domain, $user_id ) ) {
			return array( 'served' => true, 'hosts' => array(), 'at_risk' => null, 'choice' => self::KEEP, 'ours' => $ours );
		}

		$found = Delegation::lookup( $domain );
		$hosts = array();

		foreach ( (array) ( $found['ns'] ?? array() ) as $host ) {
			$host = strtolower( rtrim( trim( (string) $host ), '.' ) );
			if ( '' !== $host ) {
				$hosts[ $host ] = $host;
			}
		}

		$hosts = array_values( $hosts );

		// Nothing that can be read: the safe choice is to move the DNS first.
		if ( 'ok' !== (string) ( $found['status'] ?? '' ) || ! $hosts ) {
			return array( 'served' => false, 'hosts' => array(), 'at_risk' => null, 'choice' => self::FIRST, 'ours' => $ours );
		}

		$risk = self::at_risk( $hosts, $domain );

		return array( 'served' => false, 'hosts' => $hosts, 'at_risk' => $risk, 'choice' => $risk ? self::FIRST : self::KEEP, 'ours' => $ours );
	}

	/**
	 * GET domains/transfer-dns: the recommendation, for the order form. The same request checks as the cart route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_recommend( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WC-Nonce' ), 'wc_store_api' ) ) {
			return new \WP_Error( 'bad_nonce', __( 'Refresh the page and try again.', 'beltoft-services' ), array( 'status' => 403 ) );
		}

		if ( RateLimit::exceeded( RateLimit::visitor(), 'transfer-dns', (int) apply_filters( 'beltoft_services_domain_cart_limit', 20 ) ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please wait a minute.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		$domain = Search::normalise( (string) $request['domain'] );

		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$response = new \WP_REST_Response( self::recommend( $domain, get_current_user_id() ), 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Is the domain delegated to our name servers, at the resolver or at the parent zone?
	 *
	 * @param string $domain Domain.
	 */
	public static function delegated_to_us( string $domain ): bool {
		if ( 'ours' === Delegation::check( $domain ) ) {
			return true;
		}

		$found = Delegation::lookup( $domain );

		if ( 'ok' !== (string) ( $found['status'] ?? '' ) ) {
			return false;
		}

		$hosts = array_values( array_unique( array_filter( array_map( static fn( $host ) => strtolower( rtrim( trim( (string) $host ), '.' ) ), (array) ( $found['ns'] ?? array() ) ) ) ) );
		$ours  = Zone::nameservers();

		sort( $hosts, SORT_STRING );
		sort( $ours, SORT_STRING );

		return count( $ours ) >= 2 && $hosts === $ours;
	}

	/**
	 * Fills our new zone with the records read from the current name servers. A record that cannot be added is left out and said
	 * (import_error, the way a DNS hosting import says it). Nothing here fails the order.
	 *
	 * @param Resource $r Resource.
	 */
	public static function copy_records( Resource $r ): StepResult {
		$name  = (string) $r->get( 'domain' );
		$found = DnsClaimJobs::proof( 'delegation', array( $name ) );
		$rows  = array();

		if ( 'ok' === ( $found['status'] ?? '' ) ) {
			$scan = DnsClaimJobs::proof( 'scan', array( $name, $found ) );
			$rows = array_values( (array) ( $scan['records'] ?? array() ) );
		}

		$meta = array( 'import_error' => '' );
		$good = array();
		$bad  = 0;

		foreach ( $rows as $row ) {
			$row = is_array( $row ) ? array_intersect_key( $row, array_flip( self::ROW_KEYS ) ) : array();

			if ( is_wp_error( Records::validate( $name, array( $row ) ) ) ) {
				++$bad;
				continue;
			}

			$good[] = $row;
		}

		if ( ! $rows ) {
			$meta['import_error'] = 'no records could be read from the current name servers';
		} else {
			$checked = Records::validate( $name, $good );

			if ( is_wp_error( $checked ) ) {
				$meta['import_error'] = $checked->get_error_message();
				$good                 = array();
			} elseif ( $bad > 0 ) {
				/* translators: %d: number of records. */
				$meta['import_error'] = sprintf( _n( '%d record could not be read in a form we can add', '%d records could not be read in a form we can add', $bad, 'beltoft-services' ), $bad );
			}
		}

		if ( $good ) {
			$copied = Records::replace( $name, $good );

			if ( is_wp_error( $copied ) ) {
				return StepResult::retry( 'zone_busy' === $copied->get_error_code() ? 30 : 3600, 'The DNS records could not be copied.' );
			}
		}

		return StepResult::done( $meta + array( 'records_copied' => count( $good ) ) );
	}

	/**
	 * The wait of the "first" choice: done once the domain uses our name servers and our zone answers on all of them.
	 *
	 * @param Resource $r Resource.
	 */
	public static function wait_for_delegation( Resource $r ): StepResult {
		$name  = (string) $r->get( 'domain' );
		$since = (int) ( $r->get( 'dns_wait_since' ) ? $r->get( 'dns_wait_since' ) : time() );

		if ( time() - $since > self::GIVE_UP ) {
			return StepResult::fail( 'The domain did not move to our name servers within 14 days, so the transfer was not sent.' );
		}

		if ( self::delegated_to_us( $name ) ) {
			// Our zone must answer on every name server before the transfer is sent.
			$ready = Zone::prepare( $r );

			if ( ! $ready->is_done() ) {
				return $ready;
			}

			return StepResult::done( array( 'transfer_dns_state' => 'ours', 'transfer_dns_checked' => time() ) );
		}

		$delay = time() - $since < DAY_IN_SECONDS ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;

		return StepResult::wait( $delay, 'Waiting until the domain uses our name servers. The transfer is sent after that.', $since + self::GIVE_UP, array( 'dns_wait_since' => $since, 'transfer_dns_state' => 'waiting' ) );
	}

	/**
	 * The order gave up or was refused: the zone made for it goes, but only when it was made for this order, the domain never
	 * became ours and the name servers do not point at it.
	 *
	 * @param Resource $r Resource.
	 */
	public static function release( Resource $r ): void {
		if ( ! self::copies( $r ) || ! $r->get( 'zone_claimed' ) || 'ACT' === strtoupper( (string) $r->get( 'registrar_status' ) ) || in_array( 'active', (array) $r->get( 'steps' ), true ) ) {
			return;
		}

		if ( self::delegated_to_us( (string) $r->get( 'domain' ) ) ) {
			Alerts::admin( $r, 'A transfer failed but the domain uses our name servers, so its DNS zone was kept. Review it by hand.' );
			return;
		}

		$removed = Zone::remove( $r );

		if ( $removed->is_done() ) {
			$r->set( array( 'zone_claimed' => false, 'zone_ready' => false, 'transfer_zone_removed' => time() ) );
			return;
		}

		Alerts::admin( $r, 'A transfer failed and its DNS zone could not be removed yet: ' . $removed->error() );
	}

	/**
	 * Does a cancelled transfer have a zone that the domain's name servers point at? Then the zone must stay.
	 *
	 * @param Resource $r Resource.
	 */
	public static function must_keep_zone( Resource $r ): bool {
		return 'transfer' === $r->get( 'mode' ) && 'domain' === $r->get( 'driver' ) && (bool) $r->get( 'zone_claimed' ) && 'ACT' !== strtoupper( (string) $r->get( 'registrar_status' ) ) && self::delegated_to_us( (string) $r->get( 'domain' ) );
	}

	/**
	 * What the account page and the admin panel show about a transfer that copies the DNS, or null.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public static function view( Resource $r ): ?array {
		if ( ! self::copies( $r ) ) {
			return null;
		}

		$choice = self::choice( $r );
		$steps  = (array) $r->get( 'steps' );
		$state  = (string) $r->get( 'state' );
		$made   = (bool) $r->get( 'zone_claimed' ) && in_array( 'zone_prepared', $steps, true );
		// First: the zone answers once the domain uses it. After: once the name servers were switched.
		$in_use = self::FIRST === $choice ? in_array( 'ns_ours', $steps, true ) || 'ours' === (string) $r->get( 'transfer_dns_state' ) : in_array( 'dns_switch', $steps, true );

		return array(
			'choice'  => $choice,
			'current' => DomainDriver::stored_nameservers( $r ),
			'ours'    => Zone::nameservers(),
			'made'    => $made,
			'in_use'  => $in_use,
			'registry' => self::with_registry( $r ),
			'waiting' => self::FIRST === $choice && ! $in_use && ! in_array( $state, array( 'failed', 'deleted' ), true ),
			'editable' => $made && 'ready' !== $state && ! in_array( $state, array( 'failed', 'deleted' ), true ),
			'checked' => (int) $r->get( 'transfer_dns_checked' ),
			'since'   => (int) $r->get( 'dns_wait_since' ),
		);
	}

	/**
	 * POST resources/<id>/transfer-dns-check: the customer asks whether the name servers have moved. One check a minute.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_check( \WP_REST_Request $request ): \WP_REST_Response {
		$r = Routes::guard( $request, false, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$view = self::view( $r );

		if ( $view && self::FIRST === $view['choice'] && $view['in_use'] ) {
			// A page that was open while the order moved on: say what is happening instead of refusing.
			return new \WP_REST_Response( array( 'status' => 'ours', 'message' => __( 'Your domain already uses our name servers. The transfer is with the registry now.', 'beltoft-services' ) ), 200 );
		}

		if ( $view && self::FIRST === $view['choice'] && ! $view['waiting'] ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_not_waiting', __( 'This transfer is no longer waiting for the name servers.', 'beltoft-services' ), array( 'status' => 409 ) ) );
		}

		if ( ! $view || ! $view['waiting'] ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_forbidden', __( 'This service is not available.', 'beltoft-services' ), array( 'status' => 403 ) ) );
		}

		$result = self::check_now( $r );

		if ( is_wp_error( $result ) ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_rate_limited', $result->get_error_message(), array( 'status' => 429 ) ) );
		}

		return new \WP_REST_Response( array( 'status' => $result ), 200 );
	}

	/**
	 * What a domain transfer waits for at the current step, for the customer: empty when it waits for nothing in particular.
	 * Reads stored data only (never a network call).
	 *
	 * @param mixed    $wait    Earlier answer.
	 * @param Resource $r       Resource.
	 * @param string   $driver  Driver id.
	 * @param string   $current Key of the current step.
	 * @return array<string, mixed>
	 */
	public static function progress_wait( $wait, $r, $driver, $current ): array {
		if ( ! $r instanceof Resource || 'domain' !== $driver || 'transfer' !== (string) $r->get( 'mode' ) ) {
			return (array) $wait;
		}

		$base = array( 'eta' => __( 'A transfer usually takes a few days.', 'beltoft-services' ) );

		$done = (array) $r->get( 'steps' );

		if ( 'order' === $current && self::FIRST === self::plan( $r ) && in_array( 'dns_copy', $done, true ) && ! in_array( 'ns_ours', $done, true ) ) {
			$link = array();
			$page = \BeltoftServices\Account\Domains::url( $r->license(), 'nameservers' );

			if ( '' !== $page ) {
				$link = array( 'url' => $page, 'label' => __( 'Open the instructions', 'beltoft-services' ) );
			}

			return $base + array(
				'step_label' => __( 'Set our name servers at your current registrar', 'beltoft-services' ),
				'text'       => __( 'We are waiting for you. Set our name servers at your current registrar, then we send the transfer.', 'beltoft-services' ),
				'leave'      => __( 'You can leave this site. We check your name servers on our own, and the same progress is in your account.', 'beltoft-services' ),
				'note'       => __( 'Your action is needed: set these name servers at your current registrar. It can take a few hours after you change them, and we check automatically. Your website and email keep working.', 'beltoft-services' ),
				'hosts'      => Zone::nameservers(),
				'link'       => $link,
			);
		}

		if ( 'active' === $current ) {
			$page      = \BeltoftServices\Account\Domains::url( $r->license(), 'nameservers' );
			$registrar = self::cached_registrar( (string) $r->get( 'domain' ) );
			$who       = $registrar && '' !== $registrar['name'] ? $registrar['name'] : '';
			$note      = '' === $who
				? __( 'Your current registrar can take up to 5 days to release the domain. Approve the transfer at your current registrar, or in the email they send, to finish sooner. Your website and email keep working.', 'beltoft-services' )
				/* translators: %s: the current registrar's name. */
				: sprintf( __( '%1$s can take up to 5 days to release the domain. Approve the transfer at %1$s, or in the email they send, to finish sooner. Your website and email keep working.', 'beltoft-services' ), $who );

			return $base + array(
				'step_label' => '',
				'text'       => __( 'The transfer is with the registry. This can take up to 5 days.', 'beltoft-services' ),
				'leave'      => '',
				'note'       => $note,
				'hosts'      => array(),
				'link'       => '' === $page ? array() : array( 'url' => $page, 'label' => __( 'Check the transfer now', 'beltoft-services' ) ),
			);
		}

		return $base;
	}


	/**
	 * Is a transfer order with the registry, not yet accepted?
	 *
	 * @param Resource $r Resource.
	 */
	public static function with_registry( Resource $r ): bool {
		$steps = (array) $r->get( 'steps' );

		return 'domain' === $r->get( 'driver' ) && 'transfer' === $r->get( 'mode' ) && in_array( 'order', $steps, true ) && ! in_array( 'active', $steps, true ) && ! in_array( (string) $r->get( 'state' ), array( 'failed', 'deleted', 'ready' ), true );
	}

	/**
	 * POST resources/<id>/transfer-registry-check: asks the registry now whether the transfer is done, through the order's own job.
	 * One check a minute.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest_registry_check( \WP_REST_Request $request ): \WP_REST_Response {
		$r = Routes::guard( $request, false, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		if ( ! self::with_registry( $r ) ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_not_waiting', __( 'This transfer is not waiting for the registry.', 'beltoft-services' ), array( 'status' => 409 ) ) );
		}

		if ( (int) $r->get( 'registry_checked' ) > time() - self::CHECK_EVERY ) {
			return rest_convert_error_to_response( new \WP_Error( 'beltoft_services_rate_limited', __( 'Check again in a minute.', 'beltoft-services' ), array( 'status' => 429 ) ) );
		}

		$r->set( array( 'registry_checked' => time(), 'next_attempt_at' => time() ) );
		Jobs::run( $r->id() );

		$fresh = Resource::find( $r->id() );
		$done  = $fresh && in_array( 'active', (array) $fresh->get( 'steps' ), true );

		return new \WP_REST_Response(
			array(
				'status'  => $done ? 'done' : 'waiting',
				'message' => $done
					? __( 'The transfer is done. We are setting up the DNS now.', 'beltoft-services' )
					: __( 'The registry has not finished the transfer yet. Your current registrar can take up to 5 days.', 'beltoft-services' ),
			),
			200
		);
	}

	/**
	 * Looks at the delegation now. When the domain uses our name servers the order's next run is brought forward.
	 *
	 * @param Resource $r Resource.
	 * @return string|\WP_Error ours or other, or rate_limited.
	 */
	public static function check_now( Resource $r ) {
		if ( (int) $r->get( 'transfer_dns_checked' ) > time() - self::CHECK_EVERY ) {
			return new \WP_Error( 'rate_limited', __( 'Check again in a minute.', 'beltoft-services' ) );
		}

		$ours = self::delegated_to_us( (string) $r->get( 'domain' ) );
		$r->set( array( 'transfer_dns_checked' => time(), 'transfer_dns_state' => $ours ? 'ours' : 'waiting' ) );

		if ( $ours ) {
			$r->set( array( 'next_attempt_at' => time() ) );
			Jobs::enqueue( $r->id(), 0 );
		}

		return $ours ? 'ours' : 'other';
	}

	/**
	 * What the order waits for, for the admin panel, or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function waiting_reason( Resource $r ): string {
		$view = self::view( $r );

		if ( ! $view || ! $view['waiting'] ) {
			return '';
		}

		return 'Waiting until the domain uses our name servers' . ( $view['since'] > 0 ? ' (since ' . wp_date( 'Y-m-d H:i', $view['since'] ) . ')' : '' );
	}
}
