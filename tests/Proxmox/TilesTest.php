<?php
/**
 * The operating system and app tiles: logos, markup and where the assets load.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Tiles;
use BeltoftServices\Tests\TestCase;

class TilesTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_os_logo' );
		remove_all_filters( 'beltoft_services_apps' );
		@unlink( BELTOFT_SERVICES_PATH . 'apps/docker/evil.svg' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- test file.
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

	public function test_the_apps_name_their_logo_in_app_json_and_the_shipped_ones_exist(): void {
		foreach ( array( 'docker', 'wordpress', 'coolify' ) as $id ) {
			$data = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/app.json' ), true );
			$this->assertSame( 'logo.svg', $data['logo'], $id );
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/logo.svg' );
			$this->assertNotSame( Tiles::os_logo( 1, 'Nothing', '' ), Tiles::app_logo( $data ), $id . ' has its own logo.' );
		}

		foreach ( array( 'ubuntu', 'debian', 'almalinux', 'generic' ) as $slug ) {
			$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/os/' . $slug . '.svg' );
		}
	}

	public function test_an_app_without_a_usable_logo_gets_the_neutral_one(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );

		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker' ) ), 'No logo field.' );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => '../../apps/CHECKSUMS.svg' ) ), 'A path is not a file name.' );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => 'missing.svg' ) ) );
		$this->assertSame( $generic, Tiles::app_logo( array( 'id' => '../x', 'logo' => 'logo.svg' ) ) );
	}

	public function test_a_logo_with_a_script_or_a_handler_is_never_printed(): void {
		$generic = Tiles::os_logo( 1, 'Nothing', '' );

		foreach ( array( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>', '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0"/></a></svg>', '<html><svg></svg></html>' ) as $i => $body ) {
			file_put_contents( BELTOFT_SERVICES_PATH . 'apps/docker/evil.svg', $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file.
			clearstatcache();
			// The same file name gives a new content each round, but the markup is cached per path: use a new name.
			$name = 'evil' . $i . '.svg';
			rename( BELTOFT_SERVICES_PATH . 'apps/docker/evil.svg', BELTOFT_SERVICES_PATH . 'apps/docker/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- test file.
			$this->assertSame( $generic, Tiles::app_logo( array( 'id' => 'docker', 'logo' => $name ) ), $body );
			unlink( BELTOFT_SERVICES_PATH . 'apps/docker/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test file.
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
		update_option( \BeltoftServices\Proxmox\Clusters::OPTION, array( 'tiles' => $cluster ) );

		$items = Tiles::os_items( 'tiles', array( 9000 => 'Ubuntu 24.04 LTS', 9002 => 'Debian 13' ), 9002 );
		$this->assertSame( 'ubuntu-24.04', $items[0]['attrs']['data-family'] );
		$this->assertSame( 'debian-13', $items[1]['attrs']['data-family'] );

		$this->assertSame( array( 'ubuntu-24.04', 'debian-13' ), \BeltoftServices\Proxmox\Apps::families( array( array( 'os' => array( 'ubuntu-24.04' ) ), array( 'os' => array( 'ubuntu-24.04', 'debian-13' ) ), null ) ) );
		$this->assertSame( 'Runs on Ubuntu 24.04', \BeltoftServices\Proxmox\Apps::runs_on( $cluster, array( 'ubuntu-24.04' ) ) );
		$this->assertSame( 'Runs on Ubuntu 24.04 or Debian 13', \BeltoftServices\Proxmox\Apps::runs_on( $cluster, array( 'ubuntu-24.04', 'debian-13' ) ) );
		$this->assertSame( 'Runs on Rocky 9', \BeltoftServices\Proxmox\Apps::runs_on( $cluster, array( 'rocky-9' ) ), 'A family without a template still gets a name.' );
		$this->assertSame( '', \BeltoftServices\Proxmox\Apps::runs_on( $cluster, array() ) );
		$this->assertSame( 'WordPress runs on Ubuntu 24.04, so no app is selected now.', \BeltoftServices\Proxmox\Apps::dropped_text( $cluster, array( 'ubuntu-24.04' ), 'WordPress' ) );

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
		$this->assertMatchesRegularExpression( '/__input:checked \+ \.beltoft-services-tile__face[^{]*\{[^}]*var\(--navy/', $css );
		$this->assertMatchesRegularExpression( '/__input:focus-visible \+ \.beltoft-services-tile__face/', $css );
	}

	public function test_the_script_posts_the_checked_radio_of_a_group_only(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertStringContainsString( "'radio' !== field.type || field.checked", $js );
	}
}
