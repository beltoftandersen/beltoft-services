<?php
/**
 * The scheduled jobs of a website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Background;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Every website can have scheduled jobs (up to the plan's `cron_jobs`, 5 by default): a PHP file in the website's folder run with a chosen PHP
 * version, an address of the website itself fetched, or, for a website with WordPress, the server cron of WordPress (the agent runs
 * WordPress's due tasks and switches WordPress's own cron off while that job is on). The plugin is the source of truth: the list is the site flag `cron`
 * and each save replaces the whole list on the node (agent operation `site.cron_set`, the shared action `set_cron` of Actions\CronActions). The node
 * keeps a copy, and the last runs are read from it (`site.cron_status`) and never stored here.
 *
 * What a customer types reaches the node only after the validators below (the agent validates again, and a root-owned runner a third time):
 * the schedule is five fields of digits and `* , - /` with at least 5 minutes between two runs (checked over the 1440 minutes of a day, midnight
 * included), a script path is `[A-Za-z0-9._/-]` relative to the website's folder with no `..`, no leading slash or dash, the arguments are a short
 * list of plain words, the PHP version is one of the offered ones, and an address is `https://` plus a name the website serves itself. Nothing else
 * passes, so no shell syntax ever gets through.
 */
final class SiteCron {

	/** Background hook: the stored jobs go to the node again after a restore of the whole account. */
	public const RESEND_HOOK = 'beltoft_services_hosting_cron_resend';

	/** Resource meta: when an agent that does not know the operations was last met. */
	public const UNSUPPORTED = 'cron_unsupported';

	/** How long an agent that did not know the operations is taken as not knowing them. */
	public const UNSUPPORTED_FOR = 6 * HOUR_IN_SECONDS;

	/** Seconds a job may run unless the customer says another time. */
	public const DEFAULT_TIMEOUT = 300;

	/** The longest a job may run. */
	public const MAX_TIMEOUT = 900;

	/** The shortest time between two runs, in minutes. */
	public const MIN_GAP = 5;

	/** Arguments of a script. */
	public const MAX_ARGS = 5;

	/** Tries of the follow-up that sends the jobs again (every 30 seconds). */
	public const RESEND_TRIES = 20;

	/**
	 * Hooks the follow-up.
	 */
	public static function register(): void {
		add_action( self::RESEND_HOOK, array( __CLASS__, 'resend_run' ), 10, 2 );
		Background::declare(
			self::RESEND_HOOK,
			array(
				'resource' => true,
				'label'    => 'Hosting scheduled jobs sent again',
			)
		);
	}

	// What the plan allows.

	/**
	 * Jobs one website may have on this plan.
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 */
	public static function limit( array $plan ): int {
		return max( 0, (int) ( $plan['cron_jobs'] ?? 5 ) );
	}

	// The schedule.

	/**
	 * The values of one field of a schedule, or null when the field is not allowed. Allowed: `*`, `a`, `a-b`, and a step after `*` or a range, joined by commas,
	 * digits only (no names, no `5/10`), inside the range of the field.
	 *
	 * @param string $text Field.
	 * @param int    $lo   Lowest value.
	 * @param int    $hi   Highest value.
	 * @return int[]|null
	 */
	private static function field( string $text, int $lo, int $hi ): ?array {
		if ( 1 !== preg_match( '/^[0-9*,\/-]{1,40}$/D', $text ) ) {
			return null;
		}

		$values = array();

		foreach ( explode( ',', $text ) as $item ) {
			if ( 1 !== preg_match( '/^(\*|[0-9]{1,2}|[0-9]{1,2}-[0-9]{1,2})(?:\/([0-9]{1,2}))?$/D', $item, $m ) ) {
				return null;
			}

			$step = isset( $m[2] ) ? (int) $m[2] : 1;

			if ( $step < 1 || $step > $hi || ( isset( $m[2] ) && ctype_digit( $m[1] ) ) ) {
				return null; // A step belongs to `*` or a range, not to a single number.
			}

			if ( '*' === $m[1] ) {
				$from = $lo;
				$to   = $hi;
			} elseif ( false !== strpos( $m[1], '-' ) ) {
				list( $from, $to ) = array_map( 'intval', explode( '-', $m[1] ) );
			} else {
				$from = (int) $m[1];
				$to   = $from;
			}

			if ( $from < $lo || $to > $hi || $from > $to ) {
				return null;
			}

			for ( $v = $from; $v <= $to; $v += $step ) {
				$values[ $v ] = $v;
			}
		}

		sort( $values );

		return $values;
	}

