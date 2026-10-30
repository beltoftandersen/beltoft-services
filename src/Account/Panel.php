<?php
/**
 * The service panel on My Account → Licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Account\Endpoint;
use BeltoftServices\Api\Routes;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Product;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a service's state, the driver's part and the customer's action buttons to its license
 * on the beltoft-licenses account page (action beltoft_licenses_account_license). Nothing here
 * calls the service: the driver prints what it already knows, and a small script asks the
 * REST routes (Routes) for the state while it is changing and sends the actions.
 */
final class Panel {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'beltoft-services-account';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_account_license', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * The badge group of a state: getting-ready, ready, paused, removed or failed.
	 *
	 * @param string        $state Resource state.
	 * @param Resource|null $r     The resource: a server whose app is still being installed is still getting ready.
	 */
	public static function group( string $state, ?Resource $r = null ): string {
		switch ( $state ) {
			case 'ready':
				return $r && 'installing' === \BeltoftServices\Proxmox\AppProgress::state( $r ) ? 'getting-ready' : 'ready';
			case 'failed':
				return 'failed';
			case 'paused':
				return 'paused';
			case 'deleting':
			case 'deleted':
				return 'removed';
			case 'requested':
			case 'provisioning':
				return 'getting-ready';
			default:
				// An unknown state, or none (metadata missing): something is wrong.
				return 'failed';
		}
	}

	/**
	 * The badge text of a state.
	 *
	 * @param string        $state Resource state.
	 * @param Resource|null $r     The resource, when its hold decides the text.
	 */
	public static function label( string $state, ?Resource $r = null ): string {
		if ( $r && $r->on_hold() && 'removed' !== self::group( $state ) ) {
			return __( 'Suspended', 'beltoft-services' );
		}

		if ( $r && 'failed' === $state && Emails::delay_applies( $r ) ) {
			return __( 'Delayed', 'beltoft-services' );
		}

		$app = $r ? \BeltoftServices\Proxmox\AppProgress::line( $r ) : '';

		if ( '' !== $app ) {
			return $app;
		}

		$labels = array(
			'getting-ready' => __( 'Getting ready', 'beltoft-services' ),
			'ready'         => __( 'Ready', 'beltoft-services' ),
			'paused'        => __( 'Paused', 'beltoft-services' ),
			'removed'       => __( 'Removed', 'beltoft-services' ),
			'failed'        => __( 'Failed: contact us', 'beltoft-services' ),
		);

		return $labels[ self::group( $state, $r ) ];
	}

	/**
	 * A sentence for the customer about a state. Fixed texts only: never the resource's
	 * last_error or anything else a driver reported.
	 *
	 * @param string        $state Resource state.
	 * @param Resource|null $r     The resource, when its hold or a delayed setup decides the text.
	 */
	public static function message( string $state, ?Resource $r = null ): string {
		if ( $r && $r->on_hold() && 'removed' !== self::group( $state ) ) {
			return __( 'This server is suspended. Contact support.', 'beltoft-services' );
		}

		// A server that could not be set up: a delay, never what went wrong.
		if ( $r && 'failed' === $state && Emails::delay_applies( $r ) ) {
			return __( "There's a delay setting up your service. We're on it.", 'beltoft-services' );
		}

		$app = $r ? \BeltoftServices\Proxmox\AppProgress::message( $r ) : '';

		if ( '' !== $app ) {
			return $app;
		}

		$messages = array(
			'getting-ready' => __( 'Your service is being set up. This page updates by itself.', 'beltoft-services' ),
			'ready'         => __( 'Your service is ready.', 'beltoft-services' ),
			'paused'        => __( 'Your service is paused.', 'beltoft-services' ),
			'removed'       => __( 'This service has been removed.', 'beltoft-services' ),
			'failed'        => __( 'Something went wrong with your service. Please contact us and we will sort it out.', 'beltoft-services' ),
		);

		return $messages[ self::group( $state ) ];
	}

	/**
	 * Prints the panel inside a service license. The driver's part, the actions and the live
	 * updates are only for the license's own user; a license matched by email only shows its
	 * state.
	 *
	 * @param mixed $license License row.
	 * @param mixed $view    Display values of the license (the title of a server page).
	 */
	public static function render( $license, $view = array() ): void {
		if ( ! is_array( $license ) || empty( $license['id'] ) || ! Product::is_service_license( $license ) ) {
			return;
		}

		$page = Servers::page() ?? Domains::page() ?? ServiceArea::page();
		$page = $page && (int) $page['license']['id'] === (int) $license['id'] ? $page : null;

		// The server page already loaded the resource: one lookup, not two.
		$resource = null !== $page && ( $page['resource'] ?? null ) instanceof Resource ? $page['resource'] : Resource::for_license( (int) $license['id'] );

		if ( ! $resource ) {
			return;
		}

		$state = (string) $resource->get( 'state' );
		$group = self::group( $state, $resource );
		$owner = ! empty( $license['user_id'] ) && (int) $license['user_id'] === get_current_user_id();

		echo '<div class="beltoft-services-panel' . ( null !== $page ? ' beltoft-services-panel--page' : '' ) . '" id="beltoft-service-' . (int) $resource->id() . '"';
		if ( $owner ) {
			printf(
				' data-resource="%d" data-state="%s" data-desired="%s"%s',
				(int) $resource->id(),
				esc_attr( $state ),
				esc_attr( (string) $resource->get( 'desired' ) ),
				// The status route refuses a paused, ended or held service: do not ask on page load (the panel
				// still polls after one of its own actions).
				$resource->shows_status() ? '' : ' data-idle="1"'
			);
		}
		echo '>';

		/**
		 * Lets a driver print the head of a server page (its name, state and the buttons for what the
		 * customer does most) in place of the plain state line. It prints and returns true; it must keep the
		 * [data-badge] and [data-message] elements that the status poll fills in.
		 *
		 * @param bool                 $handled  Whether a listener printed the head.
		 * @param Resource             $resource Resource.
		 * @param array<string, mixed> $license  License row.
		 * @param array<string, mixed> $view     Display values of the license.
		 * @param array<string, mixed> $page     The page being printed: license and requested tab.
		 */
		$handled = $owner && null !== $page && (bool) apply_filters( 'beltoft_services_account_page_head', false, $resource, $license, is_array( $view ) ? $view : array(), $page );

		if ( ! $handled ) {
			printf(
				'<p class="beltoft-services-panel__head"><span class="beltoft-services-badge beltoft-services-badge--%1$s" data-badge>%2$s</span> <span class="beltoft-services-panel__message" data-message>%3$s</span></p>',
				esc_attr( $group ),
				esc_html( self::label( $state, $resource ) ),
				esc_html( self::message( $state, $resource ) )
			);
		}

		if ( $owner ) {
			// On a server page the line for script feedback sits under the head, where the buttons are.
			if ( null !== $page ) {
				echo '<p class="beltoft-services-panel__notice" data-notice role="status" aria-live="polite"></p>';
			}

			Progress::render( Progress::build( $resource ) );
			self::owner_part( $resource, null !== $page );
		}

		echo '</div>';
	}

