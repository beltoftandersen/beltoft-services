<?php
/**
 * The automatic reinstall of a dedicated server: rescue system, installimage, new access.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * What the sources say (plan 2026-11-18-dedicated-controls):
 *
 * - Robot webservice, "Boot configuration", POST /boot/{server-number}/rescue without `authorized_key`: the answer has the rescue system's
 *   root `password`. That one-time password is the only login of the job. It is kept encrypted (Crypto) in the step memory between the job's
 *   steps and wiped when the job ends; it is never in an error, a log, a mail or a REST answer.
 * - A reset boots the rescue system (the web service activates it for the next start).
 * - installimage (docs.hetzner.com installimage page and the sources it links): a file /autosetup is used without a menu; keys: the line
 *   SSHKEYS_URL names a file with public keys; with keys installimage disables the root password and password login, without keys it sets the
 *   root password to the rescue system's password and enables root login with it. So: a customer with a key gets the key installed, the
 *   rescue password is thrown away; a customer without one gets the rescue password as the new root password, shown behind the reveal.
 * - The disks are erased by the installer: the job is started only after the typed name and the emailed code (the route), and the chosen image
 *   is checked against the listing of the rescue system before anything is written.
 *
 * The steps are stored in the resource meta `ded_reinstall` (state, step, image, deadlines, the encrypted password), so a repeat of the job after
 * a crash continues where it was and never repeats a finished step. The work with the rescue system goes through RescueShell (ssh live, a fake elsewhere).
 */
final class DedicatedReinstall {

	public const META   = 'ded_reinstall';
	public const ACTION = 'dedicated_reinstall';

	/** Seconds to wait before looking for the rescue system after the reset, and how long to wait for it (a fake shell: no wait, see delay()). */
	private const BOOT_AFTER = 45;
	private const BOOT_WAIT  = 1200;
	/** The longest an installation may take. */
	private const INSTALL_WAIT = 2700;
	/** Seconds to wait after the restart into the new system before looking, and how long to wait for it. */
	private const REBOOT_AFTER = 90;
	private const REBOOT_WAIT  = 900;

	/**
	 * What the customer is asked to decide: the images offered, newest tile first (see ImageCatalog).
	 *
	 * @return string[]
	 */
	public static function images(): array {
		return ImageCatalog::names();
	}

	/**
	 * Refusal of a reinstall request, or null when it may start (checks that need no side effect).
	 *
	 * @param Resource $r     Resource.
	 * @param string   $image Image file name.
	 * @return \WP_Error|null
	 */
	public static function check( Resource $r, string $image ): ?\WP_Error {
		$number = ServerControls::guard( $r, true );

		if ( is_wp_error( $number ) ) {
			return $number;
		}

		if ( ! ImageCatalog::offers( $image ) ) {
			return ServerControls::refusal( 'dedicated_invalid', __( 'Choose one of the systems in the list.', 'beltoft-services' ), 400 );
		}

		$ips = ManualServerDelivery::ips( $r );

		if ( ! $ips ) {
			return ServerControls::refusal( 'dedicated_unavailable', __( 'The server has no address yet.', 'beltoft-services' ), 409 );
		}

		return null;
	}

	/**
	 * Starts the job (the route has checked the typed name and the code). The step memory is stored by the start, under the resource lock.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $image Image file name.
	 * @return true|\WP_Error
	 */
	public static function start( Resource $r, string $image ) {
		$why = self::check( $r, $image );

		if ( $why ) {
			return $why;
		}

		return Jobs::start_action(
			$r,
			self::ACTION,
			__( 'Reinstalling your server', 'beltoft-services' ),
			array( 'image' => $image ),
			static function () use ( $r, $image ) {
				return $r->set( array( self::META => array( 'state' => 'running', 'step' => 'rescue', 'image' => $image, 'at' => time() ) ) ) ? true : ServerControls::refusal( 'dedicated_busy', __( 'The request could not be stored. Try again.', 'beltoft-services' ), 500 );
			}
		);
	}

	/**
	 * The job: runs the steps until one has to wait.
	 *
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $args Arguments.
	 */
	public static function run( Resource $r, array $args ): StepResult {
		unset( $args );
		$memo = $r->get( self::META );

		if ( ! is_array( $memo ) || 'running' !== ( $memo['state'] ?? '' ) ) {
			return StepResult::fail( 'There is no reinstall to run.', array( Jobs::TEXT => __( 'There was no reinstall to run.', 'beltoft-services' ), Jobs::EXPECTED => true ) );
		}

		$number = ServerControls::number( $r );
		$ips    = ManualServerDelivery::ips( $r );

		if ( $number < 1 || ! $ips ) {
			return self::fail( $r, $memo, 'The server is not available for a reinstall.', false );
		}

		$ip = $ips[0];

		// Each pass does one step; a step that has to wait returns a poll, one that is done goes on at once.
		for ( $i = 0; $i < 12; $i++ ) {
			$result = self::step( $r, $memo, $number, $ip );

			if ( $result instanceof StepResult ) {
				return $result;
			}
		}

		return StepResult::poll( 5, 'Reinstalling your server', Reconciler::POLL_BUDGET_MAX );
	}

