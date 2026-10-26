<?php
/** Registrar API contract. @package BeltoftServices */
namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

interface Client {
	/** @return array|\WP_Error Decoded data member or an error. */
	public function request( string $method, string $path, array $body = array(), array $query = array() ): array|\WP_Error;
}
