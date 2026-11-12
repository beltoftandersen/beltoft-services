<?php
/**
 * The address WordPress itself uses on a hosting website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A website is installed on its temporary address. Connecting a domain only routes it: until WordPress is moved to it,
 * WordPress builds its links, its login address and its canonical URLs on the temporary address. This class moves it.
 * The work is the agent's `site.wp_address` (a fresh database dump, `wp search-replace` over every table, `home` and
 * `siteurl`, caches, and the dump put back when anything fails); this class decides when, runs it as an Action Scheduler
 * job under the site lock, and keeps what happened in the flags of the website:
 *
 * - `wp_address`: the host WordPress uses now ('' means the temporary address);
 * - `wp_prev` and `wp_at`: the host before the last move and when it moved (the way back is offered for 7 days);
 * - `wp_moving`: the host a move is under way to, `wp_manual` whether the customer asked for it;
 * - `wp_note`: '' or what the last move found: `foreign` (the customer set another address in WordPress, left alone),
 *   `failed` (the move failed and the dump was put back), `unsupported` (a manual move on a node whose agent cannot do it).
 *
 * It moves by itself only twice: when the website's first own domain is live (both names of a pair, the DNS shows our
 * address) and WordPress is still on the temporary address, and when the customer chooses the other name of a pair that
 * WordPress is on. Everything else is the customer's button. An agent without the operation is left alone: no error is
 * shown and nothing is retried for a few hours.
 */
final class SiteWordPress {

	use Runs;

	public const HOOK = 'beltoft_services_hosting_wp_address';

	/** Attempts of one move (a busy lock, a DNS that does not show our address yet, a failed job). */
	public const TRIES = 12;

	/** The way back, and the agent's safety dump, last this long. */
	public const KEEP = 7 * DAY_IN_SECONDS;

	/** How long an agent that does not know the operation is not asked again by the automatic move. */
	public const UNSUPPORTED_FOR = 6 * HOUR_IN_SECONDS;

	/** Customer moves per resource and hour. */
	public const LIMIT = 5;

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
	 * The host WordPress uses on a website.
	 *
	 * @param array<string, mixed> $site    Site row.
	 * @param string               $preview The temporary address.
	 */
	public static function address( array $site, string $preview ): string {
		$host = (string) ( $site['flags']['wp_address'] ?? '' );

		return '' === $host ? $preview : $host;
	}

	/**
	 * What the page shows about the move: address, note, way back, move under way.
	 *
	 * @param array<string, mixed>             $site    Site row.
	 * @param array<int, array<string, mixed>> $rows    The site's name rows, the preview name included.
	 * @param string                           $preview The temporary address.
	 * @return array{address: string, note: string, seen: string, moving: string, prev: string}
	 */
	public static function view( array $site, array $rows, string $preview ): array {
		$flags = (array) $site['flags'];
		$prev  = (string) ( $flags['wp_prev'] ?? '' );
		$at    = (int) ( $flags['wp_at'] ?? 0 );
		$now   = Jobs::now();

		// The way back is offered for 7 days, to a name the site still has.
		if ( '' === $prev || $at + self::KEEP <= $now || ! self::is_name( $rows, $preview, $prev ) || $prev === self::address( $site, $preview ) ) {
			$prev = '';
		}

		return array(
			'address' => self::address( $site, $preview ),
			'note'    => (string) ( $flags['wp_note'] ?? '' ),
			'seen'    => (string) ( $flags['wp_seen'] ?? '' ),
			'moving'  => (string) ( $flags['wp_moving'] ?? '' ),
			'prev'    => $prev,
		);
	}

