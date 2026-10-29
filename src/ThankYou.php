<?php
/**
 * What happens next on the order received page: the services of the order, live.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\DiskDriver;
use BeltoftServices\Proxmox\DiskGuide;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Proxmox\SnapshotDriver;
use BeltoftServices\Proxmox\SnapshotMeta;
use BeltoftServices\Proxmox\SshKeyField;

defined( 'ABSPATH' ) || exit;

/**
 * One "What happens next" area under the thank-you heading, printed once per order and only for
 * an order that is not failed or cancelled:
 *
 * - "Your services": a row per service the order created, with the progress component of
 *   Progress::build() while it is set up and "Ready" with a link to its card in My Account when it
 *   is (a server, a domain or a DNS card). An extra disk also says where to find it.
 * - the DNS hosting and transfer blocks of Domains\ThankYou, in the same area and style.
 *
 * The rows poll GET beltoft-services/v1/orders/<id>/progress?key=<order key>. The order key is the
 * credential (also for a guest): it is compared with hash_equals, the route is rate limited per
 * visitor address, and a reply holds only fixed texts, state and the progress payloads of that
 * order's services: never an address, a password or last_error.
 */
final class ThankYou {

	/**
	 * Requests per visitor address and minute to the order progress route, unless filtered: room for
	 * the page's polling every 5 seconds, a second tab and a reload.
	 */
	public const RATE_LIMIT = 40;

	/**
	 * Orders already printed in this request.
	 *
	 * @var array<int, bool>
	 */
	private static array $printed = array();

	/**
	 * Hooks. The DNS and transfer blocks of Domains\ThankYou print inside this area, so it owns the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'render_details' ), 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render' ), 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the order progress route.
	 */
	public static function routes(): void {
		register_rest_route(
			Api\Routes::NAMESPACE,
			'/orders/(?P<id>\d+)/progress',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'progress' ),
				// The order key is the credential; the callback checks it.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'  => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET orders/<id>/progress?key=: the progress of the order's services.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function progress( \WP_REST_Request $request ): \WP_REST_Response {
		/**
		 * Requests per visitor address and minute to the order progress route (0 turns it off).
		 *
		 * @param int $max Requests, 40 by default.
		 */
		$max = (int) apply_filters( 'beltoft_services_order_progress_rate_limit', self::RATE_LIMIT );

		if ( RateLimit::exceeded( RateLimit::visitor(), 'beltoft-services:order-progress', $max, MINUTE_IN_SECONDS ) ) {
			return self::reply( array( 'code' => 'beltoft_services_rate_limited', 'message' => __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ) ), 429 );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $request['id'] ) : null;
		$key   = (string) $request['key'];

		// The same answer for a missing order and a wrong key.
		if ( ! $order instanceof \WC_Order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return self::reply( array( 'code' => 'beltoft_services_forbidden', 'message' => __( 'This order is not available.', 'beltoft-services' ) ), 403 );
		}

