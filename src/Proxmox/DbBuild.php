<?php
/**
 * The database-only steps of a build: the private address, the guest setup, the health check.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Steps `net`, `db_init` and `db_ready` of DbProfile, run by Provision. Each one returns meta (done) or a
 * StepResult (wait, retry, fail). Texts here reach only the admin: Reconciler alerts on a failure.
 */
final class DbBuild {

	/** Seconds the guest has to finish its setup. */
	public const INIT_BUDGET = 900;

	/** Seconds the guest agent may take to accept the job. */
	private const QUEUE_BUDGET = 600;

	/** Seconds a ready guest may take to show its login. */
	private const LOGIN_BUDGET = 600;

	/** Seconds the database has to answer after the setup. */
	public const READY_BUDGET = 600;

	/** @var Resource */
	private $r;

	/** @var Client */
	private $client;

	public function __construct( Resource $r, Client $client ) {
		$this->r      = $r;
		$this->client = $client;
	}

	/**
	 * Plan check that needs no Proxmox call.
	 */
	public static function plan_error( Resource $r ): ?string {
		$plan = (array) $r->get( 'plan' );

		if ( ! empty( $plan['public_ip'] ) ) {
			return 'A database with a public IP address is not available yet (public_ip must be 0)';
		}

		if ( 'postgres' !== ( $plan['engine'] ?? 'postgres' ) ) {
			return 'Only the postgres engine is available';
		}

		return null;
	}

