<?php
/** Remove only app A records this plugin made, after a reinstall starts. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Background;
use BeltoftServices\Domains\Records;
use BeltoftServices\Jobs;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Domains\ZoneAccess;

defined( 'ABSPATH' ) || exit;

final class AppDomainRelease {
	public const HOOK = 'beltoft_services_app_domain_release';

	public static function register(): void {
		add_action( 'beltoft_services_reinstall_started', array( __CLASS__, 'started' ), 10, 2 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'deleted' ), 10, 3 );
		add_filter( 'beltoft_services_hold_ip_on_delete', array( __CLASS__, 'hold_ip' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		Background::declare(
			self::HOOK,
			array(
				'due'      => array( __CLASS__, 'due' ),
				'resource' => true,
				'label'    => 'App domain record release',
			)
		);
	}

	/**
	 * The meta that moves a server away from the name it has: the A and www records we wrote for it go into the removal queue
	 * (removed later only while they still are what we wrote), a name that sat in our zone without a record of ours is noted
	 * as left, and the www marks are cleared. The same for a reinstall and for a change of domain; nothing is written here.
	 *
	 * @param string[] $keep Names the new site still uses: their records are not queued (the change of domain moves their ownership).
	 * @return array<string, mixed> Meta to merge.
	 */
	public static function queue_old( Resource $r, array $keep = array() ): array {
		$meta      = array();
		$published = (string) $r->get( 'app_domain' );
		$own       = (string) $r->get( 'app_domain_own' );

		// A name on the customer's own DNS never had a record of ours, so there is nothing to say was left behind.
		if ( '' !== $published && '' === $own && true !== $r->get( 'app_domain_made' ) ) {
			$meta['app_domain_left'] = array( 'zone' => (string) $r->get( 'app_zone' ), 'name' => $published );
		} else {
			$meta['app_domain_left'] = null;
		}
		$old    = (array) $r->get( 'app_domain_old' );
		$queued = false;
		if ( '' !== $published && true === $r->get( 'app_domain_made' ) && '' !== (string) $r->get( 'app_zone' ) && ! in_array( $published, $keep, true ) ) {
			$ip    = \BeltoftServices\Proxmox\Network\IpPool::for_resource( $r->id() );
			$old[] = array(
				'zone'  => (string) $r->get( 'app_zone' ),
				'name'  => $published,
				'ip'    => (string) ( $ip['address'] ?? $r->get( 'ip' ) ),
				'tries' => 0,
			);
			$queued = true;
		}
		// The www record we wrote next to the domain's goes the same way: removed only while it is still the record we wrote.
		$www_ip = (string) $r->get( 'app_www_ip' );
		if ( true === $r->get( 'app_www_made' ) && '' !== (string) $r->get( 'app_zone' ) && '' !== $www_ip && ! in_array( 'www.' . (string) $r->get( 'app_zone' ), $keep, true ) ) {
			$old[] = array(
				'zone'  => (string) $r->get( 'app_zone' ),
				'name'  => 'www.' . (string) $r->get( 'app_zone' ),
				'ip'    => $www_ip,
				'tries' => 0,
			);
			$queued = true;
		}
		$meta['app_www_made'] = null;
		$meta['app_www_ip']   = null;
		if ( $queued ) {
			$meta['app_domain_old'] = array_slice( $old, 0, 4 );
		}
		return $meta;
	}

	/** The action fires after reinstall metadata is stored. No DNS work runs inside the server job. */
	public static function started( Resource $r, array $args = array() ): void {
		if ( (array) $r->get( 'app_domain_old' ) ) {
			self::schedule( $r->id() );
		}
	}

	/**
	 * A deleted server frees its address for the next order, so the A and www records we made for its domain must go: queued
	 * as a reinstall queues them (removed only while they are still the records we wrote), marked final because the domain is
	 * still set on the closed resource. The job needs no live resource.
	 *
	 * @param mixed $r   Resource.
	 * @param mixed $old Old state.
	 * @param mixed $new New state.
	 */
	public static function deleted( $r, $old, $new ): void {
		if ( 'deleted' !== $new || 'deleted' === $old || ! $r instanceof Resource || \BeltoftServices\Proxmox\Vm\ProxmoxDriver::ID !== (string) $r->get( 'driver' ) ) {
			return;
		}

		$zone = (string) $r->get( 'app_zone' );
		$ip   = (string) $r->get( 'ip' );
		$old  = array_values( array_filter( (array) $r->get( 'app_domain_old' ), 'is_array' ) );
		$new  = array();

		if ( '' !== $zone && true === $r->get( 'app_domain_made' ) && '' !== (string) $r->get( 'app_domain' ) ) {
			$new[] = array( 'zone' => $zone, 'name' => (string) $r->get( 'app_domain' ), 'ip' => $ip, 'tries' => 0, 'final' => true );
		}

		if ( '' !== $zone && true === $r->get( 'app_www_made' ) && '' !== (string) $r->get( 'app_www_ip' ) ) {
			$new[] = array( 'zone' => $zone, 'name' => 'www.' . $zone, 'ip' => (string) $r->get( 'app_www_ip' ), 'tries' => 0, 'final' => true );
		}

		// Records written for a new name of a change of domain that was not finished (AppDomainChange): still ours, still pointing here.
		foreach ( AppDomainChange::pending_records( $r ) as $pending ) {
			$new[] = $pending + array( 'tries' => 0, 'final' => true );
		}

		if ( ! $new ) {
			self::free_ip( $r );
			return;
		}
		if ( ! $r->set( array( 'app_domain_old' => array_slice( array_merge( $old, $new ), 0, 6 ) ) ) ) {
			return;
		}

		self::schedule( $r->id() );
	}

	/**
	 * The server delete asks whether its address may go back to the pool: not while an A record we wrote still points to it.
	 * The flag is stored first; run() frees the address when the queue is empty.
	 *
	 * @param mixed $hold Filter value.
	 * @param mixed $r    Resource.
	 */
	public static function hold_ip( $hold, $r ) {
		if ( ! $r instanceof Resource ) {
			return $hold;
		}
		$zone = '' !== (string) $r->get( 'app_zone' );
		$ours = ( $zone && true === $r->get( 'app_domain_made' ) && '' !== (string) $r->get( 'app_domain' ) ) || ( $zone && true === $r->get( 'app_www_made' ) && '' !== (string) $r->get( 'app_www_ip' ) ) || array() !== AppDomainChange::pending_records( $r );
		if ( ! $ours || ! $r->set( array( 'app_ip_hold' => true ) ) ) {
			return $hold;
		}
		return true;
	}

	/** Gives the held address back once nothing of ours points to it. */
	private static function free_ip( Resource $r ): void {
		if ( true === $r->get( 'app_ip_hold' ) && 'deleted' === (string) $r->get( 'state' ) && ! array_filter( (array) $r->get( 'app_domain_old' ), 'is_array' ) ) {
			\BeltoftServices\Proxmox\Network\IpPool::release( $r->id() );
			$r->set( array( 'app_ip_hold' => false ) );
		}
	}

	public static function schedule( int $id, int $delay = 0 ): void {
		Jobs::schedule_once( time() + $delay, self::HOOK, array( $id ) );
	}

	/**
	 * The watchdog's list (Background): an old pending request whose scheduled action was lost.
	 *
	 * @return array<int, array<int, int>>
	 */
	public static function due(): array {
		$sets = array();
		foreach ( Resource::find_with_list( 'vm', 'app_domain_old' ) as $id ) {
			$r   = Resource::find( (int) $id );
			$old = $r ? (array) $r->get( 'app_domain_old' ) : array();
			if ( $r && self::runnable( $r ) && $old && time() - (int) ( $old[0]['at'] ?? 0 ) >= ( empty( $old[0]['in_progress'] ) ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS ) ) {
				$sets[] = array( $r->id() );
			}
		}
		return $sets;
	}

	/** A live resource, or a deleted one that still owes the removal of its records. */
	private static function runnable( Resource $r ): bool {
		return $r->is_live() || 'deleted' === (string) $r->get( 'state' );
	}

	/** One bounded attempt. The resource and zone locks are held on separate turns. */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! self::runnable( $r ) ) {
			return;
		}
		$entry = null;
		$wait = 0;
		$claimed = $r->locked( static function () use ( $r, &$entry, &$wait ): bool {
			$old = array_values( (array) $r->get( 'app_domain_old' ) );
			$changed = false;
			while ( $old && ! is_array( $old[0] ) ) {
				array_shift( $old );
				$changed = true;
			}
			if ( $changed && ! $r->set( array( 'app_domain_old' => $old ) ) ) {
				$wait = 30;
				return false;
			}
			if ( ! $old ) {
				self::free_ip( $r );
				return false;
			}
			$first = $old[0];
			$age = time() - (int) ( $first['at'] ?? 0 );
			if ( ! empty( $first['in_progress'] ) && $age < 10 * MINUTE_IN_SECONDS ) {
				$wait = max( 30, 10 * MINUTE_IN_SECONDS - $age );
				return false;
			}
			$first['in_progress'] = wp_generate_uuid4();
			$first['at'] = time();
			$old[0] = $first;
			if ( ! $r->set( array( 'app_domain_old' => $old ) ) ) {
				$wait = 30;
				return false;
			}
			$entry = $first;
			return true;
		} );
		if ( true !== $claimed ) {
			if ( null === $claimed || $wait > 0 ) {
				self::schedule( $id, $wait > 0 ? $wait : 30 );
			}
			return;
		}

		// No resource lock is held across this DNS read or the zone-locked deletion.
		$done = self::attempt( Resource::find( $id ) ?: $r, $entry );
		$next = null;
		$alert = false;
		$finished = $r->locked( static function () use ( $r, $entry, $done, &$next, &$alert ): bool {
			$old = (array) $r->get( 'app_domain_old' );
			if ( ! is_array( $old[0] ?? null ) || ( $old[0]['in_progress'] ?? '' ) !== $entry['in_progress'] ) {
				return false;
			}
			array_shift( $old );
			$meta = array();
			if ( is_wp_error( $done ) ) {
				$tries = (int) ( $entry['tries'] ?? 0 );
				if ( $tries < count( Reconciler::BACKOFF ) ) {
					$entry['tries'] = $tries + 1;
					unset( $entry['in_progress'] );
					$entry['at'] = time();
					array_unshift( $old, $entry );
					$next = Reconciler::BACKOFF[ $tries ];
				} elseif ( ! $r->get( 'app_domain_release_alerted' ) ) {
					$meta['app_domain_release_alerted'] = true;
					$alert = true;
				}
			}
			$meta['app_domain_old'] = $old;
			if ( ! $r->set( $meta ) ) {
				$alert = false;
				return false;
			}
			if ( null === $next && $old ) {
				$next = 0;
			}
			return true;
		} );
		if ( true !== $finished ) {
			self::schedule( $id, 30 );
			return;
		}
		if ( null === $next ) {
			self::free_ip( Resource::find( $id ) ?: $r );
		}
		if ( $alert ) {
			AppAlerts::domain_release_failed( $r, (string) ( $entry['name'] ?? '' ), $done->get_error_message() );
		}
		if ( null !== $next ) {
			self::schedule( $id, $next );
		}
	}

	/** DNS work only; every queue mutation happens in run() under the server lock. @return true|\WP_Error */
	private static function attempt( Resource $r, array $entry ): bool|\WP_Error {
		$zone = (string) ( $entry['zone'] ?? '' );
		$name = (string) ( $entry['name'] ?? '' );
		$ip = (string) ( $entry['ip'] ?? '' );
		$user = (int) ( $r->license()['user_id'] ?? 0 );
		$final = ! empty( $entry['final'] );
		if ( '' === $zone || '' === $name || ( ! $final && ( $name === (string) $r->get( 'app_domain' ) || $name === (string) $r->get( 'app_domain_want' ) || ( $name === (string) $r->get( 'app_chg_name' ) && in_array( (string) $r->get( 'app_chg_state' ), array( 'waiting', 'applying' ), true ) ) ) ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! self::editable( $zone, $user ) ) {
			return true;
		}
		$existing = AppDomains::existing( $zone, $name );
		if ( is_array( $existing ) && array_diff( array_keys( $existing ), array( 'A' ) ) ) {
			return true;
		}
		$expect = array( array( 'ttl' => AppDomains::TTL, 'value' => $ip ) );
		$done = Records::delete_set( $zone, $name, 'A', $expect );
		return true === $done || in_array( $done->get_error_code(), array( 'stale', 'records_off', 'zone_missing' ), true ) ? true : $done;
	}

	/** The current owner must still be able to edit this precise zone. */
	private static function editable( string $zone, int $user ): bool {
		if ( $user <= 0 || ! class_exists( \BeltoftLicenses\Licenses::class ) ) {
			return false;
		}
		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', $user ) as $license ) {
			$domain = Resource::for_license( (int) $license['id'] );
			if ( $domain && strtolower( (string) $domain->get( 'domain' ) ) === $zone && ZoneAccess::editable( $domain, $user ) ) {
				return true;
			}
		}
		return false;
	}
}