		return self::reply( array( 'services' => array_map( array( __CLASS__, 'row' ), self::resources( $order ) ) ), 200 );
	}

	/**
	 * A reply that no cache keeps.
	 *
	 * @param array<string, mixed> $body   Body.
	 * @param int                  $status Status.
	 */
	private static function reply( array $body, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The service resources of an order (none for a failed or cancelled order).
	 *
	 * @param \WC_Order $order Order.
	 * @return Resource[]
	 */
	public static function resources( \WC_Order $order ): array {
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) || ! class_exists( Licenses::class ) ) {
			return array();
		}

		$out = array();

		foreach ( Licenses::where( 'order_id', $order->get_id() ) as $license ) {
			$resource = Product::is_service_license( $license ) ? Resource::for_license( (int) $license['id'] ) : null;

			if ( $resource && ! in_array( (string) $resource->get( 'state' ), array( 'deleted', 'deleting' ), true ) ) {
				$out[] = $resource;
			}
		}

		return $out;
	}

	/**
	 * What the page and the route say about one service. Fixed texts and state only.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function row( Resource $r ): array {
		$state   = (string) $r->get( 'state' );
		$group   = Account\Panel::group( $state, $r );
		$driver  = Registry::get( (string) $r->get( 'driver' ) );
		$kind    = $driver ? $driver->kind() : '';
		$license = $r->license();
		$name    = in_array( $kind, array( 'domain', 'dns' ), true ) && '' !== (string) $r->get( 'domain' )
			? (string) $r->get( 'domain' )
			: ServerName::display( $r, $license );

		$row = array(
			'id'       => $r->id(),
			'kind'     => $kind,
			'name'     => $name,
			'group'    => $group,
			'ready'    => 'ready' === $group,
			'label'    => Account\Panel::label( $state, $r ),
			'message'  => 'failed' === $group ? Account\Panel::message( $state, $r ) : '',
			'progress' => Progress::build( $r ),
			'url'      => DiskGuide::card_url( $r ),
		);

		if ( SnapshotDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['url']      = DiskGuide::server_url( $r );
			$row['snapshot'] = array(
				'slots'      => SnapshotMeta::slots_of( $r ),
				'server_url' => $row['url'],
			);
		}

		if ( DiskDriver::ID === (string) $r->get( 'driver' ) ) {
			$row['disk'] = array(
				'server_url' => DiskGuide::server_url( $r ),
				'guide_url'  => DiskGuide::guide_url( $r ),
			);
		}

		return $row;
	}

	/**
	 * The link text to a service's card.
	 *
	 * @param string $kind Driver kind.
	 */
	public static function open_label( string $kind ): string {
		$labels = array(
			'vm'     => __( 'Open your server', 'beltoft-services' ),
			'disk'   => __( 'Open your disk', 'beltoft-services' ),
			'snapshot' => __( 'Open your server', 'beltoft-services' ),
			'domain' => __( 'Open your domain', 'beltoft-services' ),
			'dns'    => __( 'Open your DNS hosting', 'beltoft-services' ),
		);

		return $labels[ $kind ] ?? __( 'Open in My Account', 'beltoft-services' );
	}

	/**
	 * Where "Go to your dashboard" leads: the page of the new server, else the first service's card.
	 *
	 * @param Resource[]                       $resources The order's services.
	 * @param array<int, array<string, mixed>> $rows      Their rows.
	 */
	private static function dashboard_url( array $resources, array $rows ): string {
		foreach ( $resources as $resource ) {
			$license = $resource->license();

			if ( $license && SshKeyField::DRIVER === (string) $resource->get( 'driver' ) ) {
				return Account\Servers::url( $license );
			}
		}

		return $rows ? (string) $rows[0]['url'] : '';
	}

	/**
	 * The order of the order received page, or null.
	 */
	private static function current_order(): ?\WC_Order {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return null;
		}

		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Script and style, only on an order received page with a service to show.
	 */
	public static function assets(): void {
		$order = self::current_order();

		if ( ! $order || ! self::resources( $order ) ) {
			return;
		}

		wp_enqueue_style( 'beltoft-services-thankyou', plugins_url( 'assets/thankyou.css', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/thankyou.css' ) );
		wp_enqueue_script( 'beltoft-services-progress', plugins_url( 'assets/progress.js', BELTOFT_SERVICES_FILE ), array(), Assets::version( 'assets/progress.js' ), true );
		wp_enqueue_script( 'beltoft-services-thankyou', plugins_url( 'assets/thankyou.js', BELTOFT_SERVICES_FILE ), array( 'beltoft-services-progress' ), Assets::version( 'assets/thankyou.js' ), true );
	}

	/**
	 * The order details hook: only on the order received page, not when an order is viewed later.
	 *
	 * @param mixed $order Order.
	 */
	public static function render_details( $order ): void {
		if ( $order instanceof \WC_Order && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			self::render( $order->get_id() );
		}
	}

	/**
	 * Prints the area once for an order.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function render( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || isset( self::$printed[ $order_id ] ) || $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			return;
		}

		$resources = self::resources( $order );
		$rows      = array_map( array( __CLASS__, 'row' ), $resources );

		// The DNS and transfer blocks print inside this area, in the same style.
		ob_start();
		Domains\ThankYou::render( $order_id );
		$domain_blocks = (string) ob_get_clean();

		if ( ! $rows && '' === trim( $domain_blocks ) ) {
			return;
		}

		self::$printed[ $order_id ] = true;

		$url = add_query_arg( 'key', rawurlencode( (string) $order->get_order_key() ), rest_url( Api\Routes::NAMESPACE . '/orders/' . $order_id . '/progress' ) );

		wc_get_template(
			'order/next.php',
			array(
				'rows'          => $rows,
				'domain_blocks' => $domain_blocks,
				'poll_url'      => $url,
				'dashboard_url' => self::dashboard_url( $resources, $rows ),
			),
			'beltoft-services/',
			BELTOFT_SERVICES_PATH . 'templates/'
		);
	}
}