	/**
	 * Step net: the customer's private network and an address on it.
	 *
	 * @return array<string, mixed>|StepResult
	 */
	public function net( array $cluster ) {
		$user = (int) ( $this->r->license()['user_id'] ?? 0 );

		try {
			$row = PrivateNets::for_resource( $this->r->id() );

			if ( null === $row ) {
				$got = PrivateNets::reserve( $user, $this->r->id() );
				$row = null === $got ? null : PrivateNets::for_resource( $this->r->id() );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return StepResult::retry( 0, 'net: the private network pool is busy' );
		}

		if ( null === $row ) {
			return StepResult::fail( 'No private network available for this customer' );
		}

		if ( '' === (string) ( $cluster['private_bridge'] ?? '' ) ) {
			return StepResult::fail( 'The cluster has no private network bridge configured' );
		}

		$nic = PrivateNic::params( $row, $row['address'], (string) $cluster['private_bridge'] );

		if ( is_wp_error( $nic ) ) {
			return StepResult::fail( 'net: ' . $nic->get_error_message() );
		}

		return array(
			'db_address'        => $row['address'],
			'db_cidr'           => $row['cidr'],
			Firewall::LOCKED    => true,
		);
	}

	/**
	 * Step db_init: the init job, its status and its login.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	public function init( array $record ) {
		if ( 'failed' === $this->r->get( 'db_state' ) ) {
			// An admin tried again: a new job. A guest that was set up before gets a new password instead.
			if ( ! $this->r->set( array( 'db_state' => null, 'db_request' => null, 'db_ready_since' => null ) ) ) {
				return StepResult::retry( 0, 'db_init: the state could not be reset' );
			}
		}

		$state = (string) $this->r->get( 'db_state' );

		if ( 'installing' !== $state ) {
			return $this->queue( $record );
		}

		$started = (int) $this->r->get( 'db_started_at' );
		$request = (string) $this->r->get( 'db_request' );
		$check   = DbJob::check( $this->r, $this->client, $record, $request, true );

		if ( 'failed' === $check['state'] ) {
			$this->r->set( array( 'db_state' => 'failed', 'db_step' => $check['step'], 'db_inited' => $this->r->get( 'db_inited' ) || 'finish' === $check['step'] ) );

			return StepResult::fail( 'The database server reported a failure at step ' . $check['step'] );
		}

		if ( 'working' === $check['state'] || 'none' === $check['state'] ) {
			if ( time() - $started > self::INIT_BUDGET ) {
				$this->r->set( array( 'db_state' => 'failed' ) );

				return StepResult::fail( 'The database server did not report a valid status within 15 minutes' );
			}

			$this->r->set( array( 'db_step' => $check['step'] ) );

			return StepResult::poll( 15, 'db_init: ' . ( '' === $check['step'] ? 'waiting for the guest' : $check['step'] ), max( 30, self::INIT_BUDGET - ( time() - $started ) ) );
		}

		if ( 'credentials' === $check['state'] ) {
			$since = (int) $this->r->get( 'db_ready_since' );
			$since = $since > 0 ? $since : time();

			if ( time() - $since > self::LOGIN_BUDGET ) {
				$this->r->set( array( 'db_state' => 'failed', 'db_inited' => true ) );

				return StepResult::fail( 'The database server was ready but its login could not be read for 10 minutes' );
			}

			$this->r->set( array( 'db_ready_since' => $since, 'db_inited' => true ) );

			return StepResult::poll( 10, 'db_init: waiting for the login', self::LOGIN_BUDGET );
		}

		return array( 'db_state' => 'ready', 'db_step' => 'complete', 'db_inited' => true, 'db_ready_since' => null );
	}

	/**
	 * Writes the init (or, on a guest that is set up, reset_password) job.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	private function queue( array $record ) {
		$error = self::plan_error( $this->r );

		if ( null !== $error ) {
			return StepResult::fail( $error );
		}

		$address = (string) $this->r->get( 'db_address' );
		$cidr    = (string) $this->r->get( 'db_cidr' );

		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || 1 !== preg_match( '#/28$#D', $cidr ) ) {
			return StepResult::fail( 'db_init: the private address is missing' );
		}

		$request = (string) $this->r->get( 'db_request' );

		if ( '' === $request ) {
			$request = wp_generate_uuid4();

			if ( ! $this->r->set( array( 'db_request' => $request, 'db_state' => 'pending' ) ) ) {
				return StepResult::poll( 10, 'db_init: the request id could not be stored', 600 );
			}
		}

		$op  = $this->r->get( 'db_inited' ) ? 'reset_password' : 'init';
		$job = 'init' === $op
			? array( 'v' => 1, 'request' => $request, 'op' => 'init', 'db' => DbGuest::DATABASE, 'role' => DbGuest::ROLE, 'listen' => array( $address ), 'private_cidr' => $cidr, 'allow' => array(), 'world' => false, 'max_connections' => DbProfile::max_connections( $this->r ) )
			: array( 'v' => 1, 'request' => $request, 'op' => 'reset_password' );

		$sent = DbJob::send( $this->client, $record, $job );

		if ( is_wp_error( $sent ) ) {
			$data = (array) $sent->get_error_data();

			if ( 403 === (int) ( $data['status'] ?? 0 ) ) {
				return StepResult::fail( 'Guest file-write permission denied' );
			}

			$until = (int) $this->r->get( 'db_queue_until' );
			$until = $until > 0 ? $until : time() + self::QUEUE_BUDGET;

			if ( time() >= $until ) {
				return StepResult::fail( 'The guest agent did not accept the database job' );
			}

			$this->r->set( array( 'db_queue_until' => $until ) );

			return StepResult::poll( 10, 'db_init: waiting for the guest agent', self::QUEUE_BUDGET );
		}

		if ( ! $this->r->set( array( 'db_state' => 'installing', 'db_op' => $op, 'db_started_at' => time(), 'db_queue_until' => null ) ) ) {
			return StepResult::poll( 5, 'db_init: the job state could not be stored', 300 );
		}

		return StepResult::poll( 15, 'db_init: the job was sent', self::INIT_BUDGET );
	}

	/**
	 * Step db_ready: the database answers.
	 *
	 * @param array<string, mixed> $record VM record.
	 * @return array<string, mixed>|StepResult
	 */
	public function ready( array $record ) {
		$since = (int) $this->r->get( 'db_ready_check_since' );
		$since = $since > 0 ? $since : time();

		if ( DbGuest::healthy( $this->client, (string) $record['node'], (int) $record['vmid'], (int) $this->r->get( 'db_started_at' ) ) ) {
			return array( 'db_ready_check_since' => null, 'db_ready_at' => time() );
		}

		if ( time() - $since > self::READY_BUDGET ) {
			return StepResult::fail( 'The database did not become healthy within 10 minutes' );
		}

		$this->r->set( array( 'db_ready_check_since' => $since ) );

		return StepResult::poll( 15, 'db_ready: waiting for the database', self::READY_BUDGET );
	}
}
