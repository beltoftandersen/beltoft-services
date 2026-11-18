<?php
/**
 * The operating system and app tiles: logos, markup and where the assets load.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Proxmox\Vm\Tiles;
use BeltoftServices\Tests\TestCase;

class TilesTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_os_logo' );
		remove_all_filters( 'beltoft_services_engine_logo' );
		remove_all_filters( 'beltoft_services_apps' );
		array_map( 'unlink', glob( BELTOFT_SERVICES_PATH . 'assets/logos/evil*.svg' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test files.
		parent::tear_down();
	}

	public function test_a_label_is_split_into_a_name_and_a_version_line(): void {
		$this->assertSame( array( 'Ubuntu', 'Version 24.04' ), Tiles::os_parts( 'Ubuntu 24.04' ) );
		$this->assertSame( array( 'Debian', 'Version 12' ), Tiles::os_parts( 'Debian 12' ) );
		$this->assertSame( array( 'AlmaLinux', 'Version 9' ), Tiles::os_parts( 'AlmaLinux 9' ) );
		$this->assertSame( array( 'Ubuntu', 'Version 26.04 LTS' ), Tiles::os_parts( 'Ubuntu 26.04 LTS' ) );
		$this->assertSame( array( 'Custom image', '' ), Tiles::os_parts( 'Custom image' ) );
	}

	public function test_each_family_has_its_logo_and_an_unknown_system_a_neutral_one(): void {
		$ubuntu = Tiles::os_logo( 9001, 'Ubuntu 24.04', 'ubuntu-24.04' );
		$debian = Tiles::os_logo( 9000, 'Debian 12', '' );
		$alma   = Tiles::os_logo( 9002, 'AlmaLinux 9', 'almalinux-9' );
		$other  = Tiles::os_logo( 9003, 'Something else', '' );

		foreach ( array( $ubuntu, $debian, $alma, $other ) as $svg ) {
			$this->assertStringStartsWith( '<svg aria-hidden="true" focusable="false"', $svg );
		}

		$this->assertCount( 4, array_unique( array( $ubuntu, $debian, $alma, $other ) ), 'Four different marks.' );
		$this->assertSame( $other, Tiles::os_logo( 9004, 'Plan 9', '' ), 'The generic icon.' );
		$this->assertSame( $ubuntu, Tiles::os_logo( 9005, 'Server', 'ubuntu-22.04' ), 'By family when the label says nothing.' );
	}

	public function test_a_filter_can_name_the_logo_and_a_bad_name_falls_back_to_the_generic_one(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );

		add_filter( 'beltoft_services_os_logo', static fn() => 'debian' );
		$this->assertSame( Tiles::os_logo( 9000, 'Debian 12', '' ), Tiles::os_logo( 1, 'Nothing', '' ) );

		remove_all_filters( 'beltoft_services_os_logo' );
		add_filter( 'beltoft_services_os_logo', static fn() => '../../wp-config' );
		$this->assertSame( $generic, Tiles::os_logo( 1, 'Nothing', '' ) );

		remove_all_filters( 'beltoft_services_os_logo' );
		add_filter( 'beltoft_services_os_logo', static fn() => 'does-not-exist' );
		$this->assertSame( $generic, Tiles::os_logo( 1, 'Nothing', '' ) );
	}

	public function test_every_shipped_logo_is_accepted_and_carries_its_own_colour(): void {
		$files = glob( BELTOFT_SERVICES_PATH . 'assets/logos/*.svg' );
		$this->assertCount( 16, $files );
		$read = new \ReflectionMethod( Tiles::class, 'svg' );
		$read->setAccessible( true );

		foreach ( $files as $file ) {
			$svg = $read->invoke( null, $file );
			$this->assertNotSame( '', $svg, basename( $file ) . ' passes the safety check.' );
			$this->assertMatchesRegularExpression( '/^<svg aria-hidden="true" focusable="false" [^>]*viewBox="[^"]+"/', $svg, basename( $file ) . ' has a viewBox.' );
			$this->assertStringContainsString( 'xmlns="http://www.w3.org/2000/svg"', $svg, basename( $file ) . ' works as an img.' );

			if ( 'generic.svg' !== basename( $file ) ) {
				$this->assertDoesNotMatchRegularExpression( '/currentColor/', $svg, $file . ' has its colour written in the file.' );
				$this->assertMatchesRegularExpression( '/fill="#[0-9a-fA-F]{6}"/', $svg, $file );
			}
		}
	}

	public function test_each_engine_has_its_logo_and_an_unknown_one_the_generic_glyph(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );
		$logos   = array();

		foreach ( array( 'postgres', 'valkey', 'mssql' ) as $id ) {
			$logos[ $id ] = Tiles::engine_logo( $id );
			$this->assertStringStartsWith( '<svg aria-hidden="true" focusable="false"', $logos[ $id ], $id );
			$this->assertNotSame( $generic, $logos[ $id ], $id );
		}

		$this->assertCount( 3, array_unique( $logos ) );
		$this->assertSame( $generic, Tiles::engine_logo( 'oracle' ) );
		$this->assertSame( $generic, Tiles::engine_logo( '../x' ) );
	}

	public function test_a_filter_can_name_another_engine_logo_and_a_bad_name_is_ignored(): void {
		add_filter( 'beltoft_services_engine_logo', static fn() => 'valkey' );
		$this->assertSame( Tiles::engine_logo( 'postgres' ), Tiles::engine_logo( 'oracle' ) );
		$this->assertStringContainsString( '#6983FF', Tiles::engine_logo( 'oracle' ) );

		remove_all_filters( 'beltoft_services_engine_logo' );
		add_filter( 'beltoft_services_engine_logo', static fn() => '../../wp-config' );
		$this->assertSame( Tiles::os_logo( 1, 'Nothing', '' ), Tiles::engine_logo( 'postgres' ) );
	}

	public function test_a_selected_tile_is_light_and_keeps_the_colours_of_its_logo(): void {
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.css' );
		$this->assertStringNotContainsString( 'fill: currentColor', $css, 'The logo keeps its brand colours on the selected tile.' );
		$this->assertMatchesRegularExpression( '/__input:checked \+ \.beltoft-services-tile__face[^{]*\{ background: var\(--bs-surface, #fff\);/', $css );
	}

	public function test_the_apps_name_their_logo_in_app_json_and_the_shipped_ones_exist(): void {
		foreach ( array( 'docker', 'wordpress', 'coolify', 'n8n', 'nextcloud', 'odoo', 'plesk' ) as $id ) {
			$data = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json' ), true );
			$this->assertSame( $id, $data['logo'], 'The slug of the file in assets/logos.' );
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/logos/' . $data['logo'] . '.svg' );
			$this->assertNotSame( Tiles::os_logo( 1, 'Nothing', '' ), Tiles::app_logo( $data ), $id . ' has its own logo.' );
		}

		foreach ( array( 'ubuntu', 'debian', 'almalinux', 'generic', 'cloudlinux', 'woocommerce', 'postgres', 'valkey', 'mssql' ) as $slug ) {
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/logos/' . $slug . '.svg' );
		}
	}

	public function test_logo_files_live_in_one_folder_only(): void {
		$root  = realpath( BELTOFT_SERVICES_PATH );
		$found = array();
		$skip  = array( '.git', '.internal', 'tests', 'vendor', 'node_modules', '.playwright-mcp', '.superpowers' );
		$walk  = new \RecursiveIteratorIterator( new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), static fn( $f ) => ! in_array( $f->getFilename(), $skip, true ) && ! ( $f->isDir() && 0 === strpos( $f->getFilename(), '.' ) ) ) ); // No hidden folder is part of the plugin (tool worktrees, editor folders).

		foreach ( $walk as $file ) {
			if ( 'svg' === strtolower( $file->getExtension() ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		foreach ( $found as $path ) {
			$this->assertStringStartsWith( 'assets/logos/', $path, 'Every logo file is in assets/logos.' );
		}

		$this->assertDirectoryDoesNotExist( BELTOFT_SERVICES_PATH . 'assets/os' );
		$this->assertDirectoryDoesNotExist( BELTOFT_SERVICES_PATH . 'assets/db' );
	}

	public function test_logo_url_gives_the_file_of_a_slug_and_nothing_for_an_unknown_one(): void {
		$url = Tiles::logo_url( 'ubuntu' );
		$this->assertStringEndsWith( '/assets/logos/ubuntu.svg', $url );
		$this->assertSame( '', Tiles::logo_url( 'no-such-logo' ) );
		$this->assertSame( '', Tiles::logo_url( '../tiles' ) );
		$this->assertSame( '', Tiles::logo_url( '' ) );

		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/logos/*.svg' ) as $file ) {
			$this->assertNotSame( '', Tiles::logo_url( basename( $file, '.svg' ) ) );
		}
	}

	public function test_an_app_without_a_usable_logo_gets_the_neutral_one(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );

		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker' ) ), 'No logo field.' );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => '../../apps/CHECKSUMS' ) ), 'A path is not a file name.' );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => 'missing' ) ) );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => 'Docker.svg' ) ) );
	}

	public function test_a_logo_with_a_script_or_a_handler_is_never_printed(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );

		foreach ( array( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>', '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0"/></a></svg>', '<html><svg></svg></html>' ) as $i => $body ) {
			// The markup is cached per path: a new name each round.
			$name = 'evil' . $i;
			file_put_contents( BELTOFT_SERVICES_PATH . 'assets/logos/' . $name . '.svg', $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file.
			$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => $name ) ), $body );
			unlink( BELTOFT_SERVICES_PATH . 'assets/logos/' . $name . '.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test file.
		}
	}

	public function test_a_group_prints_real_radios_in_one_name_with_the_checked_and_disabled_state(): void {
		ob_start();
		Tiles::print_group(
			array(
				'kind'   => 'app',
				'id'     => 'grp',
				'name'   => 'pick',
				'legend' => 'Pick one',
				'items'  => array(
					array( 'value' => 'a', 'name' => 'A <b>', 'line' => 'First', 'logo' => '', 'checked' => true ),
					array( 'value' => 'b', 'name' => 'B', 'line' => '', 'logo' => '', 'disabled' => true, 'why' => 'Needs Performance', 'attrs' => array( 'data-versions' => '[]', 'onclick' => 'x' ) ),
				),
				'note'   => 'A note',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, 'type="radio" name="pick"' ) );
		$this->assertMatchesRegularExpression( '#value="a" checked=\'checked\'#', $html );
		$this->assertMatchesRegularExpression( '#value="b" disabled=\'disabled\'#', $html );
		$this->assertStringContainsString( 'Needs Performance', $html );
		$this->assertStringContainsString( 'A &lt;b&gt;', $html, 'Names are escaped.' );
		$this->assertStringContainsString( 'data-versions="[]"', $html );
		$this->assertStringNotContainsString( 'onclick', $html, 'Only data attributes are accepted.' );
		$this->assertStringContainsString( '<legend', $html );
		$this->assertStringContainsString( 'data-tiles-note hidden', $html );
	}

	public function test_os_tiles_carry_their_family_and_an_app_tile_says_what_it_runs_on(): void {
		$cluster = array(
			'templates'         => array( 9000 => 'Ubuntu 24.04 LTS', 9002 => 'Debian 13' ),
			'template_families' => array( 9000 => 'ubuntu-24.04', 9002 => 'debian-13' ),
		);
		update_option( \BeltoftServices\Proxmox\Pve\Clusters::OPTION, array( 'tiles' => $cluster ) );

		$items = Tiles::os_items( 'tiles', array( 9000 => 'Ubuntu 24.04 LTS', 9002 => 'Debian 13' ), 9002 );
		$this->assertSame( 'ubuntu-24.04', $items[0]['attrs']['data-family'] );
		$this->assertSame( 'debian-13', $items[1]['attrs']['data-family'] );

		$this->assertSame( array( 'ubuntu-24.04', 'debian-13' ), \BeltoftServices\Apps\Apps::families( array( array( 'os' => array( 'ubuntu-24.04' ) ), array( 'os' => array( 'ubuntu-24.04', 'debian-13' ) ), null ) ) );
		$this->assertSame( 'Runs on Ubuntu 24.04', \BeltoftServices\Apps\Apps::runs_on( $cluster, array( 'ubuntu-24.04' ) ) );
		$this->assertSame( 'Runs on Ubuntu 24.04 or Debian 13', \BeltoftServices\Apps\Apps::runs_on( $cluster, array( 'ubuntu-24.04', 'debian-13' ) ) );
		$this->assertSame( 'Runs on Rocky 9', \BeltoftServices\Apps\Apps::runs_on( $cluster, array( 'rocky-9' ) ), 'A family without a template still gets a name.' );
		$this->assertSame( '', \BeltoftServices\Apps\Apps::runs_on( $cluster, array() ) );
		$this->assertSame( 'WordPress runs on Ubuntu 24.04, so no app is selected now.', \BeltoftServices\Apps\Apps::dropped_text( $cluster, array( 'ubuntu-24.04' ), 'WordPress' ) );

		ob_start();
		Tiles::print_group(
			array(
				'kind'  => 'app',
				'name'  => 'pick',
				'items' => array( array( 'value' => 'n8n', 'name' => 'n8n', 'runs' => 'Runs on Ubuntu 24.04', 'logo' => '', 'attrs' => array( 'data-families' => 'ubuntu-24.04' ) ) ),
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<span class="beltoft-services-tile__line beltoft-services-tile__runs">Runs on Ubuntu 24.04</span>', $html );
		$this->assertStringContainsString( 'data-families="ubuntu-24.04"', $html );
	}

	public function test_the_script_keeps_every_system_open_and_drops_an_app_that_does_not_run_on_the_chosen_one(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );
		$this->assertStringNotContainsString( 'radio.disabled = on', $js, 'Systems are never greyed out by an app.' );
		$this->assertStringContainsString( 'function drop()', $js );
		$this->assertStringContainsString( 'none.checked = true', $js );
		$this->assertStringContainsString( 'function follow()', $js );
	}

	public function test_the_stylesheet_has_square_tiles_in_three_and_two_columns_and_navy_when_chosen(): void {
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.css' );

		$this->assertStringContainsString( 'repeat(3, minmax(0, 1fr))', $css );
		$this->assertStringContainsString( 'repeat(2, minmax(0, 1fr))', $css );
		$this->assertStringNotContainsString( 'border-radius: ', str_replace( 'border-radius: 0', '', $css ), 'No rounded corners.' );
		$this->assertMatchesRegularExpression( '/__input:checked \+ \.beltoft-services-tile__face[^{]*\{[^}]*var\(--bs-dark/', $css );
		$this->assertMatchesRegularExpression( '/__input:focus-visible \+ \.beltoft-services-tile__face/', $css );
	}

	public function test_the_script_posts_the_checked_radio_of_a_group_only(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertStringContainsString( "'radio' !== field.type || field.checked", $js );
	}

	private function os_cluster(): array {
		$labels = array(
			9000 => 'Ubuntu 24.04',
			9001 => 'Debian 12',
			9002 => 'Ubuntu 26.04 LTS',
			9003 => 'Ubuntu 22.04',
			9004 => 'AlmaLinux 9',
			9005 => 'Debian 13',
			9006 => 'Custom image',
		);
		update_option(
			\BeltoftServices\Proxmox\Pve\Clusters::OPTION,
			array(
				'osv' => array(
					'templates'         => $labels,
					'template_families' => array( 9000 => 'ubuntu-24.04', 9001 => 'debian-12', 9002 => 'ubuntu-26.04', 9003 => 'ubuntu-22.04', 9004 => 'almalinux-9', 9005 => 'debian-13' ),
				),
			)
		);

		return $labels;
	}

	public function test_system_tiles_are_one_per_family_with_the_newest_version_first(): void {
		$items = Tiles::os_items( 'osv', $this->os_cluster(), 9000 );

		$this->assertSame( array( 'Ubuntu', 'Debian', 'AlmaLinux', 'Custom image' ), array_column( $items, 'name' ), 'One tile per family, in the order the families first appear.' );

		$ubuntu = json_decode( $items[0]['attrs']['data-versions'], true );
		$this->assertSame( array( '9002', '9000', '9003' ), array_column( $ubuntu, 'value' ), '26.04, 24.04, 22.04.' );
		$this->assertSame( array( 'ubuntu-26.04', 'ubuntu-24.04', 'ubuntu-22.04' ), array_column( $ubuntu, 'family' ) );
		$this->assertSame( array( '9005', '9001' ), array_column( json_decode( $items[1]['attrs']['data-versions'], true ), 'value' ), 'Debian 13 before 12.' );
	}

	public function test_the_checked_system_stays_checked_and_other_families_show_their_newest(): void {
		$items = Tiles::os_items( 'osv', $this->os_cluster(), 9000 );

		$this->assertSame( '9000', $items[0]['value'], 'The preselected system is the tile value.' );
		$this->assertTrue( $items[0]['checked'] );
		$this->assertSame( 'Version 24.04', $items[0]['line'] );
		$this->assertSame( 'ubuntu-24.04', $items[0]['attrs']['data-family'] );
		$this->assertSame( '9005', $items[1]['value'], 'Debian shows its newest version.' );
		$this->assertFalse( $items[1]['checked'] );

		$items = Tiles::os_items( 'osv', $this->os_cluster(), 9001 );
		$this->assertSame( '9001', $items[1]['value'] );
		$this->assertTrue( $items[1]['checked'] );
		$this->assertFalse( $items[0]['checked'] );
		$this->assertSame( '9002', $items[0]['value'] );
	}

	public function test_a_family_with_one_version_has_no_versions_list(): void {
		$items = Tiles::os_items( 'osv', $this->os_cluster(), 9000 );

		$this->assertArrayNotHasKey( 'data-versions', $items[2]['attrs'] );
		$this->assertSame( '9004', $items[2]['value'] );
		$this->assertArrayNotHasKey( 'data-versions', $items[3]['attrs'] );
		$this->assertSame( 'Custom image', $items[3]['name'] );

		ob_start();
		Tiles::print_os( 'osv', array( 9004 => 'AlmaLinux 9', 9006 => 'Custom image' ), 9004, 'os', 'os-one' );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'data-tiles-os-version', $html, 'No select when no family has a second version.' );
		$this->assertStringContainsString( '>Version 9</span>', $html, 'The plain line stays.' );
	}

	public function test_the_posted_value_is_the_same_field_and_a_system_id_as_before(): void {
		$labels = $this->os_cluster();

		ob_start();
		Tiles::print_os( 'osv', $labels, 9000, 'os', 'beltoft-vm-os' );
		$html = (string) ob_get_clean();

		$this->assertSame( 4, substr_count( $html, 'type="radio" name="os"' ), 'One radio per family, all in the same group named os.' );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="os" value="9000" checked#', $html );
		preg_match_all( '#type="radio" name="os" value="(\d+)"#', $html, $m );
		foreach ( $m[1] as $value ) {
			$this->assertArrayHasKey( (int) $value, $labels, 'Every posted value is a template id the cluster lists.' );
		}

		// The select sits in the tile, has no name (it is never posted itself) and is labelled.
		$this->assertMatchesRegularExpression( '#<span class="beltoft-services-tile__name">Ubuntu</span><select class="beltoft-services-tile__version" data-tiles-os-version aria-label="Ubuntu Version">#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<select[^>]*name=#', $html );
		$this->assertStringNotContainsString( 'data-tiles-os-version-row', $html, 'No separate select under the group.' );
		$this->assertMatchesRegularExpression( '#<option value="9002">26.04 LTS</option><option value="9000" selected=\'selected\'>24.04</option><option value="9003">22.04</option>#', $html );
		$this->assertSame( 2, substr_count( $html, 'data-tiles-os-version ' ), 'Ubuntu and Debian have a select, AlmaLinux and Custom image the plain line.' );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '·', $html );
	}

	public function test_the_script_writes_the_chosen_version_into_the_tile_value(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );

		$this->assertStringContainsString( 'radio.value = entry.value;', $js );
		$this->assertStringContainsString( 'data-tiles-os-version', $js );
		$this->assertSame( 3, substr_count( $js, 'refresh();' ), 'A version change refreshes the order summary through the existing listeners.' );
	}
}