	/**
	 * The minutes of a day a schedule runs at (hour times 60 plus minute), or null for a schedule that is not allowed in its form.
	 *
	 * @param string $schedule Five fields.
	 * @return int[]|null
	 */
	public static function fires( string $schedule ): ?array {
		if ( 1 !== preg_match( '/^[0-9*,\/-]{1,40}( [0-9*,\/-]{1,40}){4}$/D', $schedule ) ) {
			return null;
		}

		$parts   = explode( ' ', $schedule );
		$minutes = self::field( $parts[0], 0, 59 );
		$hours   = self::field( $parts[1], 0, 23 );

		if ( null === $minutes || null === $hours || null === self::field( $parts[2], 1, 31 ) || null === self::field( $parts[3], 1, 12 ) || null === self::field( $parts[4], 0, 7 ) ) {
			return null;
		}

		$day = array();

		foreach ( $hours as $h ) {
			foreach ( $minutes as $m ) {
				$day[] = $h * 60 + $m;
			}
		}

		return $day;
	}

	/**
	 * Is the schedule allowed: the form, and at least MIN_GAP minutes between two runs (also from the last run of a day to the first of the next).
	 * The gap is looked at over all 1440 minutes of a day, whatever the days of the month or week say: a rule that is met there is met on every day.
	 *
	 * @param string $schedule Schedule.
	 * @return string '' when it is allowed, else `form` or `often`.
	 */
	public static function schedule_problem( string $schedule ): string {
		$day = self::fires( $schedule );

		if ( null === $day || array() === $day ) {
			return 'form';
		}

		$count = count( $day );

		for ( $i = 0; $i < $count; $i++ ) {
			$next = $day[ ( $i + 1 ) % $count ];
			$gap  = ( $next - $day[ $i ] + 1440 ) % 1440;

			if ( 0 === $gap && 1 === $count ) {
				$gap = 1440;
			}

			if ( $gap < self::MIN_GAP ) {
				return 'often';
			}
		}

		return '';
	}

	/**
	 * A schedule as the page lets it be edited: a preset with its time, or custom.
	 *
	 * @param string $schedule Five fields.
	 * @return array{preset: string, time: string, weekday: int, day: int, custom: string}
	 */
	public static function describe( string $schedule ): array {
		$out = array( 'preset' => 'custom', 'time' => '03:00', 'weekday' => 1, 'day' => 1, 'custom' => $schedule );

		foreach ( array( '*/5 * * * *' => 'every5', '*/15 * * * *' => 'every15', '*/30 * * * *' => 'every30', '0 * * * *' => 'hourly' ) as $text => $preset ) {
			if ( $schedule === $text ) {
				$out['preset'] = $preset;

				return $out;
			}
		}

		if ( 1 === preg_match( '/^([0-9]{1,2}) ([0-9]{1,2}) (\*|[0-9]{1,2}) \* (\*|[0-7])$/D', $schedule, $m ) && (int) $m[1] <= 59 && (int) $m[2] <= 23 ) {
			$out['time'] = sprintf( '%02d:%02d', (int) $m[2], (int) $m[1] );

			if ( '*' === $m[3] && '*' === $m[4] ) {
				$out['preset'] = 'daily';
			} elseif ( '*' === $m[3] ) {
				$out['preset']  = 'weekly';
				$out['weekday'] = (int) $m[4] % 7;
			} elseif ( '*' === $m[4] && (int) $m[3] >= 1 && (int) $m[3] <= 28 ) {
				$out['preset'] = 'monthly';
				$out['day']    = (int) $m[3];
			}
		}

		return $out;
	}

	// The jobs.