	/**
	 * Is the host a name WordPress may be moved to: the temporary name, or an active name of the site?
	 *
	 * @param array<int, array<string, mixed>> $rows    The site's name rows.
	 * @param string                           $preview The temporary address.
	 * @param string                           $host    Host.
	 */
	public static function is_name( array $rows, string $preview, string $host ): bool {
		if ( $host === $preview ) {
			return true;
		}

		foreach ( $rows as $row ) {
			if ( 'preview' !== $row['kind'] && 'active' === $row['state'] && $row['host'] === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The address WordPress should be moved to by itself, '' for none.
	 *
	 * @param array<string, mixed>             $site    Site row.
	 * @param array<int, array<string, mixed>> $rows    The site's name rows, the preview name included.
	 * @param string                           $preview The temporary address.
	 * @param bool                             $chosen  True when the customer just chose the main address of a pair (not a name going live).
	 */
	public static function auto_target( array $site, array $rows, string $preview, bool $chosen ): string {
		$view = self::view( $site, $rows, $preview );
		$main = SiteAddresses::main_address( $rows, $preview );

		if ( '' !== $view['moving'] || '' !== $view['note'] || $main === $preview || $main === $view['address'] || ! SiteAddresses::settled( $rows, $main ) ) {
			return '';
		}

		if ( $view['address'] === $preview ) {
			return $main;
		}

		// WordPress is on an own name already: it follows only the customer's own choice inside the pair it is on.
		return $chosen && SiteAddresses::base_of( $view['address'] ) === SiteAddresses::base_of( $main ) ? $main : '';
	}

	/**
	 * Queues the automatic move when the website's names call for it. Only queues: nothing is sent to the node here, so it
	 * is safe inside any lock.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $slug   Site.
	 * @param bool     $chosen The customer just chose the main address of a pair.
	 * @return bool Whether a move is queued (or was already).
	 */
	public static function consider( Resource $r, string $slug, bool $chosen = false ): bool {
		$site = Sites::find( $r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) || (int) ( Resource::find( $r->id() ) ?? $r )->get( 'wp_address_unsupported' ) + self::UNSUPPORTED_FOR > Jobs::now() ) {
			return false;
		}

		$rows = array_values( array_filter( SiteDomains::for_resource( $r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] ) );
		$to   = self::auto_target( $site, $rows, EdgeMap::preview_host( $slug ), $chosen );

		if ( '' === $to ) {
			return false;
		}

		Jobs::schedule_once( time() + 5, self::HOOK, array( $r->id(), $slug, $to, 0, 0 ) );

		return true;
	}

	/**
	 * Looks at every website of an account after names went live or stopped.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function consider_all( Resource $r ): void {
		foreach ( Sites::for_resource( $r->id() ) as $site ) {
			self::consider( $r, (string) $site['slug'] );
		}
	}

	/**
	 * The customer's move: recorded now, run in the background.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Site.
	 * @param string   $to   Host.
	 * @param string   $from The address of her own that WordPress has and the customer agreed to replace, '' for none.
	 */
	public static function queue_manual( Resource $r, string $slug, string $to, string $from = '' ): void {
		Sites::update( $r->id(), $slug, array( 'flags' => array( 'wp_moving' => $to, 'wp_manual' => true, 'wp_note' => '', 'wp_seen' => '', 'wp_from' => $from ) ) );
		Jobs::schedule_once( time() + 2, self::HOOK, array( $r->id(), $slug, $to, 1, 0 ) );
	}

	// The job.

	/**
	 * Background run of one move.
	 *
	 * @param int    $id     Resource id.
	 * @param string $slug   Site.
	 * @param string $to     Host to move to.
	 * @param int    $manual 1 when the customer asked.
	 * @param int    $try    Attempt, from 0.
	 */
	public static function run( int $id, string $slug, string $to, int $manual = 0, int $try = 0 ): void {
		$args = array( $id, $slug, $to, $manual, $try );

		if ( Settings::get( 'pause_all' ) ) {
			Jobs::schedule_once( time() + 300, self::HOOK, $args );

			return;
		}

		$r = Resource::find( $id );

		if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) ) {
			return;
		}

		$me    = new self( $r );
		$delay = $me->step( $slug, $to, 1 === $manual, $try );

		if ( $delay <= 0 ) {
			return;
		}

		if ( $try + 1 >= self::TRIES ) {
			$me->settle( $slug, 1 === $manual ? array( 'wp_note' => 'failed' ) : array() );
			$me->push();

			return;
		}

		$args[4] = $try + 1;
		Jobs::schedule_once( time() + $delay, self::HOOK, $args );
	}

