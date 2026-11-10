<?php
/**
 * Nightly and manual backups of a hosting container.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 10.1, in the pattern of Proxmox\Backup\Backups (its vzdump, prune and listing calls, with container paths and
 * `type=lxc`; that class is not changed). One resumable round per backup, kept in the resource meta `backup`:
 *
 * 1. `backup.prepare`: the agent dumps the customer's databases from the shared server into the container's dump
 *    mount, so the container backup holds them. A failed dump does not stop the backup; the admin is told ("no fresh
 *    dump") because that backup would hold no current database.
 * 2. `POST /nodes/{node}/vzdump` (snapshot mode) onto the node's backup storage.
 * 3. A nightly round prunes with `keep-daily=<backup_days>`; a manual round keeps the 3 newest manual backups.
 * 4. The listing is stored in the meta `backup_list`, so no page ever calls the node.
 *
 * Nightly rounds start in a slot between 00:30 and 05:30 UTC chosen by the account id (the plan lets the agent assign
 * slots by size; the plugin spreads by id until the agent does). "Back up now": 3 a day.
 */
final class HostingBackups {

	use Runs;

	public const HOOK       = 'beltoft_services_hosting_backups';
	public const CRON       = '*/10 * * * *';
	public const MIN_GAP    = 20 * HOUR_IN_SECONDS;
	public const MANUAL_MAX = 3;
	public const MANUAL_DAY = 3;
	public const ALERT_AFTER = 3;
	public const RETRY_GAP   = HOUR_IN_SECONDS;

	/**
	 * Hooks the job and its schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the recurring run once.
	 */
	public static function schedule(): void {
		Jobs::ensure_cron( self::HOOK, self::CRON );
	}

