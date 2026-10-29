<?php
/** Versioned app catalogue and Docker recipe invariants. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Tests\TestCase;

final class AppsTest extends TestCase {
	public function test_docker_has_one_current_pinned_version(): void {
		$app = Apps::current( 'docker' );
		$this->assertSame( '29', $app['version_id'] );
		$this->assertSame( '29.9.2', $app['upstream'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $app['sha256'] );
		$this->assertStringContainsString( '/apps/docker/29/install.script', $app['url'] );
		$this->assertNull( Apps::version( 'docker', '../29' ) );
	}

	public function test_docker_getting_started_warns_about_published_ports(): void {
		$text = (string) Apps::current( 'docker' )['getting_started'];
		$this->assertStringContainsString( 'Ports you publish with Docker bypass the firewall inside the server. Open or close them in the Firewall tab', $text );
		$this->assertStringContainsString( '127.0.0.1', $text );
	}

	public function test_retired_version_is_readable_only_for_existing_server(): void {
		$filter = static function ( $data ) {
			$data['versions'][0]['status'] = 'retired';
			return $data;
		};
		add_filter( 'beltoft_services_apps', $filter );
		try {
			$this->assertNull( Apps::current( 'docker' ) );
			$this->assertNull( Apps::version( 'docker', '29' ) );
			$this->assertSame( '29', Apps::version( 'docker', '29', true )['version_id'] );
		} finally {
			remove_filter( 'beltoft_services_apps', $filter );
		}
	}

	public function test_docker_recipe_has_exact_pins_and_no_download_execution(): void {
		$file = BELTOFT_SERVICES_PATH . 'apps/docker/29/install.script';
		$script = file_get_contents( $file );
		$this->assertStringContainsString( 'docker-ce=5:29.9.2-1~ubuntu.24.04~noble', $script );
		$this->assertStringContainsString( 'hello-world:linux@sha256:', $script );
		$manifest = file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' );
		$this->assertStringContainsString( hash_file( 'sha256', $file ) . '  docker/29/install.script', $manifest );
		$this->assertDoesNotMatchRegularExpression( '/curl[^\n]*\|\s*(?:sh|bash)|wget[^\n]*\|\s*(?:sh|bash)|chmod\s+777|sshd_config|passwd\s+root/', $script );
		$check = array();
		$code = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );
	}

	public function test_wordpress_and_coolify_have_current_versioned_recipes(): void {
		foreach ( array( 'wordpress' => '7.1.4', 'coolify' => '4.5.1' ) as $id => $version ) {
			$app = Apps::current( $id );
			$this->assertNotNull( $app );
			$this->assertSame( $version, $app['version_id'] );
			$this->assertSame( $version, $app['upstream'] );
			$this->assertSame( 1, $app['recipe'] );
			$this->assertNotEmpty( $app['credentials'] );
			$file = BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $version . '/install.script';
			$this->assertSame( hash_file( 'sha256', $file ), $app['sha256'] );
			$this->assertStringContainsString( $app['sha256'] . '  ' . $id . '/' . $version . '/install.script', file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
			$check = array();
			$code = 1;
			exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
			$this->assertSame( 0, $code );
		}
	}

	public function test_web_recipes_pin_their_downloads_and_admin_setup(): void {
		$wp = file_get_contents( BELTOFT_SERVICES_PATH . 'apps/wordpress/7.1.4/install.script' );
		$this->assertStringContainsString( 'wordpress-7.1.4.tar.gz', $wp );
		$this->assertStringContainsString( 'wp-cli-2.12.0.phar', $wp );
		$this->assertStringContainsString( 'sha256sum -c -', $wp );
		$this->assertStringContainsString( '--prompt=admin_password', $wp );
		$this->assertStringContainsString( 'DISALLOW_FILE_EDIT', $wp );
		$this->assertStringContainsString( 'ufw allow 443/tcp', $wp );
		$coolify = file_get_contents( BELTOFT_SERVICES_PATH . 'apps/coolify/4.5.1/install.script' );
		$this->assertStringContainsString( 'coolify/v4.5.1', $coolify );
		$this->assertStringContainsString( 'sha256sum -c -', $coolify );
		$this->assertStringContainsString( 'AUTOUPDATE=false', $coolify );
		$this->assertStringContainsString( 'coolify-helper@sha256:', $coolify );
		$this->assertStringContainsString( 'coolify@sha256:', $coolify );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(?:curl|wget)[^\n]*\|\s*(?:sh|bash)/m', $coolify );
	}

	public function test_coolify_requires_performance_sized_server(): void {
		$app = Apps::current( 'coolify' );
		$this->assertSame( 'performance', $app['min_plan'] );
		$this->assertSame( 2, $app['min']['cores'] );
		$this->assertGreaterThanOrEqual( 80, $app['min']['disk_gb'] );
		$this->assertStringContainsString( 'Docker-published ports', $app['getting_started'] );
	}
}
