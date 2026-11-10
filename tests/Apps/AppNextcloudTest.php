<?php
/** The Nextcloud Server one-click app recipe. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppTab;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Tests\TestCase;

final class AppNextcloudTest extends TestCase {
	private function script(): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/nextcloud/35/install.script' );
	}

	public function test_the_definition_loads_with_a_matching_checksum_and_the_logo_exists(): void {
		$app = Apps::current( 'nextcloud' );
		$this->assertNotNull( $app );
		$this->assertSame( '35', $app['version_id'] );
		$this->assertSame( '35.0.2', $app['upstream'] );
		$this->assertSame( hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/nextcloud/35/install.script' ), $app['sha256'] );
		$this->assertStringContainsString( hash( 'sha256', $this->script() ) . '  nextcloud/35/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'apps/nextcloud/logo.svg' );
	}

	public function test_the_minimum_plan_is_performance_and_a_starter_plan_is_refused(): void {
		$app = Apps::current( 'nextcloud' );
		$this->assertSame( array( 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40 ), $app['min'] );
		$this->assertSame( 'performance', $app['min_plan'] );
		$this->assertNull( Apps::plan_refusal( $app, array( 'cores' => 2, 'memory_mb' => 8192, 'disk_gb' => 60 ) ) );
		$this->assertSame( 'Nextcloud needs the Performance plan or larger. Choose a larger plan or no app.', Apps::plan_refusal( $app, array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ) ) );
	}

	public function test_it_declares_a_domain_www_the_steps_and_the_credential_keys(): void {
		$app = Apps::current( 'nextcloud' );
		$raw = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/nextcloud/app.json' ), true );
		$this->assertTrue( $raw['domain'] );
		$this->assertSame( array( 'since_recipe' => 1 ), $raw['www'] );
		$this->assertTrue( $raw['needs_email'] );
		$this->assertSame( array( 'admin_user', 'admin_password' ), $app['credentials'] );
		$labels = AppTab::step_labels();
		foreach ( $raw['steps'] as $step ) {
			$this->assertArrayHasKey( $step, $labels );
		}
		$this->assertStringContainsString( "jq '{admin_user,admin_password}' \"\$secrets/nextcloud.json\" > \"\$secrets/credentials.json\"", $this->script() );
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/app-tab.js' );
		$this->assertStringContainsString( "admin_user: 'user'", $js );
		$this->assertStringContainsString( "admin_password: 'pass'", $js );
	}

	public function test_the_script_is_strict_valid_bash_with_pinned_images_and_no_secrets_in_arguments(): void {
		$file   = BELTOFT_SERVICES_PATH . 'apps/nextcloud/35/install.script';
		$script = $this->script();
		exec( 'bash -n ' . escapeshellarg( $file ), $out, $code );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'set -Eeuo pipefail', $script );
		$this->assertMatchesRegularExpression( '#image: nextcloud:35\.0\.2-apache@sha256:[a-f0-9]{64}#', $script );
		$this->assertMatchesRegularExpression( '#image: postgres:16\.15-alpine@sha256:[a-f0-9]{64}#', $script );
		$this->assertMatchesRegularExpression( '#image: redis:[0-9.]+-alpine@sha256:[a-f0-9]{64}#', $script );
		$this->assertStringContainsString( '127.0.0.1:8080:80', $script );
		$this->assertStringNotContainsString( '0.0.0.0', $script );
		$this->assertStringContainsString( 'NEXTCLOUD_TRUSTED_DOMAINS', $script );
		$this->assertStringContainsString( 'TRUSTED_PROXIES=172.29.80.0/24', $script );
		$this->assertStringContainsString( 'OVERWRITEPROTOCOL=https', $script );
		$this->assertStringContainsString( 'PHP_UPLOAD_LIMIT=2G', $script );
		$this->assertStringContainsString( 'occ background:cron', $script );
		$this->assertStringContainsString( 'cron.php', $script );
		$this->assertStringContainsString( '/.well-known/carddav /remote.php/dav/', $script );
		$this->assertStringContainsString( '/.well-known/caldav /remote.php/dav/', $script );
		$this->assertDoesNotMatchRegularExpression( '/\becho\b[^\n]*(key|password)/i', $script );
		$this->assertDoesNotMatchRegularExpression( '/docker (?:compose )?(?:run|exec)[^\n]* -e /', $script );
		$this->assertDoesNotMatchRegularExpression( '/(admin_password|NEXTCLOUD_ADMIN_PASSWORD)[^\n]*docker compose/', $script );
		$this->assertStringNotContainsString( '5678', $script );
		$this->assertStringNotContainsString( '8069', $script );
	}

	public function test_nextcloud_is_installed_and_answers_before_the_web_server_and_the_firewall_open(): void {
		$script = $this->script();
		$ready  = strpos( $script, '/status.php | jq -e' );
		$this->assertNotFalse( $ready );
		$this->assertLessThan( strpos( $script, 'systemctl enable --now caddy' ), $ready );
		$this->assertLessThan( strpos( $script, 'ufw --force enable' ), $ready );
		$this->assertLessThan( strpos( $script, 'ufw --force enable' ), strpos( $script, 'occ background:cron' ) );
		$this->assertLessThan( strpos( $script, 'systemctl enable --now caddy' ), strpos( $script, 'occ background:cron' ) );
	}

	public function test_the_text_has_the_required_words_and_no_em_dash(): void {
		$raw  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/nextcloud/app.json' );
		$text = (string) Apps::current( 'nextcloud' )['getting_started'];
		$this->assertStringNotContainsString( "\u{2014}", $raw );
		$this->assertStringNotContainsString( "\u{2014}", $this->script() );
		foreach ( array( 'password shown once', 'domain for HTTPS', 'CalDAV', 'CardDAV', 'port 25 is blocked', '587 or 465', 'maintenance:mode', 'pg_dump', 'next major version only', 'AGPL-3.0' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
		$this->assertStringContainsString( 'docs.nextcloud.com/server/latest/admin_manual/installation/', Apps::current( 'nextcloud' )['docs_url'] );
	}
}
