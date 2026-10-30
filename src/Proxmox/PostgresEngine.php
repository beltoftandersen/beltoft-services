<?php
/**
 * The PostgreSQL engine of a managed database (the reference engine).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

final class PostgresEngine implements DbEngine {

	public function id(): string {
		return 'postgres';
	}

	public function label( array $plan ): string {
		$major = (int) ( $plan['major'] ?? 0 );

		return $major > 0 ? 'PostgreSQL ' . $major : 'PostgreSQL';
	}

	public function port(): int {
		return DbGuest::PORT;
	}

	public function database(): string {
		return DbGuest::DATABASE;
	}

	public function login(): string {
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

	public function init_fields( Resource $r ): array {
		return array( 'major' => DbBuild::major( (array) $r->get( 'plan' ) ) );
	}

	public function connection( string $host ): array {
		$base = 'postgresql://' . $this->login() . ':PASSWORD@' . $host . ':' . $this->port() . '/' . $this->database();

		return array(
			'verify'  => $base . '?sslmode=verify-full&sslrootcert=ca.crt',
			'require' => $base . '?sslmode=require',
		);
	}

	public function limits(): string {
		return '';
	}

	public function ca_hint(): string {
		return '';
	}
}
