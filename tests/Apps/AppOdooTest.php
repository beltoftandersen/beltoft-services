<?php
/** The Odoo Community one-click app recipe. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppTab;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Tests\TestCase;

final class AppOdooTest extends TestCase {
	private function script(): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/20/install.script' );
	}

	public function test_the_definition_loads_with_a_matching_checksum_and_the_logo_exists(): void {
		$app = Apps::current( 'odoo' );
		$this->assertNotNull( $app );
		$this->assertSame( '20', $app['version_id'] );
		$this->assertSame( '20.0', $app['upstream'] );
		$this->assertSame( hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/odoo/20/install.script' ), $app['sha256'] );
		$this->assertStringContainsString( hash( 'sha256', $this->script() ) . '  odoo/20/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'apps/odoo/logo.svg' );
	}

	public function test_the_minimum_is_the_smallest_plan_and_more_is_recommended_in_the_text(): void {
		$app = Apps::current( 'odoo' );
		$this->assertSame( array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ), $app['min'] );
		$this->assertSame( 'starter', $app['min_plan'] );
		$this->assertNull( Apps::plan_refusal( $app, array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ) ) );
		$this->assertStringContainsString( 'choose 2 cores and 4 GB of memory or more', $app['getting_started'] );
	}

	public function test_it_declares_a_domain_www_the_steps_and_the_credential_keys(): void {
		$app = Apps::current( 'odoo' );
		$raw = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/app.json' ), true );
		$this->assertTrue( $raw['domain'] );
		$this->assertSame( array( 'since_recipe' => 1 ), $raw['www'] );
		$this->assertTrue( $raw['needs_email'] );
		$this->assertSame( array( 'root_email', 'root_password', 'master_password' ), $app['credentials'] );
		$labels = AppTab::step_labels();
		foreach ( $raw['steps'] as $step ) {
			$this->assertArrayHasKey( $step, $labels );
		}
		$this->assertStringContainsString( "jq '{root_email,root_password,master_password}' \"\$secrets/odoo.json\" > \"\$secrets/credentials.json\"", $this->script() );
		$this->assertStringContainsString( "master_password: 'master'", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/app-tab.js' ) );
	}

	public function test_the_script_is_strict_valid_bash_with_pinned_images_and_no_secrets_in_arguments(): void {
		$file   = BELTOFT_SERVICES_PATH . 'apps/odoo/20/install.script';
		$script = $this->script();
		exec( 'bash -n ' . escapeshellarg( $file ), $out, $code );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'set -Eeuo pipefail', $script );
		$this->assertMatchesRegularExpression( '#image: odoo:20\.0@sha256:[a-f0-9]{64}#', $script );
		$this->assertMatchesRegularExpression( '#image: postgres:16\.15-alpine@sha256:[a-f0-9]{64}#', $script );
		$this->assertStringContainsString( 'proxy_mode = True', $script );
		$this->assertStringContainsString( 'list_db = False', $script );
		$this->assertStringContainsString( 'dbfilter = ^odoo$', $script );
		$this->assertStringContainsString( '127.0.0.1:8069:8069', $script );
		// The port is published on the VM's own 127.0.0.1 only; 0.0.0.0 may only appear as Odoo's address inside its container.
		$this->assertStringNotContainsString( '"0.0.0.0:', $script );
		$this->assertDoesNotMatchRegularExpression( '/^\s*-\s*"?8069:8069/m', $script );
		$this->assertDoesNotMatchRegularExpression( '/\becho\b[^\n]*(key|password)/i', $script );
		$this->assertDoesNotMatchRegularExpression( '/docker (?:compose )?run[^\n]* -e /', $script );
		$this->assertStringNotContainsString( '5678', $script );
	}

	public function test_the_database_and_admin_exist_before_the_web_server_and_the_firewall_open(): void {
		$script = $this->script();
		$init   = strpos( $script, 'odoo -d odoo -i base --without-demo=all' );
		$admin  = strpos( $script, 'base.user_admin' );
		$this->assertNotFalse( $init );
		$this->assertLessThan( $admin, $init );
		$this->assertLessThan( strpos( $script, 'systemctl enable --now caddy' ), $admin );
		$this->assertLessThan( strpos( $script, 'ufw --force enable' ), $admin );
		$this->assertLessThan( strpos( $script, 'systemctl enable --now caddy' ), strpos( $script, '/web/health' ) );
	}

	public function test_the_text_has_the_required_words_and_no_em_dash(): void {
		$raw  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/app.json' );
		$text = (string) Apps::current( 'odoo' )['getting_started'];
		$this->assertStringNotContainsString( "\u{2014}", $raw );
		$this->assertStringNotContainsString( "\u{2014}", $this->script() );
		foreach ( array( 'password shown once', 'master password', 'domain for HTTPS', 'port 25 is blocked', '587 or 465', 'pg_dump', 'filestore', 'manual', 'LGPL-3.0', 'Enterprise' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
		$this->assertStringContainsString( 'odoo.com/documentation/20.0/administration/on_premise/deploy.html', Apps::current( 'odoo' )['docs_url'] );
	}

	public function test_odoo_listens_on_every_address_inside_its_container(): void {
		// Odoo 20 binds to 127.0.0.1 by default, which Docker's port mapping cannot reach; the port is published on the VM's 127.0.0.1 only.
		$this->assertStringContainsString( 'http_interface = 0.0.0.0', $this->script() );
		$this->assertStringContainsString( '"127.0.0.1:8069:8069"', $this->script() );
	}
}
