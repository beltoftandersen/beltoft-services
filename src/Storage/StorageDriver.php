<?php
/**
 * The backup storage driver: one resource is one storage box at the storage provider.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A monthly subscription is one box. The work is in Provision (build) and BoxLifecycle (pause, resume, resize, delete); the
 * customer's actions are StorageActions; every provider call goes through StorageClients::for().
 */
final class StorageDriver implements Driver, InternalActions, NeedsBilling, ProvisionSteps {
	public const ID   = 'storage';
	public const KIND = 'storage';

	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function step_labels(): array {
		return array(
			'password'  => __( 'Making the access password', 'beltoft-services' ),
			'guard'     => __( 'Checking the order', 'beltoft-services' ),
			'create'    => __( 'Creating your storage', 'beltoft-services' ),
			'wait'      => __( 'Waiting for the storage to start', 'beltoft-services' ),
			'access'    => __( 'Setting up access', 'beltoft-services' ),
			'protect'   => __( 'Protecting the storage against deletion', 'beltoft-services' ),
			'snapshots' => __( 'Setting up snapshots', 'beltoft-services' ),
			'alias'     => __( 'Making the host name', 'beltoft-services' ),
		);
	}

	public function label(): string {
		return __( 'Backup storage', 'beltoft-services' );
	}

	public function kind(): string {
		return self::KIND;
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: %d: number of storage boxes. */
		return sprintf( _n( '%d backup storage', '%d backup storages', $max, 'beltoft-services' ), $used );
	}

	/**
	 * The box type of the provider and the size shown to the customer, per variation. The location is not a field: Germany only.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function plan_fields(): array {
		return array(
			'storage_type'    => array( 'text', __( 'Storage provider box type', 'beltoft-services' ), __( 'The type name at the storage provider, for example bx11. It must be in the cost table under Settings, Backup storage, or no box is bought.', 'beltoft-services' ), '', true ),
			'storage_size_gb' => array( 'number', __( 'Size shown, in GB', 'beltoft-services' ), __( 'The size the customer is told, for example 1000.', 'beltoft-services' ), 0, true ),
		);
	}

	/**
	 * Actions queued by trusted routes, never by the generic customer route.
	 *
	 * @return string[]
	 */
	public function internal_actions(): array {
		return StorageActions::internal();
	}

	public function provision( Resource $r ): StepResult {
		return StorageSettings::enabled() ? Provision::run( $r ) : StepResult::retry( 3600, 'Backup storage is switched off in Settings, Backup storage.' );
	}

	public function pause( Resource $r ): StepResult {
		return BoxLifecycle::pause( $r );
	}

	public function resume( Resource $r ): StepResult {
		return BoxLifecycle::resume( $r );
	}

	public function update_plan( Resource $r ): StepResult {
		return BoxLifecycle::update_plan( $r );
	}

	public function delete( Resource $r ): StepResult {
		return BoxLifecycle::delete( $r );
	}

	/**
	 * Display values.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public function status( Resource $r ): ?array {
		return array(
			'size_bytes' => (int) $r->get( 'storage_size_bytes' ),
			'used_bytes' => (int) $r->get( 'storage_used_bytes' ),
			'locked'     => (int) $r->get( 'storage_locked_at' ) > 0,
		);
	}

	public function customer_actions( Resource $r ): array {
		return StorageActions::offered( $r );
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StorageActions::run( $r, $action, $args );
	}

	public function render_account( Resource $r ): void {
		StoragePanel::account( $r );
	}

	public function render_admin( Resource $r ): void {
		StoragePanel::admin( $r );
	}
}
