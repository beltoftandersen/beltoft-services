<?php
/** The driver of a standalone licence key: a Plesk edition or an add-on for a server the customer runs. @package BeltoftServices */

namespace BeltoftServices\Licences;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per licence. Provision orders the vendor licence for the customer's server address
 * (KeyOrder), pausing keeps it (billed monthly whatever happens), a plan change switches its item, and delete removes it.
 * The customer sees the key in My Account (KeyPanel); there are no customer actions.
 */
final class KeyDriver implements Driver {
	public const ID   = 'licence-key';
	public const KIND = 'lickey';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Licence key', 'beltoft-services' );
	}

	public function kind(): string {
		return self::KIND;
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: licences used, 2: licences allowed. */
		return sprintf( _n( '%1$d of %2$d licence', '%1$d of %2$d licences', $max, 'beltoft-services' ), $used, $max );
	}

	/**
	 * What a variation sells: its kind, its key and, for an edition, the kind of server it is valid on.
	 */
	public function plan_fields(): array {
		return array(
			'lic_kind'   => array( 'text', __( 'Kind', 'beltoft-services' ), __( 'edition (a Plesk licence) or addon (an extension key).', 'beltoft-services' ), 'edition', true ),
			'lic_tier'   => array( 'text', __( 'Licence key', 'beltoft-services' ), __( 'The edition (admin, pro, host) or add-on key (for example cloudlinux-solo, kernelcare).', 'beltoft-services' ), '', true ),
			'lic_server' => array( 'text', __( 'Server type', 'beltoft-services' ), __( 'For an edition: vps (valid on a virtual server) or physical.', 'beltoft-services' ), 'vps', true ),
			'lic_label'  => array( 'text', __( 'Name', 'beltoft-services' ), __( 'What the customer sees. Empty uses the variation name.', 'beltoft-services' ), '', true ),
		);
	}

	public function provision( Resource $r ): StepResult {
		return KeyOrder::provision( $r );
	}

	public function pause( Resource $r ): StepResult {
		// A licence on hold is kept: a deleted one would have to be bought again, and it is billed monthly anyway.
		unset( $r );

		return StepResult::done();
	}

	public function resume( Resource $r ): StepResult {
		// The licence was kept while paused; only one that never got its key is looked at again.
		return '' === (string) $r->get( 'lic_key' ) ? $this->provision( $r ) : StepResult::done();
	}

	public function update_plan( Resource $r ): StepResult {
		return KeyOrder::switch_item( $r );
	}

	public function delete( Resource $r ): StepResult {
		KeyOrder::release( $r );

		return StepResult::done();
	}

	public function status( Resource $r ): ?array {
		return array(
			'state' => (string) $r->get( 'lic_state' ),
			'ip'    => (string) $r->get( 'lic_ip' ),
		);
	}

	public function customer_actions( Resource $r ): array {
		unset( $r );

		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		unset( $r, $action, $args );

		return StepResult::fail( 'This licence action is not available.' );
	}

	public function render_account( Resource $r ): void {
		KeyPanel::account( $r );
	}

	public function render_admin( Resource $r ): void {
		KeyPanel::admin( $r );
	}
}
