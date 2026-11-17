<?php
/**
 * The address WordPress itself uses on a hosting website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A website is installed on its temporary address. Connecting a domain only routes it: until WordPress is moved to it,
 * WordPress builds its links, its login address and its canonical URLs on the temporary address. The work is the agent's
 * `site.wp_address` (a fresh database dump, `wp search-replace` over every table, `home` and `siteurl`, caches, and the dump put
 * back when anything fails). Since 0.31.0 the move is a change of the account on the shared action controller (the internal action
 * `wordpress_address`, run by Actions\SiteActions::wordpress_address() in job mode, steps in Actions\Steps): no loop, retry counter
 * or "moving" flag of its own. This class decides when, and keeps the feature state in the flags of the website:
 *
 * - `wp_address`: the host WordPress uses now ('' means the temporary address);
 * - `wp_prev` and `wp_at`: the host before the last move and when it moved (the way back is offered for 7 days);
 * - `wp_note`: '' or what the last move found: `foreign` (the customer set another address in WordPress, left alone; `wp_seen`
 *   is that address), `failed` (the move failed and the dump was put back), `unsupported` (a manual move on a node whose agent
 *   cannot do it). It also stops the automatic move from trying again until the customer chooses.
 *
 * The move under way is read from the account's task (view() says where it goes). It starts by itself only twice: when the
 * website's first own domain is live (both names of a pair, the DNS shows our address) and WordPress is still on the temporary
 * address, and when the customer chooses the other name of a pair that WordPress is on. consider() looks at that after a change
 * of the addresses (the hook of Actions\Steps::done()) and in the domain sweep, and queues the action through the same path;
 * a refused start (another change runs) is looked at again by the sweep. Everything else is the customer's button. An agent
 * without the operation is left alone: no error is shown and nothing is queued for a few hours.
 */
final class SiteWordPress {

	/** The way back, and the agent's safety dump, last this long. */
	public const KEEP = 7 * DAY_IN_SECONDS;

	/** How long an agent that does not know the operation is not asked again by the automatic move. */
	public const UNSUPPORTED_FOR = 6 * HOUR_IN_SECONDS;

	/** Customer moves per resource and hour. */
	public const LIMIT = 5;

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
	 * The host a move of this website is under way to, '' for none: read from the account's task.
	 *
	 * @param array<string, mixed>      $site Site row.
	 * @param array<string, mixed>|null $task The account's task (Actions\Steps::view()), or null to read it.
	 */
	public static function moving( array $site, ?array $task = null ): string {
		if ( null === $task && isset( $site['resource_id'] ) ) {
			$fresh = Resource::find( (int) $site['resource_id'] );
			$task  = null === $fresh ? null : Steps::view( $fresh );
		}

		if ( null === $task || 'wordpress_address' !== (string) $task['action'] || (string) $task['slug'] !== (string) ( $site['slug'] ?? '' ) ) {
			return '';
		}

		return (string) ( $task['args'][1] ?? '' );
	}

	/**
	 * What the page shows about the move: address, note, way back, move under way.
	 *
	 * @param array<string, mixed>             $site    Site row.
	 * @param array<int, array<string, mixed>> $rows    The site's name rows, the preview name included.
	 * @param string                           $preview The temporary address.
	 * @param array<string, mixed>|null        $task    The account's task, or null to read it.
	 * @return array{address: string, note: string, seen: string, moving: string, prev: string}
	 */
	public static function view( array $site, array $rows, string $preview, ?array $task = null ): array {
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
			'moving'  => self::moving( $site, $task ),
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

		if ( '' !== $view['note'] || $main === $preview || $main === $view['address'] || ! SiteAddresses::settled( $rows, $main ) ) {
			return '';
		}

		if ( $view['address'] === $preview ) {
			return $main;
		}

		// WordPress is on an own name already: it follows only the customer's own choice inside the pair it is on.
		return $chosen && SiteAddresses::base_of( $view['address'] ) === SiteAddresses::base_of( $main ) ? $main : '';
	}

	/**
	 * Queues the automatic move when the website's names call for it, as a change of the account through the shared path: nothing
	 * is sent to the node here. A move to an own name waits until a public lookup shows our address (a name that does not reach us
	 * yet would lock the customer out of the website's own login); the domain sweep looks again. A refused start (another change
	 * runs) is looked at again by the sweep.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $slug   Site.
	 * @param bool     $chosen The customer just chose the main address of a pair.
	 * @return bool Whether a move is queued.
	 */
	public static function consider( Resource $r, string $slug, bool $chosen = false ): bool {
		$site = Sites::find( $r->id(), $slug );

		if ( null === $site || ! empty( $site['flags']['pending'] ) || ! Sites::has_wordpress( $site ) || (int) ( Resource::find( $r->id() ) ?? $r )->get( 'wp_address_unsupported' ) + self::UNSUPPORTED_FOR > Jobs::now() ) {
			return false;
		}

		$rows    = array_values( array_filter( SiteDomains::for_resource( $r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] ) );
		$preview = EdgeMap::preview_host( $slug );
		$to      = self::auto_target( $site, $rows, $preview, $chosen );

		if ( '' === $to || '' !== self::moving( $site ) ) {
			return false;
		}

		if ( $to !== $preview && self::address( $site, $preview ) === $preview ) {
			$record = Records::for_resource( $r->id() );
			$node   = $record ? Nodes::get( (string) $record['node'] ) : null;
			$found  = DnsQuery::addresses( $to, 'A' );

			if ( null === $node || is_wp_error( $found ) || ! in_array( SiteDns::edge( $node )['A'], $found, true ) ) {
				return false;
			}
		}

		return Steps::start_follow( $r, 'wordpress_address', array( $slug, $to, '', false ) );
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
		return '' !== $wp && '' !== $preview && $main === $wp && $main !== $preview ? array( 'from' => $preview, 'to' => $main ) : null;
	}

	/**
	 * Ends a move: what was found is kept.
	 *
	 * @param Resource             $r     Hosting resource.
	 * @param string               $slug  Site.
	 * @param array<string, mixed> $flags Flags to set.
	 */
	public static function settle( Resource $r, string $slug, array $flags ): void {
		Sites::update( $r->id(), $slug, array( 'flags' => $flags ) );
	}

	/**
	 * Keeps what the agent answered for a move that worked (or did not change anything).
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param string               $slug   Site.
	 * @param string               $to     Host.
	 * @param array<string, mixed> $answer The agent's result.
	 * @return string '' when the move is recorded; `foreign` or `failed` for a move that was left or put back.
	 */
	public static function record( Resource $r, string $slug, string $to, array $answer ): string {
		$from    = (string) ( $answer['from'] ?? '' );
		$preview = EdgeMap::preview_host( $slug );

		if ( ! empty( $answer['foreign'] ) ) {
			self::settle( $r, $slug, array( 'wp_note' => 'foreign', 'wp_seen' => $from ) );

			return 'foreign';
		}

		if ( ! empty( $answer['failed'] ) ) {
			self::settle( $r, $slug, array( 'wp_note' => 'failed' ) );

			return 'failed';
		}

		if ( ! empty( $answer['changed'] ) ) {
			self::settle( $r, $slug, array( 'wp_address' => $to === $preview ? '' : $to, 'wp_prev' => $from, 'wp_at' => Jobs::now(), 'wp_note' => '', 'wp_seen' => '' ) );

			return '';
		}

		self::settle( $r, $slug, array( 'wp_address' => $to === $preview ? '' : $to, 'wp_note' => '', 'wp_seen' => '' ) );

		return '';
	}
}
