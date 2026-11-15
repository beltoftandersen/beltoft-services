<?php
/**
 * The steps of a hosting change that runs in the background: what lets it resume.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\SiteWordPress;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A change of a hosting account (a website added or removed, a PHP version, an address, a password) is a customer action of
 * the shared controller: the route checks the input and does what is local, then Progress::mark() and Jobs::queue_act() queue
 * it, Jobs::act() runs HostingDriver::act() on the runners under the resource lock (poll budget, retries, crash handling), and
 * the outcome is the shared last_result. One change at a time is Progress::pending(). A second change is refused with "Wait
 * until ... is done".
 *
 * What is hosting's own is the memory that lets a change of several agent calls go on where it stopped without starting an
 * agent job twice: resource meta `hosting_steps` = action, slug, args (the job's arguments, public ones: the page says where a
 * move goes), step and step_label (what the page says), memo (call name => ok, result and, for a failed call, what the agent
 * said), fail (the failure the clean-up of a new website works from) and secret (encrypted, the SFTP password only, never in the
 * arguments of the job). It is replaced when a change is queued and removed when the change ends.
 *
 * Since 0.31.0 the staging copy, the move of WordPress to another address, "Back up now" and the restores are changes of this
 * kind too (their own loops, retry counters and outcome fields are gone). Their feature state stays with the feature: the
 * copy's address, time and undo window (SiteStaging), WordPress's address and the way back (SiteWordPress), the restore round
 * and safety point (HostingRestore) and the backup round (HostingBackups).
 */
final class Steps {

	/** Resource meta of the steps of the change in progress. */
	public const META = 'hosting_steps';

	/** Seconds the last failure is shown on the page. */
	public const LAST_SHOWN = 3600;

	/**
	 * Hooks what hosting adds to the shared controller: which actions may be repeated after a lost job, the clean-up of a
	 * website that was not finished, and the words of the status bar.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_action_resumable', array( __CLASS__, 'resumable' ), 10, 3 );
		add_action( 'beltoft_services_action_failed', array( __CLASS__, 'failed' ), 10, 4 );
		add_action( 'beltoft_services_action_done', array( __CLASS__, 'done' ), 10, 3 );
		add_filter( 'beltoft_services_progress_text', array( __CLASS__, 'words' ), 10, 3 );
	}

	/**
	 * Every hosting change keeps its steps, so a repeat after a lost job starts nothing twice.
	 *
	 * @param mixed  $resumable Default.
	 * @param string $driver    Driver id.
	 * @param string $action    Action name.
	 */
	public static function resumable( $resumable, $driver, $action ) {
		return HostingDriver::ID === $driver && in_array( (string) $action, self::actions(), true ) ? true : $resumable;
	}

	/**
	 * A change ended without finishing (it failed for good, its job was lost twice, nothing worked on it, or it ran out of
	 * retries): each feature puts right what the change left half done.
	 *
	 * - A website that was being added: its clean-up is queued as a change of its own, through the same path.
	 * - A staging copy that was being made: its address goes and the website is as it was; one that was being deleted is a copy again.
	 * - A restore or a backup that was under way: its round ends, so the account is not left half way (an account restore that had
	 *   stopped the container starts it again).
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments, when known (empty for a lapsed one: the steps hold the website then).
	 * @param string               $why    failed, stopped or lapsed.
	 */
	public static function failed( $r, $action, $args, $why = 'failed' ): void {
		if ( ! $r instanceof Resource || HostingDriver::ID !== $r->get( 'driver' ) ) {
			return;
		}

		$slug = (string) ( ( (array) $args )[0] ?? self::state( $r )['slug'] ?? '' );

		switch ( (string) $action ) {
			case 'add_site':
				$site = '' === $slug ? null : Sites::find( $r->id(), $slug );

				if ( null !== $site && ! empty( $site['flags']['pending'] ) ) {
					self::start( $r, 'abandon_site', array( $slug ) );
				}

				break;

			case 'staging_create':
				SiteStaging::abandon_create( $r, $slug );
				break;

			case 'staging_delete':
				SiteStaging::resume_after_failed_delete( $r, $slug );
				break;

			case 'restore_backup':
			case 'restore_undo':
				( new HostingRestore( $r ) )->give_up( 'The restore job ended without finishing (' . (string) $why . ')' );
				break;

			case 'backup_now':
				( new HostingBackups( $r ) )->give_up( 'The backup job ended without finishing (' . (string) $why . ')' );
				break;
		}

		// What the change left is gone with it: its steps are not read again.
		if ( in_array( (string) $why, array( 'stopped', 'lapsed' ), true ) && self::state( $r )['action'] === (string) $action ) {
			( Resource::find( $r->id() ) ?? $r )->set( array( self::META => null ) );
		}
	}

