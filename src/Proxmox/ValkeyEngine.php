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
final class ValkeyEngine implements DbEngine, DbEngineEviction {

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

	/** Meta: the policy the customer chose for this database. */
	public const POLICY_META = 'db_policy';

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

	public function login(): string {
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
	 * The eviction policy of the server: the customer's choice when there is one, else what a new server starts with
	 * (a filter may change that; anything unknown is the default).
	 */
	public static function policy( Resource $r ): string {
		$chosen = $r->get( self::POLICY_META );

		if ( is_string( $chosen ) && in_array( $chosen, self::POLICIES, true ) ) {
			return $chosen;
		}

		/**
		 * Filters maxmemory-policy of a new Valkey server.
		 *
		 * @param string   $policy Policy.
		 * @param Resource $r      Resource.
		 */
		$policy = apply_filters( 'beltoft_services_valkey_policy', self::DEFAULT_POLICY, $r );

		return is_string( $policy ) && in_array( $policy, self::POLICIES, true ) ? $policy : self::DEFAULT_POLICY;
	}

	/**
	 * The memory limit and the eviction policy. Valkey has no database name and no connection limit, so it does not
	 * use the SQL job of SqlEngine.
	 */
	public function init_fields( Resource $r ): array {
		return array( 'maxmemory_mb' => self::maxmemory_mb( $r ), 'policy' => self::policy( $r ) );
	}

	public function init_job( Resource $r, string $request, array $listen, string $cidr ): array {
		return array( 'v' => 1, 'request' => $request, 'op' => 'init', 'role' => self::ROLE, 'listen' => $listen, 'private_cidr' => $cidr, 'allow' => array(), 'world' => false ) + $this->init_fields( $r );
	}

	public function eviction_default(): string {
		return self::DEFAULT_POLICY;
	}

	public function eviction_choices(): array {
		return array(
			'noeviction'      => array( __( 'Refuse new writes', 'beltoft-services' ), __( 'Nothing is ever deleted. When memory is full, commands that add data fail with an error and reads keep working. The safest choice for data you cannot lose.', 'beltoft-services' ) ),
			'allkeys-lru'     => array( __( 'Remove the least recently used keys', 'beltoft-services' ), __( 'Any key may be removed, starting with the one not used for the longest time. The usual choice for a cache.', 'beltoft-services' ) ),
			'allkeys-lfu'     => array( __( 'Remove the least frequently used keys', 'beltoft-services' ), __( 'Any key may be removed, starting with the one used least often. Good for a cache with a few very popular keys.', 'beltoft-services' ) ),
			'allkeys-random'  => array( __( 'Remove random keys', 'beltoft-services' ), __( 'Any key may be removed, picked at random.', 'beltoft-services' ) ),
			'volatile-lru'    => array( __( 'Remove the least recently used keys that expire', 'beltoft-services' ), __( 'Only keys with an expiry time may be removed, the least recently used first. Keys without an expiry stay. When none are left to remove, new writes fail.', 'beltoft-services' ) ),
			'volatile-lfu'    => array( __( 'Remove the least frequently used keys that expire', 'beltoft-services' ), __( 'Only keys with an expiry time may be removed, the least often used first. Keys without an expiry stay. When none are left to remove, new writes fail.', 'beltoft-services' ) ),
			'volatile-random' => array( __( 'Remove random keys that expire', 'beltoft-services' ), __( 'Only keys with an expiry time may be removed, picked at random. Keys without an expiry stay. When none are left to remove, new writes fail.', 'beltoft-services' ) ),
			'volatile-ttl'    => array( __( 'Remove the keys that expire soonest', 'beltoft-services' ), __( 'Only keys with an expiry time may be removed, the ones closest to expiring first. Keys without an expiry stay. When none are left to remove, new writes fail.', 'beltoft-services' ) ),
		);
	}

	public function limits(): string {
		return '';
	}

	public function ca_hint(): string {
		return '';
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
