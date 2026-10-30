<?php
/**
 * The PostgreSQL engine of the managed database driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The reference engine: the one every plan without an `engine` field has.
 */
final class PostgresEngine extends DbEngine {

	public const ID = 'postgres';

	public function id(): string {
		return self::ID;
	}

	public function label( array $plan ): string {
		return (int) ( $plan['major'] ?? 0 ) > 0 ? 'PostgreSQL ' . (int) $plan['major'] : 'PostgreSQL';
	}

	public function port(): int {
		return DbGuest::PORT;
	}

	public function database(): string {
		return DbGuest::DATABASE;
	}

	public function role(): string {
		return DbGuest::ROLE;
	}

	public function family( array $plan ): string {
		return 'postgres-' . DbBuild::major( $plan );
	}

	public function plan_error( array $plan ): ?string {
		if ( ! in_array( DbBuild::major( $plan ), DbDriver::SUPPORTED_MAJORS, true ) ) {
			return 'The PostgreSQL major version of this plan (' . mb_substr( sanitize_text_field( (string) ( is_scalar( $plan['major'] ?? null ) ? $plan['major'] : '' ) ), 0, 10 ) . ') is not supported. Supported: ' . implode( ', ', DbDriver::SUPPORTED_MAJORS );
		}

		return null;
	}

	public function init_job( Resource $r, string $request, array $listen, string $cidr ): array {
		return array( 'v' => 1, 'request' => $request, 'op' => 'init', 'db' => DbGuest::DATABASE, 'role' => DbGuest::ROLE, 'listen' => $listen, 'private_cidr' => $cidr, 'allow' => array(), 'world' => false, 'max_connections' => DbProfile::max_connections( $r ), 'major' => DbBuild::major( (array) $r->get( 'plan' ) ) );
	}

	public function connections( string $host ): array {
		$base = DbGuest::ROLE . ':PASSWORD@' . $host . ':' . DbGuest::PORT . '/' . DbGuest::DATABASE;

		return array(
			array( 'label' => __( 'With certificate check (recommended):', 'beltoft-services' ), 'value' => 'postgresql://' . $base . '?sslmode=verify-full&sslrootcert=ca.crt' ),
			array( 'label' => __( 'Encrypted, without certificate check:', 'beltoft-services' ), 'value' => 'postgresql://' . $base . '?sslmode=require' ),
		);
	}
}
