<?php
/** Moves one DNS packet over a real socket, UDP or TCP. Bytes only: no parsing, no address check. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

final class SocketChannel implements DnsChannel {
	/** @var float Seconds to wait for a connection or an answer. */
	private static float $timeout = 3;

	/** Tests only: lowers the wait so a silent server does not slow the suite. Returns the old value. */
	public static function timeout( float $seconds ): float {
		$old           = self::$timeout;
		self::$timeout = $seconds;
		return $old;
	}

	public function send( string $host, int $port, string $wire, string $protocol ): string|\WP_Error {
		return 'tcp' === $protocol ? $this->tcp( $host, $wire, $port ) : $this->udp( $host, $wire, $port );
	}

	private function udp( string $host, string $wire, int $port ): string|\WP_Error {
		$socket = @fsockopen( 'udp://' . $host, $port, $errno, $message, self::$timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- UDP DNS query, not file I/O; transport error returned below.
		if ( ! $socket ) { return new \WP_Error( 'dns_unreachable', 'Name server could not be reached.' ); }
		stream_set_timeout( $socket, (int) floor( self::$timeout ), (int) ( ( self::$timeout - floor( self::$timeout ) ) * 1000000 ) );
		fwrite( $socket, $wire ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UDP DNS socket, not file I/O.
		$data = fread( $socket, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- UDP DNS socket, not file I/O.
		$timed_out = (bool) ( stream_get_meta_data( $socket )['timed_out'] ?? false );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP DNS socket, not file I/O.
		if ( is_string( $data ) && '' !== $data ) { return $data; }
		return new \WP_Error( $timed_out ? 'dns_timeout' : 'dns_unreachable', 'Name server did not answer.' );
	}

	private function tcp( string $host, string $wire, int $port ): string|\WP_Error {
		$socket = @fsockopen( 'tcp://' . $host, $port, $errno, $message, self::$timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- TCP DNS query, not file I/O; transport error returned below.
		if ( ! $socket ) { return new \WP_Error( 110 === $errno ? 'dns_timeout' : 'dns_unreachable', 'Name server could not be reached.' ); }
		stream_set_timeout( $socket, (int) floor( self::$timeout ), (int) ( ( self::$timeout - floor( self::$timeout ) ) * 1000000 ) );
		fwrite( $socket, pack( 'n', strlen( $wire ) ) . $wire ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- TCP DNS socket, not file I/O.
		$deadline = microtime( true ) + self::$timeout;
		$head     = $this->read_exactly( $socket, 2, $deadline );
		$data     = null === $head ? null : $this->read_exactly( $socket, unpack( 'n', $head )[1], $deadline );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- TCP DNS socket, not file I/O.
		return null === $data ? new \WP_Error( 'dns_timeout', 'Name server did not answer.' ) : $data;
	}

	/** @param resource $socket */
	private function read_exactly( $socket, int $length, float $deadline ): ?string {
		$buffer = '';
		while ( strlen( $buffer ) < $length ) {
			if ( microtime( true ) > $deadline || feof( $socket ) ) { return null; }
			$chunk = fread( $socket, $length - strlen( $buffer ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- TCP DNS socket, not file I/O.
			if ( false === $chunk || '' === $chunk ) { return null; }
			$buffer .= $chunk;
		}
		return $buffer;
	}
}
