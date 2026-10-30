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
 * {upid, node, started}, `backup_last_at`, `backup_ok_at`, `backup_error`, `backup_fails`,
 * `backup_list` (the listing as of the last good run, for the panels), `backup_purge`. Every
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
	use Prepares;

	/**
	 * A server is backed up again when its last attempt is older than this.
	 */
	public const MIN_GAP = 20 * HOUR_IN_SECONDS;

	/**
	 * Consecutive failures after which the admin gets a reminder. The first failure always alerts.
	 */
	public const ALERT_AFTER = 3;

	/**
	 * Tries to delete a server's backups before its VM is deleted. After this many the admin is
	 * told and the deletion goes on.
	 */
	public const PURGE_TRIES = 3;

	/**
	 * A delete also waits this long, from the first failed try, before it gives up on the backups.
	 */
	public const PURGE_GIVE_UP_AFTER = HOUR_IN_SECONDS;

	/**
	 * A backup task that has not ended after this long counts as failed (it may be gone from Proxmox).
	 */
	public const MAX_TASK = 6 * HOUR_IN_SECONDS;

	/**
	 * Retry text of a backup that is simply still running (not a problem; the chain keeps polling it).
	 */
	public const WAITING = 'backup: waiting for the Proxmox task';

	/**
	 * Retry text of a backup delete that is still running (not a failed try).
	 */
	public const PURGE_PENDING = 'backup purge: waiting for a Proxmox task';

	/**
	 * Option listing the backups left behind by a server whose clean-up was given up.
	 */
	public const ORPHANS = 'beltoft_services_orphaned_backups';

	/**
	 * Days a managed database's backups are kept when its plan names none: a database is always backed up.
	 */
	public const DB_DEFAULT_DAYS = 7;

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
	 * Does this server get backups? The plan asks for them (a managed database always does), its cluster
	 * has a backup storage, and it is not an adopted machine.
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
		// A server on its way out (pause or delete asked, an operation running) is left alone.
		if ( 'ready' !== $r->get( 'state' ) || 'ready' !== $r->get( 'desired' ) || '' !== (string) $r->get( 'op' ) || ! self::enabled( $r ) ) {
			return false;
		}

		$task = $r->get( 'backup_task' );

		if ( is_array( $task ) && ! empty( $task['upid'] ) ) {
			return true;
		}

		return $now - (int) $r->get( 'backup_last_at' ) > self::MIN_GAP;
	}

	/**
	 * Does a volume id have the shape of a backup of this server on its cluster's backup storage?
	 * No Proxmox call: the route uses it. The listing (list()) is the real proof.
	 *
	 * @param Resource $r     Resource.
	 * @param mixed    $volid Volume id as sent.
	 */
	public static function volid_fits( Resource $r, $volid ): bool {
		$record  = VmRecords::for_resource( $r->id() );
		$storage = self::storage( $r );

		if ( null === $record || '' === $storage || ! is_string( $volid ) ) {
			return false;
		}

		return 1 === preg_match( '#^' . preg_quote( $storage, '#' ) . ':backup/vm/' . (int) $record['vmid'] . '/[A-Za-z0-9._:\-]{1,64}$#', $volid );
	}

	/**
	 * Is the server's backups' removal waiting for the nightly run (the plan lost its backups)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function purge_due( Resource $r ): bool {
		return true === $r->get( 'backup_purge' ) && in_array( $r->get( 'state' ), array( 'ready', 'paused' ), true );
	}

	/**
	 * Days the plan keeps backups. A managed database whose plan names none keeps DB_DEFAULT_DAYS: its backups
	 * do not depend on a backup variation.
	 *
	 * @param Resource $r Resource.
	 */
	public static function days( Resource $r ): int {
		$plan = (array) $r->get( 'plan' );
		$days = max( 0, (int) ( $plan['backup_days'] ?? 0 ) );

		return 0 === $days && DbDriver::ID === $r->get( 'driver' ) ? self::DB_DEFAULT_DAYS : $days;
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
	 * customer: it is recorded, and the admin is mailed on the first failed night and again after ALERT_AFTER.
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

		$result  = $this->client->wait_task( (string) $task['upid'], $this->wait );
		$started = (int) ( $task['started'] ?? 0 );
		$late    = $started > 0 && time() - $started > self::MAX_TASK;

		// A task that never ends (gone from Proxmox, a node that stays away) is a failure after a while, so it cannot hold the chain.
		if ( is_wp_error( $result ) && 'task_pending' === $result->get_error_code() ) {
			return $late ? $this->failed( 'The backup task did not finish in time' ) : StepResult::retry( 60, self::WAITING );
		}

		if ( is_wp_error( $result ) && 'task_failed' !== $result->get_error_code() ) {
			return $late ? $this->failed( 'The backup task could not be followed: ' . $this->text( $result ) ) : StepResult::retry( 60, 'backup: ' . $this->text( $result ) );
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
	 * A backup ended well: recorded, then pruned to the plan's days. The night counts as good only when the prune worked too.
	 *
	 * @param array<string, mixed> $record VM record.
	 */
	private function succeeded( array $record ): StepResult {
		$stored = $this->store(
			array(
				'backup_task'  => null,
				'backup_ok_at' => time(),
			)
		);

		if ( ! $stored ) {
			return StepResult::retry( 60, 'backup: the result could not be stored' );
		}

		$pruned = $this->prune( $record );

		// A prune that fails (a backup server that refuses deletes) is a failed night like a failed backup: counted, and alerted by the same rule. Only a night where both worked ends a run of failures.
		if ( null !== $pruned ) {
			$this->failed( $pruned );
		} else {
			$this->store(
				array(
					'backup_error' => '',
					'backup_fails' => 0,
				)
			);
		}

		// The panels show this copy, so rendering a page never calls Proxmox.
		$list = $this->list();

		if ( is_array( $list ) ) {
			$this->store( array( 'backup_list' => $list ) );
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
	 * on the first failed night and again when the count reaches ALERT_AFTER. The round is over (done), not retried: the next night tries again.
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

		if ( $stored && ( 1 === $fails || self::ALERT_AFTER === $fails ) ) {
			$this->r->locked(
				function () use ( $fails, $why ): bool {
					if ( 1 === $fails ) {
						// A new run of failures: an earlier run's alert must not hide this one.
						$this->r->set( array( 'alerted' => null ) );
					}

					/* Admin text, not shown to customers. */
					Alerts::admin(
						$this->r,
						1 === $fails
							? sprintf( 'Backup failed on %s: %s', gmdate( 'Y-m-d' ), $why )
							: sprintf( 'Backup failed %d nights in a row: %s', $fails, $why )
					);

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
			return new \WP_Error( 'absent' === $v->get_error_code() ? 'absent' : 'beltoft_services_backups', $this->text( $v ) );
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

		// The record's time is WordPress's clock, ctime Proxmox's: no skew matters for backups taken after the VM exists. A time that cannot be read shows nothing.
		$since = strtotime( (string) $record['created_at'] . ' UTC' );

		if ( false === $since ) {
			return array();
		}

		$out = array();

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

		// A VM that is not listed cannot be proved ours: nothing is deleted, and nothing can be reached.
		if ( is_wp_error( $list ) && 'absent' === $list->get_error_code() ) {
			return StepResult::done();
		}

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

			if ( is_wp_error( $done ) && 'task_pending' === $done->get_error_code() ) {
				return StepResult::retry( 60, self::PURGE_PENDING );
			}

			if ( is_wp_error( $done ) ) {
				return StepResult::retry( 60, 'backup purge: ' . $this->text( $done ) );
			}
		}

		return StepResult::done();
	}

	/**
	 * A backup that is still running is waited for (a delete or a restore must not race it). Null:
	 * none runs, or it has ended (its task is cleared; the outcome is not counted here).
	 */
	public function collect_running(): ?StepResult {
		$task = $this->r->get( 'backup_task' );

		if ( ! is_array( $task ) || empty( $task['upid'] ) || ! is_string( $task['upid'] ) ) {
			return null;
		}

		$record = VmRecords::for_resource( $this->r->id() );
		$early  = null === $record ? null : $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		if ( null === $record ) {
			return null;
		}

		$waited = $this->client->wait_task( $task['upid'], $this->wait );

		if ( is_wp_error( $waited ) && 'task_pending' === $waited->get_error_code() ) {
			$started = (int) ( $task['started'] ?? 0 );

			if ( $started > 0 && time() - $started > self::MAX_TASK ) {
				return $this->store( array( 'backup_task' => null ) ) ? null : StepResult::retry( 60, 'backup: progress could not be stored' );
			}

			return StepResult::retry( 60, self::PURGE_PENDING );
		}

		return $this->store( array( 'backup_task' => null ) ) ? null : StepResult::retry( 60, 'backup: progress could not be stored' );
	}

	/**
	 * The nightly removal of the backups of a server whose plan lost them. A backup that was still
	 * running is collected first. Done clears the bookkeeping, so a later purchase starts afresh.
	 */
	public function run_purge(): StepResult {
		$early = $this->collect_running();

		if ( null !== $early ) {
			return $early;
		}

		$result = $this->purge();

		if ( ! $result->is_done() ) {
			return $result;
		}

		$stored = $this->store(
			array(
				'backup_purge'   => null,
				'backup_list'    => null,
				'backup_task'    => null,
				'backup_ok_at'   => null,
				'backup_last_at' => null, // Backups bought again are due at once.
				'backup_error'   => '',
				'backup_fails'   => 0,
			)
		);

		return $stored ? $result : StepResult::retry( 60, 'backup purge: the result could not be stored' );
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

	// -- Variations ------------------------------------------------------------------------------

	/**
	 * The sibling of a variation with or without backups: the variation of the same parent whose
	 * attributes are equal to this one's except for exactly one, and whose plan has backup_days
	 * above zero when $with is true (zero when false). This variation must be in the other state
	 * (a variation with backups has no sibling "with backups"). Null when there is none, and then
	 * no backup UI is shown.
	 *
	 * For an existing server (the account panel's links) stock does not matter: its machine exists,
	 * and Stock lets a switch through at add-to-cart. Only a NEW purchase ($new_purchase: the
	 * checkout box) needs a sibling that can be bought now.
	 *
	 * @param \WC_Product_Variation $v            Variation.
	 * @param bool                  $with         Whether the sibling has backups.
	 * @param bool                  $new_purchase Whether a new server is bought (stock and capacity count).
	 */
	public static function sibling( \WC_Product_Variation $v, bool $with, bool $new_purchase = false ): ?\WC_Product_Variation {
		return self::sibling_by( $v, 'backup_days', $with, $new_purchase );
	}

	/**
	 * The same, for any plan field that is 0 or above 0 (the public IP add-on of a database is `public_ip`): the
	 * variation of the same product whose attributes differ in exactly one place and whose plan differs only in that field.
	 *
	 * @param \WC_Product_Variation $v            Variation.
	 * @param string                $field        Plan field.
	 * @param bool                  $with         Whether the sibling has the field above zero.
	 * @param bool                  $new_purchase Whether a new machine is bought (stock and capacity count).
	 */
	public static function sibling_by( \WC_Product_Variation $v, string $field, bool $with, bool $new_purchase = false ): ?\WC_Product_Variation {
		$parent = wc_get_product( $v->get_parent_id() );

		if ( ! $parent instanceof \WC_Product_Variable ) {
			return null;
		}

		// A sibling is the other state of the same plan: asking for one with backups of a variation that has them gets nothing.
		$own = \BeltoftServices\Product::plan( $parent->get_id(), $v->get_id() );

		if ( ( (int) ( $own[ $field ] ?? 0 ) > 0 ) === $with ) {
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

			// The same server, only the backups differ: never a silent plan change, never one that cannot be bought.
			if ( ( (int) ( $plan[ $field ] ?? 0 ) > 0 ) === $with && array_diff_key( $plan, array( $field => 1 ) ) === array_diff_key( $own, array( $field => 1 ) ) && ( $new_purchase ? $other->is_purchasable() && $other->is_in_stock() : '' !== (string) $other->get_price() ) ) {
				return $other;
			}
		}

		return null;
	}

	// -- Panels and switch links -----------------------------------------------------------------

	/**
	 * What the customer's panel shows, from stored data only (no Proxmox call): null when there is
	 * nothing to show or offer.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public static function panel( Resource $r ): ?array {
		if ( true === $r->get( 'linked' ) ) {
			return null;
		}

		$enabled = self::enabled( $r );
		$add     = $enabled ? '' : self::switch_url( $r, true );
		$remove  = $enabled ? self::switch_url( $r, false ) : '';

		if ( ! $enabled && '' === $add ) {
			return null;
		}

		$view = self::listing( $r );
		$list = $view['list'];

		return array(
			'enabled'     => $enabled,
			'days'        => self::days( $r ),
			'list'        => $list,
			'last_ok'     => $view['last_ok'],
			'add_url'     => $add,
			'add_price'   => '' === $add ? '' : self::price_label( $r, true ),
			'remove_url'  => $remove,
			'can_restore' => $enabled && array() !== $list,
		);
	}

	/**
	 * The stored backup list and the time of the last good run, ready to print (no Proxmox call).
	 *
	 * @param Resource $r Resource.
	 * @return array{list: array<int, array{volid: string, when: string, size: string}>, last_ok: string, last_ok_at: int}
	 */
	public static function listing( Resource $r ): array {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$list   = array();
		$stored = $r->get( 'backup_list' );

		foreach ( self::enabled( $r ) && is_array( $stored ) ? $stored : array() as $item ) {
			if ( is_array( $item ) && isset( $item['volid'], $item['ctime'] ) ) {
				$list[] = array(
					'volid' => (string) $item['volid'],
					'when'  => wp_date( $format, (int) $item['ctime'] ),
					'size'  => (int) ( $item['size'] ?? 0 ) > 0 ? size_format( (int) $item['size'] ) : '',
				);
			}
		}

		$ok = (int) $r->get( 'backup_ok_at' );

		return array(
			'list'       => $list,
			'last_ok'    => $ok > 0 ? wp_date( $format, $ok ) : '',
			'last_ok_at' => $ok,
		);
	}

	/**
	 * The price difference of the sibling, for a link: "+€12.00 / month" (excluding tax, as the
	 * product page shows it). The period comes from WooCommerce Subscriptions when it is active.
	 * Empty when there is no sibling.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the sibling has backups.
	 */
	public static function price_label( Resource $r, bool $with ): string {
		$variation = wc_get_product( (int) ( $r->license()['variation_id'] ?? 0 ) );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return '';
		}

		$sibling = self::sibling( $variation, $with );

		return null === $sibling ? '' : self::price_difference( $variation, $sibling );
	}

	/**
	 * "+€12.00 / month" for a variation compared with another one.
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function price_difference( \WC_Product_Variation $from, \WC_Product_Variation $to ): string {
		// Pay as you go variations cost 0 at checkout: the difference is the hourly price of the extra monthly amount.
		if ( \BeltoftServices\Usage\Rates::is_usage( $to ) ) {
			$was  = \BeltoftServices\Usage\Rates::monthly( $from );
			$now  = \BeltoftServices\Usage\Rates::monthly( $to );
			$rate = null === $was || null === $now ? 0.0 : \BeltoftServices\Usage\Rates::hourly( $now - $was, \BeltoftServices\Usage\Rates::premium( $to->get_parent_id() ) );

			/* translators: %s: price per hour such as EUR 0.0041 */
			return sprintf( __( '%s / hour', 'beltoft-services' ), ( $rate < 0 ? '-' : '+' ) . \BeltoftServices\Usage\Rates::money( abs( $rate ), 4 ) );
		}

		$diff = (float) wc_get_price_excluding_tax( $to ) - (float) wc_get_price_excluding_tax( $from );
		$text = ( $diff < 0 ? '-' : '+' ) . html_entity_decode( wp_strip_all_tags( wc_price( abs( $diff ) ) ), ENT_QUOTES, 'UTF-8' );

		if ( class_exists( '\WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $to ) ) {
			$period = (string) \WC_Subscriptions_Product::get_period( $to );
			$every  = (int) \WC_Subscriptions_Product::get_interval( $to );

			if ( '' !== $period && function_exists( 'wcs_get_subscription_period_strings' ) ) {
				$text .= ' / ' . (string) wcs_get_subscription_period_strings( max( 1, $every ), $period );
			}
		}

		return $text;
	}

	/**
	 * The link that switches the server's subscription to the sibling with or without backups:
	 * WooCommerce Subscriptions' own switch arguments (subscription, item, nonce, from
	 * WC_Subscriptions_Switcher::get_switch_url()) on an add-to-cart URL of the sibling that lands
	 * on the checkout. WCS accepts that add-to-cart with a valid nonce in the URL. Empty when WCS
	 * is inactive, there is no sibling, or the item cannot be switched by this customer.
	 *
	 * @param Resource $r    Resource.
	 * @param bool     $with Whether the target has backups.
	 */
	public static function switch_url( Resource $r, bool $with ): string {
		$license      = $r->license();
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$variation    = $variation_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return '';
		}

		$sibling = self::sibling( $variation, $with );

		return null === $sibling ? '' : self::switch_to( $r, $sibling );
	}

	/**
	 * The WooCommerce Subscriptions switch link of the server's subscription item to another variation of the same
	 * product (the shared part of the backups and the upgrade links). Empty when WCS is inactive, the item cannot be
	 * switched by this customer, or the server has no subscription item.
	 *
	 * @param Resource              $r      Resource.
	 * @param \WC_Product_Variation $target The variation to switch to.
	 */
	public static function switch_to( Resource $r, \WC_Product_Variation $target ): string {
		if ( ! class_exists( '\WC_Subscriptions_Switcher' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$license      = $r->license();
		$variation_id = (int) ( $license['variation_id'] ?? 0 );
		$sub_id       = (int) ( $license['subscription_id'] ?? 0 );

		if ( $sub_id < 1 || $variation_id < 1 ) {
			return '';
		}

		$subscription = wcs_get_subscription( $sub_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		foreach ( $subscription->get_items() as $item_id => $item ) {
			if ( (int) $item->get_variation_id() !== $variation_id ) {
				continue;
			}

			if ( ! \WC_Subscriptions_Switcher::can_item_be_switched_by_user( $item, $subscription ) ) {
				return '';
			}

			$query = array();
			wp_parse_str( (string) wp_parse_url( \WC_Subscriptions_Switcher::get_switch_url( $item_id, $item, $subscription ), PHP_URL_QUERY ), $query );

			$args = array_intersect_key( $query, array_flip( array( 'switch-subscription', 'item', '_wcsnonce' ) ) );

			if ( 3 !== count( $args ) ) {
				return '';
			}

			$args['add-to-cart'] = $target->get_parent_id();
			$args['variation_id'] = $target->get_id();

			foreach ( $target->get_attributes() as $name => $value ) {
				$args[ 'attribute_' . $name ] = $value;
			}

			return add_query_arg( $args, wc_get_checkout_url() );
		}

		return '';
	}
}
