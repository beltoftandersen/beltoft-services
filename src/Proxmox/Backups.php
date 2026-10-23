<?php
/**
 * Customer backups of a Proxmox VM: the nightly run, the listing, the clean-up.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One backup of one VM at a time, to the cluster's backup storage (a Proxmox Backup Server storage).
 * Backup state lives in its own resource meta keys, never in `task` (the reconciler's): `backup_task`
 * {upid, node, started}, `backup_last_at`, `backup_ok_at`, `backup_error`, `backup_fails`. Every
 * write goes through Resource::locked(), because the nightly run is not a reconciler job.
 *
 * Proxmox endpoints (API viewer, https://pve.proxmox.com/pve-docs/api-viewer/index.html#<path>):
 * POST /nodes/{node}/vzdump (`vmid`, `storage`, `mode`, `notes-template`; needs VM.Backup on the VM
 * and Datastore.AllocateSpace on the storage; its own `prune-backups` parameter is not used because it
 * needs Datastore.Allocate), DELETE /nodes/{node}/storage/{storage}/prunebackups (`vmid`, `type`,
 * `prune-backups`), GET .../storage/{storage}/content (`content`, `vmid`) and
 * DELETE .../storage/{storage}/content/{volume}. There is no `compress` parameter: PBS ignores it.
 *
 * A deleted server's VMID can be reused, so everything a customer can reach (the listing, and with
 * it restore and purge) drops backups taken before the VM record was created.
 */
final class Backups {

	use Steps;

	/**
	 * A server is backed up again when its last attempt is older than this.
	 */
	public const MIN_GAP = 20 * HOUR_IN_SECONDS;

	/**
	 * Consecutive failures that alert the admin (once).
	 */
	public const ALERT_AFTER = 3;

	/**
	 * Cluster settings (token secret decrypted).
	 *
	 * @var array<string, mixed>
	 */
	private $cluster = array();

	/**
	 * @param Resource $r Resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	// -- Rules -----------------------------------------------------------------------------------

	/**
	 * Does this server get backups? The plan asks for them, its cluster has a backup storage, and
	 * it is not an adopted machine.
	 *
	 * @param Resource $r Resource.
	 */
	public static function enabled( Resource $r ): bool {
		if ( true === $r->get( 'linked' ) || self::days( $r ) < 1 ) {
			return false;
		}

		return '' !== self::storage( $r );
	}

	/**
	 * Is a backup due? The server is enabled and ready, and either a backup task of an earlier run
	 * is still to be collected or the last attempt is more than MIN_GAP old.
	 *
	 * @param Resource $r   Resource.
	 * @param int      $now Time.
	 */
	public static function due( Resource $r, int $now ): bool {
		if ( 'ready' !== $r->get( 'state' ) || ! self::enabled( $r ) ) {
			return false;
		}

		$task = $r->get( 'backup_task' );

		if ( is_array( $task ) && ! empty( $task['upid'] ) ) {
			return true;
		}

		return $now - (int) $r->get( 'backup_last_at' ) > self::MIN_GAP;
	}

	/**
	 * Days the plan keeps backups.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days( Resource $r ): int {
		$plan = (array) $r->get( 'plan' );

		return max( 0, (int) ( $plan['backup_days'] ?? 0 ) );
	}

	/**
	 * The backup storage of the cluster this resource's VM is on, or ''.
	 *
	 * @param Resource $r Resource.
	 */
	public static function storage( Resource $r ): string {
		$record = VmRecords::for_resource( $r->id() );

		if ( null === $record ) {
			return '';
		}

		$cluster = Clusters::get( (string) $record['cluster'] );

		return null === $cluster ? '' : (string) $cluster['backup_storage'];
	}

	// -- The nightly step ------------------------------------------------------------------------

