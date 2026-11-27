<?php
/** The customer's Check now button of the "Point your domain" box. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Api\Routes;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * POST resources/<id>/dns-check: looks at the public DNS of this server's own domain now, for the signed-in owner of the licence
 * (Routes::guard). The look and its hand-over to the install are AppOwnDomain::check_now(); the limits (one a minute, ten an hour per
 * server) are kept there. The answer is the fresh box, so the page shows the result at once. assets/app-dns-check.js is the button.
 */
final class AppDnsCheck {

	public const HANDLE = 'beltoft-services-app-dns-check';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/resources/(?P<id>\d+)/dns-check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
			)
		);
	}

	public static function check( \WP_REST_Request $request ): \WP_REST_Response {
		$resource = Routes::guard( $request, true, Routes::RATE_LIMIT );

		if ( ! $resource instanceof Resource ) {
			return $resource;
		}

		$result = AppOwnDomain::check_now( $resource );
		$fresh  = Resource::find( $resource->id() ) ?: $resource;

		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$status = (int) ( $data['status'] ?? 409 );
			$view   = AppProgress::dns_view( $fresh );
			$reply  = new \WP_REST_Response(
				array(
					'code'      => 'beltoft_services_' . $result->get_error_code(),
					'message'   => 429 === $status ? (string) $view['next_text'] : $result->get_error_message(),
					'next'      => (int) ( $data['next'] ?? 0 ),
					'box'       => AppOwnDomain::waiting( $fresh ) ? AppProgress::dns_box( $fresh ) : '',
				),
				$status
			);
			$reply->header( 'Cache-Control', 'no-store' );
			if ( 429 === $status ) {
				$reply->header( 'Retry-After', (string) max( 1, (int) $data['next'] - time() ) );
			}

			return $reply;
		}

		$reply = new \WP_REST_Response(
			array(
				'verdict' => $result['verdict'],
				'found'   => 'live' === (string) $fresh->get( 'app_domain_dns' ),
				'next'    => AppOwnDomain::manual_next( $fresh ),
				'box'     => AppProgress::dns_box( $fresh ),
			),
			200
		);
		$reply->header( 'Cache-Control', 'no-store' );

		return $reply;
	}

	/**
	 * The script and its words, on the page of a server that has an app.
	 *
	 * @param string $parent Handle of the generic account script.
	 */
	public static function assets( string $parent ): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/app-dns-check.js', BELTOFT_SERVICES_FILE ), array( $parent, 'beltoft-services-status', \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/app-dns-check.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesDnsCheck',
			array(
				'strings' => array(
					'checking' => __( 'Checking…', 'beltoft-services' ),
					'working'  => __( 'Looking up your domain…', 'beltoft-services' ),
					'failed'   => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
				),
			)
		);
	}
}
