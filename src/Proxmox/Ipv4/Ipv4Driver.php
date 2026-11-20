<?php
/**
 * The IPv4 address add-on driver: one address from our own pool for a server or a database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Ipv4;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Db\DbAccess;
use BeltoftServices\Proxmox\Db\DbIp;
use BeltoftServices\Proxmox\Disk\DiskCascade;
use BeltoftServices\Proxmox\Disk\DiskGuide;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\NetConfig;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per address. Only the pool is used: this driver never calls a supplier.
 *
 * provision: wait for the parent (a server or a database), decide the role (Ipv4Meta), take the address from the pool, then
 * have the parent's VM made to match through a plugin action on the PARENT (Ipv4Server for servers, DbIp for databases),
 * and wait until it shows. delete: the parent is made to match without the address first (firewall first, one restart for
 * a main address), and the address goes back to our pool after that.
 */
final class Ipv4Driver implements Driver, \BeltoftServices\Drivers\UsageItems {

	use \BeltoftServices\Drivers\UsageDefaults;

	public const ID = 'proxmox-ipv4';

	/** Meta: when the action on the parent was queued, and how often. */
	private const QUEUED = 'ipv4_queued';

	/** Meta: when the removal was asked of the parent. */
	private const REMOVE_ASKED = 'ipv4_remove_asked';