	/**
	 * The driver's part, the action buttons and the notice line for script feedback.
	 *
	 * @param Resource $resource Resource.
	 * @param bool     $page     Whether this is a server page (its notice line is printed under the head).
	 */
	private static function owner_part( Resource $resource, bool $page = false ): void {
		$driver = Registry::get( $resource->get( 'driver' ) );

		if ( ! $driver ) {
			return;
		}

		echo '<div class="beltoft-services-panel__driver">';
		$driver->render_account( $resource );
		echo '</div>';

		$actions = $resource->accepts_actions() ? $driver->customer_actions( $resource ) : array();

		/**
		 * The actions that get a generic button. The generic script posts no arguments, so a driver
		 * removes the actions that need some (a typed confirmation, a key) and draws their forms in
		 * render_account() itself.
		 *
		 * @param array<string, string> $actions  Action name => label.
		 * @param Resource              $resource Resource.
		 * @param string                $context  card (a license card) or page (a server page, which prints the power buttons itself).
		 */
		$buttons = apply_filters( 'beltoft_services_account_buttons', $actions, $resource, $page ? 'page' : 'card' );
		$actions = is_array( $buttons ) ? array_intersect_key( $buttons, $actions ) : $actions;

		if ( $actions ) {
			echo '<p class="beltoft-services-panel__actions">';
			foreach ( $actions as $name => $label ) {
				printf(
					'<button type="button" class="button woocommerce-button beltoft-services-action" data-action="%1$s">%2$s</button> ',
					esc_attr( (string) $name ),
					esc_html( (string) $label )
				);
			}
			echo '</p>';
		}

		if ( ! $page ) {
			echo '<p class="beltoft-services-panel__notice" data-notice role="status" aria-live="polite"></p>';
		}
	}

	/**
	 * The layout stylesheet. Themes can turn it off with the beltoft_services_account_css filter.
	 */
	public static function style(): void {
		/**
		 * Whether to load the service panel's layout stylesheet on My Account.
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_account_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/account.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/account.css' ) );
		}
	}

	/**
	 * The script and stylesheet, only on the My Account licenses endpoint and its extra tabs. Themes can turn the
	 * stylesheet off with the beltoft_services_account_css filter.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! array_filter( array_map( 'is_wc_endpoint_url', Endpoint::slugs() ) ) ) {
			return;
		}

		self::style();

		$active = '';

		foreach ( Endpoint::slugs() as $slug ) {
			if ( is_wc_endpoint_url( $slug ) ) {
				$active = (string) $slug;
				break;
			}
		}

		/**
		 * Lets a driver add its own script or style to the panel page. Load only what the endpoint needs.
		 *
		 * @param string $handle   Handle of the generic panel script, which a driver script depends on.
		 * @param string $endpoint The My Account endpoint being shown (servers, domains, licenses).
		 */
		do_action( 'beltoft_services_account_assets', self::HANDLE, $active );

		wp_enqueue_script( 'beltoft-services-progress', plugins_url( 'assets/progress.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/progress.js' ), true );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/account.js', BELTOFT_SERVICES_FILE ), array( 'beltoft-services-progress' ), \BeltoftServices\Assets::version( 'assets/account.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesAccount',
			array(
				'root'     => esc_url_raw( rest_url( Routes::NAMESPACE . '/resources/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				// WordPress's own rest-nonce action hands out a fresh nonce to a logged-in user.
				'nonceUrl' => esc_url_raw( add_query_arg( 'action', 'rest-nonce', admin_url( 'admin-ajax.php' ) ) ),
				'interval' => 10000,
				'strings'  => array(
					'queued'  => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
					'refused' => __( 'This is not available right now.', 'beltoft-services' ),
					'tooMany' => __( 'Too many requests. Please wait a minute and try again.', 'beltoft-services' ),
					'failed'  => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'expired' => __( 'Your session has expired. Please reload the page.', 'beltoft-services' ),
					'working' => __( 'Working…', 'beltoft-services' ),
				),
			)
		);
	}
}
