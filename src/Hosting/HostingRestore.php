<?php
/**
 * Restoring a hosting account from a backup, with a safety point and Undo.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 10.2. Three restores, each one resumable round kept in the resource meta `restore`:
 *
 * - `account`: the whole container from a backup (`POST /nodes/{node}/lxc` with `restore=1`, `force=1`), then the
 *   databases of every site imported again from the dumps that backup holds (the databases live on the shared
 *   server, so a container restore alone would leave them as they are now);
 * - `files`: one site's files, by the agent (`backup.file_restore`);
 * - `database`: one site's database, by the agent (`backup.db_restore`).
 *
 * Before every restore a **safety point** is made: the agent dumps the account's databases under a label
 * (`backup.prepare` with `label`) and the container is snapshotted (`POST .../lxc/{vmid}/snapshot`), both with the
 * same name. It is kept 24 hours. **Undo** goes back to it: the container is rolled back to the snapshot and the
 * databases imported from the labelled dump, so files and databases stay in step. Only a backup the plugin lists for
 * this container can be restored, and one round runs at a time.
 */
final class HostingRestore {

	use Runs;

	public const HOOK        = 'beltoft_services_hosting_restore';
	public const SAFETY_TTL  = DAY_IN_SECONDS;
	public const ASSUMED_MBS = 50;

	/**
	 * Hooks the background run and the expiry of safety points.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( HostingBackups::HOOK, array( __CLASS__, 'expire_all' ), 20 );
	}

	/**
	 * Background run of one account's round.
	 *
	 * @param int $id Resource id.
	 */
	public static function run( int $id ): void {
		if ( Settings::get( 'pause_all' ) ) {
			as_schedule_single_action( time() + 300, self::HOOK, array( $id ), Jobs::GROUP );

			return;
		}

		$r = Resource::find( $id );

		if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || ! self::active( $r ) ) {
			return;
		}

		$answer = ( new self( $r ) )->step();

