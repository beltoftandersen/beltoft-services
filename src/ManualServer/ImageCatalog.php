<?php
/**
 * The images the installer of a dedicated server offers for an automatic reinstall.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Documentation: the installimage page (docs.hetzner.com/robot/dedicated-server/operating-systems/installimage/) says images are in the rescue
 * system ("OS Choices"; older ones in the folder old_images) and gives no list; the installimage sources (config.sh IMAGESPATH, the example
 * config `IMAGE /root/images/Debian-stable-amd64-base.tar.gz`, get_options.sh "supported image types: tar,tar.gz,tar.bz,tar.bz2,tar.xz,tar.zst,tgz,tbz,txz")
 * name the folder and the file types. So the list is the content of /root/images in a rescue system. It is kept in an option with the time it
 * was read, refreshed by every reinstall that boots a rescue system, and seeded by the setting `dedicated_images` (one file name per line).
 * A customer picks a file name from this list and the job checks it against the live listing of the rescue system before anything is erased.
 * The value posted and used in the IMAGE line is the exact file name.
 */
final class ImageCatalog {

	public const OPTION = 'beltoft_services_dedicated_images';

	/**
	 * File types installimage supports (get_options.sh, -i).
	 */
	private const EXT = '/\.(tar|tar\.gz|tar\.bz|tar\.bz2|tar\.xz|tar\.zst|tgz|tbz|txz)$/D';

	/**
	 * The names to offer: the last listing of a rescue system, else the setting.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		$state = get_option( self::OPTION, array() );
		$names = is_array( $state ) && is_array( $state['names'] ?? null ) ? array_map( 'strval', $state['names'] ) : array();

		if ( ! $names ) {
			$names = self::clean( preg_split( '/\R/', (string) Settings::get( 'dedicated_images' ) ) ?: array() );
		}

		return $names;
	}

	/**
	 * Keeps the listing of a rescue system.
	 *
	 * @param string[] $names File names (from `ls /root/images`).
	 */
	public static function remember( array $names ): void {
		$names = self::clean( $names );

		if ( $names ) {
			update_option( self::OPTION, array( 'at' => time(), 'names' => $names ), false );
		}
	}

	/**
	 * Does the catalog offer this exact name?
	 *
	 * @param string $name File name.
	 */
	public static function offers( string $name ): bool {
		return in_array( $name, self::names(), true );
	}

	/**
	 * Only image files with a plain name (no path, no signature file), sorted, without duplicates.
	 *
	 * @param array<int, mixed> $names Candidates.
	 * @return string[]
	 */
	public static function clean( array $names ): array {
		$out = array();

		foreach ( $names as $name ) {
			$name = trim( (string) $name );

			if ( 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/D', $name ) && 1 === preg_match( self::EXT, $name ) ) {
				$out[ $name ] = true;
			}
		}

		$out = array_keys( $out );
		sort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * Tile entries: the family is the first word of the file name (with "Stream" or "Linux" kept when it follows), the select shows the
	 * rest without the architecture, "base" and the file type. The value stays the whole file name.
	 *
	 * @param string[] $names File names.
	 * @return array<int, array{value: string, family: string, short: string}>
	 */
	public static function entries( array $names ): array {
		$out = array();

		foreach ( $names as $name ) {
			$stem = (string) preg_replace( self::EXT, '', $name );
			$tok  = explode( '-', $stem );
			$fam  = array( (string) array_shift( $tok ) );

			if ( $tok && in_array( strtolower( $tok[0] ), array( 'stream', 'linux' ), true ) ) {
				$fam[] = array_shift( $tok );
			}

			$tok = array_values( array_filter( $tok, static fn( string $t ): bool => ! in_array( strtolower( $t ), array( 'amd64', 'arm64', 'x86_64', 'base' ), true ) ) );

			$out[] = array( 'value' => $name, 'family' => implode( ' ', $fam ), 'short' => $tok ? implode( ' ', $tok ) : $name );
		}

		return $out;
	}
}
