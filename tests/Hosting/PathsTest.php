<?php
/**
 * Hosting's one knowledge of the plugin root.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Assets;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingView;
use BeltoftServices\Hosting\Paths;

/**
 * Paths gives the values the code built by hand before, and nothing else in src/Hosting names the root.
 */
class PathsTest extends \WP_UnitTestCase {

	public function test_values_equal_the_old_formulas(): void {
		$this->assertSame( BELTOFT_SERVICES_PATH, Paths::dir() );
		$this->assertSame( BELTOFT_SERVICES_PATH . 'assets/hosting.css', Paths::dir( 'assets/hosting.css' ) );
		$this->assertSame( plugins_url( 'assets/hosting.js', BELTOFT_SERVICES_FILE ), Paths::url( 'assets/hosting.js' ) );

		foreach ( array( 'assets/hosting.css', 'assets/hosting.js', 'assets/site-field.css', 'assets/site-field.js', 'assets/missing-file.css' ) as $file ) {
			$this->assertSame( Assets::version( $file ), Paths::version( $file ), $file );
		}

		$this->assertSame( BELTOFT_SERVICES_VERSION, Paths::version( 'assets/missing-file.css' ) );
	}

	public function test_hosting_assets_are_enqueued_with_the_old_src_and_ver(): void {
		wp_dequeue_style( HostingAccount::HANDLE );
		wp_dequeue_script( HostingAccount::HANDLE );
		wp_register_script( \BeltoftServices\Account\Panel::HANDLE, '' );
		wp_register_script( \BeltoftServices\Status::HANDLE, '' );
		wp_register_script( \BeltoftServices\Status::API_HANDLE, '' );

		HostingAccount::enqueue();

		$style  = wp_styles()->registered[ HostingAccount::HANDLE ];
		$script = wp_scripts()->registered[ HostingAccount::HANDLE ];

		$this->assertSame( plugins_url( 'assets/hosting.css', BELTOFT_SERVICES_FILE ), $style->src );
		$this->assertSame( Assets::version( 'assets/hosting.css' ), $style->ver );
		$this->assertSame( plugins_url( 'assets/hosting.js', BELTOFT_SERVICES_FILE ), $script->src );
		$this->assertSame( Assets::version( 'assets/hosting.js' ), $script->ver );
	}

	public function test_a_hosting_template_renders_through_the_view(): void {
		$html = HostingView::capture( 'code-field', array( 'purpose' => 'hosting_files' ) );

		$this->assertStringContainsString( 'data-hosting-field="code"', $html );
		$this->assertStringContainsString( 'data-purpose="hosting_files"', $html );
	}

	public function test_only_paths_names_the_plugin_root_in_src_hosting(): void {
		$bad = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Hosting', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() || 'Paths.php' === $file->getFilename() ) {
				continue;
			}

			if ( 1 === preg_match( '/BELTOFT_SERVICES_(FILE|PATH)|Assets::version/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$bad[] = $file->getFilename();
			}
		}

		$this->assertSame( array(), $bad );
	}
}