		if ( $answer->is_retry() ) {
			as_schedule_single_action( time() + max( 5, $answer->delay() ), self::HOOK, array( $id ), Jobs::GROUP );
		}
	}

	/**
	 * Deletes the safety points that are 24 hours old (after the backups job, so it needs no schedule of its own).
	 */
	public static function expire_all(): void {
		foreach ( Resource::live_ids( HostingDriver::KIND ) as $id ) {
			$r = Resource::find( (int) $id );

			if ( $r && HostingDriver::ID === $r->get( 'driver' ) && self::safety( $r ) && self::safety( $r )['expires'] <= Jobs::now() && ! self::active( $r ) ) {
				( new self( $r ) )->expire();
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
	 * Is a round running?
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function active( Resource $r ): bool {
		$round = (array) $r->get( 'restore' );

		return isset( $round['kind'] ) && '' !== (string) $round['kind'];
	}

	/**
	 * The safety point: name, label, time, expiry, scope.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array{name: string, at: int, expires: int, scope: string, mode: string, volid: string}|null Null when none is kept.
	 */
	public static function safety( Resource $r ): ?array {
		$s = $r->get( 'safety' );

		return is_array( $s ) && ! empty( $s['name'] ) && (int) ( $s['expires'] ?? 0 ) > 0 ? array_merge( array( 'scope' => '', 'mode' => 'snapshot', 'volid' => '' ), $s ) : null;
	}

	/**
	 * Starts a restore. The round runs in the background.
	 *
	 * @param string $kind  account, files or database.
	 * @param string $volid Backup volume id, from the listing of this container.
	 * @param string $slug  Site (files and database).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function start( string $kind, string $volid, string $slug = '' ) {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;

		if ( 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) || ! HostingBackups::enabled( $this->r ) ) {
			return new \WP_Error( 'not_ready', __( 'Restores are not available for your hosting right now.', 'beltoft-services' ) );
		}

		if ( self::active( $this->r ) || ( new HostingBackups( $this->r ) )->active() ) {
			return new \WP_Error( 'busy', __( 'Something else is running on your hosting. Try again in a few minutes.', 'beltoft-services' ) );
		}

		if ( ! in_array( $kind, array( 'account', 'files', 'database' ), true ) ) {
			return new \WP_Error( 'invalid', __( 'Choose what to restore.', 'beltoft-services' ) );
		}

		if ( 'account' !== $kind && null === Sites::find( $this->r->id(), $slug ) ) {
			return new \WP_Error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$backup = null;

		foreach ( HostingBackups::stored( $this->r ) as $b ) {
			if ( $b['volid'] === $volid ) {
				$backup = $b;
			}
		}

		if ( null === $backup ) {
			return new \WP_Error( 'not_found', __( 'That backup is not in your list.', 'beltoft-services' ) );
		}

		$round = array(
			'kind'     => $kind,
			'volid'    => $volid,
			'slug'     => $slug,
			'snapshot' => 'b' . (int) $backup['ctime'],
			'safety'   => 'safety' . Jobs::now(),
			'done'     => array(),
			'started'  => Jobs::now(),
		);

		if ( ! $this->r->set( array( 'restore' => $round ) ) ) {
			return new \WP_Error( 'failed', __( 'The restore could not be started. Try again.', 'beltoft-services' ) );
		}

		as_enqueue_async_action( self::HOOK, array( (int) $this->r->id() ), Jobs::GROUP );

		return array(
			'started'  => true,
			'minutes'  => 'account' === $kind ? self::estimate_minutes( (int) $backup['size'] ) : 2,
		);
	}

	/**
	 * Undo: back to the safety point.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function undo() {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$safety  = self::safety( $this->r );

		if ( 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) || self::active( $this->r ) || ( new HostingBackups( $this->r ) )->active() ) {
			return new \WP_Error( 'busy', __( 'Something else is running on your hosting. Try again in a few minutes.', 'beltoft-services' ) );
		}

		if ( null === $safety || $safety['expires'] <= Jobs::now() ) {
			return new \WP_Error( 'not_found', __( 'There is nothing to undo.', 'beltoft-services' ) );
		}

		$round = array(
			'kind'     => 'undo',
			'volid'    => '',
			'slug'     => '',
			'snapshot' => $safety['name'],
			'safety'   => $safety['name'],
			'done'     => array(),
			'started'  => Jobs::now(),
		);

		if ( ! $this->r->set( array( 'restore' => $round ) ) ) {
			return new \WP_Error( 'failed', __( 'The undo could not be started. Try again.', 'beltoft-services' ) );
		}

		as_enqueue_async_action( self::HOOK, array( (int) $this->r->id() ), Jobs::GROUP );

		return array( 'started' => true );
	}

	/**
	 * A rough time for restoring a whole account: the backup size over an assumed rate, in minutes (filter
	 * `beltoft_services_hosting_restore_mbs` sets the rate in MB a second).
	 *
	 * @param int $bytes Backup size.
	 */
	public static function estimate_minutes( int $bytes ): int {
		$rate = max( 1, (int) apply_filters( 'beltoft_services_hosting_restore_mbs', self::ASSUMED_MBS ) );

		return max( 2, (int) ceil( $bytes / 1048576 / $rate / 60 ) + 1 );
	}

	/**
	 * The phases of each kind of round.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	private static function phases( string $kind ): array {
		return array(
			'account'  => array( 'safety', 'stop', 'restore', 'start', 'databases' ),
			'files'    => array( 'safety', 'files' ),
			'database' => array( 'safety', 'database' ),
			'undo'     => array( 'stop', 'rollback', 'start', 'databases' ),
		)[ $kind ] ?? array();
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

		$round = (array) ( Resource::find( $this->r->id() ) ?? $this->r )->get( 'restore' );

		foreach ( self::phases( (string) ( $round['kind'] ?? '' ) ) as $phase ) {
			if ( in_array( $phase, (array) $round['done'], true ) ) {
				continue;
			}

			$result = $this->{'phase_' . $phase}( $round );

			if ( $result instanceof StepResult ) {
				return $result->is_failed() ? $this->abort( $round, $result->error() ) : $result;
			}

			$round['done'][] = $phase;
			$this->r         = Resource::find( $this->r->id() ) ?? $this->r;

			if ( ! $this->r->set( array( 'restore' => $round ) ) ) {
				return StepResult::retry( 0, 'restore: the phase could not be stored' );
			}

			$this->progressed = true;
		}

		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$this->r->set( array( 'restore' => array( 'kind' => '' ), 'restore_last' => array( 'kind' => $round['kind'], 'at' => Jobs::now(), 'ok' => true ) ) );

		return $this->signal( StepResult::done( array(), 'Restore finished' ) );
	}

	/**
	 * A round that cannot go on: it ends, the failure is kept, the account is not left half way without a word.
	 *
	 * @param array<string, mixed> $round Round.
	 * @param string               $why   Admin text.
	 */
	private function abort( array $round, string $why ): StepResult {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$this->r->set( array( 'restore' => array( 'kind' => '' ), 'restore_last' => array( 'kind' => $round['kind'], 'at' => Jobs::now(), 'ok' => false, 'why' => $this->scrub( $why, 200 ) ) ) );
		\BeltoftServices\Alerts::admin( $this->r, 'A restore failed and stopped: ' . $this->scrub( $why, 200 ) );

		return StepResult::done( array(), 'Restore failed' );
	}

	/**
	 * The account's databases for the sites.
	 *
	 * @return string[] Site slug => database name.
	 */
	private function databases(): array {
		$out = array();

		foreach ( Sites::for_resource( $this->r->id() ) as $site ) {
			$out[ (string) $site['slug'] ] = Provision::db_name( $this->r->id(), (string) $site['slug'] );
		}

		return $out;
	}

	/**
	 * Phase safety: the labelled dump, then the snapshot. A safety point made before replaces the old one.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_safety( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$v      = $this->verified( $record, 'safety' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$old = self::safety( $this->r );

		if ( null !== $old && $old['name'] !== $round['safety'] ) {
			$gone = $this->discard( $old, $v );

			if ( $gone instanceof StepResult ) {
				return $gone;
			}

			$this->r->set( array( 'safety' => null ) );
		}

		$dump = $this->agent_call( 'safety_dump', 'backup.prepare', array( 'vmid' => (int) $record['vmid'], 'label' => $round['safety'] ) );

		if ( $dump instanceof StepResult ) {
			// No restore without a way back for the databases.
			return $dump->is_poll() ? $dump : StepResult::fail( 'The safety dump failed: ' . $dump->error() );
		}

		$mode = $this->mode( (string) $round['kind'] );

		if ( 'vzdump' === $mode ) {
			return $this->safety_vzdump( $round, $v );
		}

		$ended = $this->just_finished( 'safety', 'snapshot' );
		$snap  = null === $ended ? $this->run_task( 'safety', 'POST', $this->lxc( $v['node'] ) . '/snapshot', array( 'snapname' => $round['safety'], 'description' => 'safety point before a restore' ), 'snapshot' ) : $ended;

		if ( $snap instanceof StepResult ) {
			return $snap;
		}

		if ( true !== $snap ) {
			return StepResult::fail( 'The safety snapshot failed (' . ( is_array( $snap ) ? $snap['failed'] : (string) ( $this->finished['exit'] ?? '' ) ) . ')' );
		}

		return $this->keep_safety( $round, 'snapshot', '' );
	}

	/**
	 * How the safety point of a kind of round is made: a whole-account restore uses the node's setting (a vzdump
	 * unless the node was shown to keep snapshots over a forced restore), every other round a snapshot.
	 *
	 * @param string $kind Kind of round.
	 */
	private function mode( string $kind ): string {
		return 'account' === $kind && 'snapshot' !== (string) ( $this->node['restore_safety'] ?? 'vzdump' ) ? 'vzdump' : 'snapshot';
	}

	/**
	 * The safety point as a protected backup: vzdump, found in the listing by its note, protected against the
	 * nightly prune.
	 *
	 * @param array<string, mixed> $round Round.
	 * @param array<string, mixed> $v     Verified container.
	 * @return true|StepResult
	 */
	private function safety_vzdump( array $round, array $v ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$ended  = $this->just_finished( 'safety', 'vzdump' );
		$done   = null === $ended ? $this->run_task(
			'safety',
			'POST',
			'/nodes/' . rawurlencode( (string) $v['node'] ) . '/vzdump',
			array(
				'vmid'           => (int) $record['vmid'],
				'storage'        => (string) $this->node['backup_storage'],
				'mode'           => 'snapshot',
				'notes-template' => '{{guestname}} safety',
			),
			'vzdump'
		) : $ended;

		if ( $done instanceof StepResult ) {
			return $done;
		}

		if ( true !== $done ) {
			return StepResult::fail( 'The safety backup failed (' . ( is_array( $done ) ? $done['failed'] : (string) ( $this->finished['exit'] ?? '' ) ) . ')' );
		}

		$list = ( new HostingBackups( $this->r ) )->list();
		$volid = '';

		foreach ( is_array( $list ) ? $list : array() as $b ) {
			if ( 'safety' === $b['kind'] ) {
				$volid = $b['volid'];
				break;
			}
		}

		if ( '' === $volid ) {
			return StepResult::fail( 'The safety backup is not in the listing' );
		}

		$kept = $this->client->request( 'PUT', '/nodes/' . rawurlencode( (string) $v['node'] ) . '/storage/' . rawurlencode( (string) $this->node['backup_storage'] ) . '/content/' . rawurlencode( $volid ), array( 'protected' => 1 ) );

		if ( is_wp_error( $kept ) ) {
			return $this->classify( $kept, 'safety' );
		}

		return $this->keep_safety( $round, 'vzdump', $volid );
	}

	/**
	 * Stores the safety point.
	 *
	 * @param array<string, mixed> $round Round.
	 * @param string               $mode  snapshot or vzdump.
	 * @param string               $volid The backup volume (vzdump).
	 * @return true|StepResult
	 */
	private function keep_safety( array $round, string $mode, string $volid ) {
		$now = Jobs::now();

		return $this->r->set( array( 'safety' => array( 'name' => $round['safety'], 'at' => $now, 'expires' => $now + self::SAFETY_TTL, 'scope' => (string) $round['kind'], 'mode' => $mode, 'volid' => $volid ) ) ) ? true : StepResult::retry( 0, 'restore: the safety point could not be stored' );
	}

	/**
	 * Removes a safety point: the snapshot, or the protected backup (unprotected first).
	 *
	 * @param array<string, mixed> $safety The safety point.
	 * @param array<string, mixed> $v      Verified container.
	 * @return true|StepResult
	 */
	private function discard( array $safety, array $v ) {
		if ( 'vzdump' === ( $safety['mode'] ?? 'snapshot' ) && '' !== (string) ( $safety['volid'] ?? '' ) ) {
			$base = '/nodes/' . rawurlencode( (string) $v['node'] ) . '/storage/' . rawurlencode( (string) $this->node['backup_storage'] ) . '/content/' . rawurlencode( (string) $safety['volid'] );
			$off  = $this->client->request( 'PUT', $base, array( 'protected' => 0 ) );

			if ( is_wp_error( $off ) ) {
				return $this->classify( $off, 'safety' );
			}

			return true === ( $gone = $this->run_task( 'safety', 'DELETE', $base, array(), 'voldel' ) ) ? true : ( $gone instanceof StepResult ? $gone : StepResult::retry( 0, 'safety: the old safety backup could not be deleted' ) );
		}

		$gone = $this->run_task( 'safety', 'DELETE', $this->lxc( $v['node'] ) . '/snapshot/' . $safety['name'], array(), 'snapdel' );

		return $gone instanceof StepResult ? $gone : true;
	}

	/**
	 * A safety point made on its own (before an automatic PHP switch). A safety point of the last 24 hours is
	 * reused, so a customer's Undo is never replaced by it. Nothing is made while a round runs.
	 *
	 * @param string $scope What it is for (php).
	 * @return true|StepResult True: a safety point is there. Otherwise retry (wait, try again next time) or fail.
	 */
	public function make_safety( string $scope ) {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;

		if ( self::active( $this->r ) || ( new HostingBackups( $this->r ) )->active() ) {
			return StepResult::retry( 0, 'safety: another round is running' );
		}

		$early = null === $this->client ? $this->connect() : null;

		if ( null !== $early ) {
			return $early;
		}

		$early = $this->poll_task();

		if ( null !== $early ) {
			return $early;
		}

		$safety = self::safety( $this->r );

		if ( null !== $safety && $safety['expires'] > Jobs::now() ) {
			return true;
		}

		return $this->phase_safety( array( 'safety' => 'safety' . Jobs::now(), 'kind' => $scope ) );
	}

	/**
	 * Phase stop.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_stop( array $round ) {
		unset( $round );
		$v = $this->verified( (array) Records::for_resource( $this->r->id() ), 'stop' );

		return $v instanceof StepResult ? $v : $this->power_off( $v, 'restore' );
	}

	/**
	 * Phase restore: the container from the backup archive.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_restore( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$v      = $this->verified( $record, 'restore' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		// The archive is checked against the listing again: only this container's own backups are restored.
		if ( ! HostingBackups::volid_fits( (int) $record['vmid'], (string) $round['volid'] ) ) {
			return StepResult::fail( 'The backup is not one of this container' );
		}

		$ended = $this->just_finished( 'restore', 'restore' );

		if ( null !== $ended ) {
			return $ended ? true : StepResult::fail( 'The container restore failed (' . (string) ( $this->finished['exit'] ?? '' ) . ')' );
		}

		$done = $this->run_task(
			'restore',
			'POST',
			'/nodes/' . rawurlencode( (string) $v['node'] ) . '/lxc',
			array(
				'vmid'       => (int) $record['vmid'],
				'ostemplate' => (string) $round['volid'],
				'restore'    => 1,
				'force'      => 1,
			),
			'restore'
		);

		if ( $done instanceof StepResult ) {
			return $done;
		}

		return true === $done ? true : StepResult::fail( 'The container restore failed (' . $done['failed'] . ')' );
	}

	/**
	 * Phase start.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_start( array $round ) {
		unset( $round );
		$v = $this->verified( (array) Records::for_resource( $this->r->id() ), 'start' );

		return $v instanceof StepResult ? $v : $this->power_on( $v, 'restore' );
	}

	/**
	 * Phase rollback (undo): the container goes back to the safety snapshot.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_rollback( array $round ) {
		$safety = self::safety( $this->r );

		// A safety backup: the container is restored from it, like any backup.
		if ( null !== $safety && 'vzdump' === ( $safety['mode'] ?? 'snapshot' ) ) {
			return $this->phase_restore( array_merge( $round, array( 'volid' => (string) $safety['volid'] ) ) );
		}

		$v = $this->verified( (array) Records::for_resource( $this->r->id() ), 'rollback' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$ended = $this->just_finished( 'rollback', 'rollback' );

		if ( null !== $ended ) {
			return $ended ? true : StepResult::fail( 'The rollback failed (' . (string) ( $this->finished['exit'] ?? '' ) . ')' );
		}

		$done = $this->run_task( 'rollback', 'POST', $this->lxc( $v['node'] ) . '/snapshot/' . $round['safety'] . '/rollback', array(), 'rollback' );

		if ( $done instanceof StepResult ) {
			return $done;
		}

		return true === $done ? true : StepResult::fail( 'The rollback failed (' . $done['failed'] . ')' );
	}

	/**
	 * Phase databases: every site's database from the dump of the backup (or, for an undo, of the safety point).
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_databases( array $round ) {
		foreach ( $this->databases() as $slug => $database ) {
			$done = $this->agent_call( 'restore_db_' . $slug, 'backup.db_restore', array( 'account' => Config::account( $this->r->id() ), 'database' => $database, 'snapshot' => $round['snapshot'] ) );

			if ( $done instanceof StepResult ) {
				return $done;
			}
		}

		return true;
	}

	/**
	 * Phase files: one site's files.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_files( array $round ) {
		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->agent_call( 'restore_files_' . $round['slug'], 'backup.file_restore', array( 'vmid' => (int) $record['vmid'], 'slug' => (string) $round['slug'], 'snapshot' => $round['snapshot'] ) );

		return $done instanceof StepResult ? $done : true;
	}

	/**
	 * Phase database: one site's database.
	 *
	 * @param array<string, mixed> $round Round.
	 * @return true|StepResult
	 */
	private function phase_database( array $round ) {
		$done = $this->agent_call( 'restore_db_' . $round['slug'], 'backup.db_restore', array( 'account' => Config::account( $this->r->id() ), 'database' => Provision::db_name( $this->r->id(), (string) $round['slug'] ), 'snapshot' => $round['snapshot'] ) );

		return $done instanceof StepResult ? $done : true;
	}

	/**
	 * Deletes the safety snapshot (after its 24 hours).
	 */
	public function expire(): void {
		$safety = self::safety( $this->r );

		if ( null === $safety || null !== ( null === $this->client ? $this->connect() : null ) ) {
			return;
		}

		$v = $this->verified( (array) Records::for_resource( $this->r->id() ), 'expire' );

		if ( $v instanceof StepResult ) {
			return;
		}

		if ( true === $this->discard( $safety, $v ) ) {
			$this->r->set( array( 'safety' => null ) );
		}
	}
}