	/**
	 * One step. Returns a result to return from the job, or null to go on with the next step (the memo is updated in place).
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $memo   Memory, by reference.
	 * @param int                  $number Server number.
	 * @param string               $ip     Address.
	 */
	private static function step( Resource $r, array &$memo, int $number, string $ip ): ?StepResult {
		$client = DedicatedClients::for();
		$shell  = RescueShells::for();
		$now    = Jobs::now();

		switch ( (string) ( $memo['step'] ?? '' ) ) {
			case 'rescue':
				// Without a key the answer holds the one-time password. A rescue system that is already on (and whose password we do not have) is switched off first.
				$status = $client->rescue_status( $number );

				if ( is_wp_error( $status ) ) {
					return self::retry_or_fail( $r, $memo, $status, 'The rescue system could not be read.' );
				}

				if ( ! empty( $status['active'] ) && '' === (string) ( $memo['pw'] ?? '' ) ) {
					$off = $client->rescue_deactivate( $number );

					if ( is_wp_error( $off ) ) {
						return self::retry_or_fail( $r, $memo, $off, 'The rescue system could not be switched off.' );
					}
				}

				$os = in_array( 'linux', array_map( 'strval', (array) ( $status['os'] ?? array() ) ), true ) ? 'linux' : (string) ( ( (array) ( $status['os'] ?? array() ) )[0] ?? 'linux' );

				if ( empty( $status['active'] ) || '' === (string) ( $memo['pw'] ?? '' ) ) {
					$on = $client->rescue_activate( $number, $os, array() );

					if ( is_wp_error( $on ) ) {
						return self::retry_or_fail( $r, $memo, $on, 'The rescue system could not be switched on.' );
					}

					$password = isset( $on['password'] ) && is_string( $on['password'] ) ? $on['password'] : '';

					if ( '' === $password ) {
						return self::fail( $r, $memo, 'The rescue system answered without a password.', true );
					}

					$memo['pw'] = Crypto::encrypt( $password );
				}

				$memo['step'] = 'reset';
				self::save( $r, $memo );

				return null;

			case 'reset':
				$listed = $client->reset_options( $number );

				if ( is_wp_error( $listed ) ) {
					return self::retry_or_fail( $r, $memo, $listed, 'The reset options could not be read.' );
				}

				$types = array_map( 'strval', (array) ( $listed['type'] ?? array() ) );
				// The server must restart whatever state its old system is in: a hardware reset when it is listed, else a software one.
				$type = in_array( 'hw', $types, true ) ? 'hw' : ( in_array( 'sw', $types, true ) ? 'sw' : '' );

				if ( '' === $type ) {
					return self::fail( $r, $memo, 'The server lists no reset type that restarts it.', true );
				}

				$done = $client->reset_server( $number, $type );

				if ( is_wp_error( $done ) ) {
					return self::retry_or_fail( $r, $memo, $done, 'The server could not be restarted.' );
				}

				$memo['step']  = 'wait';
				$memo['after'] = $now + self::delay( self::BOOT_AFTER );
				$memo['until'] = $now + self::BOOT_WAIT;
				self::save( $r, $memo );

				return null;

			case 'wait':
				if ( $now < (int) $memo['after'] ) {
					return self::wait( __( 'Starting the server in the installer', 'beltoft-services' ) );
				}

				$password = self::password( $memo );
				$names    = '' === $password ? new \WP_Error( 'x', 'x' ) : $shell->images( $ip, $password );

				if ( is_wp_error( $names ) ) {
					// Not up yet (the old system may still answer, and the password is only right in the rescue system).
					return $now > (int) $memo['until'] ? self::fail( $r, $memo, 'The rescue system did not start in time.', true ) : self::wait( __( 'Starting the server in the installer', 'beltoft-services' ) );
				}

				ImageCatalog::remember( $names );

				// Before anything is erased: the chosen file must be in the listing of this rescue system.
				if ( ! in_array( (string) $memo['image'], $names, true ) ) {
					return self::fail( $r, $memo, 'The chosen image is not in the rescue system.', true, __( 'That system is not available any more. Your server was not changed. Choose another one.', 'beltoft-services' ) );
				}

				$memo['step'] = 'install';
				self::save( $r, $memo );

				return null;

			case 'install':
				$password = self::password( $memo );
				$drives   = $shell->drives( $ip, $password );

				if ( is_wp_error( $drives ) || ! $drives ) {
					return self::fail( $r, $memo, 'The disks of the server could not be listed.', true );
				}

				$key    = self::customer_key( $r );
				$config = self::config( $drives, (string) $r->get( 'server_name' ), (string) $memo['image'], '' !== $key );
				$go     = $shell->start( $ip, $password, $config, '' !== $key ? $key . "\n" : '' );

				if ( is_wp_error( $go ) ) {
					return self::retry_or_fail( $r, $memo, $go, 'The installation could not be started.' );
				}

				$memo['step']    = 'installing';
				$memo['started'] = $now;
				$memo['until']   = $now + self::INSTALL_WAIT;
				self::save( $r, $memo );

				return self::wait( __( 'Installing the system', 'beltoft-services' ), true );

			case 'installing':
				$state = $shell->status( $ip, self::password( $memo ) );

				if ( is_wp_error( $state ) ) {
					return $now > (int) $memo['until'] ? self::fail( $r, $memo, 'The installation could not be followed.', true ) : self::wait( __( 'Installing the system', 'beltoft-services' ) );
				}

				if ( 'failed' === $state['state'] ) {
					return self::fail( $r, $memo, 'The installer failed: ' . mb_substr( preg_replace( '/\s+/', ' ', $state['tail'] ) ?? '', 0, 200 ), false );
				}

				if ( 'running' === $state['state'] ) {
					return $now > (int) $memo['until'] ? self::fail( $r, $memo, 'The installation took too long.', false ) : self::wait( __( 'Installing the system', 'beltoft-services' ) );
				}

				$memo['step'] = 'reboot';
				self::save( $r, $memo );

				return null;

			case 'reboot':
				$shell->reboot( $ip, self::password( $memo ) );
				$memo['step']  = 'back';
				$memo['after'] = $now + self::delay( self::REBOOT_AFTER );
				$memo['until'] = $now + self::REBOOT_WAIT;
				self::save( $r, $memo );

				return self::wait( __( 'Starting the new system', 'beltoft-services' ), true );

			case 'back':
				if ( $now < (int) $memo['after'] || ! $shell->reachable( $ip ) ) {
					return $now > (int) $memo['until'] ? self::fail( $r, $memo, 'The new system did not answer after the restart.', false ) : self::wait( __( 'Starting the new system', 'beltoft-services' ) );
				}

				return self::finish( $r, $memo, $number, $client );
		}

		return self::fail( $r, $memo, 'Unknown step.', true );
	}

