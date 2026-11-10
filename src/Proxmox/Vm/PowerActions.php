<?php
/**
 * start, shutdown and reboot of a Proxmox VM.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Proxmox\Vm\PowerJobs;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Pve\Prepares;
use BeltoftServices\Proxmox\Vm\ServerName;
use BeltoftServices\Proxmox\Pve\Steps;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Start, shutdown and reboot. Built by Actions::run() after the VM is verified, and run under the resource lock.
 */
final class PowerActions {

	use Steps;
	use Prepares;

	/**
	 * Cluster settings (token secret decrypted), as Actions prepared them.
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r     Resource.
	 * @param Actions  $owner The Actions run that prepared the client and polled the stored task.
	 */
	public function __construct( Resource $r, Actions $owner ) {
		$this->r = $r;
		$state = $owner->state();

		$this->client    = $state['client'];
		$this->cluster   = $state['cluster'];
		$this->secrets   = $state['secrets'];
		$this->wait      = $state['wait'];
		$this->finished  = $state['finished'];
	}

	/**
	 * Start (only a VM that is off).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function start( array $record, array $v ): StepResult {
		if ( 'running' === $v['status'] ) {
			$this->mark_sent( null );
			Operations::remember_power( $this->r->id(), 'running' );
			$this->r->set( array( 'stopped_by_customer' => null ) );

			return StepResult::done( array(), 'Start: the server was already running' );
		}

		// A start sent a moment ago that the status does not show yet is waited for, never sent again.
		if ( $this->sent_recently( 'start' ) ) {
			$late = $this->reread_power( $record, 'start', true );

			if ( $late instanceof StepResult ) {
				return $late;
			}

			if ( 'running' !== $late['status'] ) {
				return StepResult::poll( 5, 'start: the start task did not leave the VM running (OK)', 60 );
			}

			$this->mark_sent( null );
			Operations::remember_power( $this->r->id(), 'running' );
			$this->r->set( array( 'stopped_by_customer' => null, ServerName::PENDING => null, ServerName::PENDING_AT => null ) );

			return StepResult::done( array(), 'Customer started the server' );
		}

		$done = $this->run_task( 'act', 'POST', Actions::base( $record, $v ) . '/status/start', array(), 'start' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$again = $this->reread_power( $record, 'start', true );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' !== $again['status'] ) {
			return StepResult::poll( 5, 'start: the start task did not leave the VM running (' . ( true === $done ? 'OK' : $done['failed'] ) . ')', 60 );
		}

		$this->mark_sent( null );
		Operations::remember_power( $this->r->id(), 'running' );
		$this->r->set( array( 'stopped_by_customer' => null, ServerName::PENDING => null, ServerName::PENDING_AT => null ) );

		return StepResult::done( array(), 'Customer started the server' );
	}

	/**
	 * Graceful shutdown (ACPI). A system that does not power off in time is left running: a plug
	 * pull is the shop's decision, never a customer button.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function shutdown( array $record, array $v ): StepResult {
		if ( 'running' !== $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'stopped' );
			$this->r->set( array( 'stopped_by_customer' => time() ) );

			return StepResult::done( array(), 'Shutdown: the server was already off' );
		}

		$done = $this->run_task( 'act', 'POST', Actions::base( $record, $v ) . '/status/shutdown', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'shutdown' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		// The status can lag the task and a guest can still be powering off: look again for a while (two seconds apart) before saying anything.
		$again = $this->reread_power( $record, 'shutdown', false, 30 );

		if ( $again instanceof StepResult ) {
			return $again;
		}

		if ( 'running' === $again['status'] ) {
			// Not claimed as left running: a follow-up reads the power again and keeps the page right. A stop by the customer is not an outage.
			$this->r->set( array( 'stopped_by_customer' => time() ) );
			PowerJobs::follow_up( $this->r->id() );

			return StepResult::done( array(), 'Shutdown requested; the server is still powering off' );
		}

		Operations::remember_power( $this->r->id(), 'stopped' );
		$this->r->set( array( 'stopped_by_customer' => time() ) );

		return StepResult::done( array(), 'Customer shut the server down' );
	}

	/**
	 * Graceful reboot of a running VM.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @param array<string, mixed> $v      Verified VM.
	 */
	public function reboot( array $record, array $v ): StepResult {
		if ( 'running' !== $v['status'] ) {
			Operations::remember_power( $this->r->id(), 'stopped' );

			return StepResult::done( array(), 'Reboot refused: the server is not running' );
		}
		$this->r->set( array( 'stopped_by_customer' => time() ) );

		$done = $this->run_task( 'act', 'POST', Actions::base( $record, $v ) . '/status/reboot', array( 'timeout' => max( 10, (int) Settings::get( 'shutdown_timeout' ) ) ), 'reboot' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			return StepResult::done( array(), 'Reboot did not finish: the system did not restart in time' );
		}

		$this->r->set( array( ServerName::PENDING => null, ServerName::PENDING_AT => null ) );

		return StepResult::done( array(), 'Customer restarted the server' );
	}
}
