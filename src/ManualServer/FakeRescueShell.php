<?php
/**
 * An in-process rescue system for tests and the staging site.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * No network, no ssh. The listing is what a rescue system holds (names in the form installimage uses), the drives are two NVMe disks. An
 * installation is "running" for STATUS_READS reads and then "done", or "failed" after fail_next(). It records what it was told (never the
 * password: only whether the password was the one the fake Robot gave, so a test can see the job passed the right one).
 */
final class FakeRescueShell implements RescueShell {

	public const OPTION       = 'beltoft_services_fake_shell';
	public const STATUS_READS = 2;

	/** The images of the fake rescue system. */
	public const IMAGES = array(
		'Alma-94-amd64-base.tar.gz',
		'CentOS-Stream-9-amd64-base.tar.gz',
		'Debian-1201-bookworm-amd64-base.tar.gz',
		'Debian-1103-bullseye-amd64-base.tar.gz',
		'Rocky-94-amd64-base.tar.gz',
		'Ubuntu-2204-jammy-amd64-base.tar.gz',
		'Ubuntu-2404-noble-amd64-base.tar.gz',
		'Debian-bookworm-latest-amd64-base.tar.gz',
	);

	/** The next installation ends as failed (tests). */
	public static function fail_next(): void {
		self::save( array( 'fail' => true ) + self::state() );
	}

	/** The next listing is empty or an error (tests). */
	public static function unreachable( bool $on = true ): void {
		self::save( array( 'down' => $on ) + self::state() );
	}

	/**
	 * What the fake was asked to do.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function calls(): array {
		return (array) ( self::state()['calls'] ?? array() );
	}

	/** Forgets everything. */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Records a call. A password is never kept, only whether some rescue password of the fake Robot matched it.
	 *
	 * @param string               $call     Call.
	 * @param string               $password Password given.
	 * @param array<string, mixed> $extra    Public details.
	 */
	private static function note( string $call, string $password, array $extra = array() ): void {
		$state = self::state();
		$match = false;

		foreach ( array_keys( (array) ( get_option( FakeRobotClient::OPTION, array() )['servers'] ?? array() ) ) as $number ) {
			$known = FakeRobotClient::rescue_password( (int) $number );
			$match = $match || ( '' !== $known && hash_equals( $known, $password ) );
		}

		$state['calls'][] = array( 'call' => $call, 'password_ok' => $match ) + $extra;
		self::save( $state );
	}

	public function reachable( string $ip ): bool {
		unset( $ip );

		return empty( self::state()['down'] );
	}

	public function images( string $ip, string $password ) {
		unset( $ip );
		self::note( 'images', $password );

		return self::IMAGES;
	}

	public function drives( string $ip, string $password ) {
		unset( $ip );
		self::note( 'drives', $password );

		return array( '/dev/nvme0n1', '/dev/nvme1n1' );
	}

	public function start( string $ip, string $password, string $config, string $keys ) {
		unset( $ip );
		$state                 = self::state();
		$state['config']       = $config;
		$state['keys']         = $keys;
		$state['reads']        = 0;
		$state['started']      = true;
		self::save( $state );
		self::note( 'start', $password, array( 'config' => $config, 'keys' => '' !== $keys ) );

		return true;
	}

	public function status( string $ip, string $password ) {
		unset( $ip );
		$state = self::state();
		self::note( 'status', $password );
		$state = self::state();

		if ( empty( $state['started'] ) ) {
			return array( 'state' => 'failed', 'tail' => 'installimage was not started' );
		}

		if ( (int) ( $state['reads'] ?? 0 ) < self::STATUS_READS ) {
			$state['reads'] = (int) ( $state['reads'] ?? 0 ) + 1;
			self::save( $state );

			return array( 'state' => 'running', 'tail' => 'Installing the system' );
		}

		return ! empty( $state['fail'] )
			? array( 'state' => 'failed', 'tail' => 'An error has occurred.' )
			: array( 'state' => 'done', 'tail' => 'INSTALLATION COMPLETE' );
	}

	public function reboot( string $ip, string $password ) {
		unset( $ip );
		self::note( 'reboot', $password );

		return true;
	}
}
