<?php
/**
 * The customer's choice of what a database does when its memory is full (Valkey).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * `db_set_policy` runs from Jobs::act under the resource lock: the guest sets the policy on the running server and in
 * its config file (the `policy` job), then the choice is stored. Only an engine with DbEngineEviction offers it. A guest
 * that refuses the job itself (step `job`) is from before the job existed: the choice is not stored, the customer is told
 * the setting needs a newer database server image, the admin is told, and the database is left alone.
 */
final class DbPolicy {

	use Steps;
	use Prepares;

	public const ACTION = 'db_set_policy';

	/** Meta: `old_image` when the guest could not take the setting. */
	public const NOTICE = 'db_policy_notice';

	private const REQUEST = 'db_policy_request';
	private const WANT    = 'db_policy_want';
	private const SENT    = 'db_policy_sent';
	private const STARTED = 'db_policy_started';

	/** Seconds the guest has for the job. */
	private const BUDGET = 600;

	/** Changes per user and hour. */
	private const LIMIT = 20;

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	/**
	 * The engine's choices when the database's engine offers the setting, else null.
	 *
	 * @param Resource $r Database.
	 */
	public static function engine( Resource $r ): ?DbEngineEviction {
		$engine = DbEngines::for_resource( $r );

		return $engine instanceof DbEngineEviction ? $engine : null;
	}

	/**
	 * Is the setting offered for this database (right engine, ready, secrets available for the guest protocol)?
	 *
	 * @param Resource $r Database.
	 */
	public static function offered( Resource $r ): bool {
		return null !== self::engine( $r ) && 'ready' === $r->get( 'db_state' );
	}

	/**
	 * The policy now in force (as far as we know).
	 *
	 * @param Resource $r Database.
	 */
	public static function current( Resource $r ): string {
		$engine = self::engine( $r );

		return null === $engine ? '' : ValkeyEngine::policy( $r );
	}

	/**
	 * What the card shows: null when the engine has no such setting.
	 *
	 * @param Resource $r        Database.
	 * @param bool     $editable Whether it may be changed now.
	 * @return array<string, mixed>|null
	 */
	public static function view( Resource $r, bool $editable ): ?array {
		$engine = self::engine( $r );

		if ( null === $engine ) {
			return null;
		}

		return array(
			'choices'  => $engine->eviction_choices(),
			'current'  => self::current( $r ),
			'can_set'  => $editable && self::offered( $r ),
			'working'  => '' !== (string) $r->get( self::REQUEST ),
			'old'      => 'old_image' === $r->get( self::NOTICE ),
			'old_text' => __( 'This setting needs a newer database server image than the one your database was built from. We have been told. Your database keeps working with its current setting.', 'beltoft-services' ),
		);
	}

	/**
	 * The early refusal of the route.
	 *
	 * @param mixed                $refusal Refusal so far.
	 * @param Resource             $r       Database.
	 * @param array<string, mixed> $args    Arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, Resource $r, array $args ) {
		$engine = self::engine( $r );
		$policy = (string) ( $args['policy'] ?? '' );

		if ( null === $engine || ! array_key_exists( $policy, $engine->eviction_choices() ) ) {
			return new \WP_Error( 'beltoft_services_invalid', __( 'Choose one of the listed settings.', 'beltoft-services' ), array( 'status' => 422 ) );
		}

		if ( '' !== (string) $r->get( self::REQUEST ) ) {
			return new \WP_Error( 'beltoft_services_busy', __( 'A change is already being applied.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:db-policy:' . $r->id(), self::LIMIT, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'beltoft_services_rate_limited', __( 'Too many changes. Please try again later.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		return $refusal;
	}

	/**
	 * Runs the action (called by DbDriver::act). The wanted policy comes from the action's arguments once, then from the
	 * stored request.
	 *
	 * @param array<string, string> $args Arguments.
	 */
	public function run( array $args = array() ): StepResult {
		$engine = self::engine( $this->r );

		if ( null === $engine ) {
			return StepResult::fail( 'This database action is not available.' );
		}

		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_set_policy: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'db_set_policy' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$request        = (string) $this->r->get( self::REQUEST );

		if ( '' === $request ) {
			$want = (string) ( $args['policy'] ?? '' );

			if ( ! array_key_exists( $want, $engine->eviction_choices() ) ) {
				return StepResult::fail( 'db_set_policy: unknown policy' );
			}

			$request = wp_generate_uuid4();

			if ( ! $this->r->set( array( self::REQUEST => $request, self::WANT => $want, self::STARTED => time(), self::SENT => false ) ) ) {
				return StepResult::retry( 0, 'db_set_policy: the request could not be stored' );
			}
		}

		$want = (string) $this->r->get( self::WANT );

		if ( ! $this->r->get( self::SENT ) ) {
			$sent = DbJob::send( $this->client, $record, array( 'v' => 1, 'request' => $request, 'op' => 'policy', 'policy' => $want ) );

			if ( is_wp_error( $sent ) ) {
				return $this->over() ? $this->give_up( 'The guest agent did not accept the memory policy job' ) : StepResult::poll( 10, 'db_set_policy: waiting for the guest agent', self::BUDGET );
			}

			$this->r->set( array( self::SENT => true ) );

			return StepResult::poll( 5, 'db_set_policy: the job was sent', self::BUDGET );
		}

		$status = DbGuest::status( $this->client, (string) $record['node'], (int) $record['vmid'], $request );

		if ( null !== $status && 'failed' === $status['state'] ) {
			if ( 'job' === $status['step'] ) {
				$this->clear();
				$this->r->set( array( self::NOTICE => 'old_image' ) );
				Alerts::admin( $this->r, 'The database server does not know the memory policy job: its template is older than the guest script that supports it. Rebuild the database template' );

				return StepResult::done( array(), 'The database server image is too old for the memory policy setting' );
			}

			return $this->give_up( 'The database server did not apply the memory policy (step ' . $status['step'] . ')' );
		}

		if ( null === $status || 'ready' !== $status['state'] ) {
			return $this->over() ? $this->give_up( 'The database server did not apply the memory policy within 10 minutes' ) : StepResult::poll( 10, 'db_set_policy: waiting for the database server', self::BUDGET );
		}

		$this->clear();
		$this->r->set( array( ValkeyEngine::POLICY_META => $want, self::NOTICE => null ) );

		return StepResult::done( array(), 'Customer set the memory policy to ' . $want );
	}

	private function over(): bool {
		return time() - (int) $this->r->get( self::STARTED ) > self::BUDGET;
	}

	private function clear(): void {
		$this->r->set( array( self::REQUEST => null, self::WANT => null, self::SENT => null, self::STARTED => null ) );
	}

	private function give_up( string $why ): StepResult {
		$this->clear();

		return StepResult::fail( $why );
	}
}