	/** Seconds a change of the parent's VM may take before the add-on gives up waiting. */
	private const BUDGET = 3600;

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'IPv4 address', 'beltoft-services' );
	}

	public function kind(): string {
		return 'ipv4';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: IPv4 addresses in use, 2: IPv4 addresses allowed. */
		return sprintf( _n( '%1$d of %2$d IPv4 address', '%1$d of %2$d IPv4 addresses', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		$license = $r->license();
		$parent  = Ipv4Meta::parent_of( $r );
		$why     = '';

		if ( null === $parent ) {
			$why = 'The server or database for this IPv4 address could not be found.';
		} elseif ( (int) ( $license['user_id'] ?? 0 ) < 1 || (int) ( $parent->license()['user_id'] ?? 0 ) !== (int) ( $license['user_id'] ?? 0 ) ) {
			$why = 'The server or database belongs to another customer.';
		} elseif ( in_array( $parent->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) ) {
			$why = 'The server or database failed or was deleted.';
		} elseif ( true === $parent->get( 'linked' ) ) {
			$why = 'An adopted server cannot take an IPv4 address.';
		}

		if ( '' === $why && 'ready' !== $parent->get( 'state' ) ) {
			$until = (int) $r->get( 'server_wait_until' );

			if ( $until < 1 ) {
				$until = time() + 6 * HOUR_IN_SECONDS;

				if ( ! $r->set( array( 'server_wait_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The wait deadline could not be stored.' );
				}
			}

			if ( time() < $until ) {
				return StepResult::wait( 60, 'Waiting for the server or database.', $until );
			}

			$why = 'The server or database was not ready within six hours.';
		}

		if ( '' !== $why ) {
			return $this->undelivered( $r, $why );
		}

		// A line bought together with its parent found it in the order: from now on it is linked by license.
		if ( (int) $r->get( 'server_license' ) < 1 && ! $r->set( array( 'server_license' => (int) ( $parent->license()['id'] ?? 0 ) ) ) ) {
			return StepResult::retry( 5, 'The link to the server or database could not be stored.' );
		}

		$role = Ipv4Meta::decide_role( $parent, $r );

		if ( '' === $role ) {
			return StepResult::retry( 5, 'The IPv4 role could not be decided now.' );
		}

		// A database has one address and a server is under its limit: a line beyond that is not delivered.
		if ( ! $this->within_limit( $parent, $r ) ) {
			return $this->undelivered( $r, 'The server or database cannot take another IPv4 address.' );
		}

		$taken = $this->take( $r, $parent );

		if ( $taken instanceof StepResult ) {
			return $taken;
		}

		$done = $this->attached( $r, $parent );

		if ( $done ) {
			return StepResult::done( array( 'steps' => array( 'ready' ) ), 'IPv4 address ' . $taken . ' is in use' );
		}

		return $this->queue( $r, $parent, true );
	}

	/**
	 * Is the add-on one of the parent's allowed addresses: not more than the limit counted by earlier lines?
	 *
	 * @param Resource $parent Parent.
	 * @param Resource $addon  Add-on.
	 */
	private function within_limit( Resource $parent, Resource $addon ): bool {
		$earlier = 0;

		foreach ( Ipv4Meta::reserved_for( $parent ) as $other ) {
			if ( $other->id() < $addon->id() ) {
				++$earlier;
			}
		}

		return $earlier < Ipv4Meta::limit( $parent );
	}

	/**
	 * Takes the address from the pool (the customer has paid, so the reserve for new servers does not apply) and stores it.
	 *
	 * @param Resource $r      Add-on.
	 * @param Resource $parent Parent.
	 * @return string|StepResult The address, or the result to return.
	 */
	private function take( Resource $r, Resource $parent ) {
		$owner = Ipv4Meta::owner_id( $r, $parent );

		try {
			$row = IpPool::hold( $owner );
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 5, 'The IP pool is busy.' );
		}

		if ( null === $row ) {
			Ipv4Alerts::sold_out();

			return $this->undelivered( $r, 'No free IPv4 address was left in the pool.' );
		}

		$record  = VmRecords::for_resource( $parent->id() );
		$cluster = null === $record ? null : Clusters::get( (string) $record['cluster'] );

		if ( null !== $cluster && null !== $row['gateway'] && '' !== $row['gateway'] && ! NetConfig::gateway_on_network( $row['address'], $row['gateway'], (int) $cluster['prefix'] ) ) {
			IpPool::release( $owner );

			return $this->undelivered( $r, sprintf( 'The gateway %1$s of address %2$s is outside %2$s/%3$d. The address was given back.', $row['gateway'], $row['address'], (int) $cluster['prefix'] ) );
		}

		if ( (string) $r->get( Ipv4Meta::ADDRESS ) !== $row['address'] && ! $r->set( array( Ipv4Meta::ADDRESS => $row['address'] ) ) ) {
			return StepResult::retry( 5, 'The address could not be stored.' );
		}

		return $row['address'];
	}

	/**
	 * Does the parent show the address in use?
	 *
	 * @param Resource $r      Add-on.
	 * @param Resource $parent Parent.
	 */
	private function attached( Resource $r, Resource $parent ): bool {
		$fresh   = Resource::find( $parent->id() ) ?? $parent;
		$address = (string) $r->get( Ipv4Meta::ADDRESS );

		if ( Ipv4Meta::is_db( $fresh ) ) {
			return DbAccess::public_address( $fresh ) === $address && DbIp::idle( $fresh );
		}

		$row = IpPool::find( $address );

		if ( 'main' === $r->get( Ipv4Meta::ROLE ) ) {
			return $address === (string) $fresh->get( 'ip' ) && null !== $row && 'assigned' === $row['state'] && '' === Ipv4Server::working( $fresh );
		}

		return null !== $row && 'assigned' === $row['state'] && (float) $fresh->get( Ipv4Server::SYNCED ) >= (float) $r->get( self::QUEUED );
	}

	/**
	 * Queues the plugin action that makes the parent's VM match, once (again after a long quiet time), and waits for it.
	 *
	 * @param Resource $r      Add-on.
	 * @param Resource $parent Parent.
	 * @param bool     $add    Add or remove.
	 */
	private function queue( Resource $r, Resource $parent, bool $add ): StepResult {
		$key   = $add ? self::QUEUED : self::REMOVE_ASKED;
		$asked = (float) $r->get( $key );

		if ( $asked <= 0 || microtime( true ) - $asked > self::BUDGET / 2 ) {
			if ( ! $r->set( array( $key => microtime( true ) ) ) ) {
				return StepResult::retry( 5, 'The change could not be stored.' );
			}

			Jobs::queue_act( $parent->id(), $this->action_for( $r, $parent, $add ) );
		}

		return StepResult::poll( 10, 'Waiting for the ' . ( Ipv4Meta::is_db( $parent ) ? 'database' : 'server' ) . ' to take the IPv4 change.', self::BUDGET );
	}

	/**
	 * The plugin action of the parent for this change.
	 *
	 * @param Resource $r      Add-on.
	 * @param Resource $parent Parent.
	 * @param bool     $add    Add or remove.
	 */
	private function action_for( Resource $r, Resource $parent, bool $add ): string {
		if ( Ipv4Meta::is_db( $parent ) ) {
			return DbIp::SYNC_ACTION;
		}

		if ( 'extra' === $r->get( Ipv4Meta::ROLE ) ) {
			return Ipv4Server::SYNC;
		}

		return $add ? Ipv4Server::MAIN_ADD : Ipv4Server::MAIN_REMOVE;
	}

	/**
	 * An address that could not be delivered: the subscription is ended (nothing is refunded here) and the admin told.
	 *
	 * @param Resource $r   Add-on.
	 * @param string   $why Reason.
	 */
	private function undelivered( Resource $r, string $why ): StepResult {
		DiskCascade::cancel_undelivered( $r, $why . ' The IPv4 address was not delivered.' );

		return StepResult::fail( $why . ' IPv4 address not delivered: review and refund order #' . (int) ( $r->license()['order_id'] ?? 0 ) . '; the subscription was cancelled.' );
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

	/**
	 * {@inheritDoc}
	 *
	 * The parent stops using the address first (firewall first, one restart for a main address), then the address goes back to the pool.
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		$address = (string) $r->get( Ipv4Meta::ADDRESS );
		$parent  = Ipv4Meta::parent_of( $r );

		// The parent no longer counts it, from now on.
		if ( true !== $r->get( Ipv4Meta::ENDED ) && ! $r->set( array( Ipv4Meta::ENDED => true ) ) ) {
			return StepResult::retry( 5, 'The change could not be stored.' );
		}

		// A failed parent that may still have a machine keeps the address held (the pool screen releases it by hand, as for any
		// failed resource); a deleted one is gone.
		if ( '' !== $address && null !== $parent && 'failed' === $parent->get( 'state' ) && null !== VmRecords::for_resource( $parent->id() ) ) {
			// The deadline is stored once, so the wait ends: after it the delete fails (the admin is told by the failure path).
			$until = (int) $r->get( 'parent_failed_until' );

			if ( $until < 1 ) {
				$until = time() + 7 * DAY_IN_SECONDS;

				if ( ! $r->set( array( 'parent_failed_until' => $until ) ) ) {
					return StepResult::retry( 60, 'The wait deadline could not be stored.' );
				}
			}

			if ( time() < $until ) {
				return StepResult::wait( 300, 'The server or database failed: the IPv4 address stays held until it is deleted.', $until );
			}

			return StepResult::fail( 'The server or database failed and was not deleted within seven days: the IPv4 address is still held. Release it from the pool screen when the server is gone.' );
		}

		if ( '' === $address || null === $parent || in_array( $parent->get( 'state' ), array( 'failed', 'deleted', 'deleting' ), true ) ) {
			// Nothing was taken, or the parent is gone (a deleted parent gave back a main address with itself): the extra row goes back.
			IpPool::release( $r->id() );

			return StepResult::done();
		}

		$fresh = Resource::find( $parent->id() ) ?? $parent;
		$role  = (string) $r->get( Ipv4Meta::ROLE );

		if ( 'main' === $role ) {
			$gone = Ipv4Meta::is_db( $fresh ) ? '' === DbAccess::public_address( $fresh ) && null === IpPool::for_resource( $fresh->id() ) && DbIp::idle( $fresh ) : '' === (string) $fresh->get( 'ip' ) && null === IpPool::for_resource( $fresh->id() ) && '' === Ipv4Server::working( $fresh );

			if ( $gone ) {
				IpHistory::end( 'public', $r->id() );

				return StepResult::done();
			}

			if ( ! Ipv4Meta::is_db( $fresh ) && array() !== Ipv4Meta::extra_addresses( $fresh ) ) {
				Alerts::admin( $r, 'The main IPv4 address of a server was removed while it still has extra IPv4 addresses. They have no main address to work with: check the extra IPv4 subscriptions of this server.' );
			}

			return $this->queue( $r, $fresh, false );
		}

		// Extra: the firewall must stop allowing it before it goes back: a run of the parent's sync that started after the request.
		$asked = (float) $r->get( self::REMOVE_ASKED );

		if ( $asked > 0 && (float) $fresh->get( Ipv4Server::SYNCED ) >= $asked && '' === Ipv4Server::working( $fresh ) ) {
			IpPool::release( $r->id() );

			return StepResult::done();
		}

		return $this->queue( $r, $fresh, false );
	}

	public function status( Resource $r ): ?array {
		return null;
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This IPv4 address action is not available.' );
	}

	public function render_account( Resource $r ): void {
		$parent = Ipv4Meta::parent_of( $r );
		echo '<p>' . esc_html__( 'IPv4 address', 'beltoft-services' ) . ': <code>' . esc_html( (string) $r->get( Ipv4Meta::ADDRESS ) ) . '</code></p>';

		if ( $parent ) {
			echo '<p><a href="' . esc_url( DiskGuide::card_url( $parent ) ) . '">' . esc_html__( 'Open the service', 'beltoft-services' ) . '</a></p>';
		}
	}

	public function render_admin( Resource $r ): void {
		$parent = Ipv4Meta::parent_of( $r );
		echo '<h3>' . esc_html__( 'IPv4 address', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Address', 'beltoft-services' ) . '</th><td>' . esc_html( (string) $r->get( Ipv4Meta::ADDRESS ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Role', 'beltoft-services' ) . '</th><td>' . esc_html( (string) $r->get( Ipv4Meta::ROLE ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Server or database license', 'beltoft-services' ) . '</th><td>' . esc_html( (string) $r->get( 'server_license' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Parent resource', 'beltoft-services' ) . '</th><td>' . esc_html( $parent ? (string) $parent->id() : '' ) . '</td></tr>';
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
		return array( array( 'item' => 'ipv4', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => \BeltoftServices\Usage\Rates::hourly( $monthly, $premium ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_words(): array {
		return array( 'ipv4' => array( 'label' => __( 'IPv4 address', 'beltoft-services' ), 'word' => __( 'IPv4 address', 'beltoft-services' ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_is_addon(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>|null
	 */
	public function usage_delete(): ?array {
		return array(
			'kind'    => 'ipv4',
			'text'    => __( 'Removing takes the IPv4 address away from the server or database and gives it back. Anything that connects through it stops working. Billing stops in the hour it is removed. The service and the usage account are not touched.', 'beltoft-services' ),
			'billing' => __( 'The address is billed by the hour while you have it, and never more than the monthly price. Remove it to stop the charge.', 'beltoft-services' ),
			'stopped' => __( 'A stopped server is still billed. Delete it to stop the charge.', 'beltoft-services' ),
			'busy'    => __( 'This IPv4 address is still being set up or changed. Please try again when it has finished.', 'beltoft-services' ),
			'blocker' => Ipv4Meta::removal_blocker( ... ),
		);
	}
}
