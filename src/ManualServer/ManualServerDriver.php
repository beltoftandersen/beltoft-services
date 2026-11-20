<?php
/**
 * The manual server driver: a dedicated server that staff set up by hand.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Reconciler;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Without the setting dedicated_enabled nothing here calls a supplier or any API: a new service waits in `provisioning` (a poll, one look a minute that costs no
 * attempt) while staff are mailed the order; "Mark ready" (ManualServerDelivery) stores the address and the reconciler moves
 * the service to `ready`, which starts the hourly meter and sends the service ready email. Pause, resume and delete only
 * tell staff what to do by hand. A delete ends the activation, and with it the billing, at once.
 */
final class ManualServerDriver implements Driver, StopsOnDelete, NeedsBilling, \BeltoftServices\Drivers\TakesSshKey, \BeltoftServices\Drivers\InternalActions, \BeltoftServices\Drivers\UsageItems {

	use \BeltoftServices\Drivers\UsageDefaults;
	public const ID   = 'manual-server';
	public const KIND = 'dedicated';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Dedicated server', 'beltoft-services' );
	}

	public function kind(): string {
		return self::KIND;
	}

	/**
	 * @param int $used Used.
	 * @param int $max  Most.
	 */
	public function unit_label( int $used, int $max ): string {
		/* translators: %d: number of dedicated servers. */
		return sprintf( _n( '%d dedicated server', '%d dedicated servers', $max, 'beltoft-services' ), $used );
	}

	/**
	 * Per variation: what the customer reads (processor, memory, storage, country) and what only staff read (the supplier's
	 * product code and location code, and the cost).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function plan_fields(): array {
		return array(
			'server_cpu'          => array( 'text', __( 'Processor shown', 'beltoft-services' ), __( 'Shown to the customer.', 'beltoft-services' ), '', true ),
			'server_memory'       => array( 'text', __( 'Memory shown', 'beltoft-services' ), __( 'Shown to the customer, for example 64 GB DDR5.', 'beltoft-services' ), '', true ),
			'server_storage'      => array( 'text', __( 'Storage shown', 'beltoft-services' ), __( 'Shown to the customer, for example 2 x 512 GB NVMe SSD.', 'beltoft-services' ), '', true ),
			'server_location'     => array( 'text', __( 'Country shown', 'beltoft-services' ), __( 'Shown to the customer. Never the name of the supplier.', 'beltoft-services' ), '', true ),
			'server_ref'          => array( 'text', __( 'Supplier product code (staff only)', 'beltoft-services' ), __( 'The product to buy by hand. Staff only, never shown.', 'beltoft-services' ), '', true ),
			'server_location_code' => array( 'text', __( 'Supplier location code (staff only)', 'beltoft-services' ), __( 'Where to buy it. Staff only, never shown.', 'beltoft-services' ), '', true ),
			'server_dist'         => array( 'text', __( 'Distribution to preinstall (staff only)', 'beltoft-services' ), __( 'The name exactly as the supplier product lists it. Empty: the setting under Dedicated servers.', 'beltoft-services' ), '', true ),
			'cost_monthly'        => array( 'text', __( 'Monthly cost, EUR without VAT (staff only)', 'beltoft-services' ), __( 'A figure such as 99.00, told to staff in the order mail. Not used for billing. Kept as text: a decimal figure in a plan does not survive storage.', 'beltoft-services' ), '', true ),
			'cost_setup'          => array( 'text', __( 'Setup cost, EUR without VAT (staff only)', 'beltoft-services' ), __( 'A figure such as 49.00, told to staff in the order mail. Not used for billing.', 'beltoft-services' ), '', true ),
		);
	}

	/**
	 * Waits for staff. Done once the delivery details are stored.
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		$meta = array();

		if ( '' === (string) $r->get( 'server_name' ) ) {
			$meta['server_name'] = ManualServerDelivery::default_name( $r );
		}

		if ( null === $r->get( ManualServerDelivery::REQUESTED_AT ) ) {
			$meta[ ManualServerDelivery::REQUESTED_AT ] = time();
		}

		if ( $meta && $r->set( $meta ) ) {
			$r = Resource::find( $r->id() ) ?? $r;
		}

		// Delivered by hand (Mark ready), the fallback of an order that failed or timed out.
		if ( (int) $r->get( ManualServerDelivery::READY_AT ) > 0 ) {
			return StepResult::done( array(), 'Server delivered' );
		}

		if ( RobotOrder::enabled() ) {
			return RobotOrder::provision( $r );
		}

		ManualServerDelivery::order_mail( $r );
		ManualServerDelivery::overdue_mail( $r );

		return StepResult::poll( 60, 'Waiting for staff to set up the server', Reconciler::POLL_BUDGET_MAX );
	}

	/**
	 * Tells staff to switch the server off. Nothing is called.
	 *
	 * @param Resource $r Resource.
	 */
	public function pause( Resource $r ): StepResult {
		ManualServerDelivery::staff_mail( $r, 'pause' );

		return StepResult::done( array(), 'Staff told to switch the server off' );
	}

	/**
	 * Tells staff to switch the server on again.
	 *
	 * @param Resource $r Resource.
	 */
	public function resume( Resource $r ): StepResult {
		ManualServerDelivery::staff_mail( $r, 'resume' );

		return StepResult::done( array(), 'Staff told to switch the server on' );
	}

	/**
	 * A plan change of a running dedicated server is done by hand.
	 *
	 * @param Resource $r Resource.
	 */
	public function update_plan( Resource $r ): StepResult {
		ManualServerDelivery::staff_mail( $r, 'plan' );

		return StepResult::done( array(), 'Staff told the plan changed' );
	}

	/**
	 * Cancels the server at the supplier at once (RobotOrder::cancel), or tells staff when it was not ordered through the API.
	 * The data is gone at once there, so this is only ever reached by an explicit cancel (the customer's typed confirmation, or an admin).
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		return RobotOrder::cancel( $r );
	}

	/**
	 * What the customer may see.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public function status( Resource $r ): ?array {
		return array(
			'ips'       => ManualServerDelivery::ips( $r ),
			'delivered' => (int) $r->get( ManualServerDelivery::READY_AT ) > 0,
		);
	}

	/**
	 * @param Resource $r Resource.
	 * @return array<int, string>
	 */
	public function customer_actions( Resource $r ): array {
		unset( $r );

		return array();
	}

	/**
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function act( Resource $r, string $action, array $args ): StepResult {
		if ( DedicatedReinstall::ACTION === $action ) {
			return DedicatedReinstall::run( $r, $args );
		}

		return StepResult::fail( sprintf( 'Unknown action "%s".', $action ) );
	}

	/**
	 * The reinstall is queued by its route (ControlRoutes) after the typed name and the emailed code.
	 *
	 * @return string[]
	 */
	public function internal_actions(): array {
		return array( DedicatedReinstall::ACTION );
	}

	/**
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void {
		ManualServerPanel::account( $r );
	}

	/**
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void {
		ManualServerPanel::admin( $r );
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
		// A dedicated server: one item, the variation's price / 730 an hour. With the product premium 0 the monthly cap is the monthly price.
		return array( array( 'item' => 'server', 'quantity' => 1.0, 'unit' => 'hour', 'rate' => \BeltoftServices\Usage\Rates::hourly( $monthly, $premium ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_words(): array {
		return ProxmoxDriver::server_words();
	}

	/**
	 * {@inheritDoc}
	 */
	public function usage_billing_choice(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A dedicated server has a fixed name of its own (dedicated-<8 characters>), not a vm- one.
	 *
	 * @param Resource $r Resource.
	 */
	public function usage_reference( Resource $r ): ?string {
		return \BeltoftServices\Usage\Renewal::label( $r->id() );
	}
}
