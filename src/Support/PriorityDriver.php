<?php
/** Priority support service driver: an entitlement of a customer account. @package BeltoftServices */

namespace BeltoftServices\Support;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One subscription and resource per customer. Nothing is created anywhere: the entitlement is the active resource itself
 * (Priority::has()). The flag staff reads (user meta) follows the resource through Priority::sync().
 */
final class PriorityDriver implements Driver {
	public const ID = 'support-priority';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Priority support', 'beltoft-services' );
	}

	public function kind(): string {
		return 'support';
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: accounts with priority support, 2: accounts allowed. */
		return sprintf( _n( '%1$d of %2$d account', '%1$d of %2$d accounts', $max, 'beltoft-services' ), $used, $max );
	}

	public function plan_fields(): array {
		return array();
	}

	public function provision( Resource $r ): StepResult {
		if ( (int) ( $r->license()['user_id'] ?? 0 ) < 1 ) {
			return StepResult::fail( 'Priority support needs a customer account; the licence has none.' );
		}

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
		return StepResult::fail( 'This priority support action is not available.' );
	}

	public function render_account( Resource $r ): void {
		echo '<p>' . esc_html( Priority::promise() ) . '</p>';
	}

	public function render_admin( Resource $r ): void {
		echo '<h3>' . esc_html__( 'Priority support', 'beltoft-services' ) . '</h3><p>' . esc_html( Priority::promise() ) . '</p>';
	}
}
