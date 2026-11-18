<?php
/**
 * Square, radio-based tiles for the operating system and the app, with local logos.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Vm;

use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Proxmox\Pve\Clusters;

defined( 'ABSPATH' ) || exit;

/**
 * Every tile is a real radio input (visually hidden, still focusable), so the keyboard, screen readers, the form post and
 * the server-side validation work as they did with a select. The script (assets/tiles.js) only adds what a select could
 * not do: an app switches the operating system to the one it runs on and greys out the others, and a version select
 * appears for an app with more than one version. Logos are local SVG files of this plugin: assets/logos/<slug>.svg for all of
 * them (the one place of logo files): the slug of a system, of a database engine (its id) and of an app ("logo" in its app.json), generic for anything else.
 */
final class Tiles {

	public const HANDLE = 'beltoft-services-tiles';

	/**
	 * Hooks: the style and script on checkout and on a server's page, nowhere else.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_assets' ) );
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'page_assets' ), 15, 2 );
	}

	/**
	 * The checkout, when it prints the operating system tiles.
	 */
	public static function checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! OsField::cart_needs_choice() ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * The page of one server (its Reinstall tab has the tiles) and the New server page.
	 *
	 * @param mixed $parent   Handle of the generic account script.
	 * @param mixed $endpoint The endpoint being shown.
	 */
	public static function page_assets( $parent, $endpoint = null ): void {
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && ServerLinks::slug() !== $endpoint ) || ( ServerLinks::requested()['id'] <= 0 && ! ServerLinks::is_new() ) ) {
			return;
		}

		self::enqueue();
	}

	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/tiles.css', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/tiles.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/tiles.js', BELTOFT_SERVICES_FILE ), array(), \BeltoftServices\Assets::version( 'assets/tiles.js' ), true );
	}

	// -- Logos ---------------------------------------------------------------------------------

	/**
	 * Reads one of the plugin's own SVG files for inline use (brand colours are written in the files; the generic glyph
	 * follows the text). Nothing else is accepted: a file under assets/logos, small, with no script, event handler, link or embedded object.
	 *
	 * @param string $file Absolute path.
	 */
	private static function svg( string $file ): string {
		static $cache = array();

		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}

		$cache[ $file ] = '';
		$root           = wp_normalize_path( BELTOFT_SERVICES_PATH );
		$real           = is_readable( $file ) ? realpath( $file ) : false;

		if ( false === $real || ! str_starts_with( wp_normalize_path( $real ), $root . 'assets/logos/' ) || filesize( $real ) > 12288 ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of this plugin.
		$svg = (string) file_get_contents( $real );
		$svg = (string) preg_replace( '/<!--.*?-->/s', '', $svg );
		$svg = trim( $svg );

		if ( 0 !== strpos( $svg, '<svg' ) || preg_match( '/<(script|foreignObject|iframe|image|use|a)\b|\son[a-z]+\s*=|javascript:|href\s*=/i', $svg ) ) {
			return '';
		}

		$cache[ $file ] = (string) preg_replace( '/^<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1 );

		return $cache[ $file ];
	}

	/**
	 * The inline markup of the logo with this slug (assets/logos/<slug>.svg), the generic glyph when the slug is empty,
	 * not a plain slug or has no file.
	 *
	 * @param string $slug Slug, such as ubuntu, odoo or postgres.
	 * @return string SVG markup (the plugin's own file).
	 */
	private static function logo( string $slug ): string {
		$svg = 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? self::svg( BELTOFT_SERVICES_PATH . 'assets/logos/' . $slug . '.svg' ) : '';

		return '' !== $svg ? $svg : self::svg( BELTOFT_SERVICES_PATH . 'assets/logos/generic.svg' );
	}

	/**
	 * The URL of a logo file by slug, so a page can show the same file as an image: empty for an unknown slug. Every file
	 * has explicit fills, a viewBox and xmlns, so it also works as an img (the generic glyph is the one exception: it takes the text colour).
	 *
	 * @param string $slug Slug, such as ubuntu, odoo or postgres.
	 * @return string URL, or empty.
	 */
	public static function logo_url( string $slug ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/D', $slug ) || ! is_readable( BELTOFT_SERVICES_PATH . 'assets/logos/' . $slug . '.svg' ) ) {
			return '';
		}

		return plugins_url( 'assets/logos/' . $slug . '.svg', BELTOFT_SERVICES_FILE );
	}

	/**
	 * The logo of an operating system: by family and label, a filter may name another logo (a slug in assets/logos).
	 *
	 * @param int    $vmid   Template VMID.
	 * @param string $label  Label of the template.
	 * @param string $family Family of the template (such as ubuntu-24.04), or empty.
	 * @return string SVG markup (the plugin's own file).
	 */
	public static function os_logo( int $vmid, string $label, string $family ): string {
		$haystack = strtolower( $family . ' ' . $label );
		$slug     = '';

		foreach ( array( 'ubuntu' => 'ubuntu', 'debian' => 'debian', 'alma' => 'almalinux' ) as $needle => $name ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				$slug = $name;
				break;
			}
		}

		/**
		 * The logo of an operating system tile.
		 *
		 * @param string $slug   Slug in assets/logos; empty for the generic icon.
		 * @param int    $vmid   Template VMID.
		 * @param string $label  Label of the template.
		 * @param string $family Family, or empty.
		 */
		$slug = apply_filters( 'beltoft_services_os_logo', $slug, $vmid, $label, $family );
		$slug = is_string( $slug ) && 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : '';

		return self::logo( $slug );
	}

	/**
	 * The logo of an app: the slug in the "logo" field of its app.json (a file of assets/logos).
	 *
	 * @param array<string, mixed> $app Definition.
	 * @return string SVG markup.
	 */
	public static function app_logo( array $app ): string {
		return self::logo( (string) ( $app['logo'] ?? '' ) );
	}

	/**
	 * The logo of a database engine (assets/logos/<id>.svg), the generic glyph for an engine without one.
	 *
	 * @param string $id Engine id, such as postgres, valkey or mssql.
	 * @return string SVG markup (the plugin's own file).
	 */
	public static function engine_logo( string $id ): string {
		$slug = 1 === preg_match( '/^[a-z0-9-]+$/D', $id ) ? $id : '';

		/**
		 * The logo of a database engine tile.
		 *
		 * @param string $slug Slug in assets/logos; empty for the generic icon.
		 * @param string $id   Engine id.
		 */
		$slug = apply_filters( 'beltoft_services_engine_logo', $slug, $id );
		$slug = is_string( $slug ) && 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : '';

		return self::logo( $slug );
	}

	// -- The operating system tiles ------------------------------------------------------------------

	/**
	 * A template's label split into a name and a short line: "Ubuntu 24.04" is Ubuntu and "Version 24.04".
	 *
	 * @param string $label Label.
	 * @return array{0: string, 1: string}
	 */
	public static function os_parts( string $label ): array {
		$label = trim( $label );

		if ( 1 === preg_match( '/^(.+?)\s+(\d[\w.]*(?:\s+LTS)?)$/iD', $label, $m ) ) {
			/* translators: %s: version of an operating system, such as 24.04 */
			return array( $m[1], sprintf( __( 'Version %s', 'beltoft-services' ), $m[2] ) );
		}

		return array( $label, '' );
	}

	/**
	 * The tiles of the operating systems a cluster lists: one tile per family (Ubuntu, Debian, AlmaLinux), with the
	 * versions of that family in a select inside the tile. The tile's radio carries the VMID of the version chosen, so
	 * the posted field and value are the same as with one tile per version. Newest version first in a family; the family
	 * of the system checked shows that system, any other family shows its newest version. Tile order is the order of the
	 * first template of each family in $options. A family with one version has no select.
	 *
	 * @param string             $cluster  Cluster id.
	 * @param array<int, string> $options  VMID => label.
	 * @param int                $selected VMID checked first.
	 * @return array<int, array<string, mixed>>
	 */
	public static function os_items( string $cluster, array $options, int $selected ): array {
		$families = (array) ( Clusters::get( $cluster )['template_families'] ?? array() );
		$groups   = array();

		foreach ( $options as $vmid => $label ) {
			$parts = self::os_parts( (string) $label );
			$key   = strtolower( '' !== $parts[1] ? $parts[0] : (string) $label );

			$groups[ $key ][] = array(
				'value'   => (string) $vmid,
				'label'   => (string) $label,
				'name'    => $parts[0],
				'line'    => $parts[1],
				'family'  => (string) ( $families[ (int) $vmid ] ?? '' ),
				'version' => 1 === preg_match( '/(\d[\w.]*)(?:\s+LTS)?$/iD', trim( (string) $label ), $m ) ? $m[1] : '',
			);
		}

		$items = array();

		foreach ( $groups as $versions ) {
			// Newest first; equal versions keep their order.
			$order = array_keys( $versions );
			usort(
				$order,
				static function ( $a, $b ) use ( $versions ): int {
					return version_compare( $versions[ $b ]['version'], $versions[ $a ]['version'] ) ?: $a <=> $b;
				}
			);

			$shown = $versions[ $order[0] ];

			foreach ( $versions as $entry ) {
				if ( (int) $entry['value'] === $selected ) {
					$shown = $entry;
				}
			}

			$attrs = array( 'data-family' => $shown['family'] );

			if ( count( $versions ) > 1 ) {
				$list = array();

				foreach ( $order as $i ) {
					$list[] = array(
						'value'  => $versions[ $i ]['value'],
						'label'  => $versions[ $i ]['label'],
						'short'  => trim( substr( $versions[ $i ]['label'], strlen( $versions[ $i ]['name'] ) ) ),
						'line'   => $versions[ $i ]['line'],
						'family' => $versions[ $i ]['family'],
					);
				}

				$attrs['data-versions'] = (string) wp_json_encode( $list );
			}

			$items[] = array(
				'value'   => $shown['value'],
				'name'    => $shown['name'],
				'line'    => $shown['line'],
				'logo'    => self::os_logo( (int) $shown['value'], $shown['label'], $shown['family'] ),
				'checked' => (int) $shown['value'] === $selected,
				'attrs'   => $attrs,
			);
		}

		return $items;
	}

	/**
	 * Tiles for a list of choices that a supplier names (the distributions of a dedicated server, the images of its installer): one
	 * tile per family with the versions of that family in a select inside, as os_items() does for the templates. The value of a tile is
	 * the exact name the supplier lists; nothing is mapped or rewritten. Newest version first in a family; the family of the value
	 * checked shows that value, any other family its newest. Tile order is the order of the first entry of each family.
	 *
	 * @param array<int, array{value: string, family: string, short: string}> $entries  value (the exact name), family (the tile name), short (the text in the select).
	 * @param string                                                           $selected Value checked first, or empty.
	 * @return array<int, array<string, mixed>>
	 */
	public static function family_items( array $entries, string $selected ): array {
		$groups = array();
		foreach ( $entries as $entry ) {
			$groups[ strtolower( $entry['family'] ) ][] = $entry;
		}
		$items = array();
		foreach ( $groups as $versions ) {
			$order = array_keys( $versions );
			usort(
				$order,
				static function ( $a, $b ) use ( $versions ): int {
					$va = preg_match( '/\d[\d.]*/', $versions[ $a ]['short'], $ma ) ? $ma[0] : '0';
					$vb = preg_match( '/\d[\d.]*/', $versions[ $b ]['short'], $mb ) ? $mb[0] : '0';
					return version_compare( $vb, $va ) ?: $a <=> $b;
				}
			);
			$shown = $versions[ $order[0] ];
			foreach ( $versions as $entry ) {
				if ( $entry['value'] === $selected ) {
					$shown = $entry;
				}
			}
			$attrs = array();
			if ( count( $versions ) > 1 ) {
				$list = array();
				foreach ( $order as $i ) {
					$list[] = array(
						'value'  => $versions[ $i ]['value'],
						'label'  => $versions[ $i ]['family'] . ' ' . $versions[ $i ]['short'],
						'short'  => $versions[ $i ]['short'],
						'line'   => '',
						'family' => '',
					);
				}
				$attrs['data-versions'] = (string) wp_json_encode( $list );
			}
			$items[] = array(
				'value'   => $shown['value'],
				'name'    => $shown['family'],
				'line'    => '' !== $shown['short'] ? $shown['short'] : '',
				'logo'    => self::os_logo( 0, $shown['family'], '' ),
				'checked' => $shown['value'] === $selected,
				'attrs'   => $attrs,
			);
		}
		return $items;
	}

	/**
	 * Prints the operating system tiles. Each carries its family, so the app tiles' script can tell which apps run on it.
	 *
	 * @param string             $cluster  Cluster id.
	 * @param array<int, string> $options  VMID => label.
	 * @param int                $selected VMID checked first.
	 * @param string             $name     Input name.
	 * @param string             $id       Id of the group.
	 */
	public static function print_os( string $cluster, array $options, int $selected, string $name, string $id ): void {
		self::print_group(
			array(
				'kind'   => 'os',
				'id'     => $id,
				'name'   => $name,
				'legend' => __( 'Operating system', 'beltoft-services' ),
				'items'  => self::os_items( $cluster, $options, $selected ),
			)
		);
	}

	// -- The group -----------------------------------------------------------------------------------

	/**
	 * Prints a group of tiles.
	 *
	 * Group keys: kind (os or app), id, name (the radio input name), legend, items, note (a line the script shows when the group is locked), attrs (data attributes of the group).
	 * Item keys: value, name, line, runs (the operating systems an app runs on), logo (SVG markup from this class), checked, disabled, why (the line for a disabled tile), attrs (data attributes of the radio).
	 *
	 * @param array<string, mixed> $group Group.
	 */
	public static function print_group( array $group ): void {
		$kind = 'app' === ( $group['kind'] ?? '' ) ? 'app' : 'os';
		$id   = (string) ( $group['id'] ?? '' );

		echo '<fieldset class="beltoft-services-tiles beltoft-services-tiles--' . esc_attr( $kind ) . '" data-tiles="' . esc_attr( $kind ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' );
		self::print_attributes( (array) ( $group['attrs'] ?? array() ) );
		echo '>';
		echo '<legend class="beltoft-services-tiles__legend">' . esc_html( (string) ( $group['legend'] ?? '' ) ) . '</legend>';
		echo '<div class="beltoft-services-tiles__grid">';

		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$disabled = ! empty( $item['disabled'] );

			echo '<label class="beltoft-services-tile' . ( $disabled ? ' is-disabled' : '' ) . '">';
			echo '<input class="beltoft-services-tile__input" type="radio" name="' . esc_attr( (string) ( $group['name'] ?? '' ) ) . '" value="' . esc_attr( (string) $item['value'] ) . '"';
			checked( ! empty( $item['checked'] ), true );
			disabled( $disabled, true );
			self::print_attributes( (array) ( $item['attrs'] ?? array() ) );
			echo '>';
			echo '<span class="beltoft-services-tile__face">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG read and checked by self::svg() from the plugin's own files.
			echo '<span class="beltoft-services-tile__logo">' . ( $item['logo'] ?? '' ) . '</span>';
			echo '<span class="beltoft-services-tile__name">' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</span>';

			$versions = ! empty( $item['attrs']['data-versions'] ) ? json_decode( (string) $item['attrs']['data-versions'], true ) : array();

			if ( 'os' === $kind && is_array( $versions ) && count( $versions ) > 1 ) {
				// A family with several versions: a select in the tile where the version line would be. It has no name, the script writes the version into the tile's value.
				echo '<select class="beltoft-services-tile__version" data-tiles-os-version aria-label="' . esc_attr( trim( (string) ( $item['name'] ?? '' ) . ' ' . __( 'Version', 'beltoft-services' ) ) ) . '">';

				foreach ( $versions as $entry ) {
					echo '<option value="' . esc_attr( (string) $entry['value'] ) . '"' . selected( (string) $entry['value'], (string) $item['value'], false ) . '>' . esc_html( (string) $entry['short'] ) . '</option>';
				}

				echo '</select>';
			} elseif ( '' !== (string) ( $item['line'] ?? '' ) ) {
				echo '<span class="beltoft-services-tile__line">' . esc_html( (string) $item['line'] ) . '</span>';
			}

			if ( '' !== (string) ( $item['runs'] ?? '' ) ) {
				echo '<span class="beltoft-services-tile__line beltoft-services-tile__runs">' . esc_html( (string) $item['runs'] ) . '</span>';
			}

			if ( $disabled && '' !== (string) ( $item['why'] ?? '' ) ) {
				echo '<span class="beltoft-services-tile__why">' . esc_html( (string) $item['why'] ) . '</span>';
			}

			echo '</span></label>';
		}

		echo '</div>';

		if ( '' !== (string) ( $group['note'] ?? '' ) ) {
			echo '<p class="beltoft-services-tiles__note description" data-tiles-note hidden>' . esc_html( (string) $group['note'] ) . '</p>';
		}

		echo '</fieldset>';
	}

	/**
	 * Prints data attributes (names are fixed by the callers and checked here, values are escaped).
	 *
	 * @param array<string, string> $attrs Name => value.
	 */
	private static function print_attributes( array $attrs ): void {
		foreach ( $attrs as $name => $value ) {
			if ( 1 === preg_match( '/^data-[a-z0-9-]+$/D', (string) $name ) ) {
				echo ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
			}
		}
	}
}
