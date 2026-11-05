<?php
/**
 * The WordPress hosting driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One unprivileged container per account on a hosting node, cloned from a template, with the account's websites
 * inside, a database on the shared database server and routes on the edge. A thin driver: provisioning is
 * Provision, everything after it is Operations. Registered through the `beltoft_services_drivers` filter (see
 * add()). It does not start provisioning by itself: a product has to be set to this driver, and a node and
 * its settings must exist.
 *
 * The delete stops the container itself before it destroys it (StopsOnDelete), like the Proxmox driver.
 */
class HostingDriver implements Driver, StopsOnDelete {

	/**
	 * Driver id.
	 */
	public const ID = 'hosting';

	/**
	 * Adds the driver to the registry (the `beltoft_services_drivers` filter).
	 *
	 * @param mixed $drivers Drivers keyed by id.
	 * @return mixed
	 */
	public static function add( $drivers ) {
		if ( is_array( $drivers ) && ! isset( $drivers[ self::ID ] ) ) {
			$drivers[ self::ID ] = new self();
		}

		return $drivers;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'WordPress hosting', 'beltoft-services' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return 'hosting';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $used Used.
	 * @param int $max  Allowed.
	 */
	public function unit_label( int $used, int $max ): string {
		/* translators: 1: hosting accounts used, 2: hosting accounts allowed */
		return sprintf( _n( '%1$d of %2$d hosting account', '%1$d of %2$d hosting accounts', $max, 'beltoft-services' ), $used, $max );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan_fields(): array {
		return Plan::fields();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		$run = new Provision( $r );

		return $run->signal( $run->run() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function pause( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->pause() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * An account paused before its provisioning ended continues provisioning (the reconciler sends `resume`
	 * for it too).
	 *
	 * @param Resource $r Resource.
	 */
	public function resume( Resource $r ): StepResult {
		if ( ! in_array( 'ready', (array) $r->get( 'steps' ), true ) ) {
			return $this->provision( $r );
		}

		$op = new Operations( $r );

		return $op->signal( $op->resume() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function update_plan( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->update_plan() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		$op = new Operations( $r );

		return $op->signal( $op->delete() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function status( Resource $r ): ?array {
		return ( new Operations( $r ) )->status();
	}

	/**
	 * {@inheritDoc}
	 *
	 * No customer action yet: the account area comes with a later slice.
	 *
	 * @param Resource $r Resource.
	 */
	public function customer_actions( Resource $r ): array {
		unset( $r );

		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function act( Resource $r, string $action, array $args ): StepResult {
		unset( $r, $args );

		return StepResult::fail( 'Unknown hosting action "' . mb_substr( sanitize_key( $action ), 0, 40 ) . '"' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void {
		HostingAccount::render( $r );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void {
		$status = $this->status( $r );

		if ( null === $status ) {
			echo '<p>' . esc_html__( 'No hosting record yet.', 'beltoft-services' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><tbody>';

		foreach ( array(
			__( 'Node', 'beltoft-services' )      => (string) $status['node'],
			__( 'Container', 'beltoft-services' ) => null === $status['vmid'] ? '-' : (string) $status['vmid'],
			__( 'Address', 'beltoft-services' )   => (string) ( $status['ip'] ?? '-' ),
			__( 'SSH port', 'beltoft-services' )  => null === $status['ssh_port'] ? '-' : (string) $status['ssh_port'],
			__( 'Websites', 'beltoft-services' )  => (string) $status['sites'],
		) as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