	/**
	 * One look at the move.
	 *
	 * @param string $slug   Site.
	 * @param string $to     Host.
	 * @param bool   $manual The customer asked.
	 * @param int    $try    Attempt.
	 * @return int Seconds until the next look, 0 when it is over.
	 */
	private function step( string $slug, string $to, bool $manual, int $try ): int {
		$this->r = Resource::find( $this->r->id() ) ?? $this->r;
		$site    = Sites::find( $this->r->id(), $slug );

		if ( null === $site || 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) ) {
			return 0;
		}

		$preview = EdgeMap::preview_host( $slug );
		$rows    = array_values( array_filter( SiteDomains::for_resource( $this->r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] ) );

		if ( ! self::is_name( $rows, $preview, $to ) ) {
			$this->settle( $slug, array() );

			return 0;
		}

		// An automatic move re-checks its reason: it may have been done or become wrong since it was queued.
		if ( ! $manual && '' === ( $this->moving( $site ) ) && $to !== self::auto_target( $site, $rows, $preview, true ) ) {
			return 0;
		}

		$early = $this->connect();

		if ( null !== $early ) {
			return $early->is_retry() ? max( 60, $early->delay() ) : 0;
		}

		// An own name is moved to automatically only when a public lookup shows our address: a name that does not reach us
		// yet would lock the customer out of the website's own login.
		if ( ! $manual && $to !== $preview && '' === $this->moving( $site ) && self::address( $site, $preview ) === $preview ) {
			$found = DnsQuery::addresses( $to, 'A' );

			if ( is_wp_error( $found ) || ! in_array( SiteDns::edge( $this->node )['A'], $found, true ) ) {
				return 900;
			}
		}

		list( $taken, $answer ) = Locks::with( Locks::name( 'hosting-site', $this->r->id() ), (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), fn() => $this->send( $slug, $to, $manual, $site ) );

		if ( ! $taken ) {
			return 60;
		}

		if ( $answer instanceof StepResult ) {
			if ( $answer->is_poll() ) {
				return max( 5, $answer->poll_seconds() );
			}

			return $this->failed_call( $answer, $slug, $manual, $try );
		}

		$this->record( $slug, $to, $answer, $manual );

