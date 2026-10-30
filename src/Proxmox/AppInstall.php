<?php
/** Queue a one-time app install through the guest agent. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/** App work starts only after the server has reached ready. */
final class AppInstall {
	public const HOOK = 'beltoft_services_app_install';

	/** The customer action that tries a failed or silent install again. */
	public const RETRY = 'app_retry';

	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( Jobs::SWEEP, array( __CLASS__, 'sweep' ) );
	}

	/** Recover an install action lost after the server became ready. */
	public static function sweep(): void {
		global $wpdb;
		$table = \BeltoftLicenses\Install::tables()['activations'];
		$pending = '%' . $wpdb->esc_like( '"app_state":"pending"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom-table sweep, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'vm' AND deactivated_at IS NULL AND meta LIKE %s", $table, $pending ) );
		foreach ( (array) $ids as $id ) {
			$r = Resource::find( (int) $id );
			if ( $r && 'ready' === $r->get( 'state' ) && 'pending' === $r->get( 'app_state' ) ) {
				self::schedule( (int) $id );
			}
		}
	}

	/** Is "Try again" offered: a ready server whose app failed or never reported back. */
	public static function can_retry( Resource $r ): bool {
		$state = (string) $r->get( 'app_state' );
		if ( 'ready' !== $r->get( 'state' ) || '' === (string) $r->get( 'app_id' ) || null === Apps::definition( (string) $r->get( 'app_id' ) ) || ! $r->accepts_actions() ) {
			return false;
		}
		// The app installed but its login file could not be read: a second install would not help, support must look.
		if ( 'failed' === $state ) {
			return 'credentials' !== (string) $r->get( 'app_step' );
		}
		// Silent: only after an hour without a report. A pending install is already queued.
		return 'unknown' === $state && time() - (int) $r->get( 'app_started_at' ) > HOUR_IN_SECONDS;
	}

	/**
	 * Puts the app back to pending with a new request id and queues the install job again. The server is not touched.
	 */
	public static function retry( Resource $r ): StepResult {
		if ( ! self::can_retry( $r ) ) {
			return StepResult::done( array(), 'Try again refused: the app is not failed' );
		}
		$ok = $r->set( array(
			'app_state'       => 'pending',
			'app_request'     => '',
			'app_step'        => null,
			'app_started_at'  => null,
			'app_checked_at'  => null,
			'app_queue_until' => null,
			'app_alerted'     => null,
			'app_ready_since' => null,
		) );
		if ( ! $ok ) {
			return StepResult::retry( 0, 'The app state could not be reset' );
		}
		self::schedule( $r->id() );
		return StepResult::done( array(), 'The app install was tried again' );
	}

	public static function state_changed( Resource $r, string $old, string $new ): void {
		if ( 'ready' === $new && 'ready' !== $old && '' !== (string) $r->get( 'app_id' ) && ! in_array( $r->get( 'app_state' ), array( 'ready', 'installing', 'failed' ), true ) ) {
			self::schedule( $r->id(), 0 );
		}
	}

	public static function schedule( int $id, int $delay = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$waiting = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' );
		if ( ! $waiting ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), Jobs::GROUP );
		}
	}

	/** Run one bounded attempt under the resource lock; app failures never affect server state. */
	public static function run( int $id ): void {
		$r = Resource::find( $id );
		if ( ! $r || ! $r->is_live() || 'ready' !== $r->get( 'state' ) || '' === (string) $r->get( 'app_id' ) || in_array( $r->get( 'app_state' ), array( 'ready', 'installing', 'failed' ), true ) ) {
			return;
		}
		// A name on the customer's own DNS: the install waits until it points at the server (AppOwnDomain queues it again).
		if ( AppOwnDomain::waiting( $r ) ) {
			AppOwnDomain::start( $r );
			return;
		}
		$locked = $r->locked( static function () use ( $r ) {
			$plan = (array) $r->get( 'plan' );
			$record = VmRecords::for_resource( $r->id() );
			$cluster_id = $record ? $record['cluster'] : (string) ( $plan['cluster'] ?? '' );
			$cluster = Clusters::get( $cluster_id );
			if ( ! $cluster ) {
				self::fail( $r, 'The app cluster is missing' );
				return true;
			}
			$chosen = $r->get( OsField::META );
			$plan['template'] = $chosen ? (int) $chosen : (int) ( $plan['template'] ?? 0 );
			if ( ! $plan['template'] ) {
				$plan['template'] = Clusters::default_template( $cluster_id );
			}
			$why = Apps::check( $cluster, $plan, (string) $r->get( 'app_id' ), (string) $r->get( 'app_version' ) );
			if ( null !== $why ) {
				self::fail( $r, $why );
				return true;
			}
			if ( null !== Clients::gate( $cluster_id, $cluster, Clients::live_allowed() ) ) {
				self::fail( $r, 'The app cluster is not available' );
				return true;
			}
			try {
				$client = Clients::for( $cluster );
			} catch ( \Throwable $e ) {
				self::fail( $r, 'The app client could not be built' );
				return true;
			}
			$result = self::queue( $r, $client );
			if ( $result->is_poll() ) {
				$r->set( $result->meta() );
				self::schedule( $r->id(), $result->poll_seconds() );
			} elseif ( $result->is_failed() ) {
				self::fail( $r, $result->error() );
			} else {
				$r->set( $result->meta() );
				AppStatus::schedule( $r->id(), 15 );
			}
			return true;
		} );
		if ( null === $locked ) {
			self::schedule( $id, 10 );
		}
	}

	private static function fail( Resource $r, string $reason ): void {
		$r->set( array( 'app_state' => 'failed', 'app_checked_at' => time() ) );
		AppAlerts::failure( $r, $reason );
	}

	/** Tries at a zone read or write before the domain is dropped. */
	private const DOMAIN_TRIES = 4;

	/**
	 * The domain the app is served on. When the customer ticked the box at checkout, the A record is made now, once,
	 * in a zone the customer still holds with us. A DNS failure that may pass is tried again a few times; a name that
	 * can never be used is dropped at once. A dropped domain installs the app without one (plain HTTP at the address),
	 * the App tab says so and an admin is told; the server and the install never wait for DNS for long.
	 *
	 * @return string|StepResult The domain ('' for none), or a poll result to look again shortly.
	 */
	private static function domain( Resource $resource, string $ip, int $user_id ) {
		$done = (string) $resource->get( 'app_domain' );
		$want = (string) $resource->get( 'app_domain_want' );

		if ( '' !== $done || '' === $want || '' !== (string) $resource->get( 'app_domain_error' ) ) {
			return $done;
		}

		$wrote = false;
		$made = AppDomains::publish( $user_id, (string) $resource->get( 'app_zone' ), $want, $ip, $wrote );

		if ( true === $made ) {
			$resource->set( array( 'app_domain' => $want, 'app_domain_made' => $wrote ) );
			return $want;
		}

		if ( is_wp_error( $made ) ) {
			$tries = (int) $resource->get( 'app_domain_tries' ) + 1;
			if ( $tries < self::DOMAIN_TRIES ) {
				$resource->set( array( 'app_domain_tries' => $tries ) );
				return StepResult::poll( 30, 'app: DNS did not answer, trying again', 900 );
			}
			$made = $made->get_error_message();
		}

		$resource->set( array( 'app_domain_error' => $made ) );
		AppAlerts::domain_dropped( $resource, $want, $made );

		return '';
	}

	/** One file-write attempt. A missing guest directory gets a free poll, not a counted retry. */
	public static function queue( Resource $resource, Client $client ): StepResult {
		$id = (string) $resource->get( 'app_id' );
		$version_id = (string) $resource->get( 'app_version' );
		$app = Apps::version( $id, $version_id );
		$record = VmRecords::for_resource( $resource->id() );
		$ip = IpPool::for_resource( $resource->id() );
		if ( null === $app || null === $record || null === $ip ) {
			return StepResult::fail( 'The app definition, VM record or address is missing' );
		}
		$license = $resource->license();
		$owner = get_userdata( (int) ( $license['user_id'] ?? 0 ) );
		$email = $owner ? (string) $owner->user_email : '';
		if ( ! empty( $app['needs_email'] ) && ! is_email( $email ) ) {
			return StepResult::fail( 'The account email is unavailable for app setup' );
		}
		$request = (string) $resource->get( 'app_request' );
		if ( '' === $request ) {
			$request = wp_generate_uuid4();
			if ( ! $resource->set( array( 'app_request' => $request, 'app_state' => 'pending' ) ) ) {
				return StepResult::poll( 10, 'app: request id could not be stored', 600 );
			}
		}
		$domain = self::domain( $resource, (string) $ip['address'], (int) ( $license['user_id'] ?? 0 ) );
		if ( $domain instanceof StepResult ) {
			return $domain;
		}
		$job = array(
			'v' => 1,
			'op' => 'install',
			'request' => $request,
			'app' => $id,
			'version' => $version_id,
			'recipe' => (int) $app['recipe'],
			'url' => $app['url'],
			'sha256' => $app['sha256'],
			'domain' => $domain,
			'ip' => $ip['address'],
			'params' => array(),
			'key_only' => '' !== (string) $resource->get( 'ssh_key' ),
		);
		if ( ! empty( $app['needs_email'] ) ) {
			$job['email'] = $email;
		}
		$base = '/nodes/' . rawurlencode( $record['node'] ) . '/qemu/' . $record['vmid'];
		$reply = $client->request( 'POST', $base . '/agent/file-write', array( 'file' => Apps::JOB, 'content' => wp_json_encode( $job ) ) );
		if ( is_wp_error( $reply ) ) {
			$error_data = (array) $reply->get_error_data();
			$status = (int) ( $error_data['status'] ?? 0 );
			if ( 403 === $status ) {
				return StepResult::fail( 'Guest file-write permission denied' );
			}
			$until = (int) $resource->get( 'app_queue_until' );
			$until = $until ?: time() + 600;
			if ( time() >= $until ) {
				return StepResult::fail( 'Guest agent did not accept the install job' );
			}
			return StepResult::poll( 10, 'app: waiting for guest bootstrap', 600, array( 'app_queue_until' => $until ) );
		}
		return StepResult::done( array(
			'app_state' => 'installing',
			'app_recipe' => (int) $app['recipe'],
			'app_upstream' => (string) $app['upstream'],
			'app_started_at' => time(),
			'app_queue_until' => null,
		) );
	}
}
