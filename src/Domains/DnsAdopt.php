<?php
/**
 * "Use Beltoft DNS": a domain registered here whose DNS is hosted elsewhere moves to our name servers once its records are here.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Three steps, each its own call of one route: start (our zone is made and filled with the records we can find), confirm (the
 * customer imported a zone file, or acknowledged that records we could not find are missing) and switch (the name servers at the
 * registrar become ours, only when our zone answers on both of them). The switch reuses Actions::switch_to_ours.
 */
final class DnsAdopt {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The route.
	 */
	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/dns-adopt',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'id'   => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
				),
			)
		);
	}

	/**
	 * Can this domain move its DNS here: registered here, ready, its DNS hosted elsewhere (kept at a transfer, or custom name servers)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function eligible( Resource $r ): bool {
		return 'domain' === $r->get( 'driver' ) && 'ready' === $r->get( 'state' ) && ( (bool) $r->get( 'dns_external' ) || 'custom' === $r->get( 'ns_mode' ) ) && ! $r->get( 'records_off' );
	}

	/**
	 * Is our zone made for this move and not yet switched?
	 *
	 * @param Resource $r Resource.
	 */
	public static function active( Resource $r ): bool {
		return is_array( $r->get( 'dns_adopt' ) ) && self::eligible( $r );
	}

	/**
	 * What the domain page shows, or null when the move is not offered.
	 *
	 * @param Resource $r Resource.
	 * @return array{started: bool, imported: bool, reviewed: bool, copied: int, error: string}|null
	 */
	public static function view( Resource $r ): ?array {
		if ( ! self::eligible( $r ) ) {
			return null;
		}

		$state = $r->get( 'dns_adopt' );
		$state = is_array( $state ) ? $state : array();

		return array(
			'started'  => (bool) $state,
			'imported' => (int) $r->get( 'dns_imported_at' ) >= (int) ( $state['since'] ?? PHP_INT_MAX ),
			'reviewed' => (int) ( $state['reviewed_at'] ?? 0 ) > 0,
			'copied'   => (int) ( $state['copied'] ?? 0 ),
			'error'    => (string) $r->get( 'import_error' ),
		);
	}

	/**
	 * The texts of the answers.
	 *
	 * @param string $key Key.
	 */
	private static function text( string $key ): string {
		$texts = array(
			'started'  => __( 'We made a DNS zone for your domain and copied the records we could find. Check them in DNS records.', 'beltoft-services' ),
			'preparing' => __( 'Our name servers do not answer for your zone yet. Try again in a minute.', 'beltoft-services' ),
			'ack'      => __( 'Import a zone file, or tick the box that says records we could not find are missing until you add them.', 'beltoft-services' ),
			'confirmed' => __( 'Thank you. You can switch now.', 'beltoft-services' ),
			'order'    => __( 'Start with Use Beltoft DNS, then check your records.', 'beltoft-services' ),
			'done'     => __( 'Your domain now uses Beltoft DNS. You can switch back in Name servers.', 'beltoft-services' ),
			'retry'    => __( 'The name servers could not be changed just now. Try again in a minute.', 'beltoft-services' ),
			'gone'     => __( 'This is not available for this domain.', 'beltoft-services' ),
		);

		return $texts[ $key ];
	}

	/**
	 * An error response.
	 *
	 * @param string $code    Code.
	 * @param string $key     Text key.
	 * @param int    $status  Status.
	 */
	private static function fail( string $code, string $key, int $status ): \WP_REST_Response {
		return rest_convert_error_to_response( new \WP_Error( $code, self::text( $key ), array( 'status' => $status ) ) );
	}

	/**
	 * POST resources/<id>/dns-adopt with step start, confirm (and ack) or switch.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest( \WP_REST_Request $request ): \WP_REST_Response {
		$r = Routes::guard( $request, true, 10 );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$args = (array) ( $request->get_param( 'args' ) ?? array() ) + (array) $request->get_json_params();
		$step = (string) ( $args['step'] ?? '' );

		if ( ! self::eligible( $r ) || ! in_array( $step, array( 'start', 'confirm', 'switch' ), true ) ) {
			return self::fail( 'beltoft_services_forbidden', 'gone', 403 );
		}

		$view = self::view( $r );

		if ( 'start' === $step ) {
			return self::start( $r, $view );
		}

		if ( ! $view['started'] ) {
			return self::fail( 'beltoft_services_order', 'order', 409 );
		}

		return 'confirm' === $step ? self::confirm( $r, $view, ! empty( $args['ack'] ) && filter_var( $args['ack'], FILTER_VALIDATE_BOOLEAN ) ) : self::do_switch( $r, $view );
	}

	/**
	 * Makes our zone and fills it with the records we can find.
	 *
	 * @param Resource                $r    Resource.
	 * @param array<string, mixed>    $view View.
	 */
	private static function start( Resource $r, array $view ): \WP_REST_Response {
		if ( $view['started'] ) {
			return new \WP_REST_Response( array( 'status' => 'started', 'message' => self::text( 'started' ) ), 200 );
		}

		$zone = Zone::prepare( $r );

		if ( $zone->is_failed() ) {
			return self::fail( 'beltoft_services_zone', 'gone', 409 );
		}

		if ( ! $zone->is_done() ) {
			return self::fail( 'beltoft_services_preparing', 'preparing', 409 );
		}

		$copy = TransferDns::copy_records( $r );

		if ( ! $copy->is_done() ) {
			return self::fail( 'beltoft_services_preparing', 'preparing', 409 );
		}

		$r->set( array_merge( $zone->meta(), $copy->meta(), array( 'dns_adopt' => array( 'since' => time(), 'copied' => (int) ( $copy->meta()['records_copied'] ?? 0 ) ) ) ) );

		return new \WP_REST_Response( array( 'status' => 'started', 'message' => self::text( 'started' ) ), 200 );
	}

	/**
	 * The customer says the records are in: a zone file was imported, or they acknowledge that some are missing.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $view View.
	 * @param bool                 $ack  Acknowledged.
	 */
	private static function confirm( Resource $r, array $view, bool $ack ): \WP_REST_Response {
		if ( ! $view['imported'] && ! $ack ) {
			return self::fail( 'beltoft_services_ack_needed', 'ack', 422 );
		}

		$state                = (array) $r->get( 'dns_adopt' );
		$state['reviewed_at'] = time();
		$state['path']        = $view['imported'] ? 'imported' : 'acknowledged';
		$r->set( array( 'dns_adopt' => $state ) );

		return new \WP_REST_Response( array( 'status' => 'confirmed', 'message' => self::text( 'confirmed' ) ), 200 );
	}

	/**
	 * Sets our name servers at the registrar, only when the records were confirmed and our zone answers on every name server.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $view View.
	 */
	private static function do_switch( Resource $r, array $view ): \WP_REST_Response {
		if ( ! $view['reviewed'] ) {
			return self::fail( 'beltoft_services_ack_needed', 'ack', 422 );
		}

		// The zone answers on both name servers before the registrar is touched.
		if ( ! Zone::prepare( $r )->is_done() ) {
			return self::fail( 'beltoft_services_preparing', 'preparing', 409 );
		}

		$done = Actions::switch_to_ours( $r );

		if ( ! $done->is_done() ) {
			return self::fail( 'beltoft_services_retry', 'retry', 503 );
		}

		$r->set( array_merge( $done->meta(), array( 'dns_external' => false, 'dns_adopt' => null, 'adopted_at' => time() ) ) );

		return new \WP_REST_Response( array( 'status' => 'done', 'message' => self::text( 'done' ) ), 200 );
	}
}
