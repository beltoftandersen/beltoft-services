<?php
/**
 * The staging copy of a hosting website.
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
 * A website may have one staging copy: its files and database on a temporary address of its own, to try changes in and push
 * back. The node agent does the work (`site.staging_create`, `_refresh`, `_push`, `_undo`, `_delete`); this class decides what is
 * allowed, keeps what happened in the flags of the website, and runs each long step as an Action Scheduler job under the
 * account's site lock, the way SiteWordPress runs the move of WordPress. The flag `staging` of the website holds:
 *
 * - `copy`, `host`: the copy's name on the node and its temporary address (`<copy>.<preview zone>`);
 * - `state`: '' (no copy), creating, ready, refreshing, pushing, undoing or deleting; `phase` says how far a create or delete is;
 * - `routed`: the copy's address is in DNS and on the edge map;
 * - `files_only`: the push under way leaves the database alone;
 * - `at`, `refreshed`: when the copy was made and last made again;
 * - `undo`: the way back of the last push (`at`, `scope` files or full), offered for 7 days;
 * - `note` and `failed`: what the last job found (failed, unsupported, unsafe) and which job (create, refresh, push, undo, delete).
 *
 * The copy's address is a `preview` row in SiteDomains that belongs to no website (site id 0): DNS, the edge map and the deletion
 * of an account treat it as they treat every temporary address, and the website's own list of names does not show it.
 */
final class SiteStaging {

	use Runs;

	public const HOOK = 'beltoft_services_hosting_staging';

	/** The ways a job can go. */
	public const KINDS = array( 'create', 'refresh', 'push', 'undo', 'delete' );

	/** Attempts of one job (a busy lock, a failed agent job). */
	public const TRIES = 3;

	/** The way back of a push is offered, and kept by the node, this long. */
	public const KEEP = 7 * DAY_IN_SECONDS;

	/** Changes of staging per resource and hour: every one copies a website. */
	public const LIMIT = 10;

	/** The share of the plan's storage above which a copy is not made: it needs as much space as the website. */
	public const STORAGE_PERCENT = 50.0;

	/** Seconds between two looks at a job that waits for the node. */
	private const POLL = 10;

	/**
	 * Hooks the job.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 5 );
	}

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	// What the page and the rules read.

	/**
	 * What a website's flags say about its staging copy, with every key present.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @return array{copy: string, host: string, state: string, phase: string, routed: bool, files_only: bool, at: int, refreshed: int, undo: array{at: int, scope: string}|null, note: string, failed: string, busy: bool, exists: bool}
	 */
	public static function view( array $site ): array {
		$f     = (array) ( $site['flags']['staging'] ?? array() );
		$state = (string) ( $f['state'] ?? '' );
		$undo  = is_array( $f['undo'] ?? null ) && (int) ( $f['undo']['at'] ?? 0 ) + self::KEEP > Jobs::now() && in_array( (string) ( $f['undo']['scope'] ?? '' ), array( 'files', 'full' ), true )
			? array( 'at' => (int) $f['undo']['at'], 'scope' => (string) $f['undo']['scope'] )
			: null;

		return array(
			'copy'       => '' === $state ? '' : (string) ( $f['copy'] ?? '' ),
			'host'       => '' === $state ? '' : (string) ( $f['host'] ?? '' ),
			'state'      => $state,
			'phase'      => (string) ( $f['phase'] ?? '' ),
			'routed'     => ! empty( $f['routed'] ),
			'files_only' => ! empty( $f['files_only'] ),
			'at'         => (int) ( $f['at'] ?? 0 ),
			'refreshed'  => (int) ( $f['refreshed'] ?? 0 ),
			'undo'       => $undo,
			'note'       => (string) ( $f['note'] ?? '' ),
			'failed'     => (string) ( $f['failed'] ?? '' ),
			'busy'       => in_array( $state, array( 'creating', 'refreshing', 'pushing', 'undoing', 'deleting' ), true ),
			'exists'     => '' !== $state,
		);
	}

