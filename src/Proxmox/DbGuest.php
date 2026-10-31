<?php
/**
 * The files of the managed database server's guest protocol (db-v1), read and written through the guest agent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

/**
 * Everything read from the guest is untrusted: it is matched against fixed lists and patterns here, and
 * nothing else of it is used. Files: job.json (in), status.json, credentials.json and health.json (out).
 */
final class DbGuest {

	public const DIR         = '/var/lib/beltoft-db';
	public const JOB         = self::DIR . '/job.json';
	public const STATUS      = self::DIR . '/status.json';
	public const CREDENTIALS = self::DIR . '/credentials.json';
	public const HEALTH      = self::DIR . '/health.json';

	public const STATES = array( 'working', 'ready', 'failed' );
	public const STEPS  = array( 'job', 'disk', 'cluster', 'tls', 'config', 'database', 'finish', 'allow', 'listen', 'password', 'dump', 'complete', 'start', 'grow', 'policy' );

	public const DATABASE = 'app';
	public const ROLE     = 'app_user';
	public const PORT     = 5432;

	/** Largest file the plugin reads from a guest. */
	private const LIMIT = 65536;

	/**
	 * Writes a JSON file into the guest.
	 *
	 * @param Client               $client Client.
	 * @param string               $node   Node.
	 * @param int                  $vmid   VMID.
	 * @param string               $file   Guest path.
	 * @param array<string, mixed> $data   Content.
	 * @return true|\WP_Error
	 */
	public static function write( Client $client, string $node, int $vmid, string $file, array $data ) {
		$reply = $client->request( 'POST', '/nodes/' . rawurlencode( $node ) . '/qemu/' . $vmid . '/agent/file-write', array( 'file' => $file, 'content' => array() === $data ? '{}' : wp_json_encode( $data ) ) );

		return is_wp_error( $reply ) ? $reply : true;
	}

	/**
	 * Reads a JSON object from the guest (bounded read), or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read( Client $client, string $node, int $vmid, string $file ): ?array {
		$raw  = Apps::read_file( $client, $node, $vmid, $file );
		$data = null === $raw ? null : json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The status of one request: null unless it is this request's, with a known state and step and a date.
	 *
	 * `size_bytes` (a whole number, as the `grow` job reports the size of the data disk) is there only when the guest sent a valid one.
	 *
	 * @return array{state:string,step:string,size_bytes?:int}|null
	 */
	public static function status( Client $client, string $node, int $vmid, string $request ): ?array {
		$s = self::read( $client, $node, $vmid, self::STATUS );

		if ( null === $s || '' === $request || ! is_string( $s['request'] ?? null ) || ! hash_equals( $request, $s['request'] )
			|| ! in_array( $s['state'] ?? null, self::STATES, true ) || ! in_array( $s['step'] ?? null, self::STEPS, true )
			|| ! is_string( $s['updated'] ?? null ) || false === strtotime( $s['updated'] ) ) {
			return null;
		}

		$out = array( 'state' => $s['state'], 'step' => $s['step'] );

		if ( isset( $s['size_bytes'] ) && is_int( $s['size_bytes'] ) && $s['size_bytes'] > 0 && $s['size_bytes'] <= 70368744177664 ) {
			$out['size_bytes'] = $s['size_bytes'];
		}

		return $out;
	}

	/**
	 * The login of one request, checked against the patterns of the contract, or null.
	 *
	 * @return array{password:string,ca_pem:string,fingerprint:string}|null
	 */
	public static function credentials( Client $client, string $node, int $vmid, string $request ): ?array {
		$c = self::read( $client, $node, $vmid, self::CREDENTIALS );

		if ( null === $c || '' === $request || ! is_string( $c['request'] ?? null ) || ! hash_equals( $request, $c['request'] )
			|| ! is_string( $c['password'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/D', $c['password'] )
			|| ! is_string( $c['ca_pem'] ?? null ) || ! self::pem( $c['ca_pem'] )
			|| ! is_string( $c['fingerprint'] ?? null ) || 1 !== preg_match( '/^[0-9A-F:]{95}$/D', $c['fingerprint'] ) ) {
			return null;
		}

		return array( 'password' => $c['password'], 'ca_pem' => trim( $c['ca_pem'] ) . "\n", 'fingerprint' => $c['fingerprint'] );
	}

	/**
	 * One PEM certificate and nothing else.
	 */
	public static function pem( string $pem ): bool {
		return strlen( $pem ) <= 8192 && 1 === preg_match( '/\A-----BEGIN CERTIFICATE-----\n[A-Za-z0-9+\/=\n]+\n-----END CERTIFICATE-----\n?\z/D', $pem );
	}

	/**
	 * The guest's health file, checked: a bounded read, valid JSON, `ok` a boolean, `updated` a date, and each number
	 * (connections, disk_percent, last_dump, cert_expires, bans, and Valkey's memory_percent and evictions) absent, null
	 * or a plain non-negative number. Anything else rejects the whole file. Times are unix times (0 or null: not known yet).
	 *
	 * @return array{ok:bool,updated:int,connections:int|null,disk_percent:float|null,last_dump:int|null,cert_expires:int|null,bans:int|null,memory_percent?:float|null,evictions?:int|null}|null
	 */
	public static function health( Client $client, string $node, int $vmid ): ?array {
		$h = self::read( $client, $node, $vmid, self::HEALTH );

		if ( null === $h || ! is_bool( $h['ok'] ?? null ) || ! is_string( $h['updated'] ?? null ) ) {
			return null;
		}

		$at = strtotime( $h['updated'] );

		if ( false === $at ) {
			return null;
		}

		$out = array( 'ok' => $h['ok'], 'updated' => $at );

		foreach ( array( 'connections', 'last_dump', 'cert_expires', 'bans' ) as $key ) {
			$value = $h[ $key ] ?? null;

			if ( null !== $value && ( ! is_int( $value ) || $value < 0 || $value > 4294967295 ) ) {
				return null;
			}

			$out[ $key ] = $value;
		}

		$percent = $h['disk_percent'] ?? null;

		if ( null !== $percent && ( ! ( is_int( $percent ) || is_float( $percent ) ) || $percent < 0 || $percent > 100 ) ) {
			return null;
		}

		$out['disk_percent'] = null === $percent ? null : (float) $percent;

		// Valkey's extras: in the result only when the file has them (a PostgreSQL guest never writes them).
		if ( array_key_exists( 'memory_percent', $h ) ) {
			$memory = $h['memory_percent'];

			if ( null !== $memory && ( ! ( is_int( $memory ) || is_float( $memory ) ) || $memory < 0 || $memory > 100 ) ) {
				return null;
			}

			$out['memory_percent'] = null === $memory ? null : (float) $memory;
		}

		if ( array_key_exists( 'evictions', $h ) ) {
			$evictions = $h['evictions'];

			if ( null !== $evictions && ( ! is_int( $evictions ) || $evictions < 0 ) ) {
				return null;
			}

			$out['evictions'] = $evictions;
		}

		return $out;
	}

	/**
	 * Does the guest's health file say the database answers (and was written at or after $since)?
	 */
	public static function healthy( Client $client, string $node, int $vmid, int $since ): bool {
		$h = self::read( $client, $node, $vmid, self::HEALTH );

		if ( null === $h || true !== ( $h['ok'] ?? null ) || ! is_string( $h['updated'] ?? null ) ) {
			return false;
		}

		$at = strtotime( $h['updated'] );

		return false !== $at && $at >= $since - 5;
	}
}
