<?php
/**
 * What the SQL engines (PostgreSQL, SQL Server) share.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The init job of an engine with a database name and a connection limit, and the two connection strings of the card:
 * with the certificate check and without it. The engine supplies connection() and init_fields().
 */
trait SqlEngine {

	/**
	 * Connection strings for an address: `verify` checks the server certificate against the CA the customer
	 * downloads, `require` only encrypts.
	 *
	 * @return array{verify:string,require:string}
	 */
	abstract public function connection( string $host ): array;

	public function init_job( Resource $r, string $request, array $listen, string $cidr ): array {
		return array( 'v' => 1, 'request' => $request, 'op' => 'init', 'db' => $this->database(), 'role' => $this->login(), 'listen' => $listen, 'private_cidr' => $cidr, 'allow' => array(), 'world' => false, 'max_connections' => DbProfile::max_connections( $r ) ) + $this->init_fields( $r );
	}

	public function connections( string $host ): array {
		$strings = $this->connection( $host );

		return array(
			array( 'label' => __( 'With certificate check (recommended):', 'beltoft-services' ), 'value' => $strings['verify'] ),
			array( 'label' => __( 'Encrypted, without certificate check:', 'beltoft-services' ), 'value' => $strings['require'] ),
		);
	}

	public function connection_note(): string {
		return '';
	}
}
