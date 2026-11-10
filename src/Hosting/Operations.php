<?php
/**
 * Pause, resume, plan change and delete of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the operations of HostingDriver after provisioning (plan 16.4). Each one reads the remote state before it
 * acts and is safe to run again.
 *
 * - pause: the edge shows its suspended page for the account's names, then the container is shut down. Data is kept.
 * - resume: the container starts, then the edge routes the names to it again.
 * - update_plan: limits and root disk follow the plan (grown, never shrunk). The agent's pool and cache budgets
 *   are a later slice.
 * - delete (only when the deletion mode allows it, which the reconciler decides): edge routes removed, DNS records we wrote removed, databases
 *   dropped, the container stopped and destroyed after the ownership proof, then the records are released.
 *   Backups and DNS records of the account follow with their own slices.
 *
 * An account that never got a container (no record, no container id) has nothing to pause or delete.
 */
final class Operations {

	use Runs;

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Pause: edge suspended page, then power off.
	 */
	public function pause(): StepResult {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record || null === $record['vmid'] ) {
			return StepResult::done( array(), 'Nothing to pause: no container was built' );
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$edge = $this->edge( true );

		if ( $edge instanceof StepResult ) {
			return $edge;
		}

		$v = $this->verified( $record, 'pause' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$off = $this->power_off( $v, 'pause' );

		return $off instanceof StepResult ? $off : StepResult::done( array(), 'Hosting paused' );
	}

	/**
	 * Resume: power on, then the edge routes the names again.
	 */
	public function resume(): StepResult {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record || null === $record['vmid'] ) {
			return StepResult::fail( 'Nothing to resume: no container was built' );
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'resume' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$on = $this->power_on( $v, 'resume' );

		if ( $on instanceof StepResult ) {
			return $on;
		}

		$edge = $this->edge( false );

		return $edge instanceof StepResult ? $edge : StepResult::done( array(), 'Hosting resumed' );
	}

	/**
	 * Update the plan: the container's limits and root disk.
	 */
	public function update_plan(): StepResult {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record || null === $record['vmid'] ) {
			return StepResult::done( array( 'plan_dirty' => false ) );
		}

		$plan    = Plan::typed( (array) $this->r->get( 'plan' ) );
		$problem = Plan::problem( $plan );

		if ( null !== $problem ) {
			return StepResult::fail( $problem );
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'update' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$limits = $this->apply_limits( $v, $plan, 'update' );

		if ( $limits instanceof StepResult ) {
			return $limits;
		}

		// SSH on or off follows the plan: ct.prepare is idempotent, so it is sent again only when the value changed.
		$meta = array();

		if ( (bool) $this->r->get( 'prepared_ssh' ) !== ! empty( $plan['ssh'] ) ) {
			$sent = $this->agent_call(
				'prepare_ssh',
				'ct.prepare',
				array(
					'vmid'      => (int) $record['vmid'],
					'account'   => Config::account( $this->r->id() ),
					'hostname'  => Config::account( $this->r->id() ),
					'ip'        => (string) $record['ip'],
					'ssh_port'  => (int) $record['ssh_port'],
					'valkey_mb' => (int) $plan['valkey_mb'],
					'workers'   => Plan::workers_per_site( $plan, max( 1, count( Sites::for_resource( $this->r->id() ) ) ) ),
					'ssh'       => ! empty( $plan['ssh'] ),
				)
			);

			if ( $sent instanceof StepResult ) {
				return $sent;
			}

			$meta['prepared_ssh'] = ! empty( $plan['ssh'] );
		}

		return StepResult::done( $meta, 'Hosting plan applied' );
	}

	/**
	 * Delete: edge routes, databases, container, records.
	 */
	public function delete(): StepResult {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record ) {
			return StepResult::done( array(), 'Nothing to delete: no hosting record' );
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$done = (array) $this->r->get( 'delete_steps' );

		// The backups go before the container: the node lists and deletes a guest's backups only while the guest still exists, for a token that has rights on the guest and not on the whole storage.
		foreach ( array( 'edge', 'dns', 'db', 'backups', 'ct', 'agent', 'release' ) as $step ) {
			if ( in_array( $step, $done, true ) ) {
				continue;
			}

			$result = $this->{'delete_' . $step}( $record );

			if ( $result instanceof StepResult ) {
				return $result;
			}

			$done[] = $step;

			if ( ! $this->r->set( array( 'delete_steps' => $done ) ) ) {
				return StepResult::retry( 0, 'delete: the step could not be recorded' );
			}

			$this->progressed = true;
		}

		return StepResult::done( array( 'delete_steps' => $done ), 'Hosting deleted' );
	}

	/**
	 * Read-only status from the records: no remote call.
	 *
	 * @return array<string, mixed>|null
	 */
	public function status(): ?array {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record ) {
			return null;
		}

		return array(
			'node'     => $record['node'],
			'vmid'     => $record['vmid'],
			'ip'       => $record['ip'],
			'ssh_port' => $record['ssh_port'],
			'sites'    => count( Sites::for_resource( $this->r->id() ) ),
		);
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * The edge routes of the account, suspended or not.
	 *
	 * @param bool $suspended Whether the edge shows the suspended page.
	 * @return true|StepResult
	 */
	private function edge( bool $suspended ) {
		$result = $this->agent_call(
			$suspended ? 'edge_pause' : 'edge_resume',
			'edge.apply',
			array(
				'account' => Config::account( $this->r->id() ),
				'routes'  => EdgeMap::routes( $this->r->id(), $suspended ),
			)
		);

		return $result instanceof StepResult ? $result : true;
	}

	/**
	 * Delete step edge: no routes for the account.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_edge( array $record ) {
		unset( $record );

		$result = $this->agent_call(
			'edge_delete',
			'edge.apply',
			array(
				'account' => Config::account( $this->r->id() ),
				'routes'  => array(),
			)
		);

		return $result instanceof StepResult ? $result : true;
	}

	/**
	 * Delete step dns: the records we wrote for the account's names go; an external name only loses its row.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_dns( array $record ) {
		unset( $record );

		foreach ( SiteDomains::for_resource( $this->r->id() ) as $domain ) {
			$done = SiteDns::remove_domain( $this->r, $domain['host'], $this->node );

			if ( is_wp_error( $done ) ) {
				return StepResult::retry( 0, 'delete: ' . $this->scrub( $done->get_error_message(), 160 ) );
			}
		}

		return true;
	}

	/**
	 * Delete step db: the account's databases on the shared server.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_db( array $record ) {
		unset( $record );

		$names = array();

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			$names[] = Provision::db_name( $this->r->id(), (string) $site['slug'] );
		}

		if ( array() === $names ) {
			return true; // No site was recorded, so no database was made.
		}

		$result = $this->agent_call(
			'db_drop',
			'db.drop',
			array(
				'account'   => Config::account( $this->r->id() ),
				'databases' => $names,
			)
		);

		return $result instanceof StepResult ? $result : true;
	}

	/**
	 * Delete step ct: the container is stopped and destroyed, but only after it is proven ours. A container
	 * that is not listed is not assumed gone: its id must be confirmed free first.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_ct( array $record ) {
		if ( null === $record['vmid'] ) {
			return true;
		}

		$v = CtOwnership::verify( $this->client, $this->node, $record, $this->r );

		if ( is_wp_error( $v ) && 'absent' === $v->get_error_code() ) {
			$free = Ownership::is_free( $this->client, (int) $record['vmid'] );

			if ( true === $free ) {
				return true;
			}

			return StepResult::retry( 0, 'delete: the container is not listed and its id is not confirmed free' );
		}

		if ( is_wp_error( $v ) ) {
			return $this->refuse_or_retry( $v, 'delete' );
		}

		$off = $this->power_off( $v, 'delete' );

		if ( $off instanceof StepResult ) {
			return $off;
		}

		$done = $this->run_task(
			'delete',
			'DELETE',
			$this->lxc( $v['node'] ),
			array(
				'purge'                      => 1,
				'destroy-unreferenced-disks' => 1,
			),
			'delete'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		$after = CtOwnership::verify( $this->client, $this->node, $record, $this->r );

		if ( is_wp_error( $after ) && 'absent' === $after->get_error_code() ) {
			return true;
		}

		return StepResult::retry( 0, 'delete: the container is still there' . ( true === $done ? '' : ' (the destroy task failed: ' . $done['failed'] . ')' ) );
	}

	/**
	 * Delete step backups: the container's backups are removed from the backup storage (the account is only deleted
	 * after its retention, so they were kept until now). A node without a backup storage has none.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_backups( array $record ) {
		unset( $record );

		if ( '' === (string) $this->node['backup_storage'] ) {
			return true;
		}

		return ( new HostingBackups( $this->r ) )->purge_all();
	}

	/**
	 * Delete step agent: the container is gone, so the agent forgets it (ct.release). Without it a later account on the
	 * same container id would be refused as "belongs to another account".
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_agent( array $record ) {
		if ( null === $record['vmid'] ) {
			return true;
		}

		$done = $this->agent_call( 'ct_release', 'ct.release', array( 'vmid' => (int) $record['vmid'] ) );

		return $done instanceof StepResult ? $done : true;
	}

	/**
	 * Delete step release: the site records, the address, the SSH port and the container id are free again.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return true|StepResult
	 */
	private function delete_release( array $record ) {
		unset( $record );

		if ( ! SiteDomains::remove_all( $this->r->id() ) || ! Sites::remove_all( $this->r->id() ) || ! Records::remove( $this->r->id() ) ) {
			return StepResult::retry( 0, 'delete: the hosting records could not be released' );
		}

		return true;
	}
}
