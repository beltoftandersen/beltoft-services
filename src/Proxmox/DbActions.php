<?php
/**
 * The customer's action on a managed database: a new password.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * `db_reset_password` runs from Jobs::act under the resource lock: the guest sets a new password and hands it
 * over like at setup (stored encrypted for one reveal, the guest's copy emptied). The word to type is checked
 * before the job is queued.
 */
final class DbActions {

	use Steps;
	use Prepares;

	public const RESET   = 'db_reset_password';
	public const CONFIRM = 'NEW PASSWORD';

	/** Resets per user and hour. */
	private const LIMIT = 5;

	/** Seconds the guest has for a new password. */
	private const BUDGET = 600;

	/** @var array<string, mixed> */
	private $cluster = array();

	public function __construct( Resource $r ) {
		$this->r = $r;
	}

	public static function register(): void {
		add_filter( 'beltoft_services_action_refusal', array( __CLASS__, 'refusal' ), 10, 4 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 3 );
	}

	/**
	 * Is the resource a database of ours?
	 *
	 * @param mixed $r Resource.
	 */
	public static function is_db( $r ): bool {
		return $r instanceof Resource && DbDriver::ID === $r->get( 'driver' );
	}

	/**
	 * @return array<string, string>
	 */
	public static function offered( Resource $r ): array {
		return 'ready' === $r->get( 'db_state' ) && Crypto::available() ? array( self::RESET => __( 'New password', 'beltoft-services' ) ) : array();
	}

	/**
	 * The reset has a form of its own (the typed word): no generic button.
	 *
	 * @param mixed $actions Actions.
	 * @param mixed $r       Resource.
	 * @return mixed
	 */
	public static function buttons( $actions, $r ) {
		if ( is_array( $actions ) && self::is_db( $r ) ) {
			unset( $actions[ self::RESET ] );
		}

		return $actions;
	}

	/**
	 * The early refusal of the route.
	 *
	 * @param mixed                 $refusal  Refusal so far.
	 * @param mixed                 $r        Resource.
	 * @param mixed                 $action   Action.
	 * @param array<string, string> $args     Arguments.
	 * @return mixed
	 */
	public static function refusal( $refusal, $r, $action = '', $args = array() ) {
		if ( ! self::is_db( $r ) || self::RESET !== $action ) {
			return $refusal;
		}

		if ( self::CONFIRM !== ( is_array( $args ) ? ( $args['confirm'] ?? '' ) : '' ) ) {
			/* translators: %s: the words to type, NEW PASSWORD. */
			return new \WP_Error( 'beltoft_services_confirm', sprintf( __( 'Type %s to confirm. The old password stops working.', 'beltoft-services' ), self::CONFIRM ), array( 'status' => 422 ) );
		}

		if ( '' !== (string) $r->get( 'db_reset_request' ) ) {
			return new \WP_Error( 'beltoft_services_busy', __( 'A new password is already being made.', 'beltoft-services' ), array( 'status' => 409 ) );
		}

		if ( RateLimit::exceeded( get_current_user_id(), 'beltoft-services:db-reset', self::LIMIT, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'beltoft_services_rate_limited', __( 'The password can be changed five times an hour. Please try again later.', 'beltoft-services' ), array( 'status' => 429 ) );
		}

		return $refusal;
	}

	/**
	 * Runs the action (called by DbDriver::act).
	 */
	public function run(): StepResult {
		$record = VmRecords::for_resource( $this->r->id() );

		if ( null === $record ) {
			return StepResult::fail( 'db_reset_password: no VM is recorded for this service' );
		}

		$early = $this->prepare( $record );

		if ( null !== $early ) {
			return $early;
		}

		$v = $this->verified( $record, 'db_reset_password' );

		if ( $v instanceof StepResult ) {
			return $v;
		}

		$record['node'] = $v['node'];
		$request        = (string) $this->r->get( 'db_reset_request' );

		if ( '' === $request ) {
			$request = wp_generate_uuid4();

			if ( ! $this->r->set( array( 'db_reset_request' => $request, 'db_reset_started' => time(), 'db_reset_sent' => false ) ) ) {
				return StepResult::retry( 0, 'db_reset_password: the request could not be stored' );
			}
		}

		if ( ! $this->r->get( 'db_reset_sent' ) ) {
			$sent = DbJob::send( $this->client, $record, array( 'v' => 1, 'request' => $request, 'op' => 'reset_password' ) );

			if ( is_wp_error( $sent ) ) {
				return $this->over( $request ) ? $this->give_up( 'The guest agent did not accept the new password job' ) : StepResult::poll( 10, 'db_reset_password: waiting for the guest agent', self::BUDGET );
			}

			$this->r->set( array( 'db_reset_sent' => true ) );

			return StepResult::poll( 10, 'db_reset_password: the job was sent', self::BUDGET );
		}

		$check = DbJob::check( $this->r, $this->client, $record, $request, true );

		if ( 'failed' === $check['state'] ) {
			return $this->give_up( 'The database server reported a failure at step ' . $check['step'] );
		}

		if ( in_array( $check['state'], array( 'none', 'working', 'credentials' ), true ) ) {
			return $this->over( $request ) ? $this->give_up( 'The database server did not make a new password within 10 minutes' ) : StepResult::poll( 10, 'db_reset_password: waiting for the database server', self::BUDGET );
		}

		$this->r->set( array( 'db_reset_request' => null, 'db_reset_sent' => null, 'db_reset_started' => null ) );

		return StepResult::done( array(), 'Customer made a new database password' );
	}

	private function over( string $request ): bool {
		unset( $request );

		return time() - (int) $this->r->get( 'db_reset_started' ) > self::BUDGET;
	}

	private function give_up( string $why ): StepResult {
		$this->r->set( array( 'db_reset_request' => null, 'db_reset_sent' => null, 'db_reset_started' => null ) );

		return StepResult::fail( $why );
	}
}
