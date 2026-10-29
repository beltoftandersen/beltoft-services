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
		if ( in_array( $id, array( 'wordpress', 'coolify' ), true ) && ! is_email( $email ) ) {
			return StepResult::fail( 'The account email is unavailable for app setup' );
		}
		$request = (string) $resource->get( 'app_request' );
		if ( '' === $request ) {
			$request = wp_generate_uuid4();
			if ( ! $resource->set( array( 'app_request' => $request, 'app_state' => 'pending' ) ) ) {
				return StepResult::poll( 10, 'app: request id could not be stored', 600 );
			}
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
			'domain' => (string) $resource->get( 'app_domain' ),
			'ip' => $ip['address'],
			'params' => array(),
			'key_only' => '' !== (string) $resource->get( 'ssh_key' ),
		);
		if ( in_array( $id, array( 'wordpress', 'coolify' ), true ) ) {
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
