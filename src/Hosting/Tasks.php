<?php
/**
 * The background task of a hosting account: one change at a time, kept step by step.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A change of a hosting account (a website added or removed, a PHP version, an address, a password) is never done while a
 * page waits. The route checks the input and does what is local, claims the account's one task here (a second change is
 * refused with "Wait until X is done"), and queues a job (Jobs::queue_act, run by HostingDriver::act on the runners). The job
 * runs the same action code again and again, one agent step per run: every agent call that has finished is kept in the
 * task (the memo), so a run that follows a poll, a crash or a deploy goes on at the step where the last one stopped.
 *
 * The task is resource meta `hosting_task`: action, label, slug, args (never a secret), secret (encrypted, for the SFTP
 * password only), step and step_label (what the page says), started, memo (call name => ok and result, or failure) and
 * fail (the failure the cleanup of a new website works from). It holds action and step names and plain values, never class
 * names. The last outcome is `hosting_last`, for the page.
 */
final class Tasks {

	/** Resource meta of the task in progress. */
	public const META = 'hosting_task';

	/** Resource meta of the last outcome (what the customer is told). */
	public const LAST = 'hosting_last';

	/** Seconds after which a task that no job waits for is given up. */
	public const STALE = 1800;

	/** Seconds the last outcome is shown on the page. */
	public const LAST_SHOWN = 3600;

	/**
	 * What each change of the facade does as a task: the job's action and the label the customer reads while it runs.
	 * The labels read after "Wait until ... is done" in lower case, so they are phrases, not titles.
	 *
	 * @return array<string, array{0: string, 1: string}> Facade method => action, label.
	 */
	public static function table(): array {
		return array(
			'add_site'          => array( 'add_site', __( 'Adding a website', 'beltoft-services' ) ),
			'remove_site'       => array( 'remove_site', __( 'Removing a website', 'beltoft-services' ) ),
			'set_php'           => array( 'set_php', __( 'Changing the PHP version', 'beltoft-services' ) ),
			'php_rollback'      => array( 'php_rollback', __( 'Going back to the earlier PHP version', 'beltoft-services' ) ),
			'set_workers'       => array( 'set_workers', __( 'Changing the PHP workers', 'beltoft-services' ) ),
			'purge_cache'       => array( 'purge_cache', __( 'Clearing the cache', 'beltoft-services' ) ),
			'set_country_block' => array( 'set_country_block', __( 'Changing the country block', 'beltoft-services' ) ),
			'add_domain'        => array( 'names', __( 'Adding an address', 'beltoft-services' ) ),
			'remove_domain'     => array( 'names', __( 'Removing an address', 'beltoft-services' ) ),
			'set_main_address'  => array( 'names', __( 'Changing the main address', 'beltoft-services' ) ),
			'set_sftp_password' => array( 'set_sftp_password', __( 'Setting the SFTP password', 'beltoft-services' ) ),
			'add_ssh_key'       => array( 'add_ssh_key', __( 'Adding an SSH key', 'beltoft-services' ) ),
			'remove_ssh_key'    => array( 'remove_ssh_key', __( 'Removing an SSH key', 'beltoft-services' ) ),
		);
	}

	/**
	 * The actions the driver runs as jobs (never offered to a customer: the hosting routes queue them).
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		$names = array( 'abandon_site' );

		foreach ( self::table() as $row ) {
			$names[] = $row[0];
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * The method of the facade that runs a job action.
	 *
	 * @param string $action Job action.
	 */
	public static function method_of( string $action ): string {
		return array(
			'names'        => 'apply_names',
			'abandon_site' => 'abandon_new_site',
		)[ $action ] ?? $action;
	}

	/**
	 * Facade methods whose first argument is the website they change.
	 *
	 * @param string $method Facade method.
	 */
	private static function about_site( string $method ): bool {
		return in_array( $method, array( 'add_site', 'remove_site', 'set_php', 'php_rollback', 'set_workers', 'purge_cache', 'set_country_block', 'abandon_site' ), true );
	}