	/**
	 * A change ended well. A change of the addresses may call for WordPress to move to the website's own domain, and a restore
	 * puts older files back, so the stored country blocks go to the node again: each is the follow-up change of the account,
	 * queued through the same path now that the marker is gone.
	 *
	 * @param Resource             $r      Resource.
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Arguments.
	 */
	public static function done( $r, $action, $args ): void {
		if ( ! $r instanceof Resource || HostingDriver::ID !== $r->get( 'driver' ) ) {
			return;
		}

		$args = (array) $args;

		if ( 'names' === $action ) {
			if ( isset( $args[0] ) && '' !== (string) $args[0] ) {
				SiteWordPress::consider( $r, (string) $args[0], true );
			}

			SiteWordPress::consider_all( $r );

			return;
		}

		if ( in_array( $action, array( 'restore_backup', 'restore_undo' ), true ) ) {
			$kind = (string) ( $args[0] ?? '' );
			$slug = 'restore_backup' === $action && 'files' === $kind ? (string) ( $args[2] ?? '' ) : '';

			if ( 'restore_undo' === $action || in_array( $kind, array( 'account', 'files' ), true ) ) {
				self::start_follow( $r, 'resend_country', array( $slug ) );
			}
		}
	}

	/**
	 * The status bar says which step the change is at.
	 *
	 * @param mixed    $words Title and text.
	 * @param Resource $r     Resource.
	 * @param string   $kind  Kind of work.
	 */
	public static function words( $words, $r, $kind ) {
		$view = $r instanceof Resource ? self::view( $r ) : null;

		return null === $view || ! is_array( $words ) ? $words : array_merge( $words, array( 'title' => $view['label'], 'text' => $view['step_label'] ) );
	}

	/**
	 * What each change of the facade does: the action and the label the customer reads while it runs. The labels read after
	 * "Wait until ... is done" in lower case, so they are phrases, not titles.
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
			'abandon_site'      => array( 'abandon_site', __( 'Cleaning up a website that was not finished', 'beltoft-services' ) ),
			'apply_names'       => array( 'names', __( 'Updating the addresses', 'beltoft-services' ) ),
			'drop_domain'       => array( 'remove_domain', __( 'Removing an address', 'beltoft-services' ) ),
			'wordpress_address' => array( 'wordpress_address', __( 'Moving WordPress to another address', 'beltoft-services' ) ),
			'create_staging'    => array( 'staging_create', __( 'Making a staging copy', 'beltoft-services' ) ),
			'refresh_staging'   => array( 'staging_refresh', __( 'Refreshing the staging copy', 'beltoft-services' ) ),
			'push_staging'      => array( 'staging_push', __( 'Pushing the staging copy to the live website', 'beltoft-services' ) ),
			'undo_staging'      => array( 'staging_undo', __( 'Undoing the last push', 'beltoft-services' ) ),
			'delete_staging'    => array( 'staging_delete', __( 'Deleting the staging copy', 'beltoft-services' ) ),
			'backup_now'        => array( 'backup_now', __( 'Backing up', 'beltoft-services' ) ),
			'restore'           => array( 'restore_backup', __( 'Restoring from a backup', 'beltoft-services' ) ),
			'undo'              => array( 'restore_undo', __( 'Undoing the restore', 'beltoft-services' ) ),
			'resend_country'    => array( 'resend_country', __( 'Sending the country block to the server again', 'beltoft-services' ) ),
		);
	}

	/**
	 * The actions the driver runs as jobs (internal: the hosting routes queue them).
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return array_values( array_unique( array_column( self::table(), 0 ) ) );
	}

	/**
	 * The label of an action, for a result that names it.
	 *
	 * @param string $action Action name.
	 */
	public static function label_of( string $action ): string {
		$labels = array( 'names' => __( 'Updating the addresses', 'beltoft-services' ) );

		foreach ( self::table() as $row ) {
			$labels += array( $row[0] => $row[1] );
		}

		return $labels[ $action ] ?? '';
	}

