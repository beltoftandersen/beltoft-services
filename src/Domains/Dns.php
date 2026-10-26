<?php
/** Authoritative DNS API contract. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

interface Dns {
	public function zone( string $name ): array|\WP_Error|null;
	public function create_zone( string $name, array $nameservers ): true|\WP_Error;
	public function delete_zone( string $name ): true|\WP_Error;
	public function replace_rrsets( string $zone, array $rrsets ): true|\WP_Error;
	/** @return array|\WP_Error DNSKEY entries in registrar format. */
	public function secure( string $zone ): array|\WP_Error;
}
