<?php
/**
 * The staging copy of a hosting website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A website may have one staging copy: its files and database on a temporary address of its own, to try changes in and push
 * back. The node agent does the work (`site.staging_create`, `_refresh`, `_push`, `_undo`, `_delete`). Making, refreshing, pushing,
 * undoing and deleting a copy are changes of the account on the shared action controller (Actions\StagingActions, the internal
 * actions `staging_create` ... `staging_delete`): no loop, retry counter or outcome of their own. This class decides what is
 * allowed and keeps the feature state in the flag `staging` of the website:
 *
 * - `copy`, `host`: the copy's name on the node and its temporary address (`<copy>.<preview zone>`);
 * - `state`: '' (no copy), creating, ready or deleting. A refresh, a push and an undo leave it `ready`: that they run is read
 *   from the account's task (view() says refreshing, pushing or undoing then);
 * - `routed`: the copy's address is in DNS and on the edge map;
 * - `at`, `refreshed`: when the copy was made and last made again;
 * - `undo`: the way back of the last push (`at`, `scope` files or full), offered for 7 days.
 *
 * What the last job found (failed, unsupported, unsafe) is the shared last_result, not a flag; which step a job is at, and
 * whether the push is files only, are the task's steps and arguments.
 *
 * The copy's address is a `preview` row in SiteDomains that belongs to no website (site id 0): DNS, the edge map and the deletion
 * of an account treat it as they treat every temporary address, and the website's own list of names does not show it.
 */
final class SiteStaging {

	use Runs;

	/** The ways a job can go. */
	public const KINDS = array( 'create', 'refresh', 'push', 'undo', 'delete' );

	/** The way back of a push is offered, and kept by the node, this long. */
	public const KEEP = 7 * DAY_IN_SECONDS;

	/** Changes of staging per resource and hour: every one copies a website. */
	public const LIMIT = 10;

	/** The share of the plan's storage above which a copy is not made: it needs as much space as the website. */
	public const STORAGE_PERCENT = 50.0;

	/** Seconds a run may wait for the node's job (a copy takes minutes; the shared controller caps it). */
	public const BUDGET = 1800;

	/**
	 * @param Resource $r Hosting resource.
	 */
	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	// What the page and the rules read.