	/**
	 * The task as it is stored now.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>|null
	 */
	public static function get( Resource $r ): ?array {
		$task = ( Resource::find( $r->id() ) ?? $r )->get( self::META );

		return is_array( $task ) && isset( $task['action'], $task['started'] ) ? $task : null;
	}

	/**
	 * Is the task alive: a job waits or runs for the account, or it began lately.
	 *
	 * @param Resource             $r    Hosting resource.
	 * @param array<string, mixed> $task The task.
	 */
	private static function alive( Resource $r, array $task ): bool {
		return Jobs::acting( $r->id() ) || Jobs::now() - (int) $task['started'] <= self::STALE;
	}

	/**
	 * Is a change of the account in progress? Then the customer waits until it is done.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>|null The task.
	 */
	public static function busy( Resource $r ): ?array {
		$task = self::get( $r );

		return null !== $task && self::alive( $r, $task ) ? $task : null;
	}

	/**
	 * The refusal of a second change.
	 *
	 * @param array<string, mixed> $task The task in progress.
	 */
	public static function refusal( array $task ): \WP_Error {
		$label = (string) $task['label'];
		$first = mb_strtolower( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );

		/* translators: %s: what the account is doing now, for example "adding a website". */
		return new \WP_Error( 'busy', sprintf( __( 'Wait until %s is done.', 'beltoft-services' ), $first ) );
	}

