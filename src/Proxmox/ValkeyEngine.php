<?php
/**
 * The Valkey engine of the managed database driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Valkey (the BSD-licensed continuation of Redis: same protocol, commands and client libraries) on its own VM, built
 * from a template of the family `valkey`. One login (an ACL user), no database name, TLS only on its own port.
 */
final class ValkeyEngine extends DbEngine {

	public const ID = 'valkey';

	/** The TLS port. The plain port is switched off on every address. */
	public const PORT = 6380;

	/** The login. The guest's `default` user is off. */
	public const ROLE = 'app_user';

	/** Smallest memory a Valkey plan may have (MB): below it the OS and the server do not fit. */
	public const MIN_MEMORY_MB = 512;

	/** What the server does when the memory limit is reached. */
	public const POLICIES = array( 'noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl' );

	public const DEFAULT_POLICY = 'noeviction';

	public function id(): string {
		return self::ID;
	}

	/**
	 * Just the name: the plan's `major` field has a PostgreSQL default (18), so it says nothing about Valkey. The
	 * version is the one the template carries.
	 */
	public function label( array $plan ): string {
		unset( $plan );

		return 'Valkey';
	}

	public function port(): int {
		return self::PORT;
	}

	public function database(): string {
		return '';
	}

	public function role(): string {
		return self::ROLE;
	}

	public function family( array $plan ): string {
		unset( $plan );

		return 'valkey';
	}

	public function plan_error( array $plan ): ?string {
		$memory = $plan['memory_mb'] ?? 0;

		if ( ! ( is_int( $memory ) || ( is_string( $memory ) && ctype_digit( $memory ) ) ) || (int) $memory < self::MIN_MEMORY_MB ) {
			return 'A Valkey plan needs at least ' . self::MIN_MEMORY_MB . ' MB of memory';
		}

		return null;
	}

	/**
	 * The memory limit of the server in MB: about 75 percent of the plan's memory (a filter may change it), never
	 * more than 90 percent and never less than 64.
	 */
	public static function maxmemory_mb( Resource $r ): int {
		$mb = (int) ( ( (array) $r->get( 'plan' ) )['memory_mb'] ?? 0 );
		$n  = (int) floor( $mb * 0.75 );

		/**
		 * Filters maxmemory of a Valkey server, in MB.
		 *
		 * @param int      $n  Megabytes.
		 * @param Resource $r  Resource.
		 */
		$n = (int) apply_filters( 'beltoft_services_valkey_maxmemory_mb', $n, $r );

		return max( 64, min( (int) floor( $mb * 0.9 ), $n ) );
	}

	/**
	 * The eviction policy a new server starts with (a filter may change it; anything unknown is the default).
	 */
	public static function policy( Resource $r ): string {
		/**
		 * Filters maxmemory-policy of a new Valkey server.
		 *
		 * @param string   $policy Policy.
		 * @param Resource $r      Resource.
		 */
		$policy = apply_filters( 'beltoft_services_valkey_policy', self::DEFAULT_POLICY, $r );

		return is_string( $policy ) && in_array( $policy, self::POLICIES, true ) ? $policy : self::DEFAULT_POLICY;
	}

	public function init_job( Resource $r, string $request, array $listen, string $cidr ): array {
		return array( 'v' => 1, 'request' => $request, 'op' => 'init', 'role' => self::ROLE, 'listen' => $listen, 'private_cidr' => $cidr, 'allow' => array(), 'world' => false, 'maxmemory_mb' => self::maxmemory_mb( $r ), 'policy' => self::policy( $r ) );
	}

	public function connections( string $host ): array {
		return array(
			array( 'label' => __( 'Connection URL (TLS):', 'beltoft-services' ), 'value' => 'rediss://' . self::ROLE . ':PASSWORD@' . $host . ':' . self::PORT ),
			array( 'label' => __( 'Command line:', 'beltoft-services' ), 'value' => 'valkey-cli --tls --cacert ca.crt -h ' . $host . ' -p ' . self::PORT . ' --user ' . self::ROLE . ' --pass PASSWORD' ),
		);
	}

	public function connection_note(): string {
		return __( 'Valkey speaks the Redis protocol, so any Redis client works. Connections are encrypted only. To check the server, give your client the CA certificate below.', 'beltoft-services' );
	}
}
