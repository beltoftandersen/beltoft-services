<?php
/**
 * The rescue system over ssh, with the one-time rescue password.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

defined( 'ABSPATH' ) || exit;

/**
 * Built only by RescueShells::for() when the dedicated client is live (never for the fake or the test server). Runs /usr/bin/ssh without a shell
 * (an argument list), the password reaches it only through the environment of that one process and an askpass helper that prints it
 * (SSH_ASKPASS with SSH_ASKPASS_REQUIRE=force); it is never on a command line, in a file or in a log. The host key of a rescue system changes
 * with every activation, so it is not checked (StrictHostKeyChecking=no, UserKnownHostsFile=/dev/null): open question 9 of the plan.
 *
 * What it runs in the rescue system comes from the installimage sources (see ImageCatalog): `ls /root/images`, `/autosetup`, `installimage`.
 */
final class SshRescueShell implements RescueShell {

	private const STATUS_FILE = '/root/.bs-install-exit';
	private const LOG_FILE    = '/root/.bs-install.log';

	public function reachable( string $ip ): bool {
		if ( ! self::address( $ip ) ) {
			return false;
		}

		$errno  = 0;
		$errstr = '';
		$socket = @fsockopen( $ip, 22, $errno, $errstr, 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a closed port is a plain no.

		if ( ! is_resource( $socket ) ) {
			return false;
		}

		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket.

		return true;
	}

	public function images( string $ip, string $password ) {
		$out = $this->run( $ip, $password, 'ls -1 /root/images/' );

		return is_wp_error( $out ) ? $out : ImageCatalog::clean( preg_split( '/\R/', $out ) ?: array() );
	}

	public function drives( string $ip, string $password ) {
		$out = $this->run( $ip, $password, 'lsblk -dn -p -o NAME,TYPE' );

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$drives = array();

		foreach ( preg_split( '/\R/', $out ) ?: array() as $line ) {
			$cols = preg_split( '/\s+/', trim( $line ) ) ?: array();

			if ( 2 === count( $cols ) && 'disk' === $cols[1] && 1 === preg_match( '#^/dev/[a-z0-9/]+$#D', $cols[0] ) ) {
				$drives[] = $cols[0];
			}
		}

		return $drives;
	}

	public function start( string $ip, string $password, string $config, string $keys ) {
		$put = $this->run( $ip, $password, 'cat > /autosetup', $config );

		if ( is_wp_error( $put ) ) {
			return $put;
		}

		if ( '' !== $keys ) {
			$put = $this->run( $ip, $password, 'cat > /root/ii-keys', $keys );

			if ( is_wp_error( $put ) ) {
				return $put;
			}
		}

		$go = 'rm -f ' . self::STATUS_FILE . ' ' . self::LOG_FILE . '; nohup sh -c ' . escapeshellarg( 'installimage > ' . self::LOG_FILE . ' 2>&1; echo $? > ' . self::STATUS_FILE ) . ' > /dev/null 2>&1 < /dev/null &';
		$run = $this->run( $ip, $password, $go );

		return is_wp_error( $run ) ? $run : true;
	}

	public function status( string $ip, string $password ) {
		$out = $this->run( $ip, $password, 'cat ' . self::STATUS_FILE . ' 2>/dev/null; echo ---; tail -n 12 ' . self::LOG_FILE . ' 2>/dev/null' );

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$parts = explode( '---', $out, 2 );
		$code  = trim( $parts[0] );
		$tail  = trim( (string) preg_replace( '/\e\[[0-9;]*m/', '', $parts[1] ?? '' ) );

		if ( '' === $code ) {
			return array( 'state' => 'running', 'tail' => $tail );
		}

		// installimage ends with INSTALLATION COMPLETE (install.sh) and exit code 0.
		return '0' === $code && false !== strpos( $tail . ' ' . $this->log_has_complete( $ip, $password ), 'INSTALLATION COMPLETE' )
			? array( 'state' => 'done', 'tail' => $tail )
			: array( 'state' => 'failed', 'tail' => $tail );
	}

	/**
	 * The tail may have scrolled past the closing lines: look for them in the whole log.
	 *
	 * @param string $ip       Address.
	 * @param string $password Password.
	 */
	private function log_has_complete( string $ip, string $password ): string {
		$out = $this->run( $ip, $password, 'grep -c "INSTALLATION COMPLETE" ' . self::LOG_FILE );

		return ! is_wp_error( $out ) && (int) trim( $out ) > 0 ? 'INSTALLATION COMPLETE' : '';
	}

	public function reboot( string $ip, string $password ) {
		// The connection drops while the server goes down: that is the success.
		$this->run( $ip, $password, 'nohup sh -c "sleep 2; reboot" > /dev/null 2>&1 < /dev/null &' );

		return true;
	}

	/**
	 * Is it an address we may connect to (a public or documentation address of the server, never a loopback or private one)?
	 *
	 * @param string $ip Address.
	 */
	private static function address( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * One command as root.
	 *
	 * @param string $ip       Address.
	 * @param string $password Password.
	 * @param string $command  Remote command line.
	 * @param string $stdin    Standard input.
	 * @return string|\WP_Error Output.
	 */
	private function run( string $ip, string $password, string $command, string $stdin = '' ) {
		if ( ! self::address( $ip ) || '' === $password || ! function_exists( 'proc_open' ) ) {
			return new \WP_Error( 'rescue_shell', 'The rescue system cannot be reached.' );
		}

		$askpass = wp_tempnam( 'bs-askpass' );

		if ( ! $askpass ) {
			return new \WP_Error( 'rescue_shell', 'No temporary file.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a tiny helper that prints the password from the environment; it holds no secret itself.
		file_put_contents( $askpass, "#!/bin/sh\nprintf '%s\\n' \"\$BS_SSH_PW\"\n" );
		chmod( $askpass, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the helper must be executable.

		$args = array(
			'/usr/bin/ssh',
			'-o', 'PreferredAuthentications=password',
			'-o', 'PubkeyAuthentication=no',
			'-o', 'NumberOfPasswordPrompts=1',
			'-o', 'StrictHostKeyChecking=no',
			'-o', 'UserKnownHostsFile=/dev/null',
			'-o', 'LogLevel=ERROR',
			'-o', 'ConnectTimeout=10',
			'root@' . $ip,
			$command,
		);
		$env  = array(
			'PATH'                 => '/usr/bin:/bin',
			'HOME'                 => sys_get_temp_dir(),
			'SSH_ASKPASS'          => $askpass,
			'SSH_ASKPASS_REQUIRE'  => 'force',
			'DISPLAY'              => 'bs:0',
			'BS_SSH_PW'            => $password,
		);
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the one place that runs ssh, with an argument list (no shell) and a fixed environment.
		$proc = proc_open( $args, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );

		if ( ! is_resource( $proc ) ) {
			wp_delete_file( $askpass );

			return new \WP_Error( 'rescue_shell', 'ssh could not be started.' );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- pipes of the child process.
		fwrite( $pipes[0], $stdin );
		fclose( $pipes[0] );
		stream_set_timeout( $pipes[1], 120 );
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		// phpcs:enable
		$code = proc_close( $proc );
		wp_delete_file( $askpass );

		// The error text of ssh is not passed on: it may name the address or hold nothing useful. The code is enough.
		return 0 === $code ? $out : new \WP_Error( 'rescue_shell', 'The command in the rescue system failed (' . (int) $code . ').' );
	}
}