	/**
	 * The job: rounds in progress go on, due accounts start one.
	 *
	 * @param int $only One account only (0: every account).
	 */
	public static function run( int $only = 0 ): void {
		if ( Settings::get( 'pause_all' ) ) {
			return;
		}

		$ids = $only > 0 ? array( $only ) : Resource::live_ids( HostingDriver::KIND );

		foreach ( $ids as $id ) {
			$r = Resource::find( (int) $id );

			if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || ! $r->accepts_actions() ) {
				continue;
			}

			$round = new self( $r );

			if ( ! $round->active() && ! $round->due( Jobs::now() ) ) {
				continue;
			}

			if ( ! $round->active() ) {
				$round->start( 'nightly' );
			}

			$answer = ( new self( Resource::find( $r->id() ) ?? $r ) )->step();

			if ( $answer->is_retry() && 0 < $answer->delay() && $only > 0 ) {
				as_schedule_single_action( time() + max( 5, $answer->delay() ), self::HOOK, array( 'only' => (int) $r->id() ), Jobs::GROUP );
			}
		}
	}

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * Does the account have backups: days in the plan and a backup storage on its node?
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function enabled( Resource $r ): bool {
		$plan   = Plan::typed( (array) $r->get( 'plan' ) );
		$record = Records::for_resource( $r->id() );
		$node   = null === $record ? null : ( Nodes::all()[ $record['node'] ] ?? null );

		return (int) $plan['backup_days'] > 0 && null !== $node && '' !== (string) $node['backup_storage'];
	}

	/**
	 * The minute of the night (UTC, from midnight) this account's nightly backup may start at: 00:30 to 05:30.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function slot_minute( Resource $r ): int {
		$agent = (int) $r->get( 'backup_slot' );

		// The agent assigns slots by size (agent.health, stored by the usage job); until it does, by account id.
		return $agent >= 30 && $agent < 330 ? $agent : 30 + ( $r->id() % 300 );
	}

	/**
	 * Is the nightly backup due: enabled, none running, its slot has come and the last good one is old enough.
	 *
	 * @param int $now Unix time.
	 */
	public function due( int $now ): bool {
		if ( ! self::enabled( $this->r ) || $this->active() || 'ready' !== $this->r->get( 'state' ) ) {
			return false;
		}

		// A failed night is tried again at most once an hour (the job runs every ten minutes: without this it would hammer the node).
		if ( $now < (int) $this->r->get( 'backup_try_at' ) ) {
			return false;
		}

		$minute = (int) gmdate( 'G', $now ) * 60 + (int) gmdate( 'i', $now );

		return $minute >= self::slot_minute( $this->r ) && $minute < 6 * 60 + 30 && $now - (int) $this->r->get( 'backup_ok_at' ) >= self::MIN_GAP;
	}

	/**
	 * Is a round running?
	 */
	public function active(): bool {
		$round = (array) $this->r->get( 'backup' );

		return isset( $round['phase'] ) && '' !== (string) $round['phase'];
	}

	/**
	 * Starts a round.
	 *
	 * @param string $kind nightly or manual.
	 */
	public function start( string $kind ): bool {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;

		return $this->r->set( array( 'backup' => array( 'phase' => 'prepare', 'kind' => 'manual' === $kind ? 'manual' : 'nightly', 'started' => Jobs::now() ) ) );
	}

	/**
	 * Back up now: up to 3 in 24 hours. The round runs in the background.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function now() {
		// One start at a time per account: a double click or two tabs start one backup, not two.
		list( $taken, $result ) = Locks::with( Locks::name( 'hosting-backup', $this->r->id() ), (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), fn() => $this->now_locked() );

		return $taken ? $result : new \WP_Error( 'busy', __( 'A backup is already starting.', 'beltoft-services' ) );
	}

	/**
	 * Back up now with the lock held.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function now_locked() {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;

		if ( ! self::enabled( $this->r ) || 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) ) {
			return new \WP_Error( 'not_ready', __( 'Backups are not available for your hosting right now.', 'beltoft-services' ) );
		}

		if ( $this->active() ) {
			return new \WP_Error( 'busy', __( 'A backup is already running.', 'beltoft-services' ) );
		}

		$now = Jobs::now();
		$log = array_values( array_filter( array_map( 'intval', (array) $this->r->get( 'backup_manual_log' ) ), static fn( $t ) => $now - $t < DAY_IN_SECONDS ) );

		if ( count( $log ) >= self::MANUAL_DAY ) {
			return new \WP_Error( 'limit', __( 'You can start 3 backups a day. Try again later.', 'beltoft-services' ) );
		}

		$log[] = $now;

		if ( ! $this->r->set( array( 'backup_manual_log' => $log ) ) || ! $this->start( 'manual' ) ) {
			return new \WP_Error( 'failed', __( 'The backup could not be started. Try again.', 'beltoft-services' ) );
		}

		as_enqueue_async_action( self::HOOK, array( 'only' => (int) $this->r->id() ), Jobs::GROUP );

		return array( 'started' => true );
	}

	/**
	 * Runs the round as far as it goes.
	 */
	public function step(): StepResult {
		$early = $this->connect();

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		for ( $guard = 0; $guard < 6; $guard++ ) {
			$round = array_merge( array( 'phase' => '', 'kind' => 'nightly' ), (array) ( Resource::find( $this->r->id() ) ?? $this->r )->get( 'backup' ) );

			if ( '' === $round['phase'] ) {
				return StepResult::done();
			}

			$result = $this->{'phase_' . $round['phase']}( $round );

			if ( $result instanceof StepResult ) {
				return $result;
			}
		}

		return StepResult::retry( 0, 'backup: too many phases in one run' );
	}

	/**
	 * Sets the round's phase.
	 *
	 * @param array<string, mixed> $round Round.
	 * @param string               $phase Next phase, '' to end.
	 * @return true|StepResult
	 */
	private function go( array $round, string $phase ) {
		$round['phase'] = $phase;
		$this->r        = Resource::find( $this->r->id() ) ?? $this->r;

		return $this->r->set( array( 'backup' => $round ) ) ? true : StepResult::retry( 0, 'backup: the phase could not be stored' );
	}

	/**
	 * Phase prepare: the agent's database dumps into the container.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_prepare( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->agent_call( 'backup_prepare', 'backup.prepare', array( 'vmid' => (int) $record['vmid'] ) );

		if ( $done instanceof StepResult && $done->is_poll() ) {
			return $done;
		}

		if ( $done instanceof StepResult ) {
			// The backup still runs, but it holds no fresh dump.
			Alerts::admin( $this->r, 'Backup: no fresh database dump for this account (' . $this->scrub( $done->error(), 160 ) . '). The backup runs without it.' );
		}

		if ( is_array( $done ) && array() !== (array) ( $done['failed'] ?? array() ) ) {
			Alerts::admin( $this->r, 'Backup: these databases were not dumped: ' . $this->scrub( implode( ', ', array_map( 'strval', (array) $done['failed'] ) ), 160 ) . '. The backup runs without a fresh dump of them.' );
		}

		return $this->go( $round, 'dump' );
	}

	/**
	 * Phase dump: vzdump of the container onto the backup storage.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_dump( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$v      = $this->verified( $record, 'backup' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$ended = $this->just_finished( 'backup', 'dump' );

		if ( null !== $ended ) {
			return $ended ? $this->go( $round, 'prune' ) : $this->failed( $round, 'vzdump failed (' . (string) ( $this->finished['exit'] ?? '' ) . ')' );
		}

		$done = $this->run_task(
			'backup',
			'POST',
			'/nodes/' . rawurlencode( (string) $v['node'] ) . '/vzdump',
			array(
				'vmid'           => (int) $record['vmid'],
				'storage'        => (string) $this->node['backup_storage'],
				'mode'           => 'snapshot',
				'compress'       => 'zstd',
				'notes-template' => 'manual' === $round['kind'] ? '{{guestname}} manual' : '{{guestname}} nightly',
			),
			'dump'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			return $this->failed( $round, 'vzdump failed (' . $done['failed'] . ')' );
		}

		return $this->go( $round, 'prune' );
	}

	/**
	 * Phase prune: nightly backups to the plan's days, manual ones to the newest 3. A prune that fails is a failed
	 * night, like a failed backup.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_prune( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$v      = $this->verified( $record, 'prune' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$node = rawurlencode( (string) $v['node'] );
		$base = '/nodes/' . $node . '/storage/' . rawurlencode( (string) $this->node['backup_storage'] );

		if ( 'nightly' === $round['kind'] ) {
			$ended = $this->just_finished( 'prune', 'prune' );

			if ( null !== $ended ) {
				return $ended ? $this->go( $round, 'finish' ) : $this->failed( $round, 'prune failed (' . (string) ( $this->finished['exit'] ?? '' ) . ')' );
			}

			$days = max( 1, (int) Plan::typed( (array) $this->r->get( 'plan' ) )['backup_days'] );
			$done = $this->run_task( 'prune', 'DELETE', $base . '/prunebackups', array( 'vmid' => (int) $record['vmid'], 'type' => 'lxc', 'prune-backups' => 'keep-daily=' . $days ), 'prune' );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			if ( true !== $done ) {
				return $this->failed( $round, 'prune failed (' . $done['failed'] . ')' );
			}
		} else {
			$list   = $this->read_list();
			$manual = array_values( array_filter( is_array( $list ) ? $list : array(), static fn( $b ) => 'manual' === $b['kind'] ) );

			foreach ( array_slice( $manual, self::MANUAL_MAX ) as $old ) {
				$done = $this->run_task( 'prune', 'DELETE', $base . '/content/' . rawurlencode( $old['volid'] ), array(), 'prune' );

				if ( $done instanceof StepResult ) {
					return $done;
				}
			}
		}

		return $this->go( $round, 'finish' );
	}

	/**
	 * Phase finish: the listing is stored and a good nightly round is recorded.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_finish( array $round ) {
		$list = $this->read_list();
		$meta = array( 'backup' => array( 'phase' => '', 'kind' => $round['kind'] ), 'backup_error' => '', 'backup_fails' => 0, 'backup_try_at' => 0 );

		if ( is_array( $list ) ) {
			// A safety point is no backup of the customer's: it is not listed and is not restored from the list.
			$meta['backup_list'] = array_values( array_filter( $list, static fn( $b ) => 'safety' !== $b['kind'] ) );
		}

		if ( 'nightly' === $round['kind'] ) {
			$meta['backup_ok_at'] = Jobs::now();
		}

		$this->r = Resource::find( $this->r->id() ) ?? $this->r;

		return $this->r->set( $meta ) ? true : StepResult::retry( 0, 'backup: the result could not be stored' );
	}

	/**
	 * A failed round: counted, the admin told on the first failure and again after ALERT_AFTER in a row. The round
	 * is over, the next night tries again.
	 *
	 * @param array<string, mixed> $round Round.
	 * @param string               $why   Admin text.
	 */
	private function failed( array $round, string $why ) {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$fails   = (int) $this->r->get( 'backup_fails' ) + 1;

		$this->r->set( array( 'backup' => array( 'phase' => '', 'kind' => $round['kind'] ), 'backup_error' => $this->scrub( $why, 200 ), 'backup_fails' => $fails, 'backup_try_at' => Jobs::now() + self::RETRY_GAP ) );

		if ( 1 === $fails || self::ALERT_AFTER === $fails ) {
			Alerts::admin( $this->r, sprintf( 'Backup failed (%d in a row): %s', $fails, $this->scrub( $why, 200 ) ) );
		}

		return StepResult::done( array(), 'Backup failed: round ended' );
	}

	/**
	 * The container's backups on the node's storage, newest first: volid, ctime, size, kind (nightly or manual).
	 * Only volumes of this container id and this plugin's volume format are listed.
	 *
	 * @return array<int, array{volid: string, ctime: int, size: int, kind: string}>|\WP_Error Safety points included (kind safety).
	 */
	public function list() {
		$early = null === $this->client ? $this->connect() : null;

		if ( null !== $early ) {
			return new \WP_Error( 'failed', 'The node is not available.' );
		}

		return $this->read_list();
	}

	/**
	 * The listing, through the node client already connected.
	 *
	 * @return array<int, array{volid: string, ctime: int, size: int, kind: string}>|\WP_Error
	 */
	private function read_list() {
		$record = Records::for_resource( $this->r->id() );

		if ( ! $record || null === $record['vmid'] ) {
			return array();
		}

		$answer = $this->client->request(
			'GET',
			'/nodes/' . rawurlencode( (string) $this->node['pve_node'] ) . '/storage/' . rawurlencode( (string) $this->node['backup_storage'] ) . '/content',
			array(
				'content' => 'backup',
				'vmid'    => (int) $record['vmid'],
			)
		);

		if ( is_wp_error( $answer ) ) {
			return new \WP_Error( 'failed', $this->text( $answer ) );
		}

		$out   = array();
		$owner = Config::account( $this->r->id() ) . ' '; // The notes start with the container's name (the account): a container id that was used by a deleted account keeps its old backups, which are not this customer's.

		foreach ( is_array( $answer ) ? $answer : array() as $item ) {
			if ( ! is_array( $item ) || 0 !== strpos( (string) ( $item['notes'] ?? '' ), $owner ) || ! isset( $item['volid'], $item['ctime'] ) || ! is_string( $item['volid'] ) || (int) ( $item['vmid'] ?? 0 ) !== (int) $record['vmid'] || ! self::volid_fits( (int) $record['vmid'], $item['volid'] ) ) {
				continue;
			}

			$out[] = array(
				'volid' => $item['volid'],
				'ctime' => (int) $item['ctime'],
				'size'  => (int) ( $item['size'] ?? 0 ),
				'kind'  => false !== strpos( (string) ( $item['notes'] ?? '' ), 'safety' ) ? 'safety' : ( false !== strpos( (string) ( $item['notes'] ?? '' ), 'manual' ) ? 'manual' : 'nightly' ),
			);
		}

		usort( $out, static fn( $x, $y ) => $y['ctime'] <=> $x['ctime'] );

		return $out;
	}

	/**
	 * Is the volume id one of a container's own container backups, on a Backup Server or a directory storage? (Proxmox\Backup\Backups::volid_fits is the virtual
	 * machine version and refuses these.)
	 *
	 * @param int    $vmid  Container id.
	 * @param string $volid Volume id.
	 */
	public static function volid_fits( int $vmid, string $volid ): bool {
		$store = '[A-Za-z0-9][A-Za-z0-9._-]*';

		// A Proxmox Backup Server volume, or a file on a directory storage (vzdump-lxc-<vmid>-<date>.tar.zst).
		return 1 === preg_match( '#^' . $store . ':backup/ct/' . $vmid . '/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$#D', $volid )
			|| 1 === preg_match( '#^' . $store . ':backup/vzdump-lxc-' . $vmid . '-[0-9]{4}_[0-9]{2}_[0-9]{2}-[0-9]{2}_[0-9]{2}_[0-9]{2}\.tar(\.zst|\.gz|\.lzo)?$#D', $volid );
	}

	/**
	 * The stored listing, for the pages (no call to the node).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<int, array{volid: string, ctime: int, size: int, kind: string}>
	 */
	public static function stored( Resource $r ): array {
		return array_values( array_filter( (array) $r->get( 'backup_list' ), 'is_array' ) );
	}

	/**
	 * Removes every backup of the container from the storage (a deleted account, after its retention).
	 *
	 * @return true|StepResult
	 */
	public function purge_all() {
		$early = null === $this->client ? $this->connect() : null;

		if ( null !== $early ) {
			return $early;
		}

		$list = $this->read_list();

		if ( is_wp_error( $list ) ) {
			return StepResult::retry( 0, 'delete: ' . $this->scrub( $list->get_error_message(), 160 ) );
		}

		$base = '/nodes/' . rawurlencode( (string) $this->node['pve_node'] ) . '/storage/' . rawurlencode( (string) $this->node['backup_storage'] );

		foreach ( $list as $b ) {
			$volume = $base . '/content/' . rawurlencode( $b['volid'] );

			// A safety backup is protected so no prune can take it: it is let go first, or the node refuses to delete it.
			if ( 'safety' === $b['kind'] ) {
				$off = $this->client->request( 'PUT', $volume, array( 'protected' => 0 ) );

				if ( is_wp_error( $off ) ) {
					return $this->classify( $off, 'delete' );
				}
			}

			$done = $this->run_task( 'delete', 'DELETE', $volume, array(), 'purge' );

			if ( $done instanceof StepResult ) {
				return $done;
			}

			// A delete the node refused (a task that ended with an error) is not done: the account is not released with backups left behind.
			if ( true !== $done ) {
				return StepResult::retry( 300, 'delete: a backup could not be deleted (' . $this->scrub( (string) ( $done['failed'] ?? '' ), 120 ) . ')' );
			}
		}

		return true;
	}
}
