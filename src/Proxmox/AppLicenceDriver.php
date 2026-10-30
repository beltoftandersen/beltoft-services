<?php
/** The service driver of a paid app's tier subscription. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per paid app on a server. An entitlement only: nothing is built anywhere.
 * What it does is keep the link to the server and tell a later vendor driver when the licence changes
 * (see AppLicence for the event and how a vendor plugs in).
 */
final class AppLicenceDriver implements Driver {
	public const ID = 'proxmox-app';

	/** The server wait ends after this many seconds without the server's license appearing in the order. */
	private const WAIT = HOUR_IN_SECONDS;

	/** Use the server retention default unless the product sets its own period. */
	public static function default_retention( $days, $product_id ) {
		$driver = \BeltoftServices\Product::driver_for( (int) $product_id );
		return $driver && self::ID === $driver->id() ? ProxmoxDriver::DEFAULT_RETENTION_DAYS : $days;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'App licence', 'beltoft-services' );
	}

	public function kind(): string {
		return 'applic';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: app licences used, 2: app licences allowed. */
		return sprintf( _n( '%1$d of %2$d app licence', '%1$d of %2$d app licences', $max, 'beltoft-services' ), $used, $max );
	}

	/**
	 * The product names the app (`app`), a variation names its tier (`tier`, `tier_label`). More fields, for
	 * limits a vendor driver reads (domains, seats), come from the filter beltoft_services_app_licence_plan_fields.
	 */
	public function plan_fields(): array {
		$fields = array(
			'app'        => array( 'text', __( 'App id', 'beltoft-services' ), __( 'The id of the app this licence belongs to (its folder in apps/).', 'beltoft-services' ), '', false ),
			'tier'       => array( 'text', __( 'Tier key', 'beltoft-services' ), __( 'A short key for the tier, for example "pro". Handed to the app install.', 'beltoft-services' ), '', true ),
			'tier_label' => array( 'text', __( 'Tier name', 'beltoft-services' ), __( 'What the customer sees. Empty uses the variation name.', 'beltoft-services' ), '', true ),
		);

		/**
		 * Filters the plan fields of an app licence product: more fields a vendor driver needs per tier.
		 *
		 * @param array<string, array<int, mixed>> $fields key => [type, label, description, default, variation(bool)].
		 */
		$more = apply_filters( 'beltoft_services_app_licence_plan_fields', array() );

		return array_merge( is_array( $more ) ? array_diff_key( $more, $fields ) : array(), $fields );
	}

	public function provision( Resource $r ): StepResult {
		$license = $r->license();
		$id      = (int) $r->get( 'server_license' );

		if ( $id < 1 && (int) $r->get( 'server_order_id' ) > 0 ) {
			$id = (int) DiskMeta::server_in_order( (int) $r->get( 'server_order_id' ), (int) ( $license['user_id'] ?? 0 ) );

			if ( $id > 0 && ! $r->set( array( 'server_license' => $id ) ) ) {
				return StepResult::retry( 60, 'The server link could not be stored.' );
			}
		}

		$owner  = $id > 0 ? Licenses::find( $id ) : null;
		$server = $owner ? Resource::for_license( $id ) : null;
		$app    = (string) ( ( (array) $r->get( 'plan' ) )['app'] ?? '' );
		$why    = '';

		if ( $owner && (int) ( $owner['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) {
			$why = 'The server belongs to another customer.';
		} elseif ( $server && SshKeyField::DRIVER !== $server->get( 'driver' ) ) {
			$why = 'The linked service is not a server.';
		} elseif ( $server && in_array( $server->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
			$why = 'The server failed or was deleted.';
		} elseif ( $server && ( '' === $app || (string) $server->get( 'app_id' ) !== $app ) ) {
			$why = 'The server was built without this app.';
		}

		if ( '' === $why && null === $server ) {
			$until = (int) $r->get( 'server_wait_until' );

			if ( $until < 1 ) {
				$until = time() + self::WAIT;

				if ( ! $r->set( array( 'server_wait_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The server wait deadline could not be stored.' );
				}
			}

			if ( time() < $until ) {
				return StepResult::wait( 60, 'Waiting for the server.', $until );
			}

			$why = 'The server for this app licence was not found in the order.';
		}

		if ( '' !== $why ) {
			$order = (int) ( $license['order_id'] ?? 0 );
			DiskCascade::cancel_undelivered( $r, $why . ' The app licence was not delivered.' );

			return StepResult::fail( $why . ' App licence not delivered: review and refund order #' . $order . '; the subscription was cancelled.' );
		}

		return StepResult::done( array( 'steps' => array( 'ready' ), 'applic_tier' => (string) ( ( (array) $r->get( 'plan' ) )['tier'] ?? '' ) ) );
	}

	public function pause( Resource $r ): StepResult {
		// A licence on hold is "paused"; one that expired or was revoked or cancelled has ended.
		AppLicence::announce( $r, Licenses::STATUS_SUSPENDED === Licenses::effective_status( (array) $r->license() ) ? 'paused' : 'cancelled' );

		return StepResult::done();
	}

	public function resume( Resource $r ): StepResult {
		AppLicence::announce( $r, 'resumed' );

		return StepResult::done();
	}

	public function update_plan( Resource $r ): StepResult {
		$tier = (string) ( ( (array) $r->get( 'plan' ) )['tier'] ?? '' );

		if ( $tier !== (string) $r->get( 'applic_tier' ) ) {
			if ( ! $r->set( array( 'applic_tier' => $tier ) ) ) {
				return StepResult::retry( 60, 'The tier could not be stored.' );
			}

			AppLicence::announce( $r, 'tier_changed' );
		}

		return StepResult::done();
	}

	public function delete( Resource $r ): StepResult {
		AppLicence::announce( $r, 'cancelled' );

		return StepResult::done();
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This app licence action is not available.' );
	}

	public function render_account( Resource $r ): void {
		$server = AppLicence::server_of( $r );
		$tier   = AppLicence::tier_label( $r );
		$url    = $server ? DiskGuide::card_url( $server, '', AppTab::SLUG ) : '';

		echo '<div class="beltoft-services-applic"><p>';
		/* translators: %s: tier name */
		echo esc_html( sprintf( __( 'App licence: %s', 'beltoft-services' ), $tier ) );
		echo '</p>';

		if ( '' !== $url ) {
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open your server', 'beltoft-services' ) . '</a></p>';
		}

		echo '</div>';
	}

	public function render_admin( Resource $r ): void {
		$server = AppLicence::server_of( $r );
		$plan   = (array) $r->get( 'plan' );
		$rows   = array(
			__( 'App', 'beltoft-services' )             => (string) ( $plan['app'] ?? '' ),
			__( 'Tier', 'beltoft-services' )            => (string) ( $plan['tier'] ?? '' ),
			__( 'Licence state', 'beltoft-services' )   => AppLicence::state( $r ),
			__( 'Server license', 'beltoft-services' )  => (string) $r->get( 'server_license' ),
			__( 'Server resource', 'beltoft-services' ) => $server ? (string) $server->id() : '',
		);

		echo '<h3>' . esc_html__( 'App licence', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
