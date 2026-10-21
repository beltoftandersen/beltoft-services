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
use BeltoftServices\Product;
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
	 * @param string $state Resource state.
	 */
	public static function group( string $state ): string {
		switch ( $state ) {
			case 'ready':
				return 'ready';
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
	 * @param string $state Resource state.
	 */
	public static function label( string $state ): string {
		$labels = array(
			'getting-ready' => __( 'Getting ready', 'beltoft-services' ),
			'ready'         => __( 'Ready', 'beltoft-services' ),
			'paused'        => __( 'Paused', 'beltoft-services' ),
			'removed'       => __( 'Removed', 'beltoft-services' ),
			'failed'        => __( 'Failed: contact us', 'beltoft-services' ),
		);

		return $labels[ self::group( $state ) ];
	}

	/**
	 * A sentence for the customer about a state. Fixed texts only: never the resource's
	 * last_error or anything else a driver reported.
	 *
	 * @param string $state Resource state.
	 */
	public static function message( string $state ): string {
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
	 * @param mixed $view    Display values (unused).
	 */
	public static function render( $license, $view = array() ): void {
		unset( $view );

		if ( ! is_array( $license ) || empty( $license['id'] ) || ! Product::is_service_license( $license ) ) {
			return;
		}

		$resource = Resource::for_license( (int) $license['id'] );

		if ( ! $resource ) {
			return;
		}

		$state = (string) $resource->get( 'state' );
		$group = self::group( $state );
		$owner = ! empty( $license['user_id'] ) && (int) $license['user_id'] === get_current_user_id();

		echo '<div class="beltoft-services-panel"';
		if ( $owner ) {
			printf(
				' data-resource="%d" data-state="%s" data-desired="%s"',
				(int) $resource->id(),
				esc_attr( $state ),
				esc_attr( (string) $resource->get( 'desired' ) )
			);
		}
		echo '>';

		printf(
			'<p class="beltoft-services-panel__head"><span class="beltoft-services-badge beltoft-services-badge--%1$s" data-badge>%2$s</span> <span class="beltoft-services-panel__message" data-message>%3$s</span></p>',
			esc_attr( $group ),
			esc_html( self::label( $state ) ),
			esc_html( self::message( $state ) )
		);

		if ( $owner ) {
			self::owner_part( $resource );
		}

		echo '</div>';
	}

	/**
	 * The driver's part, the action buttons and the notice line for script feedback.
	 *
	 * @param Resource $resource Resource.
	 */
	private static function owner_part( Resource $resource ): void {
		$driver = Registry::get( $resource->get( 'driver' ) );

		if ( ! $driver ) {
			return;
		}

		echo '<div class="beltoft-services-panel__driver">';
		$driver->render_account( $resource );
		echo '</div>';

		$actions = $resource->accepts_actions() ? $driver->customer_actions( $resource ) : array();

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

		echo '<p class="beltoft-services-panel__notice" data-notice role="status" aria-live="polite"></p>';
	}

	/**
	 * The script and stylesheet, only on the My Account licenses endpoint. Themes can turn the
	 * stylesheet off with the beltoft_services_account_css filter.
	 */
	public static function assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( Endpoint::slug() ) ) {
			return;
		}

		/**
		 * Whether to load the service panel's layout stylesheet on My Account.
		 *
		 * @param bool $load Load it, default true.
		 */
		if ( apply_filters( 'beltoft_services_account_css', true ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'assets/account.css', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION );
		}

		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/account.js', BELTOFT_SERVICES_FILE ), array(), BELTOFT_SERVICES_VERSION, true );
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
				),
			)
		);
	}
}
