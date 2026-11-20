<?php
/**
 * The order section of the Overview: the services of a new order with their process lines, until they are ready.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Plugin;
use BeltoftServices\ThankYou;

defined( 'ABSPATH' ) || exit;

/**
 * Which orders the section shows (orders()): the order named by ?order= (the hand-off screen's address; only the customer's own),
 * and the customer's most recent paid orders (last RECENT_DAYS days, at most LIMIT) that are still being set up or have an
 * unfinished domain step, also without the address argument and after a reload. An order that is finished (ready, or failed) and whose
 * closing line the customer has seen leaves on the next load; seeing is kept per order in user meta, never in a cookie.
 *
 * The rows are the ones of the thank-you page (ThankYou::row()) and the live data is the owner route of ThankYou: no second
 * progress system. The order key is never in the markup.
 */
final class OverviewOrder {

	public const SEEN        = 'beltoft_services_orders_seen';
	public const LIMIT       = 3;
	public const RECENT_DAYS = 14;
	public const KEEP_SEEN   = 50;

	/**
	 * What was built in this request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $built = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_order_progress_served', array( __CLASS__, 'served' ), 10, 2 );
	}

	/**
	 * Forgets what was built (tests).
	 */
	public static function reset(): void {
		self::$built = null;
	}

	/**
	 * The route served a finished order to its customer: the closing line is shown, so it counts as seen.
	 *
	 * @param mixed $order Order.
	 * @param mixed $rows  Rows served.
	 */
	public static function served( $order, $rows ): void {
		if ( $order instanceof \WC_Order && is_array( $rows ) && $rows && 'running' !== self::state( $rows ) && is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) {
			self::mark_seen( $order->get_id() );
		}
	}