	/**
	 * One round for this server. A backup of an earlier round is collected first: still running is
	 * a retry; finished well it is pruned, finished badly it is counted. Otherwise a new backup is
	 * started and waited for (bounded). A failure never fails the resource and never mails the
	 * customer: it is recorded, and the admin hears of it after ALERT_AFTER nights in a row.
	 */
	public function step(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::done();
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$task = $this->r->get( 'backup_task' );

		if ( ! is_array( $task ) || empty( $task['upid'] ) || ! is_string( $task['upid'] ) ) {
			// Counted as attempted before anything is asked, so a server that keeps failing is not tried again at once.
			if ( ! $this->store( array( 'backup_last_at' => time() ) ) ) {
				return StepResult::retry( 60, 'backup: progress could not be stored' );
			}

			$started = $this->start( $record );

			if ( null !== $started ) {
				return $started;
			}

			$task = $this->r->get( 'backup_task' );
		}

		$result = $this->client->wait_task( (string) $task['upid'], $this->wait );

		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return StepResult::retry( 60, 'backup: waiting for the Proxmox task' );
		}

		if ( is_wp_error( $result ) && 'task_failed' !== $result->get_error_code() ) {
			return StepResult::retry( 60, 'backup: ' . $this->text( $result ) );
		}

		if ( true !== $result && ! self::accepted( $result ) ) {
			return $this->failed( 'The backup task ended with: ' . $this->exit_status( $result ) );
		}

