<?php
/** The SSL certificate driver: one resource is one certificate order at the registrar. @package BeltoftServices */
namespace BeltoftServices\Ssl;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\InternalActions;
use BeltoftServices\Drivers\NeedsBilling;
use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A yearly subscription is one order for one name. Provision places it (Provision), pausing does nothing (the certificate
 * keeps working until it ends; an unpaid renewal lets the order expire), a plan change does nothing (a different certificate is
 * a new order), and delete cancels an order that can still be cancelled for a refund. The customer's actions are Operations.
 */
final class SslDriver implements Driver, InternalActions, NeedsBilling, ProvisionSteps {
	public const ID   = 'ssl';
	public const KIND = 'ssl';

	/**
	 * Driver id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function step_labels(): array {
		return array(
			'csr'          => __( 'Making the certificate request', 'beltoft-services' ),
			'contact'      => __( 'Saving the owner details', 'beltoft-services' ),
			'order'        => __( 'Placing the order', 'beltoft-services' ),
			'request'      => __( 'Sending the request to the certificate authority', 'beltoft-services' ),
			'validate'     => __( 'Validating the domain', 'beltoft-services' ),
			'active'       => __( 'Waiting for the certificate authority', 'beltoft-services' ),
			'deliver'      => __( 'Collecting the certificate', 'beltoft-services' ),
			'dates'        => __( 'Final checks', 'beltoft-services' ),
		);
	}

	/**
	 * Driver name.
	 */
	public function label(): string {
		return __( 'SSL certificate', 'beltoft-services' );
	}

	/**
	 * Activation kind.
	 */
	public function kind(): string {
		return self::KIND;
	}

	/**
	 * Usage line.
	 *
	 * @param int $used Used.
	 * @param int $max  Allowed.
	 */
	public function unit_label( int $used, int $max ): string {
		/* translators: %d: number of certificates. */
		return sprintf( _n( '%d certificate', '%d certificates', $max, 'beltoft-services' ), $used );
	}

	/**
	 * The registrar product, the validation level, whether it is a wildcard and how many names it covers, per variation.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function plan_fields(): array {
		return array(
			'ssl_product'  => array( 'number', __( 'Registrar product id', 'beltoft-services' ), __( 'The id of the certificate product at the registrar (see Settings, Services, SSL certificates).', 'beltoft-services' ), 0, true ),
			'ssl_level'    => array( 'text', __( 'Validation level', 'beltoft-services' ), __( 'dv, ov or ev. Decides what the checkout asks for.', 'beltoft-services' ), 'dv', true ),
			'ssl_wildcard' => array( 'checkbox', __( 'Wildcard', 'beltoft-services' ), __( 'The certificate covers every name under the domain.', 'beltoft-services' ), false, true ),
			'ssl_domains'  => array( 'number', __( 'Names', 'beltoft-services' ), __( 'How many names the certificate covers. 1 for a single name or a wildcard certificate; for a multi domain product the number of names its price includes (the customer may enter up to this many).', 'beltoft-services' ), 1, true ),
		);
	}

	/**
	 * Actions queued by trusted hooks, never from the customer's REST route.
	 *
	 * @return string[]
	 */
	public function internal_actions(): array {
		return array( 'renew', 'refresh' );
	}

	/**
	 * Builds the order.
	 *
	 * @param Resource $r Resource.
	 */
	public function provision( Resource $r ): StepResult {
		return Provision::run( $r );
	}

	/**
	 * A certificate keeps working while its service is paused.
	 *
	 * @param Resource $r Resource.
	 */
	public function pause( Resource $r ): StepResult {
		unset( $r );

		return StepResult::done();
	}

	/**
	 * Resuming continues an unfinished build; a built certificate has nothing to resume.
	 *
	 * @param Resource $r Resource.
	 */
	public function resume( Resource $r ): StepResult {
		return in_array( 'dates', (array) $r->get( 'steps' ), true ) ? StepResult::done() : $this->provision( $r );
	}

	/**
	 * A different product is a different order.
	 *
	 * @param Resource $r Resource.
	 */
	public function update_plan( Resource $r ): StepResult {
		unset( $r );

		return StepResult::done();
	}

	/**
	 * Cancels what can still be cancelled for a refund and wipes the key.
	 *
	 * @param Resource $r Resource.
	 */
	public function delete( Resource $r ): StepResult {
		return Operations::delete( $r );
	}

	/**
	 * Display values.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public function status( Resource $r ): ?array {
		return array(
			'name'       => (string) $r->get( 'ssl_name' ),
			'status'     => (string) $r->get( 'ssl_status' ),
			'valid_from' => (string) $r->get( 'ssl_valid_from' ),
			'valid_to'   => (string) $r->get( 'ssl_valid_to' ),
			'order_ends' => (string) $r->get( 'ssl_order_expires' ),
		);
	}

	/**
	 * Actions the customer may ask for now.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public function customer_actions( Resource $r ): array {
		return Operations::offered( $r );
	}

	/**
	 * Runs an action.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public function act( Resource $r, string $action, array $args ): StepResult {
		return Operations::run( $r, $action, $args );
	}

	/**
	 * The account part.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_account( Resource $r ): void {
		Panels::account( $r );
	}

	/**
	 * The admin part.
	 *
	 * @param Resource $r Resource.
	 */
	public function render_admin( Resource $r ): void {
		Panels::admin( $r );
	}
}
