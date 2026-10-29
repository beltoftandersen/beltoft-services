<?php
/** Paid snapshot slots service driver. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per purchase of extra snapshot slots. An entitlement only: nothing is created
 * in Proxmox. The slots count while the resource is ready and its license is active (SnapshotMeta). When a pack
 * is paused, updated or deleted, the server's snapshots are checked against the slots it has left (D7).
 */
final class SnapshotDriver implements Driver {
	public const ID = 'proxmox-snapshots';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Snapshot slots', 'beltoft-services' );
	}

	public function kind(): string {
		return 'snapshot';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: snapshots used, 2: snapshots allowed. */
		return sprintf( _n( '%1$d of %2$d snapshot', '%1$d of %2$d snapshots', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		return array(
			'snapshot_slots' => array( 'number', __( 'Snapshot slots', 'beltoft-services' ), __( 'Extra snapshots the server may hold while this subscription is active.', 'beltoft-services' ), 2, true ),
			'snapshot_days'  => array( 'number', __( 'Days a snapshot is kept', 'beltoft-services' ), __( 'Leave empty to use the setting for paid slots.', 'beltoft-services' ), '', true ),
		);
	}

	public function provision( Resource $r ): StepResult {
		if ( null === SnapshotMeta::server_of( $r ) ) {
			return StepResult::fail( 'The server for these snapshot slots could not be found.' );
		}

		return StepResult::done( array( 'steps' => array( 'ready' ) ) );
	}

	public function pause( Resource $r ): StepResult {
		return $this->checked( $r );
	}

	public function resume( Resource $r ): StepResult {
		return $this->checked( $r );
	}

	public function update_plan( Resource $r ): StepResult {
		return $this->checked( $r );
	}

	public function delete( Resource $r ): StepResult {
		return $this->checked( $r );
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This snapshot action is not available.' );
	}

	public function render_account( Resource $r ): void {
		$server = SnapshotMeta::server_of( $r );
		$state  = array(
			'slots'      => SnapshotMeta::slots_of( $r ),
			'days'       => SnapshotMeta::days_of( $r ),
			'paused'     => 'paused' === $r->get( 'state' ),
			'server_url' => $server ? DiskGuide::card_url( $server ) : '',
		);
		( static function ( array $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed template data.
			include BELTOFT_SERVICES_PATH . 'templates/account/snapshot.php';
		} )( $state );
	}

	public function render_admin( Resource $r ): void {
		$server = SnapshotMeta::server_of( $r );
		$rows   = array(
			__( 'Server license', 'beltoft-services' ) => (string) $r->get( 'server_license' ),
			__( 'Snapshot slots', 'beltoft-services' ) => '+' . SnapshotMeta::slots_of( $r ),
			__( 'Server resource', 'beltoft-services' ) => $server ? (string) $server->id() : '',
		);
		echo '<h3>' . esc_html__( 'Snapshot slots', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Looks at the server's snapshots against the slots it has now. The pack being paused, updated or deleted is
	 * no longer counted (its state or license says so). A busy server lock is not an error: the hourly job
	 * repeats the check.
	 *
	 * @param Resource $r Pack resource.
	 */
	private function checked( Resource $r ): StepResult {
		SnapshotMeta::clamp_server( $r );

		return StepResult::done();
	}
}
