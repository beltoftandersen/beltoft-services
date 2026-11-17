<?php
/**
 * Shared by the four groups of hosting account actions.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Runs;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\JobActions;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The parts every group of hosting account actions shares: the site lock, the agent job, the readiness check, the
 * edge map, error words and the plan ceilings. Each group class uses this trait and
 * is built from the same Resource by the HostingActions facade.
 */
trait Base {

	use Runs;

	/**
	 * The plan, typed.
	 *
	 * @var array<string, int|string|bool>
	 */
	private $plan = array();

	/**
	 * How the agent calls of this object are made: 'inline' (the caller waits a few seconds for each, for the command line, the
	 * add-ons and the jobs that already run in the background), 'request' (a web request: every check and the local work
	 * run, the first agent call ends it with a queued task) or 'job' (a run of the task's job: one agent step, nothing waits).
	 *
	 * @var string
	 */
	private $mode = 'inline';

	/**
	 * The change a request asked for: facade method and arguments.
	 *
	 * @var array{0: string, 1: array<int, mixed>}
	 */
	private $request = array( '', array() );

	/**
	 * What the change says about itself before its first agent call: the answer to the customer and the arguments of the job
	 * when they are not the ones asked with (a name that was made up).
	 *
	 * @var array{result: array<string, mixed>, args: array<int, mixed>|null}
	 */
	private $deferred = array( 'result' => array(), 'args' => null );

	/**
	 * The secret of the change that is being queued (see queue()).
	 *
	 * @var string|null
	 */
	private $secret_arg = null;

	/**
	 * Puts right the local work of the request when the change cannot be queued after all (see defer_undo()).
	 *
	 * @var callable|null
	 */
	private $undo = null;

	/**
	 * @param Resource $r    Hosting resource.
	 * @param string   $mode inline, request or job.
	 */
	public function __construct( Resource $r, string $mode = 'inline' ) {
		$this->r    = $r;
		$this->plan = Plan::typed( (array) $r->get( 'plan' ) );
		$this->mode = in_array( $mode, array( 'request', 'job' ), true ) ? $mode : 'inline';
	}

	/**
	 * Is this a run of the task's job?
	 */
	private function in_job(): bool {
		return 'job' === $this->mode;
	}

