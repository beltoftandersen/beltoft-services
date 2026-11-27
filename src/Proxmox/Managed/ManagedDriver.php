<?php
/** Managed add-on driver: a paid upkeep service for one server or database. @package BeltoftServices */

namespace BeltoftServices\Proxmox\Managed;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Disk\DiskCascade;
use BeltoftServices\Proxmox\Disk\DiskGuide;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per managed server. An entitlement only: nothing is created in Proxmox. Our staff keeps the
 * server up to date, answers its alerts and checks its backups while the resource is ready and its licence is active
 * (ManagedMeta::counts). The admin is told when one starts, so the work is picked up.
 */
final class ManagedDriver implements Driver, \BeltoftServices\Drivers\UsageItems {

	use \BeltoftServices\Drivers\UsageDefaults;

	public const ID = 'proxmox-managed';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Managed', 'beltoft-services' );
	}

	public function kind(): string {
		return 'managed';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: Managed add-ons in use, 2: Managed add-ons allowed. */
		return sprintf( _n( '%1$d of %2$d Managed', '%1$d of %2$d Managed', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		return array();
	}

	public function provision( Resource $r ): StepResult {
		$license = $r->license();
		$server  = ManagedMeta::server_of( $r ); // First: a service bought with its server stores the server's licence here.
		$id      = (int) Resource::find( $r->id() )->get( 'server_license' );
		$owner   = $id > 0 ? \BeltoftLicenses\Licenses::find( $id ) : null;
		$why     = '';

		if ( null === $server || ! $owner ) {
			$why = 'The server for this managed service could not be found.';
		} elseif ( (int) ( $license['user_id'] ?? 0 ) < 1 || (int) ( $owner['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) {
			$why = 'The server belongs to another customer.';
		} elseif ( in_array( $server->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) ) {
			$why = 'The server failed or was deleted.';
		}

		if ( '' === $why && 'ready' !== $server->get( 'state' ) ) {
			$until = (int) $r->get( 'server_wait_until' );

			if ( $until < 1 ) {
				$until = time() + 6 * HOUR_IN_SECONDS;

				if ( ! $r->set( array( 'server_wait_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The server wait deadline could not be stored.' );
				}
			}

			if ( time() < $until ) {
				return StepResult::wait( 60, 'Waiting for the server.', $until );
			}

			$why = 'The server was not ready within six hours.';
		}

		if ( '' !== $why ) {
			DiskCascade::cancel_undelivered( $r, $why . ' The managed service was not delivered.' );

			return StepResult::fail( $why . ' Managed not delivered: review and refund order #' . (int) ( $license['order_id'] ?? 0 ) . '; the subscription was cancelled.' );
		}

		// Staff picks the work up from this mail: the service is delivered by people, not by a driver call.
		$what = ManagedMeta::is_database( $server ) ? 'database resource #' . $server->id() . ' (' . (string) ( (array) $server->get( 'plan' ) )['engine'] . ')' : 'server resource #' . $server->id();
		Alerts::admin( $r, 'Managed started for ' . $what . ' (licence #' . $id . '). Check the first update, the alerts and the backups.' );

		return StepResult::done( array( 'steps' => array( 'ready' ) ) );
	}

	public function pause( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function resume( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function update_plan( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function delete( Resource $r ): StepResult {
		return StepResult::done();
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This Managed action is not available.' );
	}

	public function render_account( Resource $r ): void {
		$server = ManagedMeta::server_of( $r );

		if ( $server && ManagedMeta::is_database( $server ) ) {
			echo '<p>' . esc_html__( 'Managed: we install updates, act on this database\'s alerts and check that the backups run.', 'beltoft-services' ) . '</p>';
			echo '<p><a href="' . esc_url( DiskGuide::card_url( $server ) ) . '">' . esc_html__( 'Open the database', 'beltoft-services' ) . '</a></p>';
			return;
		}

		echo '<p>' . esc_html__( 'Managed: we keep this server up to date, answer its alerts and check its backups.', 'beltoft-services' ) . '</p>';

		if ( $server ) {
			echo '<p><a href="' . esc_url( DiskGuide::card_url( $server ) ) . '">' . esc_html__( 'Open the server', 'beltoft-services' ) . '</a></p>';
		}
	}

	public function render_admin( Resource $r ): void {
		$server = ManagedMeta::server_of( $r );
		echo '<h3>' . esc_html__( 'Managed', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Server license', 'beltoft-services' ) . '</th><td>' . esc_html( (string) $r->get( 'server_license' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Server resource', 'beltoft-services' ) . '</th><td>' . esc_html( $server ? (string) $server->id() : '' ) . '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource              $r       Resource.
	 * @param \WC_Product_Variation $v       Variation.
	 * @param float                 $monthly Monthly price.
	 * @param float                 $premium Premium.
	 */
	public function usage_items( Resource $r, \WC_Product_Variation $v, float $monthly, float $premium ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- interface.
		return array( array( 'item' => 'managed', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => \BeltoftServices\Usage\Rates::hourly( $monthly, $premium ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_words(): array {
		return array( 'managed' => array( 'label' => __( 'Managed', 'beltoft-services' ), 'word' => __( 'Managed', 'beltoft-services' ) ) );
	}
}
