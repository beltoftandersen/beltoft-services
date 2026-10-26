<?php
/** Offline authoritative DNS state. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class FakeDns implements Dns {
	private array $zones = array();
	private array $calls = array();

	public function calls(): array { return $this->calls; }
	public function zone( string $name ): array|\WP_Error|null { return $this->zones[ strtolower( rtrim( $name, '.' ) ) ] ?? null; }
	public function create_zone( string $name, array $nameservers ): true|\WP_Error {
		$name = strtolower( rtrim( $name, '.' ) );
		$this->calls[] = array( 'create_zone', $name, $nameservers );
		if ( isset( $this->zones[ $name ] ) ) { return new \WP_Error( 'zone_exists', 'Zone already exists.' ); }
		$this->zones[ $name ] = array( 'name' => $name . '.', 'nameservers' => $nameservers, 'rrsets' => array(), 'secured' => false );
		return true;
	}
	public function delete_zone( string $name ): true|\WP_Error {
		$name = strtolower( rtrim( $name, '.' ) );
		$this->calls[] = array( 'delete_zone', $name );
		unset( $this->zones[ $name ] );
		return true;
	}
	public function replace_rrsets( string $zone, array $rrsets ): true|\WP_Error {
		$zone = strtolower( rtrim( $zone, '.' ) );
		$this->calls[] = array( 'replace_rrsets', $zone, $rrsets );
		if ( ! isset( $this->zones[ $zone ] ) ) { return new \WP_Error( 'zone_missing', 'Zone does not exist.' ); }
		$this->zones[ $zone ]['rrsets'] = $rrsets;
		return true;
	}
	public function secure( string $zone ): array|\WP_Error {
		$zone = strtolower( rtrim( $zone, '.' ) );
		$this->calls[] = array( 'secure', $zone );
		if ( ! isset( $this->zones[ $zone ] ) ) { return new \WP_Error( 'zone_missing', 'Zone does not exist.' ); }
		$this->zones[ $zone ]['secured'] = true;
		return array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'FAKE-DNSKEY' ) );
	}
}