	/**
	 * The refusal of a change that is called outside the shared path (inline): a change of the account is queued from a request
	 * (HostingActions::queue()) and runs in its job. Nothing waits for the agent to change something.
	 *
	 * @return \WP_Error|null Null in a request and in a job.
	 */
	private function queued_only(): ?\WP_Error {
		return 'inline' === $this->mode ? $this->error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) ) : null;
	}

	/**
	 * Says what the customer is answered and what the job is given, when the change is queued at its first agent call.
	 *
	 * @param array<string, mixed>   $result The answer.
	 * @param array<int, mixed>|null $args   The job's arguments, when they differ from the request's.
	 */
	private function defer_with( array $result, ?array $args = null ): void {
		$this->deferred = array( 'result' => $result, 'args' => $args );
	}

	/**
	 * Runs a change in a web request: its checks and local work, and then, at the first agent call, the task is taken and its
	 * job is queued. A change that needs no agent call is simply done.
	 *
	 * @param string            $method The method to run.
	 * @param array<int, mixed> $args   Its arguments.
	 * @param string|null       $secret A secret first argument: it goes into the task encrypted, never into the job's arguments.
	 * @return array<string, mixed>|\WP_Error The answer: status pending with the task, or the plain result.
	 */
	public function queue( string $method, array $args, ?string $secret = null ) {
		// One change at a time, as for servers: the Progress marker. Asked again under the account's lock (locked()).
		$busy = JobActions::busy( Resource::find( $this->r->id() ) ?? $this->r );

		if ( null !== $busy ) {
			return $busy;
		}

		$this->mode       = 'request';
		$this->request    = array( $method, $args );
		$this->secret_arg = $secret;
		$this->undo       = null;
		$this->deferred   = array( 'result' => array(), 'args' => null );

		try {
			return $this->{$method}( ...$args );
		} catch ( Deferred $d ) {
			return $d->answer;
		} finally {
			$this->mode = 'inline';
			$this->undo = null;
		}
	}

	/**
	 * Says how the local work a request has done is put right when the change is not queued after all (the account became busy
	 * between the check and the start, or the task could not be stored): the request leaves nothing half done behind.
	 *
	 * @param callable $undo Puts the local work right.
	 */
	private function defer_undo( callable $undo ): void {
		$this->undo = $undo;
	}

	/**
	 * Runs a change of the account's websites while holding the account's site lock, so two requests (a double click,
	 * two tabs, the sweep) never change the same account at once.
	 *
	 * @param callable $change The change.
	 * @return mixed|\WP_Error The change's result, or busy.
	 */
	private function locked( callable $change ) {
		$guarded = function () use ( $change ) {
			// A request that makes a change asks again under the lock, so two clicks at once cannot both do their local work.
			$busy = 'request' === $this->mode ? JobActions::busy( Resource::find( $this->r->id() ) ?? $this->r ) : null;

			return null !== $busy ? $busy : $change();
		};

		list( $taken, $result ) = \BeltoftServices\Locks::with( \BeltoftServices\Locks::name( 'hosting-site', $this->r->id() ), $this->in_job() ? 0 : (int) apply_filters( 'beltoft_services_hosting_lock_wait', 10 ), $guarded );

		if ( ! $taken && $this->in_job() ) {
			// Something else (a sweep) is changing the account: look again in a few seconds, never give up the task for it.
			throw new TaskPending( StepResult::poll( 5, 'hosting: the account is being changed by something else', 300 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		return $taken ? $result : $this->error( 'busy', __( 'Your hosting is being changed. Try again in a moment.', 'beltoft-services' ) );
	}

	/**
	 * One agent job as a result or a customer error.
	 *
	 * In a web request that makes a change (queue()) the call is not made: it ends the request with a queued task. In a job it
	 * is one step: a call that has finished is read from the task, one that has not is submitted or read once, and while the
	 * agent is still working the run ends (TaskPending). Anywhere else the caller waits a few seconds, then is asked to try again.
	 *
	 * @param string               $name   Job name.
	 * @param string               $op     Operation.
	 * @param array<string, mixed> $args   Arguments.
	 * @param int                  $budget Seconds a job run may wait for the agent's job before the wait counts as an error (a copy of a
	 *                                     website takes minutes; the shared controller caps it, see Reconciler::poll_budget()).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $name, string $op, array $args, int $budget = 600 ) {
		if ( 'request' === $this->mode ) {
			throw new Deferred( $name, $this->defer_answer() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		if ( $this->in_job() ) {
			return $this->job_call( $name, $op, $args, $budget );
		}

		$done = $this->agent_call( $name, $op, $args );

		// A live agent answers queued first and finishes in a second or two: look again (same job id) for a few seconds before asking the customer to retry.
		/**
		 * Seconds a customer action waits for the agent's job.
		 *
		 * @param int    $seconds Seconds.
		 * @param string $name    The job's name (for example site_install_<slug>).
		 */
		$until = microtime( true ) + (int) apply_filters( 'beltoft_services_hosting_action_wait', 8, $name );

		while ( $done instanceof StepResult && $done->is_poll() && microtime( true ) < $until ) {
			usleep( 400000 );
			$done = $this->agent_call( $name, $op, $args );
		}

		if ( ! $done instanceof StepResult ) {
			return $done;
		}

		return $done->is_poll()
			? $this->error( 'busy', __( 'The server is busy. Try again in a moment.', 'beltoft-services' ) )
			: $this->error( 'failed', __( 'That did not work. Try again in a moment, or contact support if it keeps happening.', 'beltoft-services' ) );
	}

	/**
	 * Ends a request that has checked a change and done its local work: the change is queued (a change whose work is not an agent
	 * call of its own, such as a backup round, has no call() to end the request at).
	 *
	 * @param string $name Name of the step the job starts at.
	 * @throws Deferred Always: queue() catches it and answers with the queued task.
	 */
	private function defer_now( string $name ): void {
		throw new Deferred( $name, $this->defer_answer() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
	}

	/**
	 * The answer of a change that is queued: through the shared controller (Progress marker, Jobs::queue_act), with the site lock held by the change.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function defer_answer() {
		list( $method, $args ) = $this->request;

		$task = Steps::start( $this->r, $method, null === $this->deferred['args'] ? $args : $this->deferred['args'], $this->secret_arg );

		if ( is_wp_error( $task ) ) {
			if ( null !== $this->undo ) {
				( $this->undo )();
				$this->undo = null;
			}

			return $task;
		}

		return array_merge( $this->deferred['result'], array( 'status' => 'pending', 'task' => $task ) );
	}

	/**
	 * One agent step of a job run.
	 *
	 * @param string               $name   Call name.
	 * @param string               $op     Operation.
	 * @param array<string, mixed> $args   Arguments.
	 * @param int                  $budget Seconds the wait for the agent's job may last.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function job_call( string $name, string $op, array $args, int $budget = 600 ) {
		$memo = Steps::memo( $this->r, $name );

		if ( null !== $memo ) {
			return $memo['ok'] ? (array) $memo['result'] : $this->failed_call( (string) ( $memo['why'] ?? '' ) );
		}

		Steps::step( $this->r, $name );

		$done = $this->agent_step( $name, $op, $args, $budget );

		if ( $done instanceof StepResult && ! $done->is_failed() ) {
			// Still working, or the agent did not answer: this run ends, and the same action is queued again.
			throw new TaskPending( $this->progressed ? $done->with_progress() : $done ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		$ok  = is_array( $done );
		$why = $ok ? '' : ( $done instanceof StepResult ? $done->error() : ( is_wp_error( $done ) ? $done->get_error_message() : '' ) );

		if ( ! Steps::remember( $this->r, $name, $ok, $ok ? $done : null, $why ) ) {
			throw new TaskPending( StepResult::retry( 0, $name . ': the task step could not be stored' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a control-flow signal between request and job, never printed.
		}

		$this->bump_job( $name );
		$this->progressed = true;

		return $ok ? $done : $this->failed_call( $why );
	}

	/**
	 * What a call that really failed is to the customer. The agent's own words (scrubbed) ride in the error data under `why`, for the
	 * job to tell an agent that lacks the operation from one that failed; they never reach a reply.
	 *
	 * @param string $why What the agent said, scrubbed.
	 */
	private function failed_call( string $why = '' ): \WP_Error {
		return new \WP_Error( 'failed', __( 'That did not work. Try again in a moment, or contact support if it keeps happening.', 'beltoft-services' ), array( 'why' => $why ) );
	}

	/**
	 * Reads or submits the agent job of a call, once.
	 *
	 * @param string               $name   Call name.
	 * @param string               $op     Operation.
	 * @param array<string, mixed> $args   Arguments.
	 * @param int                  $budget Seconds the wait for the agent's job may last.
	 * @return array<string, mixed>|StepResult|\WP_Error The result of a job that is done; a poll while it works, a retry when the
	 *                                                  agent did not answer; a failure (StepResult or WP_Error) when it failed for good.
	 */
	private function agent_step( string $name, string $op, array $args, int $budget = 600 ) {
		if ( Settings::get( 'pause_all' ) ) {
			return StepResult::wait( Jobs::PAUSED_DELAY, $name . ': all driver activity is paused', time() + 7 * DAY_IN_SECONDS );
		}

		$job = $this->agent->submit( $op, $args, $this->job_id( $name ) );

		if ( is_wp_error( $job ) ) {
			return $this->classify( $job, $name );
		}

		$status = (string) ( $job['status'] ?? '' );

		if ( in_array( $status, array( 'queued', 'running' ), true ) ) {
			return StepResult::poll( 2, $name . ': waiting for the node agent', $budget );
		}

		if ( 'failed' === $status ) {
			return new \WP_Error( 'agent_failed', $this->scrub( (string) ( $job['error'] ?? '' ), 200 ) );
		}

		if ( 'done' !== $status ) {
			return StepResult::retry( 0, $name . ': the node agent sent an unknown job status' );
		}

		return is_array( $job['result'] ?? null ) ? $job['result'] : array();
	}

	/**
	 * The agent's job id of a call: resource, name and the number kept in the resource meta (see Runs::agent_call()).
	 *
	 * @param string $name Call name.
	 */
	private function job_id( string $name ): string {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;

		// The change's own token (made when it was queued) keeps a later change of the same name from reading this one's job: a change
		// that ends before its counter moved on (stopped, lapsed) leaves the counter as it was.
		$run = $this->in_job() ? (string) Steps::state( $fresh )['run'] : '';

		return $this->r->get( 'uuid' ) . ':' . $name . ':' . (int) ( $fresh->get( $this->job_key( $name ) ) ?? 0 ) . ( '' !== $run ? ':' . $run : '' );
	}

	/**
	 * Resource meta key of a call's job number.
	 *
	 * @param string $name Call name.
	 */
	private function job_key( string $name ): string {
		return 'agent_job_' . substr( (string) preg_replace( '/[^a-z0-9_]/', '_', strtolower( $name ) ), 0, 60 );
	}

	/**
	 * Moves a call's job number on, so a later change of the same name is a new agent job.
	 *
	 * @param string $name Call name.
	 */
	private function bump_job( string $name ): void {
		$fresh = Resource::find( $this->r->id() ) ?? $this->r;

		$fresh->set( array( $this->job_key( $name ) => (int) ( $fresh->get( $this->job_key( $name ) ) ?? 0 ) + 1 ) );
	}

	/**
	 * Is this run of the job on this website's task (so a website that no longer has the pending mark is still the one being added)?
	 *
	 * @param string $slug Site.
	 */
	private function in_task( string $slug ): bool {
		if ( ! $this->in_job() ) {
			return false;
		}

		return $slug === (string) Steps::state( $this->r )['slug'];
	}

	/**
	 * Is the account ready for a change? Connects to the node on the first call.
	 *
	 * @return true|\WP_Error
	 */
	private function ready() {
		$record = Records::for_resource( $this->r->id() );

		if ( 'ready' !== $this->r->get( 'state' ) || 'ready' !== $this->r->get( 'desired' ) || ! in_array( 'ready', (array) $this->r->get( 'steps' ), true ) || ! $record ) {
			return $this->error( 'not_ready', __( 'Your hosting is not ready for changes.', 'beltoft-services' ) );
		}

		if ( null === $this->client ) {
			$err = $this->connect();

			if ( null !== $err ) {
				return $this->error( 'failed', __( 'Your hosting cannot be changed right now.', 'beltoft-services' ) );
			}
		}

		return true;
	}

	/**
	 * A job on one existing site.
	 *
	 * @param string                    $slug Site.
	 * @param string                    $op   Operation.
	 * @param string                    $name Job name.
	 * @param array<string, mixed>|null $out  What to return, or null for the agent's result.
	 * @param bool                      $wp   True when only a website with WordPress can do it.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function site_job( string $slug, string $op, string $name, ?array $out, bool $wp = false ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		if ( $wp && null !== $this->needs_wordpress( $site ) ) {
			return $this->needs_wordpress( $site );
		}

		$record = (array) Records::for_resource( $this->r->id() );

		if ( null !== $out ) {
			$this->defer_with( $out );
		}

		$done = $this->call( 'site_' . $name . '_' . $slug, $op, array( 'vmid' => (int) $record['vmid'], 'slug' => $slug ) );

		return is_wp_error( $done ) ? $done : ( null === $out ? $done : $out );
	}

	/**
	 * The edge map of the account, sent again.
	 *
	 * @return true|\WP_Error
	 */
	private function edge() {
		// The signature of what is sent, kept with the change: a replay of the job reads the first run's answer, so what is recorded is
		// what was sent then, not what the database says now (a name activated meanwhile is still to be sent).
		$sig  = $this->sent_signature( 'sig_edge', $this->edge_signature() );
		$done = $this->call( 'edge_update', 'edge.apply', array( 'account' => Config::account( $this->r->id() ), 'routes' => EdgeMap::routes( $this->r->id(), false ) ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		// What was sent last: the domain sweep compares it with the routes now (SiteActions::names_stale()).
		( Resource::find( $this->r->id() ) ?? $this->r )->set( array( 'edge_sig' => $sig ) );

		return true;
	}

	/**
	 * The signature of what a call sends, fixed by the first run of a job: later runs of the same change get the same one back.
	 *
	 * @param string $key Memo key of the signature.
	 * @param string $now The signature of the current state.
	 */
	private function sent_signature( string $key, string $now ): string {
		if ( ! $this->in_job() ) {
			return $now;
		}

		$kept = Steps::memo( $this->r, $key );

		if ( null !== $kept && ! empty( $kept['result']['sig'] ) ) {
			return (string) $kept['result']['sig'];
		}

		Steps::remember( $this->r, $key, true, array( 'sig' => $now ) );

		return $now;
	}

	/**
	 * A signature of the routes the edge should have now.
	 */
	private function edge_signature(): string {
		return md5( (string) wp_json_encode( EdgeMap::routes( $this->r->id(), false ) ) );
	}

	/**
	 * The refusal of what only a website with WordPress can do (the sign-in link, the cache, WordPress's address, a staging copy).
	 *
	 * @param array<string, mixed>|null $site Site row.
	 * @return \WP_Error|null Null when the website has WordPress (or is not found: the caller says so).
	 */
	private function needs_wordpress( ?array $site ): ?\WP_Error {
		return null !== $site && ! Sites::has_wordpress( $site ) ? $this->error( 'no_wordpress', __( 'This website has no WordPress.', 'beltoft-services' ) ) : null;
	}

	/**
	 * A customer error.
	 *
	 * @param string $code    Short code.
	 * @param string $message Words for the customer.
	 */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	/**
	 * Customer words for a plain refusal of SiteDns (the reasons are already in plain English; they are kept short).
	 *
	 * @param string $reason Reason.
	 */
	private function customer_text( string $reason ): string {
		return mb_substr( sanitize_text_field( $reason ), 0, 200 );
	}

	/**
	 * The ceilings of the plan, for the forms.
	 *
	 * @return array<string, mixed>
	 */
	public function limits(): array {
		$sites = Sites::for_resource( $this->r->id() );
		$used  = 0;

		foreach ( $sites as $s ) {
			$used += (int) $s['workers'];
		}

		return array(
			'sites'         => (int) $this->plan['sites'],
			'sites_used'    => count( $sites ),
			'php_workers'   => (int) $this->plan['php_workers'],
			'workers_used'  => $used,
			'php_memory_mb' => (int) $this->plan['php_memory_mb'],
			'upload_mb'     => (int) $this->plan['upload_mb'],
			'php_versions'  => Config::php_versions(),
			'staging'       => (string) $this->plan['staging'],
			'staging_used'  => count( \BeltoftServices\Hosting\SiteStaging::copies( $this->r->id() ) ),
		);
	}

	/**
	 * Workers given to the sites now.
	 */
	private function workers_used(): int {
		$n = 0;

		foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
			$n += (int) $s['workers'];
		}

		return $n;
	}

	/**
	 * Takes workers from the sites that have the most (down to 2 each) until the deficit is covered.
	 *
	 * @param int    $deficit Workers over the ceiling.
	 * @param string $except  The new site, which is left alone.
	 * @param int    $vmid    Container id.
	 * @return true|\WP_Error
	 */
	private function make_room( int $deficit, string $except, int $vmid ) {
		while ( $deficit > 0 ) {
			$donor = null;

			foreach ( Sites::for_resource( $this->r->id() ) as $s ) {
				if ( $s['slug'] !== $except && $s['workers'] > 2 && ( null === $donor || $s['workers'] > $donor['workers'] ) ) {
					$donor = $s;
				}
			}

			if ( null === $donor ) {
				return true; // Every site is at its minimum: the ceiling is a maximum, the minimum of 2 wins.
			}

			$new  = max( 2, $donor['workers'] - $deficit );
			$done = $this->call( 'site_workers_' . $donor['slug'], 'site.workers', array( 'vmid' => $vmid, 'slug' => $donor['slug'], 'workers' => $new ) );

			if ( is_wp_error( $done ) ) {
				return $done;
			}

			Sites::update( $this->r->id(), $donor['slug'], array( 'workers' => $new ) );
			$deficit -= $donor['workers'] - $new;
		}

		return true;
	}
}