	/**
	 * Where the order stands: running (something is still being set up), waiting (everything that can be set up is, one step waits
	 * for the customer or a service is paused), ready, mixed (some ready, some failed) or failed.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows of ThankYou::row().
	 */
	public static function state( array $rows ): string {
		$ready   = 0;
		$waiting = 0;

		// The services are made right after payment; until the first one exists the order is still being set up.
		if ( ! $rows ) {
			return 'running';
		}

		$failed = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['progress']['active'] ) ) {
				return 'running';
			}

			if ( 'failed' === ( $row['group'] ?? '' ) ) {
				++$failed;
			} elseif ( self::waits( $row ) ) {
				++$waiting;
			} elseif ( ! empty( $row['ready'] ) ) {
				++$ready;
			} else {
				return 'running';
			}
		}

		if ( 0 === $failed ) {
			return $waiting > 0 ? 'waiting' : 'ready';
		}

		return $ready + $waiting > 0 ? 'mixed' : 'failed';
	}

	/**
	 * Does the service wait on the customer or stand still on purpose (a step the customer does, a paused or removed service)?
	 * It is not being set up, so the order does not keep running for it.
	 *
	 * @param array<string, mixed> $row Row of ThankYou::row().
	 */
	public static function waits( array $row ): bool {
		return ! empty( $row['waits'] ) || in_array( (string) ( $row['group'] ?? '' ), array( 'paused', 'removed' ), true );
	}

	/**
	 * The closing line of a finished order, or ''.
	 *
	 * @param string $state  State.
	 * @param int    $failed Failed services.
	 * @param string $step   For the waiting state: the step that waits on the customer, in lower case ('' when a service is paused).
	 */
	public static function closing( string $state, int $failed, string $step = '' ): string {
		switch ( $state ) {
			case 'waiting':
				/* translators: %s: what the customer does, such as "point your domain here". */
				return '' !== $step ? sprintf( __( 'Your order is set up. One step waits for you: %s.', 'beltoft-services' ), $step ) : __( 'Your order is set up. One service is paused.', 'beltoft-services' );
			case 'ready':
				return __( 'Everything in your order is ready', 'beltoft-services' );
			case 'mixed':
				return 1 === $failed
					? __( 'Some of your order is ready. One service needs attention.', 'beltoft-services' )
					/* translators: %d: number of services. */
					: sprintf( __( 'Some of your order is ready. %d services need attention.', 'beltoft-services' ), $failed );
			case 'failed':
				return __( 'Your order needs attention.', 'beltoft-services' );
			default:
				return '';
		}
	}

	/**
	 * The orders the section shows for the logged-in customer, each as a view for the template.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function views(): array {
		if ( null !== self::$built ) {
			return self::$built;
		}

		self::$built = array();
		$user        = get_current_user_id();

		if ( $user < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return self::$built;
		}

		$forced = isset( $_GET[ \BeltoftServices\OrderHandoff::ARG ] ) ? absint( wp_unslash( $_GET[ \BeltoftServices\OrderHandoff::ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which of the customer's own orders to show.
		$seen   = self::seen();
		$orders = array();

		if ( $forced > 0 ) {
			$order = wc_get_order( $forced );

			// Only a paid order that has services, or whose services are just being made, is followed: a deleted, on-hold or
			// cancelled order, or one that never got a service, shows nothing (the page would wait for services that never come).
			if ( $order instanceof \WC_Order && (int) $order->get_customer_id() === $user && $order->has_status( array( 'processing', 'completed' ) ) && self::has_services( $order ) ) {
				$orders[ $forced ] = $order;
			} else {
				// Not followed: it must not come back through the recent orders as if the address named it.
				$forced = 0;
			}
		}

		$recent = wc_get_orders(
			array(
				'customer_id'  => $user,
				'status'       => array( 'processing', 'completed' ),
				'limit'        => 30,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . ( time() - self::RECENT_DAYS * DAY_IN_SECONDS ),
			)
		);

		foreach ( $recent as $order ) {
			// No cap here: the orders that do not belong in the section (add-ons, renewals, seen ones) are skipped below, and the
			// loop stops after LIMIT orders that do. A cap before the filter would hide an older order still being set up.
			if ( $order instanceof \WC_Order && ! isset( $orders[ $order->get_id() ] ) ) {
				$orders[ $order->get_id() ] = $order;
			}
		}

		foreach ( $orders as $id => $order ) {
			$implicit = $id !== $forced;
			$resources = ThankYou::resources( $order );

			// An add-on or a renewal belongs to its service's page, not here (unless the address names it).
			if ( $implicit && ( ! $resources || OrderTarget::license_for( $order ) ) ) {
				continue;
			}

			$rows  = array_map( array( ThankYou::class, 'row' ), $resources );
			$state = self::state( $rows );

			if ( $implicit && 'running' !== $state && isset( $seen[ $id ] ) ) {
				continue;
			}

			if ( count( self::$built ) >= self::LIMIT ) {
				break;
			}

			ob_start();
			/** This action is documented in src/ThankYou.php. */
			do_action( 'beltoft_services_thankyou_render', $id );
			$blocks = (string) ob_get_clean();

			$failed = count( array_filter( $rows, static fn( $row ) => 'failed' === $row['group'] ) );
			$step   = '';

			foreach ( $rows as $row ) {
				if ( ! empty( $row['waits'] ) ) {
					$step = (string) $row['waits'];
					break;
				}
			}

			self::$built[ $id ] = array(
				'id'       => $id,
				'number'   => (string) $order->get_order_number(),
				'view_url' => $order->get_view_order_url(),
				'rows'     => $rows,
				'state'    => $state,
				'failed'   => $failed,
				'step'     => $step,
				'closing'  => self::closing( $state, $failed, $step ),
				'blocks'   => $blocks,
				'poll_url' => rest_url( Plugin::REST_NAMESPACE . '/my/orders/' . $id . '/progress' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'licenses' => array_map( static fn( $r ) => (int) ( $r->license()['id'] ?? 0 ), $resources ),
			);
		}

		// What is shown finished now has been seen: the next load leaves it out.
		foreach ( self::$built as $id => $view ) {
			if ( 'running' !== $view['state'] ) {
				self::mark_seen( $id );
			}
		}

		return self::$built;
	}

	/**
	 * Does the order have services, or is it a paid order with service lines whose services are still being made (paid within the
	 * last hour)? An older order with service lines and no service has had them deleted or never got them.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function has_services( \WC_Order $order ): bool {
		if ( ThankYou::resources( $order ) ) {
			return true;
		}

		$paid = $order->get_date_paid() ?: $order->get_date_created();

		return \BeltoftServices\OrderHandoff::has_service_lines( $order ) && $paid && $paid->getTimestamp() > time() - HOUR_IN_SECONDS;
	}

	/**
	 * The licence ids the section shows (the "needs attention" list does not repeat them).
	 *
	 * @return int[]
	 */
	public static function licenses(): array {
		$out = array();

		foreach ( self::views() as $view ) {
			$out = array_merge( $out, $view['licenses'] );
		}

		return $out;
	}

	/**
	 * The section's markup, or ''.
	 */
	public static function html(): string {
		$views = self::views();

		if ( ! $views ) {
			return '';
		}

		wp_enqueue_style( 'beltoft-services-thankyou', plugins_url( 'assets/thankyou.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/thankyou.css' ) );
		wp_enqueue_script( 'beltoft-services-progress', plugins_url( 'assets/progress.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/progress.js' ), true );
		wp_enqueue_script( 'beltoft-services-thankyou', plugins_url( 'assets/thankyou.js', BELTOFT_SERVICES_FILE ), array( 'beltoft-services-progress', \BeltoftServices\Status::HANDLE ), \BeltoftServices\Assets::version( 'assets/thankyou.js' ), true );

		ob_start();
		wc_get_template( 'overview/new-order.php', array( 'views' => $views ), 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * The ids of the orders whose closing line the customer has seen.
	 *
	 * @return array<int, int> Order id => time.
	 */
	private static function seen(): array {
		$seen = get_user_meta( get_current_user_id(), self::SEEN, true );

		return is_array( $seen ) ? array_map( 'intval', $seen ) : array();
	}

	/**
	 * Notes that the customer has seen the closing line of an order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function mark_seen( int $order_id ): void {
		$seen = self::seen();

		if ( isset( $seen[ $order_id ] ) ) {
			return;
		}

		$seen[ $order_id ] = time();
		arsort( $seen );
		update_user_meta( get_current_user_id(), self::SEEN, array_slice( $seen, 0, self::KEEP_SEEN, true ) );
	}
}
