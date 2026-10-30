<?php
/** Authoritative DNS API contract. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

interface Dns {
	/** @return array|\WP_Error Zone summaries visible to this server. */
	public function zones(): array|\WP_Error;
	public function zone( string $name ): array|\WP_Error|null;
	public function create_zone( string $name, array $nameservers ): bool|\WP_Error;
	public function delete_zone( string $name ): bool|\WP_Error;
	public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error;
	/** @return array|\WP_Error DNSKEY entries in registrar format. */
	public function secure( string $zone ): array|\WP_Error;
}
