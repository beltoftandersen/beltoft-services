<?php
/** Add or change the domain of an app that is already running. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Background;
use BeltoftServices\Domains\ZoneAccess;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The customer action `app_domain`: a ready server whose app serves a domain gets another one without a reinstall. Two choices
 * only (a name in a zone of ours, or the customer's own domain at another DNS host); the app can never be taken off its
 * domain here. Nothing is charged and nothing is changed at the registrar.
 *
 * Order, so the old name keeps working until the new one is proven:
 * 1. start() (the action job, resource lock held): the choice is checked again. A name in our zone gets its A record through
 *    AppDomains::publish (only a zone of this customer, only when the name has no address record). An own domain writes
 *    nothing: the change waits and reads public DNS at the rate AppOwnDomain uses, for up to AppOwnDomain::WAIT_DAYS.
 * 2. The guest is told (AppRename::push) and answers in a status file that run() reads (AppRename::read, untrusted).
 * 3. On ready the new name is committed in one write and the records we wrote for the old name are queued for removal
 *    (AppDomainRelease::queue_old: only A sets we wrote, still unchanged). On any failure the records we wrote for the new
 *    name are queued for removal and the old name and its meta are untouched.
 *
 * Meta (all flat): app_chg_state (waiting, applying, failed), app_chg_mode (change, own), app_chg_name, app_chg_zone,
 * app_chg_www (the www name the guest was asked to serve), app_chg_made and app_chg_www_made (records we wrote for the new
 * name, not yet committed), app_chg_ip, app_chg_run, app_chg_since, app_chg_checked, app_chg_at, app_chg_reason, app_chg_tries,
 * and after a commit app_domain_changed_at. Links stored inside the customer's content are never rewritten.
 */
final class AppDomainChange {

	public const ACTION = 'app_domain';
	public const HOOK   = 'beltoft_services_app_domain_change';

	/** Seconds the guest has to answer for a run before the change is called failed. */
	public const APPLY_WAIT = 900;

	/** Seconds between two looks at the guest's status file. */
	public const POLL = 15;

	/** Tries to hand the request to the guest before the change is called failed. */
	public const PUSH_TRIES = 3;

	/** The reasons a failed change can show; the guest's own words are mapped onto these. */
	private const REASONS = array( 'none', 'other', 'aaaa', 'unreachable', 'timeout', 'certificate', 'caddy', 'app', 'probe', 'agent', 'busy' );