		return $this->succeeded( $record );
	}

	/**
	 * Starts vzdump and stores its task. Null: started. Otherwise the round's answer.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function start( array $record ): ?StepResult {
		$v = Ownership::verify( $this->client, $this->fresh( $record ), $this->r );

		if ( is_wp_error( $v ) ) {
			return $this->failed( 'The server could not be verified: ' . $this->text( $v ) );
		}

		$storage = (string) $this->cluster['backup_storage'];

		if ( '' === $storage ) {
			return StepResult::done();
		}

		$upid = $this->client->task(
			'POST',
			'/nodes/' . rawurlencode( $v['node'] ) . '/vzdump',
			array(
				'vmid'           => $record['vmid'],
				'storage'        => $storage,
				'mode'           => 'snapshot',
				'notes-template' => '{{guestname}}',
			)
		);

		if ( is_wp_error( $upid ) ) {
			return $this->failed( 'vzdump was refused: ' . $this->text( $upid ) );
		}

		$stored = $this->store(
			array(
				'backup_task' => array(
					'upid'    => $upid,
					'node'    => (string) $v['node'],
					'started' => time(),
				),
			)
		);

		return $stored ? null : StepResult::retry( 60, 'backup: the task id could not be stored' );
	}

	/**
	 * A backup ended well: recorded, then pruned to the plan's days.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function succeeded( array $record ): StepResult {
		$stored = $this->store(
			array(
				'backup_task'  => null,
				'backup_ok_at' => time(),
				'backup_error' => '',
				'backup_fails' => 0,
			)
		);

		if ( ! $stored ) {
			return StepResult::retry( 60, 'backup: the result could not be stored' );
		}

		$pruned = $this->prune( $record );

		if ( null !== $pruned ) {
			$this->store( array( 'backup_error' => $pruned ) );
		}

		return StepResult::done();
	}

	/**
	 * Prunes the VM's backups to the plan's days. Null: done, or still running (the next night prunes again).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return string|null Admin text of what went wrong.
	 */
	private function prune( array $record ): ?string {
		$v = Ownership::verify( $this->client, $this->fresh( $record ), $this->r );

		if ( is_wp_error( $v ) ) {
			return 'Prune skipped: ' . $this->text( $v );
		}

		$days = max( 1, self::days( $this->r ) );
		$base = '/nodes/' . rawurlencode( $v['node'] ) . '/storage/' . rawurlencode( (string) $this->cluster['backup_storage'] );
		$upid = $this->client->task(
			'DELETE',
			$base . '/prunebackups',
			array(
				'vmid'          => $record['vmid'],
				'type'          => 'qemu',
				'prune-backups' => 'keep-daily=' . $days,
			)
		);

		if ( is_wp_error( $upid ) ) {
			return 'Prune was refused: ' . $this->text( $upid );
		}

		$done = $this->client->wait_task( $upid, $this->wait );

		if ( is_wp_error( $done ) && 'task_pending' !== $done->get_error_code() ) {
			return 'Prune failed: ' . $this->text( $done );
		}

		return null;
	}

	/**
	 * A failed round: the error and the count are stored, the task cleared. The admin is mailed
	 * when the count reaches ALERT_AFTER. The round is over (done), not retried: the next night tries again.
	 *
	 * @param string $why Admin text.
	 */
	private function failed( string $why ): StepResult {
		$why   = $this->scrub( $why, 300 );
		$fails = (int) $this->r->get( 'backup_fails' ) + 1;

		$stored = $this->store(
			array(
				'backup_task'  => null,
				'backup_error' => $why,
				'backup_fails' => $fails,
			)
		);

		if ( $stored && self::ALERT_AFTER === $fails ) {
			$this->r->locked(
				function () use ( $fails, $why ): bool {
					/* Admin text, not shown to customers. */
					Alerts::admin( $this->r, sprintf( 'Backup failed %d nights in a row: %s', $fails, $why ) );

					return true;
				}
			);
		}

		return $stored ? StepResult::done() : StepResult::retry( 60, 'backup: the failure could not be stored' );
	}

	// -- Listing and clean-up --------------------------------------------------------------------

	/**
	 * The backups the customer may see and restore, newest first: of this VM, and taken since its
	 * record was created (a reused VMID never shows an earlier customer's backup).
	 *
	 * @return array<int, array{volid: string, ctime: int, size: int}>|\WP_Error
	 */
	public function list() {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return new \WP_Error( 'beltoft_services_backups', 'No VM is recorded for this service.' );
		}

		if ( null !== $this->prepare( $record ) ) {
			return new \WP_Error( 'beltoft_services_backups', 'The cluster is not available.' );
		}

		$storage = (string) $this->cluster['backup_storage'];

		if ( '' === $storage ) {
			return array();
		}

		$v = Ownership::verify( $this->client, $this->fresh( $record ), $this->r );

		if ( is_wp_error( $v ) ) {
			return new \WP_Error( 'beltoft_services_backups', $this->text( $v ) );
		}

		$answer = $this->client->request(
			'GET',
			'/nodes/' . rawurlencode( $v['node'] ) . '/storage/' . rawurlencode( $storage ) . '/content',
			array(
				'content' => 'backup',
				'vmid'    => $record['vmid'],
			)
		);

		if ( is_wp_error( $answer ) ) {
			return new \WP_Error( 'beltoft_services_backups', $this->text( $answer ) );
		}

		$since = (int) strtotime( (string) $record['created_at'] . ' UTC' );
		$out   = array();

		foreach ( is_array( $answer ) ? $answer : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['volid'], $item['ctime'] ) || ! is_string( $item['volid'] ) ) {
				continue;
			}

			if ( (int) ( $item['vmid'] ?? 0 ) !== (int) $record['vmid'] || (int) $item['ctime'] < $since ) {
				continue;
			}

			$out[] = array(
				'volid' => $item['volid'],
				'ctime' => (int) $item['ctime'],
				'size'  => (int) ( $item['size'] ?? 0 ),
			);
		}

		usort( $out, static fn( $a, $b ) => $b['ctime'] <=> $a['ctime'] );

		return $out;
	}

	/**
	 * Deletes every backup of the listing, one volume per call. Done when none is left; a retry
	 * when a delete is refused or still running. The caller counts the tries.
	 */
	public function purge(): StepResult {
		$list = $this->list();

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 60, 'backup purge: ' . $list->get_error_message() );
		}

		$record = VmRecords::for_resource( $this->r->id() );
		$node   = is_array( $record ) ? $record['node'] : '';

		foreach ( $list as $backup ) {
			$path = '/nodes/' . rawurlencode( $node ) . '/storage/' . rawurlencode( (string) $this->cluster['backup_storage'] ) . '/content/' . rawurlencode( $backup['volid'] );
			$upid = $this->client->task( 'DELETE', $path );

			if ( is_wp_error( $upid ) ) {
				return StepResult::retry( 60, 'backup purge: ' . $this->text( $upid ) );
			}

			$done = $this->client->wait_task( $upid, $this->wait );

			if ( is_wp_error( $done ) ) {
				return StepResult::retry( 60, 'backup purge: ' . $this->text( $done ) );
			}
		}

		return StepResult::done();
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/**
	 * The record as just stored (the node may have moved).
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>
	 */
	private function fresh( array $record ): array {
		$fresh = VmRecords::for_resource( $this->r->id() );

		return $fresh ? $fresh : $record;
	}

	/**
	 * Stores meta under the resource lock.
	 *
	 * @param array<string, mixed> $meta Values.
	 */
	private function store( array $meta ): bool {
		return true === $this->r->locked( fn(): bool => $this->r->set( $meta ) );
	}

	/**
	 * Builds the client of the record's cluster. Failures are permanent.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function prepare( array $record ): ?StepResult {
		$cluster = Clusters::get( (string) $record['cluster'] );

		if ( null === $cluster ) {
			return StepResult::fail( 'The cluster of this VM is not configured' );
		}

		$this->cluster = $cluster;
		$this->secrets = array( (string) $cluster['token_secret'] );

		$gate = Clients::gate( (string) $record['cluster'], $cluster, Clients::live_allowed() );

		if ( null !== $gate ) {
			return StepResult::retry( 0, $gate );
		}

		try {
			$this->client = Clients::for( $cluster );
		} catch ( \Throwable $e ) {
			unset( $e );

			return StepResult::fail( 'The Proxmox client could not be built for this cluster' );
		}

		$wait       = (int) Settings::get( 'task_wait' );
		$this->wait = $wait > 0 ? $wait : 25;

		return null;
	}

	// -- Variations ------------------------------------------------------------------------------

	/**
	 * The sibling of a variation with or without backups: the variation of the same parent whose
	 * attributes are equal to this one's except for exactly one, and whose plan has backup_days
	 * above zero when $with is true (zero when false). This variation must be in the other state
	 * (a variation with backups has no sibling "with backups"). Null when there is none, and then
	 * no backup UI is shown.
	 *
	 * @param \WC_Product_Variation $v    Variation.
	 * @param bool                  $with Whether the sibling has backups.
	 */
	public static function sibling( \WC_Product_Variation $v, bool $with ): ?\WC_Product_Variation {
		$parent = wc_get_product( $v->get_parent_id() );

		if ( ! $parent instanceof \WC_Product_Variable ) {
			return null;
		}

		// A sibling is the other state of the same plan: asking for one with backups of a variation that has them gets nothing.
		$own = \BeltoftServices\Product::plan( $parent->get_id(), $v->get_id() );

		if ( ( (int) ( $own['backup_days'] ?? 0 ) > 0 ) === $with ) {
			return null;
		}

		$mine = $v->get_attributes();

		foreach ( $parent->get_children() as $id ) {
			$other = wc_get_product( $id );

			if ( ! $other instanceof \WC_Product_Variation || $other->get_id() === $v->get_id() || 'publish' !== $other->get_status() ) {
				continue;
			}

			$theirs = $other->get_attributes();
			$keys   = array_unique( array_merge( array_keys( $mine ), array_keys( $theirs ) ) );
			$differ = 0;

			foreach ( $keys as $key ) {
				if ( (string) ( $mine[ $key ] ?? '' ) !== (string) ( $theirs[ $key ] ?? '' ) ) {
					++$differ;
				}
			}

			if ( 1 !== $differ ) {
				continue;
			}

			$plan = \BeltoftServices\Product::plan( $parent->get_id(), $other->get_id() );

			if ( ( (int) ( $plan['backup_days'] ?? 0 ) > 0 ) === $with ) {
				return $other;
			}
		}

		return null;
	}
}