	/**
	 * The method of the facade that runs an action.
	 *
	 * @param string $action Action name.
	 */
	public static function method_of( string $action ): string {
		$named = array(
			'names'        => 'apply_names',
			'abandon_site' => 'abandon_new_site',
		);

		if ( isset( $named[ $action ] ) ) {
			return $named[ $action ];
		}

		foreach ( self::table() as $method => $row ) {
			if ( $row[0] === $action ) {
				return $method;
			}
		}

		return $action;
	}

	/**
	 * Facade methods whose first argument is the website they change.
	 *
	 * @param string $method Facade method.
	 */
	private static function about_site( string $method ): bool {
		return in_array( $method, array( 'add_site', 'remove_site', 'set_php', 'php_rollback', 'set_workers', 'purge_cache', 'set_country_block', 'abandon_site', 'wordpress_address', 'create_staging', 'refresh_staging', 'push_staging', 'undo_staging', 'delete_staging', 'resend_country' ), true );
	}

	/**
	 * Queues a change through the shared controller: the Progress marker (one change at a time, the status bar), the steps, the
	 * job. The caller holds the account's site lock and has checked that no change is in progress.
	 *
	 * @param Resource          $r      Hosting resource.
	 * @param string            $method Facade method the customer asked for.
	 * @param array<int, mixed> $args   Its arguments (the job's arguments: never a secret, see $secret).
	 * @param string|null       $secret A secret first argument (the SFTP password), kept encrypted until the change ends.
	 * @return array<string, mixed>|\WP_Error The task as the page shows it, or the refusal.
	 */
	public static function start( Resource $r, string $method, array $args, ?string $secret = null ) {
		$row = self::table()[ $method ] ?? null;

		if ( null === $row ) {
			return new \WP_Error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) );
		}

		if ( null !== $secret ) {
			$args[0] = ''; // The secret is kept encrypted, never in the arguments.
		}

		$state = array(
			'action'     => $row[0],
			'slug'       => self::about_site( $method ) ? (string) ( $args[0] ?? '' ) : '',
			'args'       => array_values( $args ),
			'secret'     => null === $secret ? '' : Crypto::encrypt( $secret ),
			'step'       => '',
			'step_label' => '',
			'memo'       => array(),
			'fail'       => null,
			'run'        => strtolower( wp_generate_password( 8, false ) ),
		);

		// The one entry point: the busy check, then the step memory as the preparation, the marker and the job.
		$started = Jobs::start_action(
			$r,
			$row[0],
			$row[1],
			array_values( $args ),
			static fn() => ( Resource::find( $r->id() ) ?? $r )->set( array( self::META => $state ) ) ? true : new \WP_Error( 'failed', __( 'That cannot be done right now.', 'beltoft-services' ) )
		);

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return array(
			'action'     => $row[0],
			'label'      => $row[1],
			'slug'       => $state['slug'],
			'step_label' => $row[1],
			'started'    => time(),
		);
	}

	/**
	 * Queues a change the system itself wants (a follow-up of another change, the domain sweep) through the same path: the account's
	 * site lock is taken without waiting, an account that is not ready is left alone, and a change that is already under way
	 * refuses it (the caller looks again later: the sweep derives the need from the stored state, nothing is kept here).
	 *
	 * @param Resource          $r      Hosting resource.
	 * @param string            $method Facade method of the change.
	 * @param array<int, mixed> $args   Its arguments.
	 * @return bool Whether the change was queued.
	 */
	public static function start_follow( Resource $r, string $method, array $args ): bool {
		$fresh = Resource::find( $r->id() ) ?? $r;

		if ( 'ready' !== $fresh->get( 'state' ) || 'ready' !== $fresh->get( 'desired' ) ) {
			return false;
		}

		list( $taken, $started ) = Locks::with( Locks::name( 'hosting-site', $r->id() ), 0, static fn() => self::start( $fresh, $method, $args ) );

		return $taken && ! is_wp_error( $started );
	}

	// What the page shows.

	/**
	 * The change in progress as the page shows it (from the Progress marker and the step state), or null.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array{action: string, label: string, slug: string, args: array<int, mixed>, step_label: string, started: int}|null
	 */
	public static function view( Resource $r ): ?array {
		$fresh  = Resource::find( $r->id() ) ?? $r;
		$marker = Progress::pending( $fresh );

		if ( null === $marker || ! in_array( $marker['action'], self::actions(), true ) ) {
			return null;
		}

		$state = self::state( $fresh );
		$mine  = $state['action'] === $marker['action'];

		return array(
			'action'     => $marker['action'],
			'label'      => '' !== $marker['label'] ? $marker['label'] : self::label_of( $marker['action'] ),
			'slug'       => $mine ? (string) $state['slug'] : '',
			'args'       => $mine ? (array) $state['args'] : array(),
			'step_label' => $mine && '' !== (string) $state['step_label'] ? (string) $state['step_label'] : ( '' !== $marker['label'] ? $marker['label'] : self::label_of( $marker['action'] ) ),
			'started'    => $marker['at'],
		);
	}

	/**
	 * The last change that did not finish, from the shared last_result: what the page tells the customer.
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>|null label, text, at.
	 */
	public static function last( Resource $r ): ?array {
		$last = ( Resource::find( $r->id() ) ?? $r )->get( Jobs::RESULT );

		// A follow-up the customer did not ask for (the country block sent again after a restore) is staff's concern: they were told.
		if ( ! is_array( $last ) || ! in_array( (string) ( $last['action'] ?? '' ), self::actions(), true ) || 'resend_country' === (string) $last['action'] || (int) ( $last['at'] ?? 0 ) < time() - self::LAST_SHOWN ) {
			return null;
		}

		// The clean-up after a new website that could not be made ends well, but what the customer must read is that the
		// website was not made; its own result replaces the failure of the add.
		if ( 'abandon_site' === (string) $last['action'] && ! empty( $last['ok'] ) ) {
			return array(
				'action' => 'add_site',
				'label'  => self::label_of( 'add_site' ),
				'text'   => __( 'The new website could not be made. What was made of it has been removed, and its name is free again. We have been told.', 'beltoft-services' ),
				'at'     => (int) $last['at'],
			);
		}

		if ( ! empty( $last['ok'] ) ) {
			return null;
		}

		return array(
			'action' => (string) $last['action'],
			'label'  => self::label_of( (string) $last['action'] ),
			'text'   => (string) ( $last['text'] ?? '' ),
			'at'     => (int) $last['at'],
		);
	}

	// The memory.

	/**
	 * The steps as they are stored (an empty state when none).
	 *
	 * @param Resource $r Hosting resource.
	 * @return array<string, mixed>
	 */
	public static function state( Resource $r ): array {
		$state = ( Resource::find( $r->id() ) ?? $r )->get( self::META );

		return array_merge( array( 'action' => '', 'slug' => '', 'args' => array(), 'secret' => '', 'step' => '', 'step_label' => '', 'memo' => array(), 'fail' => null, 'run' => '' ), is_array( $state ) ? $state : array() );
	}

	/**
	 * Is the call of this name done in this run of the change, and what did it end with?
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $call Call name.
	 * @return array{ok: bool, result?: array<string, mixed>}|null
	 */
	public static function memo( Resource $r, string $call ): ?array {
		$one = self::state( $r )['memo'][ $call ] ?? null;

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
	 * @param string                    $why    What the agent said when the job failed (scrubbed, short).
	 * @return bool Whether it was stored.
	 */
	public static function remember( Resource $r, string $call, bool $ok, ?array $result = null, string $why = '' ): bool {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$state = self::state( $fresh );

		$state['memo'][ $call ] = $ok ? array( 'ok' => true, 'result' => (array) $result ) : array( 'ok' => false, 'why' => mb_substr( $why, 0, 200 ) );

		return $fresh->set( array( self::META => $state ) );
	}

	/**
	 * Notes the step the change is at, in the customer's words.
	 *
	 * @param Resource $r    Hosting resource.
	 * @param string   $call Call name.
	 */
	public static function step( Resource $r, string $call ): void {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$state = self::state( $fresh );

		if ( $state['step'] === $call ) {
			return;
		}

		$state['step']       = $call;
		$state['step_label'] = self::step_label( (string) $state['action'], $call );
		$fresh->set( array( self::META => $state ) );
	}

	/**
	 * The words for a step. The agent calls are named after what they do; only the websites' own steps have words of their
	 * own, every other change says its label.
	 *
	 * @param string $action Action name.
	 * @param string $call   Call name.
	 */
	public static function step_label( string $action, string $call ): string {
		if ( 'add_site' === $action || 'abandon_site' === $action ) {
			$steps = array(
				'db_add_'       => __( 'Making the database', 'beltoft-services' ),
				'site_add_'     => __( 'Making the website', 'beltoft-services' ),
				'site_install_' => __( 'Installing WordPress', 'beltoft-services' ),
				'edge_update'   => __( 'Setting up the address', 'beltoft-services' ),
				'site_workers_' => __( 'Making room for the new website', 'beltoft-services' ),
				'db_drop_new_'  => __( 'Cleaning up', 'beltoft-services' ),
				'site_del_new_' => __( 'Cleaning up', 'beltoft-services' ),
			);
		} elseif ( 0 === strpos( $action, 'staging_' ) ) {
			$steps = array(
				'staging_dns'  => __( 'Making the address of the copy', 'beltoft-services' ),
				'staging_'     => __( 'Copying the website', 'beltoft-services' ),
				'edge_update'  => __( 'Setting up the address', 'beltoft-services' ),
			);
		} elseif ( 'wordpress_address' === $action ) {
			$steps = array(
				'wp_address_' => __( 'Changing the address in WordPress', 'beltoft-services' ),
				'site_names_' => __( 'Updating the addresses', 'beltoft-services' ),
				'edge_update' => __( 'Updating the addresses', 'beltoft-services' ),
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
	 * Keeps the failure the clean-up of a new website works from, so a run after a crash cleans up the same way.
	 *
	 * @param Resource  $r  Hosting resource.
	 * @param \WP_Error $e  The failure.
	 * @param bool      $db Whether a database may have been made.
	 */
	public static function remember_failure( Resource $r, \WP_Error $e, bool $db ): void {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$state = self::state( $fresh );

		$state['fail'] = array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message(), 'db' => $db );
		$fresh->set( array( self::META => $state ) );
	}

	// The job.

	/**
	 * One run of the change's job: the same action code, one agent step further. Called by HostingDriver::act().
	 *
	 * @param Resource          $r      Hosting resource.
	 * @param string            $action Action name.
	 * @param array<int, mixed> $args   The job's arguments.
	 */
	public static function run( Resource $r, string $action, array $args ): StepResult {
		$fresh = Resource::find( $r->id() ) ?? $r;
		$state = self::state( $fresh );

		// A job whose steps are not the steps of this action (they were lost, or are an older change's) starts clean.
		if ( $state['action'] !== $action ) {
			$state = array_merge( $state, array( 'action' => $action, 'slug' => '', 'args' => array(), 'secret' => '', 'step' => '', 'step_label' => '', 'memo' => array(), 'fail' => null, 'run' => '' ) );
			$fresh->set( array( self::META => $state ) );
		}

		if ( '' !== (string) $state['secret'] ) {
			$args[0] = (string) Crypto::decrypt( (string) $state['secret'] );
		}

		try {
			$out = ( new HostingActions( $fresh, 'job' ) )->job( self::method_of( $action ), array_values( $args ) );
		} catch ( TaskPending $pending ) {
			$result = $pending->result;

			// A poll or a retry ends this run only: the change goes on. A done or failed result is the end of the change.
			if ( $result->is_retry() ) {
				return $result;
			}

			( Resource::find( $r->id() ) ?? $r )->set( array( self::META => null ) );

			return $result;
		}

		// The change is over: its steps are gone, and the outcome is the shared last_result.
		( Resource::find( $r->id() ) ?? $r )->set( array( self::META => null ) );

		if ( is_wp_error( $out ) ) {
			$data = $out->get_error_data();
			$meta = array();

			// A refusal that the customer reads in its own words, and one that is expected (no mail to staff): see Jobs::TEXT.
			if ( is_array( $data ) && '' !== (string) ( $data['text'] ?? '' ) ) {
				$meta[ Jobs::TEXT ] = (string) $data['text'];

				if ( ! empty( $data['expected'] ) ) {
					$meta[ Jobs::EXPECTED ] = true;
				}
			}

			// What the agent said (scrubbed, short) goes to staff with the failure; the customer reads only the text above.
			$why = is_array( $data ) && '' !== (string) ( $data['why'] ?? '' ) ? ' [' . (string) $data['why'] . ']' : '';

			return StepResult::fail( sprintf( '%s (%s)%s', $out->get_error_message(), $out->get_error_code(), $why ), $meta );
		}

		return StepResult::done( array(), sprintf( 'Hosting change "%s" done', $action ) );
	}
}