	/**
	 * Takes the account's one task. Called with the account's site lock held, so two requests cannot both take it.
	 *
	 * @param Resource             $r      Hosting resource.
	 * @param string               $method Facade method the customer asked for.
	 * @param array<int, mixed>    $args   Its arguments (never a secret: see $secret).
	 * @param string|null          $secret A secret argument (the SFTP password), kept encrypted until the job ends.
	 * @param string               $label  Label when it is not the table's (an internal task).
	 * @return array<string, mixed>|\WP_Error The task, or the refusal.
	 */
	public static function claim( Resource $r, string $method, array $args, ?string $secret = null, string $label = '' ) {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$busy  = self::busy( $fresh );

		if ( null !== $busy ) {
			return self::refusal( $busy );
		}

		$row = self::table()[ $method ] ?? array( $method, $label );

		if ( '' === $row[1] ) {
			return new \WP_Error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) );
		}

		if ( null !== $secret ) {
			$args[0] = ''; // The secret is kept encrypted, never in the arguments.
		}

		$task = array(
			'action'     => $row[0],
			'label'      => $row[1],
			'slug'       => self::about_site( $method ) ? (string) ( $args[0] ?? '' ) : '',
			'args'       => $args,
			'secret'     => null === $secret ? '' : (string) Crypto::encrypt( $secret ),
			'step'       => '',
			'step_label' => '',
			'started'    => Jobs::now(),
			'memo'       => array(),
			'fail'       => null,
		);

		if ( ! $fresh->set( array( self::META => $task, self::LAST => null ) ) ) {
			return new \WP_Error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) );
		}

		return $task;
	}

	/**
	 * Queues the job of a claimed task. The job's arguments are only the resource and the action: the task holds the rest.
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $action Job action.
	 */
	public static function queue( Resource $r, string $action ): void {
		Jobs::queue_act( $r->id(), $action, array() );
	}

	/**
	 * What the page and the routes show of a task.
	 *
	 * @param array<string, mixed> $task The task.
	 * @return array<string, mixed>
	 */
	public static function view( array $task ): array {
		return array(
			'action'     => (string) $task['action'],
			'label'      => (string) $task['label'],
			'slug'       => (string) $task['slug'],
			'step_label' => '' !== (string) $task['step_label'] ? (string) $task['step_label'] : (string) $task['label'],
			'started'    => (int) $task['started'],
		);
	}

	/**
	 * The task and the last outcome for the account's overview: a task nobody works on any more is given up first.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array{task: array<string, mixed>|null, last: array<string, mixed>|null}
	 */
	public static function overview( Resource $r ): array {
		self::watchdog( $r );

		$task  = self::busy( $r );
		$fresh = Resource::find( $r->id() ) ?? $r;
		$last  = $fresh->get( self::LAST );

		if ( ! is_array( $last ) || (int) ( $last['at'] ?? 0 ) < Jobs::now() - self::LAST_SHOWN ) {
			$last = null;
		}

		return array( 'task' => null === $task ? null : self::view( $task ), 'last' => $last );
	}

	// Steps.

	/**
	 * What a finished agent call of this task was, when the task has made it.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $call Call name.
	 * @return array{ok: bool, result?: array<string, mixed>}|null
	 */
	public static function memo( Resource $r, string $call ): ?array {
		$task = self::get( $r );
		$one  = $task['memo'][ $call ] ?? null;

		return is_array( $one ) ? $one : null;
	}

	/**
	 * Keeps what an agent call ended with. Done before the call's job number moves on, so a crash between the two reads the
	 * job again (the agent answers the same finished job) and never starts it a second time.
	 *
	 * @param Resource                  $r      Hosting resource.
	 * @param string                    $call   Call name.
	 * @param bool                      $ok     Whether the job was done.
	 * @param array<string, mixed>|null $result The result of a job that was done.
	 * @return bool Whether it was stored.
	 */
	public static function remember( Resource $r, string $call, bool $ok, ?array $result = null ): bool {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$task  = $fresh->get( self::META );

		if ( ! is_array( $task ) ) {
			return false;
		}

		$task['memo'][ $call ] = $ok ? array( 'ok' => true, 'result' => (array) $result ) : array( 'ok' => false );

		return $fresh->set( array( self::META => $task ) );
	}

	/**
	 * Notes the step the task is at, in the customer's words.
	 *
	 * @param Resource $r     Hosting resource.
	 * @param string   $call  Call name.
	 */
	public static function step( Resource $r, string $call ): void {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$task  = $fresh->get( self::META );

		if ( ! is_array( $task ) || $task['step'] === $call ) {
			return;
		}

		$task['step']       = $call;
		$task['step_label'] = self::step_label( (string) $task['action'], $call );
		$fresh->set( array( self::META => $task ) );
	}

	/**
	 * The words for a step of the task. The agent calls are named after what they do; only the websites' own steps have
	 * words of their own, every other change says its label.
	 *
	 * @param string $action Job action.
	 * @param string $call   Call name.
	 */
	public static function step_label( string $action, string $call ): string {
		if ( 'add_site' === $action || 'abandon_site' === $action ) {
			$steps = array(
				'db_add_'      => __( 'Making the database', 'beltoft-services' ),
				'site_add_'    => __( 'Making the website', 'beltoft-services' ),
				'site_install_' => __( 'Installing WordPress', 'beltoft-services' ),
				'edge_update'  => __( 'Setting up the address', 'beltoft-services' ),
				'site_workers_' => __( 'Making room for the new website', 'beltoft-services' ),
				'db_drop_new_' => __( 'Cleaning up', 'beltoft-services' ),
				'site_del_new_' => __( 'Cleaning up', 'beltoft-services' ),
			);
		} elseif ( 'remove_site' === $action ) {
			$steps = array(
				'site_del_'   => __( 'Removing the website', 'beltoft-services' ),
				'db_drop_'    => __( 'Removing the database', 'beltoft-services' ),
				'edge_update' => __( 'Updating the addresses', 'beltoft-services' ),
			);
		} else {
			return '';
		}

		foreach ( $steps as $prefix => $words ) {
			if ( 0 === strpos( $call, $prefix ) ) {
				return $words;
			}
		}

		return '';
	}

	/**
	 * Keeps the failure the cleanup of a new website works from, so a run after a crash cleans up the same way.
	 *
	 * @param Resource  $r  Hosting resource.
	 * @param \WP_Error $e  The failure.
	 * @param bool      $db Whether a database may have been made.
	 */
	public static function remember_failure( Resource $r, \WP_Error $e, bool $db ): void {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$task  = $fresh->get( self::META );

		if ( is_array( $task ) ) {
			$task['fail'] = array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'db' => $db );
			$fresh->set( array( self::META => $task ) );
		}
	}

	// The job.

	/**
	 * One run of the task's job: the same action code, one agent step further. Called by HostingDriver::act().
	 *
	 * @param Resource $r      Hosting resource.
	 * @param string   $action Job action.
	 */
	public static function run( Resource $r, string $action ): StepResult {
		$task = self::get( $r );

		if ( null === $task || $task['action'] !== $action ) {
			return StepResult::done( array(), 'Hosting task "' . $action . '" had nothing left to do' );
		}

		$args = (array) $task['args'];

		if ( '' !== (string) $task['secret'] ) {
			$args[ 0 ] = (string) Crypto::decrypt( (string) $task['secret'] );
		}

		try {
			$out = ( new HostingActions( Resource::find( $r->id() ) ?? $r, 'job' ) )->job( self::method_of( $action ), $args );
		} catch ( Actions\TaskPending $pending ) {
			return $pending->result;
		}

		return is_wp_error( $out ) ? self::end( $r, $task, $out ) : self::end( $r, $task, null );
	}

	/**
	 * The end of a task: it is cleared and the outcome is kept for the page.
	 *
	 * @param Resource             $r    Hosting resource.
	 * @param array<string, mixed> $task The task.
	 * @param \WP_Error|null       $e    The failure, or null when it was done.
	 */
	private static function end( Resource $r, array $task, ?\WP_Error $e ): StepResult {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$last  = array(
			'action' => (string) $task['action'],
			'label'  => (string) $task['label'],
			'slug'   => (string) $task['slug'],
			'ok'     => null === $e,
			'text'   => null === $e ? '' : $e->get_error_message(),
			'at'     => Jobs::now(),
		);

		$fresh->set( array( self::META => null, self::LAST => $last ) );

		return null === $e
			? StepResult::done( array(), sprintf( 'Hosting task "%s" done', $task['action'] ) )
			: StepResult::fail( sprintf( '%s (%s)', $e->get_error_message(), $e->get_error_code() ) );
	}

	// The watchdog.

	/**
	 * A task that has run for over half an hour and that no job waits for is given up: the customer sees why, staff are told.
	 * A website that was being added and never finished gets its cleanup as a task of its own.
	 *
	 * @param Resource $r Hosting resource.
	 * @return bool Whether a task was given up.
	 */
	public static function watchdog( Resource $r ): bool {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$task  = self::get( $fresh );

		if ( null === $task || self::alive( $fresh, $task ) ) {
			return false;
		}

		$last = array(
			'action' => (string) $task['action'],
			'label'  => (string) $task['label'],
			'slug'   => (string) $task['slug'],
			'ok'     => false,
			'text'   => __( 'This stopped before it was finished. We have been told and will look into it.', 'beltoft-services' ),
			'at'     => Jobs::now(),
		);

		$fresh->set( array( self::META => null, self::LAST => $last ) );
		Alerts::admin( $fresh, sprintf( 'Hosting task "%s" (%s) was given up after %d minutes with no job waiting for it', $task['action'], '' !== (string) $task['slug'] ? $task['slug'] : 'account', (int) round( ( Jobs::now() - (int) $task['started'] ) / 60 ) ) );

		if ( 'add_site' === $task['action'] && '' !== (string) $task['slug'] ) {
			$cleanup = self::claim( $fresh, 'abandon_site', array( (string) $task['slug'] ), null, __( 'Cleaning up a website that was not finished', 'beltoft-services' ) );

			if ( ! is_wp_error( $cleanup ) ) {
				$fresh->set( array( self::LAST => $last ) );
				self::queue( $fresh, 'abandon_site' );
			}
		}

		return true;
	}
}