	/**
	 * The stored jobs of a website, typed.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @return array<int, array<string, mixed>>
	 */
	public static function stored( array $site ): array {
		$list = $site['flags']['cron'] ?? array();

		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * The names a website serves itself: its temporary name (unless that only redirects) and its connected names that are not the redirecting
	 * side of a pair. An address job may name one of these only; the agent checks it again.
	 *
	 * @param int                  $resource_id Activation id.
	 * @param array<string, mixed> $site        Site row.
	 * @return string[]
	 */
	public static function own_names( int $resource_id, array $site ): array {
		$rows      = array_values( array_filter( SiteDomains::for_resource( $resource_id ), static fn( $d ) => $d['site_id'] === $site['id'] && 'preview' !== $d['kind'] ) );
		$preview   = EdgeMap::preview_host( (string) $site['slug'] );
		$redirects = array_column( SiteAddresses::redirects( $rows ), 'from' );
		$to_main   = SiteWordPress::preview_redirect( $site, $rows, $preview );

		if ( null !== $to_main ) {
			$redirects[] = $to_main['from'];
		}

		$names = array( $preview );

		foreach ( $rows as $d ) {
			if ( 'active' === $d['state'] ) {
				$names[] = (string) $d['host'];
			}
		}

		return array_values( array_filter( array_unique( $names ), static fn( $n ) => '' !== $n && ! in_array( $n, $redirects, true ) ) );
	}

	/**
	 * Checks and cleans a list of jobs as a customer or a client sent it, and returns the list to store: the whole list, in order, with ids.
	 * Anything that is not plain is refused with a message for the customer; nothing is repaired silently except the label.
	 *
	 * @param array<int, mixed>    $input Jobs as sent: label, enabled, schedule, kind, php, path, args, url, timeout, and id for a job that exists.
	 * @param array<string, mixed> $ctx   limit, php_versions, names (the website's own names), wordpress (the website has WordPress), keep (ids of
	 *                                    the jobs that exist: only these ids are kept, any other id is made new).
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function normalise( array $input, array $ctx ) {
		$limit = (int) ( $ctx['limit'] ?? 0 );

		if ( ! array_is_list( $input ) ) {
			return new \WP_Error( 'invalid', __( 'The scheduled jobs are not a list.', 'beltoft-services' ) );
		}

		if ( count( $input ) > $limit ) {
			return new \WP_Error(
				'limit',
				0 === $limit
					? __( 'Your plan has no scheduled jobs.', 'beltoft-services' )
					/* translators: %d: scheduled jobs a website may have. */
					: sprintf( __( 'A website can have up to %d scheduled jobs on your plan. Remove one to add another.', 'beltoft-services' ), $limit )
			);
		}

		$keep = array_flip( array_map( 'strval', (array) ( $ctx['keep'] ?? array() ) ) );
		$used = array();
		$out  = array();
		$wp   = 0;

		foreach ( $input as $job ) {
			$one = self::one( $job, $ctx );

			if ( $one instanceof \WP_Error ) {
				return $one;
			}

			$id = (string) ( is_array( $job ) ? ( $job['id'] ?? '' ) : '' );

			if ( 1 !== preg_match( '/^[0-9a-f]{8}$/D', $id ) || ! isset( $keep[ $id ] ) || isset( $used[ $id ] ) ) {
				do {
					$id = bin2hex( random_bytes( 4 ) );
				} while ( isset( $used[ $id ] ) || isset( $keep[ $id ] ) );
			}

			$used[ $id ] = true;
			$wp         += 'wordpress' === $one['kind'] ? 1 : 0;
			$out[]       = array_merge( array( 'id' => $id ), $one );
		}

		if ( $wp > 1 ) {
			return new \WP_Error( 'invalid', __( 'A website has one server cron for WordPress.', 'beltoft-services' ) );
		}

		return $out;
	}

	/**
	 * One job, checked.
	 *
	 * @param mixed                $job Job as sent.
	 * @param array<string, mixed> $ctx See normalise().
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function one( $job, array $ctx ) {
		if ( ! is_array( $job ) ) {
			return new \WP_Error( 'invalid', __( 'A scheduled job is not complete.', 'beltoft-services' ) );
		}

		$kind = (string) ( $job['kind'] ?? '' );

		if ( ! in_array( $kind, array( 'script', 'url', 'wordpress' ), true ) ) {
			return new \WP_Error( 'invalid', __( 'Choose what a scheduled job runs.', 'beltoft-services' ) );
		}

		if ( 'wordpress' === $kind && empty( $ctx['wordpress'] ) ) {
			return new \WP_Error( 'no_wordpress', __( 'This website has no WordPress.', 'beltoft-services' ) );
		}

		$schedule = is_string( $job['schedule'] ?? null ) ? trim( $job['schedule'] ) : '';
		$problem  = self::schedule_problem( $schedule );

		if ( 'form' === $problem ) {
			return new \WP_Error( 'invalid', __( 'A schedule has five parts: minute, hour, day of the month, month and day of the week, with numbers, *, commas, dashes and slashes only.', 'beltoft-services' ) );
		}

		if ( 'often' === $problem ) {
			/* translators: %d: minutes. */
			return new \WP_Error( 'invalid', sprintf( __( 'A scheduled job can run every %d minutes at the most.', 'beltoft-services' ), self::MIN_GAP ) );
		}

		$timeout = $job['timeout'] ?? self::DEFAULT_TIMEOUT;

		if ( is_string( $timeout ) && 1 === preg_match( '/^[0-9]{1,4}$/D', $timeout ) ) {
			$timeout = (int) $timeout;
		}

		if ( ! is_int( $timeout ) || $timeout < 1 || $timeout > self::MAX_TIMEOUT ) {
			/* translators: %d: seconds. */
			return new \WP_Error( 'invalid', sprintf( __( 'A scheduled job can run for up to %d seconds.', 'beltoft-services' ), self::MAX_TIMEOUT ) );
		}

		$label   = is_string( $job['label'] ?? null ) ? $job['label'] : '';
		$label   = trim( mb_substr( sanitize_text_field( (string) preg_replace( '/[\x00-\x1f\x7f]/', ' ', $label ) ), 0, 40 ) );
		$enabled = $job['enabled'] ?? true;
		$out     = array(
			'label'    => $label,
			'enabled'  => true === $enabled || 1 === $enabled || '1' === $enabled || 'true' === $enabled || 'on' === $enabled,
			'schedule' => $schedule,
			'kind'     => $kind,
			'timeout'  => $timeout,
		);

		if ( 'script' === $kind ) {
			$php  = is_string( $job['php'] ?? null ) ? $job['php'] : '';
			$path = is_string( $job['path'] ?? null ) ? $job['path'] : '';

			if ( ! in_array( $php, (array) ( $ctx['php_versions'] ?? array() ), true ) ) {
				return new \WP_Error( 'invalid', __( 'That PHP version is not offered.', 'beltoft-services' ) );
			}

			if ( ! self::valid_path( $path ) ) {
				return new \WP_Error( 'invalid', __( 'Enter the path of a PHP file inside the website, for example cron/run.php. Letters, numbers and . _ / - only, no .. and no slash at the start.', 'beltoft-services' ) );
			}

			$args = $job['args'] ?? array();

			if ( is_string( $args ) ) {
				$args = '' === trim( $args ) ? array() : preg_split( '/ +/', trim( $args ) );
			}

			if ( ! is_array( $args ) || ! array_is_list( $args ) || count( $args ) > self::MAX_ARGS || array() !== array_filter( $args, static fn( $a ) => ! is_string( $a ) || 1 !== preg_match( '/^[A-Za-z0-9._:=\/@,+-]{1,100}$/D', $a ) ) ) {
				/* translators: %d: arguments. */
				return new \WP_Error( 'invalid', sprintf( __( 'Give up to %d arguments, separated by spaces: letters, numbers and . _ : = / @ , + - only.', 'beltoft-services' ), self::MAX_ARGS ) );
			}

			return array_merge( $out, array( 'php' => $php, 'path' => $path, 'args' => array_values( $args ) ) );
		}

		if ( 'url' === $kind ) {
			$url = is_string( $job['url'] ?? null ) ? $job['url'] : '';

			if ( 1 !== preg_match( '#^https://([a-z0-9][a-z0-9.-]{0,251}[a-z0-9])([/?][A-Za-z0-9._~/:@,+=&%?-]{0,500})?$#D', $url, $m ) || strlen( $url ) > 800 ) {
				return new \WP_Error( 'invalid', __( 'Enter an address of this website that starts with https://, with letters, numbers and . _ ~ / : @ , + = & % ? - only.', 'beltoft-services' ) );
			}

			if ( ! in_array( $m[1], (array) ( $ctx['names'] ?? array() ), true ) ) {
				return new \WP_Error( 'invalid', __( 'A scheduled job can fetch an address of this website only.', 'beltoft-services' ) );
			}

			return array_merge( $out, array( 'url' => $url ) );
		}

		return $out;
	}

	/**
	 * Is the text a path inside the website's folder: `[A-Za-z0-9._/-]`, 1 to 200 characters, no empty, `.` or `..` part, no slash or dash at the start?
	 *
	 * @param string $path Path.
	 */
	public static function valid_path( string $path ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._\/-]{1,200}$/D', $path ) || '-' === $path[0] ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The list as `site.cron_set` takes it: only what the agent knows, `args` only for a script that has some.
	 *
	 * @param array<int, array<string, mixed>> $jobs Stored jobs.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_agent( array $jobs ): array {
		$out = array();

		foreach ( $jobs as $job ) {
			$one = array(
				'id'       => (string) $job['id'],
				'kind'     => (string) $job['kind'],
				'enabled'  => (bool) $job['enabled'],
				'schedule' => (string) $job['schedule'],
				'timeout'  => (int) $job['timeout'],
			);

			if ( '' !== (string) ( $job['label'] ?? '' ) ) {
				$one['label'] = (string) $job['label'];
			}

			if ( 'script' === $job['kind'] ) {
				$one['php']  = (string) $job['php'];
				$one['path'] = (string) $job['path'];

				if ( array() !== (array) ( $job['args'] ?? array() ) ) {
					$one['args'] = array_values( (array) $job['args'] );
				}
			} elseif ( 'url' === $job['kind'] ) {
				$one['url'] = (string) $job['url'];
			}

			$out[] = $one;
		}

		return $out;
	}

	/**
	 * What a client may read of the jobs (the overview route, hosting_info): what each job is and when it runs, but not its arguments or the query of
	 * its address, which may carry a key (the page of the owner shows them). The output of a run is never kept.
	 *
	 * @param array<string, mixed> $site Site row.
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing( array $site ): array {
		$out = array();

		foreach ( self::stored( $site ) as $job ) {
			if ( isset( $job['args'] ) ) {
				$job['args_count'] = count( (array) $job['args'] );
				unset( $job['args'] );
			}

			if ( isset( $job['url'] ) ) {
				$job['url_has_query'] = false !== strpos( (string) $job['url'], '?' );
				$job['url']           = (string) preg_replace( '/\?.*$/s', '', (string) $job['url'] );
			}

			$out[] = $job;
		}

		return $out;
	}

	// The page.

	/**
	 * What the page shows of a website's scheduled jobs.
	 *
	 * @param array<string, mixed> $site   Site of HostingActions::sites() (slug, wordpress, php, cron_state).
	 * @param array<int, array<string, mixed>> $stored The stored jobs in full (the page is the owner's: arguments and the query of an address are shown).
	 * @param bool                 $ready  Whether the account takes changes.
	 * @param array<string, mixed> $limits The plan's ceilings (cron_jobs, php_versions).
	 * @param string[]             $names  The names the website serves itself.
	 * @param int                  $until  Meta `cron_unsupported` (when an agent without the operations was met), 0 when never.
	 * @return array<string, mixed>
	 */
	public static function view( array $site, array $stored, bool $ready, array $limits, array $names, int $until = 0 ): array {
		$jobs  = array();
		$has   = ! array_key_exists( 'wordpress', $site ) || ! empty( $site['wordpress'] );
		$state = (array) ( $site['cron_state'] ?? array() );

		foreach ( $stored as $job ) {
			$jobs[] = self::row( $job, $names );
		}

		$wp_job = null;

		foreach ( $jobs as $row ) {
			if ( 'wordpress' === $row['kind'] ) {
				$wp_job = $row;
			}
		}

		$limit = (int) ( $limits['cron_jobs'] ?? 5 );

		return array(
			'slug'         => (string) $site['slug'],
			'id'           => 'beltoft-cron-' . (string) $site['slug'],
			'ready'        => $ready,
			'wp'           => $has,
			'limit'        => $limit,
			'count'        => count( $jobs ),
			'can_add'      => $ready && count( $jobs ) < $limit,
			'jobs'         => $jobs,
			'blank'        => self::row( array( 'id' => '', 'kind' => 'script', 'enabled' => true, 'schedule' => '0 3 * * *', 'timeout' => self::DEFAULT_TIMEOUT, 'php' => (string) ( $site['php'] ?? '' ), 'path' => '', 'args' => array(), 'label' => '' ), $names ),
			'php_versions' => (array) ( $limits['php_versions'] ?? array() ),
			'names'        => $names,
			'server_cron'  => $wp_job,
			'can_server'   => $has && $ready && null === $wp_job && count( $jobs ) < $limit,
			// The server cron is on but the agent could not switch WordPress's own cron off (no usable wp-config.php): the page says so.
			'wp_both'      => null !== $wp_job && $wp_job['enabled'] && ! empty( $state ) && empty( $state['wp_cron_disabled'] ),
			'unavailable'  => $until + self::UNSUPPORTED_FOR > Jobs::now(),
			'value'        => 0 === count( $jobs )
				? __( 'None', 'beltoft-services' )
				/* translators: %d: number of scheduled jobs. */
				: sprintf( _n( '%d job', '%d jobs', count( $jobs ), 'beltoft-services' ), count( $jobs ) ),
		);
	}

	/**
	 * One job as a row of the form: the stored values and what the form's controls take.
	 *
	 * @param array<string, mixed> $job   Stored job.
	 * @param string[]             $names The website's own names.
	 * @return array<string, mixed>
	 */
	private static function row( array $job, array $names ): array {
		$schedule = (string) ( $job['schedule'] ?? '' );
		$url      = (string) ( $job['url'] ?? '' );
		$host     = '';
		$rest     = '/';

		if ( 1 === preg_match( '#^https://([^/?]+)(.*)$#D', $url, $m ) ) {
			$host = $m[1];
			$rest = '' === $m[2] ? '/' : $m[2];
		}

		return array(
			'id'       => (string) ( $job['id'] ?? '' ),
			'label'    => (string) ( $job['label'] ?? '' ),
			'enabled'  => ! empty( $job['enabled'] ),
			'kind'     => (string) ( $job['kind'] ?? 'script' ),
			'schedule' => $schedule,
			'when'     => self::describe( $schedule ),
			'timeout'  => (int) ( $job['timeout'] ?? self::DEFAULT_TIMEOUT ),
			'php'      => (string) ( $job['php'] ?? '' ),
			'path'     => (string) ( $job['path'] ?? '' ),
			'args'     => implode( ' ', array_map( 'strval', (array) ( $job['args'] ?? array() ) ) ),
			'host'     => '' !== $host ? $host : (string) ( $names[0] ?? '' ),
			'rest'     => $rest,
		);
	}

	// The follow-up after a restore.

	/**
	 * Sends the stored jobs of every website to the node again once the account takes a change (a restore of the whole account put the container
	 * back as it was, with the jobs it had then). A change under way holds it back: it is asked again every 30 seconds.
	 *
	 * @param Resource $r Hosting resource.
	 */
	public static function resend_later( Resource $r ): void {
		Jobs::schedule_once( time() + 10, self::RESEND_HOOK, array( $r->id(), 0 ) );
	}

	/**
	 * The follow-up job: queues the change `resend_cron`, or looks again later.
	 *
	 * @param mixed $resource_id Resource id.
	 * @param mixed $attempt     Tries so far.
	 */
	public static function resend_run( $resource_id, $attempt = 0 ): void {
		$r = Resource::find( (int) $resource_id );

		if ( ! $r || HostingDriver::ID !== $r->get( 'driver' ) ) {
			return;
		}

		if ( ! Steps::start_follow( $r, 'resend_cron', array() ) && (int) $attempt < self::RESEND_TRIES ) {
			Jobs::schedule_once( time() + 30, self::RESEND_HOOK, array( $r->id(), (int) $attempt + 1 ) );
		}
	}
}
