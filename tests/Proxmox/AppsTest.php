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
		$this->assertStringContainsString( '/apps/docker/29/install.sh', $app['url'] );
		$this->assertNull( Apps::version( 'docker', '../29' ) );
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
		$file = BELTOFT_SERVICES_PATH . 'apps/docker/29/install.sh';
		$script = file_get_contents( $file );
		$this->assertStringContainsString( 'docker-ce=5:29.9.2-1~ubuntu.24.04~noble', $script );
		$this->assertStringContainsString( 'hello-world:linux@sha256:', $script );
		$manifest = file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' );
		$this->assertStringContainsString( hash_file( 'sha256', $file ) . '  docker/29/install.sh', $manifest );
		$this->assertDoesNotMatchRegularExpression( '/curl[^\n]*\|\s*(?:sh|bash)|wget[^\n]*\|\s*(?:sh|bash)|chmod\s+777|sshd_config|passwd\s+root/', $script );
		$check = array();
		$code = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );
	}
}
