<?php
/**
 * The SQL Server Express engine of a managed database.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft's mssql-server package (Linux), edition Express (free for production use), installed natively in the engine's own template.
 * The customer accepts Microsoft's terms at checkout (DbTermsField). The plan's `major` is the SQL Server version (2025). Express limits (Microsoft, "Editions and supported features of SQL Server 2025"): 50 GB per database, about 1.4 GB of memory for
 * the database cache, up to 4 cores; the plans start at 4 GB of memory because SQL Server needs 2 GB to start.
 */
final class MssqlEngine implements DbEngine, DbEngineTerms {

	use SqlEngine;

	/** SQL Server versions the guest and the template carry. */
	public const SUPPORTED_VERSIONS = array( 2025 );

	public const DEFAULT_VERSION = 2025;

	public const PORT = 1433;

	/** The smallest memory of a plan: SQL Server needs 2 GB to start, 4 GB leaves room for the system and the cache. */
	public const MIN_MEMORY_MB = 4096;

	public function id(): string {
		return 'mssql';
	}

	public function label( array $plan ): string {
		$version = self::version( $plan );

		return $version > 0 ? 'SQL Server ' . $version . ' Express' : 'SQL Server Express';
	}

	public function port(): int {
		return self::PORT;
	}

	public function database(): string {
		return DbGuest::DATABASE;
	}

	public function login(): string {
		return DbGuest::ROLE;
	}

	/**
	 * The plan's SQL Server version as an integer, 0 when it is not a whole number.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function version( array $plan ): int {
		$version = $plan['major'] ?? self::DEFAULT_VERSION;

		return is_int( $version ) || ( is_string( $version ) && ctype_digit( $version ) ) ? (int) $version : 0;
	}

	public function family( array $plan ): string {
		return 'mssql-' . self::version( $plan );
	}

	public function plan_error( array $plan ): ?string {
		if ( ! in_array( self::version( $plan ), self::SUPPORTED_VERSIONS, true ) ) {
			return 'The SQL Server version of this plan (' . mb_substr( sanitize_text_field( (string) ( is_scalar( $plan['major'] ?? null ) ? $plan['major'] : '' ) ), 0, 10 ) . ') is not supported. Supported: ' . implode( ', ', self::SUPPORTED_VERSIONS );
		}

		$memory = $plan['memory_mb'] ?? 0;

		if ( ! ( is_int( $memory ) || ( is_string( $memory ) && ctype_digit( $memory ) ) ) || (int) $memory < self::MIN_MEMORY_MB ) {
			return 'SQL Server needs a plan with at least ' . ( self::MIN_MEMORY_MB / 1024 ) . ' GB of memory';
		}

		return null;
	}

	/**
	 * The memory SQL Server may use in all (`memory.memorylimitmb` of mssql-conf): three quarters of the plan.
	 */
	public function init_fields( Resource $r ): array {
		$mb = (int) ( ( (array) $r->get( 'plan' ) )['memory_mb'] ?? 0 );

		/**
		 * Filters the memory limit of a SQL Server database (MB).
		 *
		 * @param int      $limit Limit.
		 * @param Resource $r     Resource.
		 */
		$limit = (int) apply_filters( 'beltoft_services_mssql_memory_limit_mb', (int) floor( $mb * 0.75 ), $r );

		return array(
			'memory_limit_mb' => max( 2048, min( 1048576, $limit ) ),
			'accept_eula'     => DbTermsField::accepted( $r ),
		);
	}

	public function terms_url(): string {
		/**
		 * Filters the address of the SQL Server licence terms the checkout box links to.
		 *
		 * @param string $url Address.
		 */
		return (string) apply_filters( 'beltoft_services_mssql_terms_url', 'https://aka.ms/useterms' );
	}

	public function terms_label(): string {
		/* translators: %s: link to the terms */
		return __( 'I accept Microsoft\'s SQL Server %s.', 'beltoft-services' );
	}

	public function connection( string $host ): array {
		$base = 'Server=' . $host . ',' . self::PORT . ';Database=' . $this->database() . ';User Id=' . $this->login() . ';Password=PASSWORD;Encrypt=True;';

		return array(
			'verify'  => $base . 'TrustServerCertificate=False;',
			'require' => $base . 'TrustServerCertificate=True;',
		);
	}

	public function limits(): string {
		return __( 'SQL Server Express edition: up to 50 GB per database, about 1.4 GB of memory for the database cache and up to 4 cores.', 'beltoft-services' );
	}

	public function ca_hint(): string {
		return __( 'For the certificate check, add the CA certificate to the trusted certificates of the system your application runs on.', 'beltoft-services' );
	}
}
