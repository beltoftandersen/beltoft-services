<?php
/**
 * Square, radio-based tiles for the operating system and the app, with local logos.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Account\Servers;

defined( 'ABSPATH' ) || exit;

/**
 * Every tile is a real radio input (visually hidden, still focusable), so the keyboard, screen readers, the form post and
 * the server-side validation work as they did with a select. The script (assets/tiles.js) only adds what a select could
 * not do: an app switches the operating system to the one it runs on and greys out the others, and a version select
 * appears for an app with more than one version. Logos are local SVG files of this plugin: assets/os/<slug>.svg for a
 * system, the file named by "logo" in an app's app.json for an app, assets/os/generic.svg for anything else.
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
		if ( ! is_string( $parent ) || ( is_string( $endpoint ) && Servers::slug() !== $endpoint ) || ( Servers::requested()['id'] <= 0 && ! Servers::is_new() ) ) {
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
	 * Reads one of the plugin's own SVG files for inline use: the colour follows the text. Nothing else is accepted: a
	 * file under assets/os or an app's folder, small, with no script, event handler, link or embedded object.
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

		if ( false === $real || ! str_starts_with( wp_normalize_path( $real ), $root ) || filesize( $real ) > 8192 ) {
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
	 * The logo of an operating system: by family and label, a filter may name another logo (a file name without .svg in assets/os).
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
		 * @param string $slug   File name (without .svg) in assets/os; empty for the generic icon.
		 * @param int    $vmid   Template VMID.
		 * @param string $label  Label of the template.
		 * @param string $family Family, or empty.
		 */
		$slug = apply_filters( 'beltoft_services_os_logo', $slug, $vmid, $label, $family );
		$slug = is_string( $slug ) && 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ? $slug : '';
		$svg  = '' === $slug ? '' : self::svg( BELTOFT_SERVICES_PATH . 'assets/os/' . $slug . '.svg' );

		return '' !== $svg ? $svg : self::svg( BELTOFT_SERVICES_PATH . 'assets/os/generic.svg' );
	}

	/**
	 * The logo of an app, from the "logo" file name in its app.json (in the app's own folder).
	 *
	 * @param array<string, mixed> $app Definition.
	 * @return string SVG markup.
	 */
	public static function app_logo( array $app ): string {
		$id   = (string) ( $app['id'] ?? '' );
		$name = (string) ( $app['logo'] ?? '' );
		$svg  = 1 === preg_match( '/^[a-z0-9-]+$/', $id ) && 1 === preg_match( '/^[a-zA-Z0-9._-]+\.svg$/', $name ) ? self::svg( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $name ) : '';

		return '' !== $svg ? $svg : self::svg( BELTOFT_SERVICES_PATH . 'assets/os/generic.svg' );
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

		if ( 1 === preg_match( '/^(.+?)\s+(\d[\w.]*(?:\s+LTS)?)$/i', $label, $m ) ) {
			/* translators: %s: version of an operating system, such as 24.04 */
			return array( $m[1], sprintf( __( 'Version %s', 'beltoft-services' ), $m[2] ) );
		}

		return array( $label, '' );
	}

	/**
	 * The tiles of the operating systems a cluster lists.
	 *
	 * @param string             $cluster  Cluster id.
	 * @param array<int, string> $options  VMID => label.
	 * @param int                $selected VMID checked first.
	 * @return array<int, array<string, mixed>>
	 */
	public static function os_items( string $cluster, array $options, int $selected ): array {
		$families = (array) ( Clusters::get( $cluster )['template_families'] ?? array() );
		$items    = array();

		foreach ( $options as $vmid => $label ) {
			$parts   = self::os_parts( (string) $label );
			$items[] = array(
				'value'   => (string) $vmid,
				'name'    => $parts[0],
				'line'    => $parts[1],
				'logo'    => self::os_logo( (int) $vmid, (string) $label, (string) ( $families[ (int) $vmid ] ?? '' ) ),
				'checked' => (int) $vmid === $selected,
			);
		}

		return $items;
	}

	/**
	 * Prints the operating system tiles with the line that explains why they are greyed out while an app is chosen.
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
				'note'   => __( 'An app runs on Ubuntu 24.04, so the other systems are off while an app is chosen.', 'beltoft-services' ),
			)
		);
	}

	// -- The group -----------------------------------------------------------------------------------

	/**
	 * Prints a group of tiles.
	 *
	 * Group keys: kind (os or app), id, name (the radio input name), legend, items, note (a line the script shows when the group is locked), attrs (data attributes of the group).
	 * Item keys: value, name, line, logo (SVG markup from this class), checked, disabled, why (the line for a disabled tile), attrs (data attributes of the radio).
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

			if ( '' !== (string) ( $item['line'] ?? '' ) ) {
				echo '<span class="beltoft-services-tile__line">' . esc_html( (string) $item['line'] ) . '</span>';
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
			if ( 1 === preg_match( '/^data-[a-z0-9-]+$/', (string) $name ) ) {
				echo ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
			}
		}
	}
}
