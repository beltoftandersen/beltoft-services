<?php
/**
 * One reboot of a running VM after a change to its config, shared by the steps that need it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Pve;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * For a class that uses Steps: one API reboot of a running VM, so that it takes a change written to its config (a network
 * address, a private network). A VM that is off takes the change at its next start. The operation the caller stores holds the
 * flags `reboot_sent`, `restarted` and `skipped`, so a job that is run again never reboots twice.
 */
trait RebootsOnce {

	/**
	 * @param array<string, mixed> $record VM record.
	 * @param string               $step   Label for messages and the task.
	 * @param callable             $read   fn(): array, the stored operation read fresh.
	 * @param callable             $store  fn( array $op ): bool, stores the operation.
	 * @return StepResult|null Null when the VM was rebooted (or was off).
	 */
	protected function reboot_once( array $record, string $step, callable $read, callable $store ) {
		$op = $read();

		if ( ! empty( $op['restarted'] ) || ! empty( $op['skipped'] ) ) {
			return null;
		}

		// The reboot task of the run before this one has ended.
		if ( is_array( $this->finished ) && 'reboot' === $this->finished['phase'] && ! empty( $op['reboot_sent'] ) ) {
			$op['restarted'] = true;

			return $store( $op ) ? null : StepResult::retry( 0, $step . ': progress could not be stored' );
		}

		$v = $this->verified( $record, $step );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		if ( 'running' !== $v['status'] ) {
			$op['skipped'] = true;

			return $store( $op ) ? null : StepResult::retry( 0, $step . ': progress could not be stored' );
		}

		$op['reboot_sent'] = true;

		if ( ! $store( $op ) || ! $this->r->set( array( 'stopped_by_customer' => time() ) ) ) {
			return StepResult::retry( 0, $step . ': progress could not be stored' );
		}

		$done = $this->run_task( $step, 'POST', Actions::base( $record, $v ) . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$op              = $read();
		$op['restarted'] = true;

		// A reboot that did not finish in time is left to finish by itself: the config is right, the VM takes it when it restarts.
		return $store( $op ) ? null : StepResult::retry( 0, $step . ': progress could not be stored' );
	}
}
