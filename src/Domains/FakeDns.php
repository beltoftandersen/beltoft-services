<?php
/** Offline authoritative DNS state. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

class FakeDns implements Dns {
	public const OPTION = 'beltoft_services_fake_dns';
	private array $zones = array();
	private array $calls = array();
	private bool $persist;

	public function __construct( bool $persist = false ) {
		$this->persist = $persist;
		$this->load();
	}
	private function load(): void {
		if ( $this->persist ) { $saved = get_option( self::OPTION, array() ); $this->zones = is_array( $saved ) ? $saved : array(); }
	}
	private function save(): void {
		if ( $this->persist ) {
			if ( false === get_option( self::OPTION, false ) ) { add_option( self::OPTION, $this->zones, '', false ); }
			else { update_option( self::OPTION, $this->zones, false ); }
		}
	}

	public function calls(): array { return $this->calls; }
	public function zone( string $name ): array|\WP_Error|null { $this->load(); return $this->zones[ strtolower( rtrim( $name, '.' ) ) ] ?? null; }
	public function zones(): array|\WP_Error { $this->load(); return array_values( $this->zones ); }
	public function create_zone( string $name, array $nameservers ): true|\WP_Error {
		$this->load();
		$name = strtolower( rtrim( $name, '.' ) );
		$this->calls[] = array( 'create_zone', $name, $nameservers );
		if ( isset( $this->zones[ $name ] ) ) { return new \WP_Error( 'zone_exists', 'Zone already exists.' ); }
		$this->zones[ $name ] = array( 'name' => $name . '.', 'nameservers' => $nameservers, 'rrsets' => array(), 'secured' => false, 'serial' => 1 );
		$this->save();
		return true;
	}
	public function delete_zone( string $name ): true|\WP_Error {
		$this->load();
		$name = strtolower( rtrim( $name, '.' ) );
		$this->calls[] = array( 'delete_zone', $name );
		unset( $this->zones[ $name ] );
		$this->save();
		return true;
	}
	public function replace_rrsets( string $zone, array $rrsets ): true|\WP_Error {
		$this->load();
		$zone = strtolower( rtrim( $zone, '.' ) );
		$this->calls[] = array( 'replace_rrsets', $zone, $rrsets );
		if ( ! isset( $this->zones[ $zone ] ) ) { return new \WP_Error( 'zone_missing', 'Zone does not exist.' ); }
		$sets = array();
		foreach ( $this->zones[ $zone ]['rrsets'] as $old ) { $sets[ ( $old['name'] ?? '' ) . '|' . ( $old['type'] ?? '' ) ] = $old; }
		foreach ( $rrsets as $set ) {
			$key = ( $set['name'] ?? '' ) . '|' . ( $set['type'] ?? '' );
			if ( 'DELETE' === ( $set['changetype'] ?? '' ) ) { unset( $sets[ $key ] ); }
			else { $sets[ $key ] = $set; }
		}
		$this->zones[ $zone ]['rrsets'] = array_values( $sets );
		$this->zones[ $zone ]['serial'] = (int) ( $this->zones[ $zone ]['serial'] ?? 1 ) + 1;
		$this->save();
		return true;
	}
	public function secure( string $zone ): array|\WP_Error {
		$this->load();
		$zone = strtolower( rtrim( $zone, '.' ) );
		$this->calls[] = array( 'secure', $zone );
		if ( ! isset( $this->zones[ $zone ] ) ) { return new \WP_Error( 'zone_missing', 'Zone does not exist.' ); }
		$this->zones[ $zone ]['secured'] = true;
		$this->save();
		return array( array( 'flags' => 257, 'protocol' => 3, 'alg' => 13, 'pub_key' => 'FAKE-DNSKEY' ) );
	}
}