	/**
	 * The sites of an account that have a staging copy (or are making or deleting one), by slug.
	 *
	 * @param int $resource_id Activation id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function copies( int $resource_id ): array {
		$out = array();

		foreach ( Sites::for_resource( $resource_id ) as $site ) {
			$view = self::view( $site );

			if ( $view['exists'] ) {
				$out[ (string) $site['slug'] ] = $view;
			}
		}

		return $out;
	}

	/**
	 * The edge routes of the copies of an account: the address of each copy that is in DNS.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $ip          The container's address.
	 * @param bool   $suspended   Whether the edge shows the suspended page.
	 * @return array<int, array{host: string, ip: string, suspended: bool}>
	 */
	public static function routes( int $resource_id, string $ip, bool $suspended ): array {
		$out = array();

		foreach ( self::copies( $resource_id ) as $view ) {
			if ( $view['routed'] && '' !== $view['host'] ) {
				$out[] = array( 'host' => $view['host'], 'ip' => $ip, 'suspended' => $suspended );
			}
		}

		return $out;
	}

	/**
	 * A free name for the copy of a website: the website's name cut to fit and `-stg`, or `-stg2` to `-stg9`. It is the first label of
	 * the copy's temporary address, so it must be free as a website's name is.
	 *
	 * @param string $slug Website.
	 * @return string '' when none is free.
	 */
	public static function label_for( string $slug ): string {
		for ( $n = 1; $n <= 9; $n++ ) {
			$suffix = 1 === $n ? '-stg' : '-stg' . $n;
			$label  = rtrim( substr( $slug, 0, 32 - strlen( $suffix ) ), '-' ) . $suffix;

			if ( Sites::valid_slug( $label ) && ! Sites::taken( $label ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Why a copy cannot be made for this website now, as words for the customer, or null.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website.
	 */
	public static function create_problem( Resource $r, string $slug ): ?string {
		$plan   = Plan::typed( (array) $r->get( 'plan' ) );
		$copies = self::copies( $r->id() );

		if ( isset( $copies[ $slug ] ) ) {
			return __( 'This website has a staging copy already.', 'beltoft-services' );
		}

		if ( 'one' === $plan['staging'] && array() !== $copies ) {
			return __( 'Your plan includes one staging copy. Delete the other staging copy first.', 'beltoft-services' );
		}

		return self::space_problem( $r );
	}

	/**
	 * A copy needs as much space as the website: refused when more than half of the plan's storage is used.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function space_problem( Resource $r ): ?string {
		$used = HostingUsage::storage_percent( $r );

		return null !== $used && $used > self::STORAGE_PERCENT ? __( 'There is not enough storage left for a staging copy. A copy needs as much space as the website.', 'beltoft-services' ) : null;
	}

	// Queueing, for the customer actions.

	/**
	 * Records the start of a job and queues it. Nothing is sent to the node here.
	 *
	 * @param Resource $r          Hosting resource.
	 * @param string   $slug       Website.
	 * @param string   $kind       One of KINDS.
	 * @param bool     $files_only A push of files only.
	 * @param string   $label      The copy's name (create).
	 */
	public static function queue( Resource $r, string $slug, string $kind, bool $files_only = false, string $label = '' ): void {
		$site = (array) Sites::find( $r->id(), $slug );
		$view = self::view( $site );
		$now  = Jobs::now();
		$base = array( 'note' => '', 'failed' => '', 'files_only' => $files_only );
		$next = array(
			'create'  => array( 'state' => 'creating', 'phase' => 'dns', 'routed' => false, 'copy' => $label, 'host' => EdgeMap::preview_host( $label ), 'at' => $now, 'refreshed' => 0 ),
			'refresh' => array( 'state' => 'refreshing' ),
			'push'    => array( 'state' => 'pushing' ),
			'undo'    => array( 'state' => 'undoing' ),
			'delete'  => array( 'state' => 'deleting', 'phase' => 'copy' ),
		)[ $kind ];

		$flags = array_merge( (array) ( $site['flags']['staging'] ?? array() ), $base, $next );

		if ( 'create' !== $kind ) {
			$flags['copy'] = $view['copy'];
			$flags['host'] = $view['host'];
		}

		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => $flags ) ) );
		Jobs::schedule_once( time() + 2, self::HOOK, array( $r->id(), $slug, $kind, $files_only ? 1 : 0, 0 ) );
	}

	// The job.

	/**
	 * Background run of one job.
	 *
	 * @param int    $id         Resource id.
	 * @param string $slug       Website.
	 * @param string $kind       One of KINDS.
	 * @param int    $files_only 1 for a push of files only.
	 * @param int    $try        Attempt, from 0.
	 */
	public static function run( int $id, string $slug, string $kind, int $files_only = 0, int $try = 0 ): void {
		$args = array( $id, $slug, $kind, $files_only, $try );

		if ( Settings::get( 'pause_all' ) ) {
			Jobs::schedule_once( time() + 300, self::HOOK, $args );

			return;
		}

		$r = Resource::find( $id );

		if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) || ! in_array( $kind, self::KINDS, true ) ) {
			return;
		}

