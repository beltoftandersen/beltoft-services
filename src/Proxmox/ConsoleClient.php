<?php
/** Build a console client without exposing the cluster's main API credential. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

defined( 'ABSPATH' ) || exit;

final class ConsoleClient {
	/** A live console always uses its separate, least-privilege token. */
	public static function for( array $cluster ): Client {
		$live = 'live' === ( $cluster['client'] ?? 'fake' );
		if ( $live && ! self::configured( $cluster ) ) {
			throw new \RuntimeException( 'A separate console token is required.' );
		}
		$console = $cluster;
		unset( $console['console_token_id'], $console['console_token_secret'] );
		if ( $live ) {
			$console['token_id'] = $cluster['console_token_id'];
			$console['token_secret'] = $cluster['console_token_secret'];
		}
		return Clients::for( $console );
	}

	/** A copy of the main token is not a dedicated console credential. */
	public static function configured( array $cluster ): bool {
		$id = (string) ( $cluster['console_token_id'] ?? '' );
		$secret = (string) ( $cluster['console_token_secret'] ?? '' );
		return '' !== $id && '' !== $secret && $id !== (string) ( $cluster['token_id'] ?? '' );
	}
}