	/**
	 * The end: the access of the new system, the memory cleaned.
	 *
	 * @param Resource             $r      Resource.
	 * @param array<string, mixed> $memo   Memory.
	 * @param int                  $number Server number.
	 * @param RobotClient          $client Client.
	 */
	private static function finish( Resource $r, array $memo, int $number, RobotClient $client ): StepResult {
		// The rescue system boots once; switching it off is only tidiness, and a failure of it changes nothing.
		$client->rescue_deactivate( $number );

		$key  = self::customer_key( $r );
		$meta = array(
			self::META                            => array( 'state' => 'done', 'image' => (string) $memo['image'], 'at' => time() ),
			ServerControls::RESCUE_PASSWORD       => null,
			ServerControls::RESCUE_AT             => null,
			ManualServerDelivery::NOTE            => null,
			'last_reinstall_at'                   => time(),
			// With a key installimage disabled the root password; without one the rescue password is the new root password.
			ManualServerDelivery::PASSWORD        => '' === $key && '' !== (string) ( $memo['pw'] ?? '' ) ? (string) $memo['pw'] : null,
		);

		return StepResult::done( $meta + array( Jobs::TEXT => __( 'Your server was reinstalled.', 'beltoft-services' ) ), 'Server reinstalled' );
	}

	/**
	 * The /autosetup of installimage: every disk, software RAID when there are several, the layout of the example in the sources, the chosen image,
	 * and the file of keys when the customer has one. It never holds a password.
	 *
	 * @param string[] $drives   /dev paths.
	 * @param string   $hostname Server name.
	 * @param string   $image    Image file name.
	 * @param bool     $keys     Whether /root/ii-keys is written.
	 */
	public static function config( array $drives, string $hostname, string $image, bool $keys ): string {
		$lines = array();

		foreach ( array_values( $drives ) as $i => $drive ) {
			$lines[] = 'DRIVE' . ( $i + 1 ) . ' ' . $drive;
		}

		$count = count( $drives );
		// Levels from the defaults of installimage (config.sh): 1 for two drives, 5 for three, 6 for four. Ten is not used.
		$level   = 2 === $count ? 1 : ( 3 === $count ? 5 : ( $count >= 4 ? 6 : 1 ) );
		$lines[] = '';
		$lines[] = 'SWRAID ' . ( $count > 1 ? '1' : '0' );
		$lines[] = 'SWRAIDLEVEL ' . $level;
		$lines[] = '';
		$lines[] = 'BOOTLOADER grub';
		$lines[] = 'HOSTNAME ' . preg_replace( '/[^A-Za-z0-9.-]/', '', $hostname );
		$lines[] = '';
		$lines[] = 'PART swap swap 8G';
		$lines[] = 'PART /boot ext3 1024M';
		$lines[] = 'PART / ext4 all';
		$lines[] = '';
		$lines[] = 'IMAGE /root/images/' . $image;

		if ( $keys ) {
			$lines[] = 'SSHKEYS_URL /root/ii-keys';
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The customer's public key, or empty.
	 *
	 * @param Resource $r Resource.
	 */
	private static function customer_key( Resource $r ): string {
		$key = trim( (string) $r->get( 'ssh_key' ) );

		return false === strpbrk( $key, "\r\n\0" ) ? $key : '';
	}

	/**
	 * The rescue password of the memory.
	 *
	 * @param array<string, mixed> $memo Memory.
	 */
	private static function password( array $memo ): string {
		$stored = (string) ( $memo['pw'] ?? '' );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	/**
	 * @param Resource             $r    Resource.
	 * @param array<string, mixed> $memo Memory.
	 */
	private static function save( Resource $r, array $memo ): void {
		$r->set( array( self::META => $memo ) );
	}

	/**
	 * A wait for a real server is the real time; with a fake shell (tests and staging: there is no machine to wait for) there is none.
	 *
	 * @param int $seconds Seconds for a real server.
	 */
	private static function delay( int $seconds ): int {
		return RescueShells::for() instanceof SshRescueShell ? $seconds : 0;
	}

	/**
	 * A wait: queued again in half a minute (a few seconds with a fake shell). $progress marks a step that got further.
	 *
	 * @param string $why      What the customer reads.
	 * @param bool   $progress True when a step finished.
	 */
	private static function wait( string $why, bool $progress = false ): StepResult {
		$result = StepResult::poll( RescueShells::for() instanceof SshRescueShell ? 30 : 3, $why, Reconciler::POLL_BUDGET_MAX );

		return $progress ? $result->with_progress() : $result;
	}

	/**
	 * A call of the web service failed: a rate limit or a server error is tried again by the job; anything else ends the job.
	 *
	 * @param Resource             $r     Resource.
	 * @param array<string, mixed> $memo  Memory.
	 * @param \WP_Error            $error Error.
	 * @param string               $what  What was tried.
	 */
	private static function retry_or_fail( Resource $r, array $memo, \WP_Error $error, string $what ): StepResult {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		if ( 0 === $status || $status >= 500 || 'RATE_LIMIT_EXCEEDED' === $error->get_error_code() ) {
			return StepResult::retry( 60, $what . ' (' . $error->get_error_code() . ')' );
		}

		return self::fail( $r, $memo, $what . ' (' . $error->get_error_code() . ')', true );
	}

	/**
	 * The end of a failed job: the password wiped, the rescue system switched off, staff told. When the old system was not touched ($restore: the
	 * server may still be in the rescue system), the server is restarted so it comes back as it was.
	 *
	 * @param Resource             $r       Resource.
	 * @param array<string, mixed> $memo    Memory.
	 * @param string               $why     For staff.
	 * @param bool                 $restore Restart the server into its old system (nothing was erased).
	 * @param string               $text    What the customer reads.
	 */
	private static function fail( Resource $r, array $memo, string $why, bool $restore, string $text = '' ): StepResult {
		$number = ServerControls::number( $r );
		$ips    = ManualServerDelivery::ips( $r );

		if ( $number > 0 ) {
			DedicatedClients::for()->rescue_deactivate( $number );

			// Nothing was erased and the server may be waiting in the rescue system: restart it into its old system. (The password was valid in the rescue system.)
			if ( $restore && $ips && 'rescue' !== ( $memo['step'] ?? '' ) && 'reset' !== ( $memo['step'] ?? '' ) ) {
				RescueShells::for()->reboot( $ips[0], self::password( $memo ) );
			}
		}

		$r->set( array( self::META => array( 'state' => 'failed', 'image' => (string) ( $memo['image'] ?? '' ), 'at' => time(), 'why' => mb_substr( $why, 0, 200 ) ) ) );
		Alerts::admin( $r, 'Reinstall failed: ' . $why );

		return StepResult::fail(
			$why,
			array( Jobs::TEXT => '' !== $text ? $text : ( $restore ? __( 'The reinstall did not start. Your server was not changed. We have been told.', 'beltoft-services' ) : __( 'The reinstall did not finish. We have been told and will look into it.', 'beltoft-services' ) ) ) + ( '' !== $text ? array( Jobs::EXPECTED => true ) : array() )
		);
	}
}