		$me    = new self( $r );
		$delay = $me->step( $slug, $kind, 1 === $files_only, $try );

		if ( $delay <= 0 ) {
			return;
		}

		if ( $try + 1 >= self::TRIES ) {
			$me->give_up( $slug, $kind );

			return;
		}

		$args[4] = $try + 1;
		Jobs::schedule_once( time() + $delay, self::HOOK, $args );
	}

	/**
	 * One look at the job.
	 *
	 * @param string $slug       Website.
	 * @param string $kind       Job.
	 * @param bool   $files_only A push of files only.
	 * @param int    $try        Attempt.
	 * @return int Seconds until the next look, 0 when it is over.
	 */
	private function step( string $slug, string $kind, bool $files_only, int $try ): int {
		unset( $try );
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$site    = Sites::find( $this->r->id(), $slug );

		if ( null === $site || 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) ) {
			return 0;
		}

		$view = self::view( $site );

		// A job that is not the one the flags wait for (it was queued twice, or ended already) does nothing.
		if ( $view['state'] !== array( 'create' => 'creating', 'refresh' => 'refreshing', 'push' => 'pushing', 'undo' => 'undoing', 'delete' => 'deleting' )[ $kind ] ) {
			return 0;
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early->is_retry() ? max( 60, $early->delay() ) : 0;
		}

		list( $taken, $answer ) = Locks::with( Locks::name( 'hosting-site', $this->r->id() ), (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), fn() => $this->send( $site, $view, $kind, $files_only ) );

		if ( ! $taken ) {
			return 30;
		}

		if ( $answer instanceof StepResult ) {
			if ( $answer->is_poll() ) {
				return max( self::POLL, $answer->poll_seconds() );
			}

			return $this->failed_call( $answer, $slug, $kind );
		}

		return 0;
	}

	/**
	 * Does the next part of the job. Returns the agent's result once the job is done (after it was recorded), or a StepResult to
	 * wait or try again.
	 *
	 * @param array<string, mixed> $site       Site row.
	 * @param array<string, mixed> $view       self::view().
	 * @param string               $kind       Job.
	 * @param bool                 $files_only A push of files only.
	 * @return array<string, mixed>|StepResult
	 */
	private function send( array $site, array $view, string $kind, bool $files_only ) {
		$slug   = (string) $site['slug'];
		$record = (array) Records::for_resource( $this->r->id() );
		$vmid   = (int) $record['vmid'];

		if ( 'create' === $kind && 'dns' === $view['phase'] ) {
			$named = $this->name_in_dns( $site, $view );

			if ( null !== $named ) {
				return $named;
			}

			$view['phase'] = 'copy';
		}

		if ( 'delete' === $kind && 'dns' === $view['phase'] ) {
			return $this->finish_delete( $slug, $view );
		}

		$op   = array( 'create' => 'site.staging_create', 'refresh' => 'site.staging_refresh', 'push' => 'site.staging_push', 'undo' => 'site.staging_undo', 'delete' => 'site.staging_delete' )[ $kind ];
		$args = array( 'vmid' => $vmid, 'slug' => $slug );

		if ( 'create' === $kind ) {
			$args += array( 'copy' => $view['copy'], 'host' => $view['host'] );
		}

		if ( 'push' === $kind && $files_only ) {
			$args['files_only'] = true;
		}

		$answer = $this->agent_call( 'staging_' . $kind . '_' . $slug, $op, $args );

		if ( $answer instanceof StepResult ) {
			return $answer;
		}

		return $this->record( $slug, $kind, $answer, $view );
	}

	/**
	 * Create, first part: the copy's address is recorded and written to DNS.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @param array<string, mixed> $view self::view().
	 * @return StepResult|null Null when the address is in DNS.
	 */
	private function name_in_dns( array $site, array $view ): ?StepResult {
		$host = $view['host'];

		if ( ! SiteDomains::add( $this->r->id(), 0, $host, 'preview' ) ) {
			return StepResult::fail( 'staging: the address "' . $host . '" could not be recorded (is it taken?)' );
		}

		$done = SiteDns::publish_preview( $view['copy'], $this->node );

		if ( is_wp_error( $done ) ) {
			return StepResult::retry( 0, 'staging: ' . $this->scrub( $done->get_error_message(), 160 ) );
		}

		if ( true !== $done ) {
			return StepResult::fail( 'staging: ' . $this->scrub( (string) $done, 160 ) );
		}

		SiteDomains::set_state( $host, 'active' );
		$this->settle( (string) $site['slug'], array( 'phase' => 'copy' ) );

		return null;
	}

	/**
	 * Keeps what the agent answered.
	 *
	 * @param string               $slug   Website.
	 * @param string               $kind   Job.
	 * @param array<string, mixed> $answer The agent's result.
	 * @param array<string, mixed> $view   self::view() as the job began.
	 * @return array<string, mixed>|StepResult
	 */
	private function record( string $slug, string $kind, array $answer, array $view ) {
		$now = Jobs::now();

		switch ( $kind ) {
			case 'create':
				$this->settle( $slug, array( 'state' => 'ready', 'phase' => '', 'routed' => true, 'at' => $now, 'refreshed' => 0, 'note' => '', 'failed' => '' ) );
				$this->push_edge();

				return $answer;

			case 'refresh':
				$this->settle( $slug, array( 'state' => 'ready', 'refreshed' => $now, 'note' => '', 'failed' => '' ) );

				return $answer;

			case 'push':
				if ( ! empty( $answer['failed'] ) ) {
					// The node put the website back as it was.
					$this->settle( $slug, array( 'state' => 'ready', 'note' => 'failed', 'failed' => 'push', 'undo' => null ) );

					return $answer;
				}

				$files_only = empty( $answer['database'] );
				$this->settle( $slug, array( 'state' => 'ready', 'note' => '', 'failed' => '', 'undo' => array( 'at' => $now, 'scope' => $files_only ? 'files' : 'full' ) ) );

				return $answer;

			case 'undo':
				$this->settle( $slug, array( 'state' => 'ready', 'note' => '', 'failed' => '', 'undo' => null ) );

				return $answer;
		}

		// Delete: the node removed the copy; now its address.
		$this->settle( $slug, array( 'phase' => 'dns' ) );

		return $this->finish_delete( $slug, $view );
	}

	/**
	 * Delete, last part: the copy's address leaves DNS, the edge map and the records.
	 *
	 * @param string               $slug Website.
	 * @param array<string, mixed> $view self::view().
	 * @return array<string, mixed>|StepResult
	 */
	private function finish_delete( string $slug, array $view ) {
		$gone = SiteDns::remove_domain( $this->r, $view['host'], $this->node );

		if ( is_wp_error( $gone ) ) {
			return StepResult::retry( 0, 'staging: ' . $this->scrub( $gone->get_error_message(), 160 ) );
		}

		// The way back of the last push belongs to the website, not to the copy: it stays.
		Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'staging' => null === $view['undo'] ? array() : array( 'undo' => $view['undo'] ) ) ) );
		$this->push_edge();

		return array( 'deleted' => true );
	}

	/**
	 * A job the agent refused or failed.
	 *
	 * @param StepResult $answer Result.
	 * @param string     $slug   Website.
	 * @param string     $kind   Job.
	 * @return int Seconds until the next look.
	 */
	private function failed_call( StepResult $answer, string $slug, string $kind ): int {
		$text = $answer->error();

		if ( false !== strpos( $text, 'unknown_op' ) ) {
			$this->give_up( $slug, $kind, 'unsupported' );

			return 0;
		}

		if ( false !== strpos( $text, 'could not be put back' ) ) {
			$this->give_up( $slug, $kind, 'unsafe' );
			Alerts::notify( 'A push from a staging copy failed and could not be undone', array( 'Hosting resource ' . $this->r->id() . ', website ' . $slug . '. The safety point is on the node, in the website\'s .staging-undo folder and /var/backups/db/stgpush/.' ), $this->r, 'hosting-staging-' . $this->r->id() );

			return 0;
		}

		if ( $answer->is_failed() ) {
			$this->give_up( $slug, $kind );

			return 0;
		}

		return 30;
	}

	/**
	 * The job did not work: the website and the copy are as the node left them, and the page says so.
	 *
	 * @param string $slug Website.
	 * @param string $kind Job.
	 * @param string $note failed, unsupported or unsafe.
	 */
	private function give_up( string $slug, string $kind, string $note = 'failed' ): void {
		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return;
		}

		$view = self::view( $site );

		if ( 'create' === $kind ) {
			// Nothing of the copy is left on the node (the agent removes a copy it could not finish): its address goes too.
			if ( '' !== $view['host'] ) {
				$this->connect();
				SiteDns::remove_domain( $this->r, $view['host'], $this->node );
			}

			Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'staging' => array( 'note' => $note, 'failed' => 'create', 'undo' => $view['undo'] ) ) ) );
			$this->push_edge();

			return;
		}

		$this->settle( $slug, array( 'state' => 'ready', 'phase' => '', 'note' => $note, 'failed' => $kind ) );
	}

	/**
	 * Changes some of the staging flags of a website.
	 *
	 * @param string               $slug  Website.
	 * @param array<string, mixed> $flags Keys to set.
	 */
	private function settle( string $slug, array $flags ): void {
		$site = (array) Sites::find( $this->r->id(), $slug );

		Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'staging' => array_merge( (array) ( $site['flags']['staging'] ?? array() ), $flags ) ) ) );
	}

	/**
	 * Sends the edge map again, with or without the copy's address.
	 */
	private function push_edge(): void {
		( new HostingActions( Resource::find( $this->r->id() ) ?? $this->r ) )->sync_edge();
	}

	/**
	 * A website is removed: its copy's address goes from DNS and the records (the node removed the copy with the website).
	 *
	 * @param Resource             $r    Hosting resource.
	 * @param array<string, mixed> $site Site row.
	 * @param array<string, mixed> $node Node settings.
	 * @return true|\WP_Error
	 */
	public static function release( Resource $r, array $site, array $node ) {
		$view = self::view( $site );

		if ( '' === $view['host'] ) {
			return true;
		}

		return SiteDns::remove_domain( $r, $view['host'], $node );
	}
}