	/**
	 * What a website's flags and the account's task say about its staging copy, with every key present.
	 *
	 * @param array<string, mixed>      $site Site row.
	 * @param array<string, mixed>|null $task The account's task (Actions\Steps::view()), or null to read it.
	 * @return array{copy: string, host: string, state: string, routed: bool, at: int, refreshed: int, undo: array{at: int, scope: string}|null, busy: bool, exists: bool}
	 */
	public static function view( array $site, ?array $task = null ): array {
		$f      = (array) ( $site['flags']['staging'] ?? array() );
		$stored = (string) ( $f['state'] ?? '' );
		$undo   = is_array( $f['undo'] ?? null ) && (int) ( $f['undo']['at'] ?? 0 ) + self::KEEP > Jobs::now() && in_array( (string) ( $f['undo']['scope'] ?? '' ), array( 'files', 'full' ), true )
			? array( 'at' => (int) $f['undo']['at'], 'scope' => (string) $f['undo']['scope'] )
			: null;

		// A flag an older version left while a job ran: the copy is there.
		if ( in_array( $stored, array( 'refreshing', 'pushing', 'undoing' ), true ) ) {
			$stored = 'ready';
		}

		if ( null === $task && isset( $site['resource_id'] ) ) {
			$fresh = Resource::find( (int) $site['resource_id'] );
			$task  = null === $fresh ? null : Steps::view( $fresh );
		}

		$kind  = null !== $task && (string) $task['slug'] === (string) ( $site['slug'] ?? '' ) && 0 === strpos( (string) $task['action'], 'staging_' ) ? substr( (string) $task['action'], 8 ) : '';
		$state = array( 'create' => 'creating', 'refresh' => 'refreshing', 'push' => 'pushing', 'undo' => 'undoing', 'delete' => 'deleting' )[ $kind ] ?? $stored;

		return array(
			'copy'      => '' === $stored ? '' : (string) ( $f['copy'] ?? '' ),
			'host'      => '' === $stored ? '' : (string) ( $f['host'] ?? '' ),
			'state'     => $state,
			'routed'    => ! empty( $f['routed'] ),
			'at'        => (int) ( $f['at'] ?? 0 ),
			'refreshed' => (int) ( $f['refreshed'] ?? 0 ),
			'undo'      => $undo,
			'busy'      => in_array( $state, array( 'creating', 'refreshing', 'pushing', 'undoing', 'deleting' ), true ),
			'exists'    => '' !== $stored,
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
			$view = self::view( $site, array( 'action' => '', 'slug' => '' ) );

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

	/**
	 * What the customer reads when a job did not work: the outcome of the action (the shared last_result), one sentence for each way
	 * it can end.
	 *
	 * @param string $kind One of KINDS.
	 * @param string $note failed, unsupported or unsafe.
	 */
	public static function outcome_text( string $kind, string $note = 'failed' ): string {
		if ( 'unsupported' === $note ) {
			return __( 'Your hosting cannot do this yet. Contact support.', 'beltoft-services' );
		}

		if ( 'unsafe' === $note ) {
			return __( 'The push did not work and the live website could not be put back automatically. Support has been told.', 'beltoft-services' );
		}

		$fail = array(
			'create'  => __( 'The staging copy could not be made. Your website was not changed.', 'beltoft-services' ),
			'refresh' => __( 'The staging copy could not be refreshed. Your website was not changed. Refresh it again, or delete it.', 'beltoft-services' ),
			'push'    => __( 'The push did not work. Your live website was put back as it was.', 'beltoft-services' ),
			'undo'    => __( 'The undo did not work. Try again, or contact support.', 'beltoft-services' ),
			'delete'  => __( 'The staging copy could not be deleted. Try again.', 'beltoft-services' ),
		);

		return (string) ( $fail[ $kind ] ?? __( 'That did not work. Try again, or contact support.', 'beltoft-services' ) );
	}

	// What a job records (called by Actions\StagingActions, in the request that queues it and in the job).

	/**
	 * The request that queues a create or a delete records the start: a copy being made or deleted exists for the plan's limit, DNS
	 * and the edge map from now on. A refresh, a push and an undo record nothing: the task says they run.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param string   $slug  Website.
	 * @param string   $kind  create or delete.
	 * @param string   $label The copy's name (create).
	 */
	public static function begin( Resource $r, string $slug, string $kind, string $label = '' ): void {
		$site  = (array) Sites::find( $r->id(), $slug );
		$flags = (array) ( $site['flags']['staging'] ?? array() );

		if ( 'create' === $kind ) {
			$flags = array_merge( $flags, array( 'state' => 'creating', 'routed' => false, 'copy' => $label, 'host' => EdgeMap::preview_host( $label ), 'at' => Jobs::now(), 'refreshed' => 0 ) );
		} else {
			$flags['state'] = 'deleting';
		}

		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => $flags ) ) );
	}

	/**
	 * Keeps what the agent answered for a job that worked.
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param string               $slug   Website.
	 * @param string               $kind   create, refresh, push or undo.
	 * @param array<string, mixed> $answer The agent's result.
	 * @return true|\WP_Error An error with the customer's sentence when the node put a push back.
	 */
	public static function record( Resource $r, string $slug, string $kind, array $answer ) {
		$now = Jobs::now();

		switch ( $kind ) {
			case 'create':
				self::settle( $r, $slug, array( 'state' => 'ready', 'routed' => true, 'at' => $now, 'refreshed' => 0 ) );

				return true;

			case 'refresh':
				self::settle( $r, $slug, array( 'state' => 'ready', 'refreshed' => $now ) );

				return true;

			case 'push':
				if ( ! empty( $answer['failed'] ) ) {
					// The node put the website back as it was: there is no way back to offer, and the customer is told so.
					self::settle( $r, $slug, array( 'state' => 'ready', 'undo' => null ) );

					return new \WP_Error( 'failed', 'The push did not work, the node put the website back.', array( 'text' => self::outcome_text( 'push' ), 'expected' => true ) );
				}

				self::settle( $r, $slug, array( 'state' => 'ready', 'undo' => array( 'at' => $now, 'scope' => empty( $answer['database'] ) ? 'files' : 'full' ) ) );

				return true;
		}

		self::settle( $r, $slug, array( 'state' => 'ready', 'undo' => null ) );

		return true;
	}

	/**
	 * Delete, last part: the copy's flags go, the way back of the last push stays (it belongs to the website, not to the copy).
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website.
	 */
	public static function forget( Resource $r, string $slug ): void {
		$site = (array) Sites::find( $r->id(), $slug );
		$undo = self::view( $site, array( 'action' => '', 'slug' => '' ) )['undo'];

		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => null === $undo ? array() : array( 'undo' => $undo ) ) ) );
	}

	/**
	 * Changes some of the staging flags of a website.
	 *
	 * @param Resource             $r     Hosting resource.
	 * @param string               $slug  Website.
	 * @param array<string, mixed> $flags Keys to set.
	 */
	private static function settle( Resource $r, string $slug, array $flags ): void {
		$site = (array) Sites::find( $r->id(), $slug );

		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => array_merge( (array) ( $site['flags']['staging'] ?? array() ), $flags ) ) ) );
	}

	// What an ended change leaves (hooked through Actions\Steps::failed()).

	/**
	 * A copy that could not be made: nothing of it is left on the node (the agent removes a copy it could not finish), so its address
	 * goes too, and the website is as it was. A copy that was recorded as made stays: only the edge map failed.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website.
	 */
	public static function abandon_create( Resource $r, string $slug ): void {
		$site = '' === $slug ? null : Sites::find( $r->id(), $slug );

		if ( null === $site || 'creating' !== (string) ( $site['flags']['staging']['state'] ?? '' ) ) {
			return;
		}

		$view = self::view( $site, array( 'action' => '', 'slug' => '' ) );
		$me   = new self( $r );

		if ( '' !== $view['host'] && null === $me->connect() ) {
			SiteDns::remove_domain( $r, $view['host'], $me->node );
		}

		self::forget( $r, $slug );
	}

	/**
	 * A delete that did not finish: the copy is a copy again (the customer may delete it again).
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $slug Website.
	 */
	public static function resume_after_failed_delete( Resource $r, string $slug ): void {
		$site = '' === $slug ? null : Sites::find( $r->id(), $slug );

		if ( null !== $site && 'deleting' === (string) ( $site['flags']['staging']['state'] ?? '' ) ) {
			self::settle( $r, $slug, array( 'state' => 'ready' ) );
		}
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
		$view = self::view( $site, array( 'action' => '', 'slug' => '' ) );

		if ( '' === $view['host'] ) {
			return true;
		}

		return SiteDns::remove_domain( $r, $view['host'], $node );
	}
}