	/**
	 * A stand-in for the guest side (AppRename) in tests: an object with supported( $r ), push( $r, $want ) and read( $r, $run ).
	 *
	 * @internal
	 * @var object|null
	 */
	public static $guest = null;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Background::declare(
			self::HOOK,
			array(
				'due'      => array( __CLASS__, 'due' ),
				'resource' => true,
				'label'    => 'App domain change',
			)
		);
	}

	// -- The guest, through one door --------------------------------------------------------------

	/** Why the guest cannot be changed (AppRename::supported), or null. */
	private static function guest_supported( Resource $r ): ?string {
		return null !== self::$guest ? self::$guest->supported( $r ) : AppRename::supported( $r );
	}

	/** @return string|\WP_Error The run token. */
	private static function guest_push( Resource $r, array $want ) {
		return null !== self::$guest ? self::$guest->push( $r, $want ) : AppRename::push( $r, $want );
	}

	private static function guest_read( Resource $r, string $run ): ?array {
		return null !== self::$guest ? self::$guest->read( $r, $run ) : AppRename::read( $r, $run );
	}

	// -- What is offered ---------------------------------------------------------------------------

	/**
	 * The action, on a ready server whose app serves a domain and is ready itself.
	 *
	 * @return array<string, string> Action => label.
	 */
	public static function offered( Resource $r ): array {
		$def = '' === (string) $r->get( 'app_id' ) ? null : Apps::definition( (string) $r->get( 'app_id' ) );
		if ( 'ready' !== $r->get( 'state' ) || null === $def || empty( $def['domain'] ) || 'ready' !== (string) $r->get( 'app_state' ) ) {
			return array();
		}
		return array( self::ACTION => __( 'Change domain', 'beltoft-services' ) );
	}

	/**
	 * Why the action cannot be accepted, in words for the customer; null when it can. Nothing is written.
	 *
	 * @param array<string, mixed> $args Posted: app_domain_mode (change or own), app_zone, app_sub, app_own.
	 */
	public static function check( Resource $r, array $args ): ?string {
		$def = '' === (string) $r->get( 'app_id' ) ? null : Apps::definition( (string) $r->get( 'app_id' ) );
		if ( null === $def || empty( $def['domain'] ) ) {
			return __( 'This app does not use a domain.', 'beltoft-services' );
		}
		if ( 'ready' !== (string) $r->get( 'app_state' ) ) {
			return __( 'The app is not ready yet. Try again when it is.', 'beltoft-services' );
		}
		if ( ! Actions::agent_ok( $r ) ) {
			return __( 'The domain cannot be changed on this server now.', 'beltoft-services' );
		}
		$why = self::guest_supported( $r );
		if ( null !== $why ) {
			return $why;
		}
		if ( in_array( (string) $r->get( 'app_chg_state' ), array( 'waiting', 'applying' ), true ) ) {
			return __( 'A change of domain is already in progress.', 'beltoft-services' );
		}
		if ( 'applying' === (string) $r->get( 'app_www' ) ) {
			return __( 'A www name is being added. Try again in a few minutes.', 'beltoft-services' );
		}
		if ( ! in_array( $args['app_domain_mode'] ?? '', array( 'change', 'own' ), true ) ) {
			return __( 'Choose a domain option.', 'beltoft-services' );
		}
		$choice = AppDomainChoice::resolve( $r, $args, $def, true );
		if ( is_string( $choice ) ) {
			return $choice;
		}
		if ( 'keep' === $choice['mode'] ) {
			return __( 'That is already the address of the app.', 'beltoft-services' );
		}
		return AppDomainChoice::release_refusal( $r, $choice );
	}

	// -- The action job ---------------------------------------------------------------------------

	/**
	 * Runs inside the action job under the resource lock: writes the A record for a name in our zone, or starts the wait for an
	 * own domain, and hands the change to the guest.
	 *
	 * @param array<string, mixed> $args Posted arguments.
	 */
	public static function start( Resource $r, array $args ): StepResult {
		$refuse = static fn( string $why ) => StepResult::refused( array( Jobs::TEXT => $why ), 'Change of domain refused: ' . $why );
		if ( ! AppInstall::installable( $r ) ) {
			return $refuse( __( 'The domain cannot be changed on this server now.', 'beltoft-services' ) );
		}
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::retry( Jobs::PAUSED_DELAY, 'Paused' );
		}
		$why = self::check( $r, $args );
		if ( null !== $why ) {
			return $refuse( $why );
		}
		$def    = (array) Apps::definition( (string) $r->get( 'app_id' ) );
		$choice = AppDomainChoice::resolve( $r, $args, $def, true );
		if ( is_string( $choice ) || ! in_array( $choice['mode'], array( 'change', 'own' ), true ) ) {
			return $refuse( is_string( $choice ) ? $choice : __( 'Choose a domain option.', 'beltoft-services' ) );
		}
		$ip = AppOwnDomain::address( $r );
		if ( '' === $ip ) {
			return $refuse( __( 'The server address is not known yet.', 'beltoft-services' ) );
		}
		$user  = (int) ( $r->license()['user_id'] ?? 0 );
		$fqdn  = $choice['fqdn'];
		$zone  = $choice['zone'];
		$meta  = array_merge(
			self::cleared(),
			array(
				'app_chg_state' => 'applying',
				'app_chg_mode'  => $choice['mode'],
				'app_chg_name'  => $fqdn,
				'app_chg_zone'  => '' === $zone ? null : $zone,
				'app_chg_ip'    => $ip,
				'app_chg_since' => time(),
				'app_chg_at'    => time(),
			)
		);

		if ( 'own' === $choice['mode'] ) {
			// Nothing is written: the customer points the name at the server, and public DNS is read until it does.
			$meta['app_chg_state'] = 'waiting';
			if ( ! $r->set( $meta ) ) {
				return StepResult::retry( 30, 'The change could not be stored' );
			}
			self::schedule( $r->id(), 0 );
			return StepResult::done( array(), 'Change of domain to ' . $fqdn . ' waits for DNS' );
		}

		// A name in a zone of ours: only a zone of this customer, only a name that has no address record.
		$wrote = false;
		$made  = AppDomains::publish( $user, $zone, $fqdn, $ip, $wrote );
		if ( is_wp_error( $made ) ) {
			return StepResult::retry( 30, 'The DNS zone could not be changed just now' );
		}
		if ( true !== $made ) {
			return $refuse( (string) $made );
		}
		$meta['app_chg_made'] = $wrote;

		$www = AppWww::name_for( $r, $fqdn, $zone );
		if ( '' !== $www ) {
			$wrote_www = false;
			$www_made  = AppDomains::publish( $user, $zone, $www, $ip, $wrote_www, true );
			if ( true === $www_made ) {
				$meta['app_chg_www']      = $www;
				$meta['app_chg_www_made'] = $wrote_www;
			} elseif ( ! is_wp_error( $www_made ) && 'live' === AppWww::verdict( $www, $ip ) ) {
				// A record of the customer's own that already points here: served, never touched.
				$meta['app_chg_www'] = $www;
			}
			// Else the www name is left to the existing follow-up (AppWww), which adds it later.
		}
		if ( ! $r->set( $meta ) ) {
			// The records written above are named in the meta that could not be stored: the action is tried again (a record that is there already is adopted, never removed).
			return StepResult::retry( 30, 'The change could not be stored' );
		}
		self::schedule( $r->id(), 0 );
		return StepResult::done( array(), 'Change of domain to ' . $fqdn . ' started' );
	}

	public static function schedule( int $id, int $delay = 0 ): void {
		Jobs::schedule_once( time() + $delay, self::HOOK, array( $id ) );
	}

	/** @return array<int, array<int, int>> The watchdog's list (Background): a look lost. */
	public static function due(): array {
		$sets = array();
		foreach ( Resource::find_by_meta( 'vm', 'app_chg_state', array( 'waiting', 'applying' ) ) as $id ) {
			$r = Resource::find( (int) $id );
			if ( $r && $r->is_live() && 'ready' === $r->get( 'state' ) ) {
				$sets[] = array( (int) $id );
			}
		}
		return $sets;
	}

	/** The meta that forgets a change. @return array<string, null> */
	public static function cleared(): array {
		$out = array();
		foreach ( array( 'state', 'mode', 'name', 'zone', 'www', 'made', 'www_made', 'ip', 'run', 'since', 'checked', 'at', 'reason', 'tries' ) as $key ) {
			$out[ 'app_chg_' . $key ] = null;
		}
		return $out;
	}

	/**
	 * The records written for a new name that is not committed yet (a server deleted meanwhile removes them too).
	 *
	 * @return array<int, array{zone: string, name: string, ip: string}>
	 */
	public static function pending_records( Resource $r ): array {
		$zone = (string) $r->get( 'app_chg_zone' );
		$ip   = (string) $r->get( 'app_chg_ip' );
		$out  = array();
		if ( '' === $zone || '' === $ip ) {
			return $out;
		}
		if ( true === $r->get( 'app_chg_made' ) && '' !== (string) $r->get( 'app_chg_name' ) ) {
			$out[] = array( 'zone' => $zone, 'name' => (string) $r->get( 'app_chg_name' ), 'ip' => $ip );
		}
		if ( true === $r->get( 'app_chg_www_made' ) && '' !== (string) $r->get( 'app_chg_www' ) ) {
			$out[] = array( 'zone' => $zone, 'name' => (string) $r->get( 'app_chg_www' ), 'ip' => $ip );
		}
		return $out;
	}

	// -- The background job ---------------------------------------------------------------------

	/** One bounded step: read public DNS (own domain), hand the change to the guest, or read the guest's answer. */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() || ! in_array( (string) $r->get( 'app_chg_state' ), array( 'waiting', 'applying' ), true ) ) {
			return;
		}
		// The kill switch, a hold or a server that is not ready stops every phase: look again later, touch nothing.
		if ( Settings::get( 'pause_all' ) ) {
			self::schedule( $id, Jobs::PAUSED_DELAY );
			return;
		}
		if ( ! AppInstall::installable( $r ) ) {
			self::schedule( $id, AppOwnDomain::INTERVAL );
			return;
		}

		if ( 'waiting' === (string) $r->get( 'app_chg_state' ) ) {
			self::wait_for_dns( $r );
			return;
		}

		$locked = $r->locked(
			static function () use ( $id ): bool {
				$fresh = Resource::find( $id );
				if ( $fresh && 'applying' === (string) $fresh->get( 'app_chg_state' ) ) {
					self::apply( $fresh );
				}
				return true;
			}
		);
		if ( true !== $locked ) {
			self::schedule( $id, 30 );
		}
	}

	/** An own domain: one bounded public DNS read, then the guest when the name points at the server and nowhere else. */
	private static function wait_for_dns( Resource $r ): void {
		$id    = $r->id();
		$now   = time();
		$since = (int) $r->get( 'app_chg_since' );
		$gap   = $now - (int) $r->get( 'app_chg_checked' );
		if ( (int) $r->get( 'app_chg_checked' ) > 0 && $gap < AppOwnDomain::MIN_GAP ) {
			self::schedule( $id, AppOwnDomain::MIN_GAP - $gap );
			return;
		}
		if ( $since > 0 && $now >= $since + AppOwnDomain::WAIT_DAYS * DAY_IN_SECONDS ) {
			self::store( $id, array( 'app_chg_state' => 'failed', 'app_chg_reason' => 'timeout', 'app_chg_checked' => $now ), 'waiting' );
			AppAlerts::domain_change_failed( $r, (string) $r->get( 'app_chg_name' ), 'timeout' );
			return;
		}
		$ip = AppOwnDomain::address( $r );
		if ( '' === $ip ) {
			self::schedule( $id, AppOwnDomain::INTERVAL );
			return;
		}
		// The read is made outside the resource lock; the result is stored under it.
		$name    = (string) $r->get( 'app_chg_name' );
		$verdict = AppWww::verdict( $name, $ip );
		if ( 'live' !== $verdict ) {
			if ( self::store( $id, array( 'app_chg_checked' => $now, 'app_chg_reason' => $verdict ), 'waiting' ) ) {
				self::schedule( $id, AppOwnDomain::INTERVAL );
			}
			return;
		}
		// The www name is handed over only when it points here too; otherwise the existing follow-up adds it later.
		$www = AppWww::name_for( $r, $name, '' );
		$use = '' !== $www && 'live' === AppWww::verdict( $www, $ip ) ? $www : '';
		if ( self::store( $id, array( 'app_chg_checked' => $now, 'app_chg_reason' => null, 'app_chg_state' => 'applying', 'app_chg_www' => $use, 'app_chg_at' => $now ), 'waiting' ) ) {
			self::schedule( $id, 0 );
		}
	}

	/**
	 * Stores meta under the resource lock when the change still is in the state it was read in.
	 *
	 * @param array<string, mixed> $meta Meta.
	 * @param string               $from The state the change must still be in.
	 */
	private static function store( int $id, array $meta, string $from ): bool {
		$done = ( Resource::find( $id ) ?: null )?->locked(
			static function () use ( $id, $meta, $from ): bool {
				$fresh = Resource::find( $id );
				return $fresh && $from === (string) $fresh->get( 'app_chg_state' ) && $fresh->set( $meta );
			}
		);
		if ( true !== $done ) {
			self::schedule( $id, 60 );
			return false;
		}
		return true;
	}

	/** The guest phase, under the resource lock, on a fresh read. */
	private static function apply( Resource $r ): void {
		$id  = $r->id();
		$now = time();
		$run = (string) $r->get( 'app_chg_run' );

		if ( '' === $run ) {
			$token = self::guest_push( $r, self::want( $r ) );
			if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
				$tries = (int) $r->get( 'app_chg_tries' ) + 1;
				if ( $tries >= self::PUSH_TRIES ) {
					self::fail( $r, 'agent' );
					return;
				}
				$r->set( array( 'app_chg_tries' => $tries ) );
				self::schedule( $id, 30 );
				return;
			}
			$r->set( array( 'app_chg_run' => $token, 'app_chg_at' => $now, 'app_chg_tries' => null ) );
			self::schedule( $id, self::POLL );
			return;
		}

		$status = self::guest_read( $r, $run );
		if ( null === $status || 'running' === ( $status['state'] ?? '' ) ) {
			if ( $now - (int) $r->get( 'app_chg_at' ) > self::APPLY_WAIT ) {
				self::fail( $r, 'agent' );
				return;
			}
			self::schedule( $id, self::POLL );
			return;
		}
		if ( 'ready' === ( $status['state'] ?? '' ) ) {
			self::commit( $r );
			return;
		}
		$reason = (string) ( $status['reason'] ?? '' );
		self::fail( $r, in_array( $reason, self::REASONS, true ) && 'none' !== $reason ? $reason : 'app' );
	}

	/**
	 * What the guest is asked for.
	 *
	 * @return array<string, mixed>
	 */
	private static function want( Resource $r ): array {
		$old     = (string) $r->get( 'app_domain' );
		$old_www = '' !== $old && 'live' === (string) $r->get( 'app_www' ) ? AppWww::name_for( $r, $old ) : '';
		$app     = (array) Apps::version( (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ), true );
		return array(
			'v'          => 1,
			'run'        => bin2hex( random_bytes( 12 ) ),
			'mode'       => 'domain',
			'domain'     => (string) $r->get( 'app_chg_name' ),
			'www'        => (string) $r->get( 'app_chg_www' ),
			'old_domain' => $old,
			'old_www'    => $old_www,
			'ip'         => (string) $r->get( 'app_chg_ip' ),
			'app'        => (string) $r->get( 'app_id' ),
			'recipe'     => (int) ( $r->get( 'app_recipe' ) ?: ( $app['recipe'] ?? 0 ) ),
		);
	}

	// -- The end ----------------------------------------------------------------------------------

	/** The guest reported ready: the new name is stored in one write, the old name's records go to the removal queue. */
	private static function commit( Resource $r ): void {
		$old     = (string) $r->get( 'app_domain' );
		$new     = (string) $r->get( 'app_chg_name' );
		$mode    = (string) $r->get( 'app_chg_mode' );
		$zone    = (string) $r->get( 'app_chg_zone' );
		$served  = (string) $r->get( 'app_chg_www' );
		$www     = AppWww::name_for( $r, $new, $zone );
		$ip      = (string) $r->get( 'app_chg_ip' );
		$now     = time();

		// The old name's meta is read before anything of it is overwritten.
		$meta = AppDomainRelease::queue_old( $r );
		$meta = array_merge(
			$meta,
			self::cleared(),
			array(
				'app_domain'            => $new,
				'app_zone'              => '' === $zone ? null : $zone,
				'app_domain_made'       => 'change' === $mode && true === $r->get( 'app_chg_made' ),
				'app_domain_want'       => null,
				'app_domain_error'      => null,
				'app_domain_tries'      => null,
				'app_domain_changed_at' => $now,
				'app_www'               => '' !== $served ? 'live' : ( '' !== $www ? 'waiting' : null ),
				'app_www_reason'        => '' !== $served || '' === $www ? null : 'none',
				'app_www_checked'       => null,
				'app_www_at'            => null,
				'app_www_made'          => '' !== $served && true === $r->get( 'app_chg_www_made' ) ? true : null,
				'app_www_ip'            => '' !== $served && true === $r->get( 'app_chg_www_made' ) ? $ip : null,
			)
		);
		if ( 'own' === $mode ) {
			$meta = array_merge(
				$meta,
				AppOwnDomain::cleared(),
				array(
					'app_domain_own'       => $new,
					'app_domain_dns'       => 'live',
					'app_domain_dns_since' => (int) $r->get( 'app_chg_since' ),
					'app_domain_dns_mailed' => null,
				)
			);
		} else {
			$meta = array_merge( $meta, AppOwnDomain::cleared() );
		}

		if ( ! $r->set( $meta ) ) {
			// Nothing was committed: look again, the guest's answer is still there.
			self::schedule( $r->id(), 30 );
			return;
		}
		$fresh = Resource::find( $r->id() ) ?: $r;
		if ( array() !== (array) $fresh->get( 'app_domain_old' ) ) {
			AppDomainRelease::schedule( $r->id() );
		}
		if ( 'own' === $mode ) {
			// The email that says the app is live on the customer's own domain (once).
			AppOwnDomain::installed( $fresh );
		}
		if ( 'waiting' === (string) $fresh->get( 'app_www' ) ) {
			AppWww::installed( $fresh );
		}
		/**
		 * The domain of an app was changed. Fired after the new name is stored.
		 *
		 * @param Resource $r   Resource.
		 * @param string   $old The old name, '' when the app had none.
		 * @param string   $new The new name.
		 */
		do_action( 'beltoft_services_app_domain_changed', $fresh, $old, $new );
	}

	/** The change failed: the old name and its meta stay; the records we wrote for the new name go to the removal queue. */
	private static function fail( Resource $r, string $reason ): void {
		$name   = (string) $r->get( 'app_chg_name' );
		$meta   = array( 'app_chg_state' => 'failed', 'app_chg_reason' => $reason, 'app_chg_made' => null, 'app_chg_www_made' => null, 'app_chg_run' => null, 'app_chg_tries' => null );
		$queued = self::pending_records( $r );
		if ( $queued ) {
			$old = array_values( array_filter( (array) $r->get( 'app_domain_old' ), 'is_array' ) );
			foreach ( $queued as $entry ) {
				$old[] = $entry + array( 'tries' => 0 );
			}
			$meta['app_domain_old'] = array_slice( $old, 0, 6 );
		}
		if ( ! $r->set( $meta ) ) {
			self::schedule( $r->id(), 30 );
			return;
		}
		if ( $queued ) {
			AppDomainRelease::schedule( $r->id() );
		}
		AppAlerts::domain_change_failed( $r, $name, $reason );
	}

	// -- What the customer sees -----------------------------------------------------------------

	/**
	 * For the App tab.
	 *
	 * @return array{offered: bool, supported: bool, why: string, state: string, mode: string, name: string, www: string, reason: string, ip: string, deadline: int, zones: array<int, string>, current: string}
	 */
	public static function view( Resource $r ): array {
		$def       = '' === (string) $r->get( 'app_id' ) ? null : Apps::definition( (string) $r->get( 'app_id' ) );
		$offered   = array() !== self::offered( $r );
		$why       = $offered ? self::guest_supported( $r ) : null;
		$state     = (string) $r->get( 'app_chg_state' );
		$since     = (int) $r->get( 'app_chg_since' );
		$mode      = (string) $r->get( 'app_chg_mode' );
		$name      = (string) $r->get( 'app_chg_name' );
		return array(
			'offered'   => $offered,
			'supported' => $offered && null === $why,
			'why'       => (string) $why,
			'state'     => in_array( $state, array( 'waiting', 'applying', 'failed' ), true ) ? $state : '',
			'mode'      => in_array( $mode, array( 'change', 'own' ), true ) ? $mode : '',
			'name'      => $name,
			'www'       => '' === $name || 'own' !== $mode ? '' : AppWww::name_for( $r, $name, '' ),
			'reason'    => in_array( (string) $r->get( 'app_chg_reason' ), self::REASONS, true ) ? (string) $r->get( 'app_chg_reason' ) : '',
			'ip'        => AppOwnDomain::address( $r ),
			'deadline'  => 'waiting' === $state && $since > 0 ? $since + AppOwnDomain::WAIT_DAYS * DAY_IN_SECONDS : 0,
			'zones'     => null === $def || empty( $def['domain'] ) ? array() : ZoneAccess::zones( (int) ( $r->license()['user_id'] ?? 0 ) ),
			'current'   => AppDomainChoice::current( $r ),
		);
	}
}