		return 0;
	}

	/**
	 * The host a move is under way to, '' for none.
	 *
	 * @param array<string, mixed> $site Site row.
	 */
	private function moving( array $site ): string {
		return (string) ( $site['flags']['wp_moving'] ?? '' );
	}

	/**
	 * Submits the operation (or looks at its job again).
	 *
	 * @param string               $slug   Site.
	 * @param string               $to     Host.
	 * @param bool                 $manual The customer asked.
	 * @param array<string, mixed> $site   Site row.
	 * @return array<string, mixed>|StepResult
	 */
	private function send( string $slug, string $to, bool $manual, array $site ) {
		if ( '' === $this->moving( $site ) ) {
			Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'wp_moving' => $to, 'wp_manual' => $manual ) ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$args   = array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'to' => $to );
		$from   = (string) ( $site['flags']['wp_from'] ?? '' );

		if ( $manual && '' !== $from ) {
			$args['from'] = $from; // The customer agreed to replace this address of her own; the node checks that WordPress still has it.
		}

		return $this->agent_call( 'wp_address_' . $slug, 'site.wp_address', $args );
	}

	/**
	 * A job the agent refused or failed.
	 *
	 * @param StepResult $answer Result.
	 * @param string     $slug   Site.
	 * @param bool       $manual The customer asked.
	 * @param int        $try    Attempt.
	 * @return int Seconds until the next look.
	 */
	private function failed_call( StepResult $answer, string $slug, bool $manual, int $try ): int {
		unset( $try );
		$text = $answer->error();

		// An agent that does not have the operation: nothing is shown for an automatic move, a customer who asked is told.
		if ( $answer->is_failed() && false !== strpos( $text, 'unknown_op' ) ) {
			$this->r->set( array( 'wp_address_unsupported' => Jobs::now() ) );
			$this->settle( $slug, $manual ? array( 'wp_note' => 'unsupported' ) : array() );
			$this->push();

			return 0;
		}

		// The agent could not even put the dump back: a person looks at it, nothing is tried again.
		if ( false !== strpos( $text, 'could not be put back' ) ) {
			$this->settle( $slug, array( 'wp_note' => 'failed' ) );
			\BeltoftServices\Alerts::notify( 'WordPress address change failed and could not be undone', array( 'Hosting resource ' . $this->r->id() . ', website ' . $slug . '. The safety dump is in the container under /var/backups/db/wpaddr/.' ), $this->r, 'hosting-wp-address-' . $this->r->id() );
			$this->push();

			return 0;
		}

		if ( $answer->is_failed() ) {
			$this->settle( $slug, array( 'wp_note' => 'failed' ) );
			$this->push();

			return 0;
		}

		return 600;
	}

	/**
	 * Ends a move: what was found is kept, the move under way is cleared.
	 *
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $flags Flags to set.
	 */
	private function settle( string $slug, array $flags ): void {
		Sites::update( $this->r->id(), $slug, array( 'flags' => array_merge( array( 'wp_moving' => '', 'wp_manual' => false, 'wp_from' => '' ), $flags ) ) );
	}

	/**
	 * Sends the names of the website again: the temporary name redirects while WordPress is on the main address and does not
	 * when it is not, and a redirect between the names of a pair that waited for the move is sent now.
	 */
	private function push(): void {
		( new HostingActions( Resource::find( $this->r->id() ) ?? $this->r ) )->sync_edge();
	}

	/**
	 * Keeps what the agent answered and sends the names again (the temporary name redirects once WordPress is on the main
	 * address, and stops when it is not).
	 *
	 * @param string               $slug   Site.
	 * @param string               $to     Host.
	 * @param array<string, mixed> $answer The agent's result.
	 * @param bool                 $manual The customer asked.
	 */
	private function record( string $slug, string $to, array $answer, bool $manual ): void {
		unset( $manual );
		$from = (string) ( $answer['from'] ?? '' );

		if ( ! empty( $answer['foreign'] ) ) {
			$this->settle( $slug, array( 'wp_note' => 'foreign', 'wp_seen' => $from ) );
		} elseif ( ! empty( $answer['failed'] ) ) {
			$this->settle( $slug, array( 'wp_note' => 'failed' ) );
		} elseif ( ! empty( $answer['changed'] ) ) {
			$preview = EdgeMap::preview_host( $slug );

			$this->settle(
				$slug,
				array(
					'wp_address' => $to === $preview ? '' : $to,
					'wp_prev'    => $from,
					'wp_at'      => Jobs::now(),
					'wp_note'    => '',
					'wp_seen'    => '',
				)
			);
		} else {
			$preview = EdgeMap::preview_host( $slug );

			$this->settle( $slug, array( 'wp_address' => $to === $preview ? '' : $to, 'wp_note' => '', 'wp_seen' => '' ) );
		}

		$this->push();
	}

	/**
	 * The rule for the temporary name redirecting to the main address.
	 *
	 * @param array<string, mixed>             $site    Site row.
	 * @param array<int, array<string, mixed>> $rows    The site's name rows, the preview name not included.
	 * @param string                           $preview The temporary address.
	 * @return array{from: string, to: string}|null
	 */
	public static function preview_redirect( array $site, array $rows, string $preview ): ?array {
		$wp   = (string) ( $site['flags']['wp_address'] ?? '' );
		$main = SiteAddresses::main_address( $rows, $preview );

		// Only while WordPress itself is on the main address: a redirect to a name WordPress does not use would be sent back by it.
		return '' !== $wp && '' !== $preview && $main === $wp && $main !== $preview && '' === (string) ( $site['flags']['wp_moving'] ?? '' ) ? array( 'from' => $preview, 'to' => $main ) : null;
	}
}
