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
		$this->assertStringContainsString( 'Ports you publish with Docker are open to the internet even though the firewall is on', $text );
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

	private function n8n_script(): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/n8n/2/install.script' );
	}

	public function test_n8n_has_one_current_pinned_version(): void {
		$app = Apps::current( 'n8n' );
		$this->assertNotNull( $app );
		$this->assertSame( '2', $app['version_id'] );
		$this->assertSame( 1, $app['recipe'] );
		$file = BELTOFT_SERVICES_PATH . 'apps/n8n/2/install.script';
		$this->assertSame( hash_file( 'sha256', $file ), $app['sha256'] );
		$this->assertStringContainsString( 'n8nio/n8n:' . $app['upstream'] . '@sha256:', $this->n8n_script() );
		$this->assertTrue( ! empty( $app['needs_email'] ) );
		$check = array();
		$code  = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );
	}

	public function test_every_checksum_line_matches_its_script_and_every_app_has_one(): void {
		$lines = array_filter( explode( "\n", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) ) );
		$seen  = array();
		foreach ( $lines as $line ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}  [a-z0-9-]+\/[a-zA-Z0-9.-]+\/install\.script$/', $line );
			list( $hash, $path ) = explode( '  ', $line, 2 );
			$this->assertSame( hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/' . $path ), $hash, $path );
			$seen[] = explode( '/', $path )[0];
		}
		$this->assertSame( array(), array_values( array_diff( Apps::ids(), $seen ) ), 'Every app has a checksum line.' );
	}

	public function test_n8n_recipe_pins_images_by_version_and_digest(): void {
		$script = $this->n8n_script();
		$this->assertMatchesRegularExpression( '/image: n8nio\/n8n:2\.45\.6@sha256:[a-f0-9]{64}\n/', $script );
		$this->assertMatchesRegularExpression( '/image: postgres:16\.15-alpine@sha256:[a-f0-9]{64}\n/', $script );
		$this->assertStringContainsString( 'docker-ce=5:29.9.2-1~ubuntu.24.04~noble', $script );
		$this->assertStringContainsString( "'caddy=2.11.7'", $script );
		$this->assertStringNotContainsString( ':latest', $script );
		$this->assertDoesNotMatchRegularExpression( '/curl[^\n]*\|\s*(?:sh|bash)|wget[^\n]*\|\s*(?:sh|bash)|chmod\s+777|sshd_config|passwd\s+root/', $script );
		$this->assertDoesNotMatchRegularExpression( '/N8N_LICENSE|\.ee\./', $script, 'No licence key and no enterprise feature.' );
	}

	public function test_n8n_publishes_only_to_localhost_and_opens_only_three_ports(): void {
		$script = $this->n8n_script();
		$this->assertStringContainsString( '"127.0.0.1:5678:5678"', $script );
		$this->assertStringNotContainsString( '5432:', $script );
		preg_match_all( '/ufw allow (\S+)/', $script, $m );
		$this->assertSame( array( '22/tcp', '80/tcp', '443/tcp' ), $m[1] );
		$this->assertStringContainsString( 'reverse_proxy 127.0.0.1:5678', $script );
	}

	public function test_n8n_secure_cookie_follows_the_domain(): void {
		$script = $this->n8n_script();
		$this->assertMatchesRegularExpression( '/if \[ -n "\$domain" \]; then\n(?:(?!\bfi\b).)*?secure=true\n\s*else\n(?:(?!\bfi\b).)*?secure=false\n\s*fi/s', $script );
		$this->assertStringContainsString( 'N8N_SECURE_COOKIE=%s', $script );
		$this->assertStringContainsString( 'N8N_PROXY_HOPS=1', $script );
		$this->assertStringContainsString( 'protocol=https', $script );
		$this->assertStringContainsString( 'protocol=http', $script );
	}

	public function test_n8n_credentials_file_holds_exactly_the_reveal_keys_and_the_key_is_never_printed(): void {
		$app    = Apps::current( 'n8n' );
		$script = $this->n8n_script();
		$this->assertSame( array( 'root_email', 'root_password', 'encryption_key' ), $app['credentials'] );
		$this->assertStringContainsString( "jq '{root_email,root_password,encryption_key}' \"\$secrets/n8n.json\" > \"\$secrets/credentials.json\"", $script );
		$this->assertStringContainsString( 'openssl rand -hex 32', $script );
		$this->assertDoesNotMatchRegularExpression( '/\becho\b[^\n]*(key|password)/i', $script );
		$this->assertDoesNotMatchRegularExpression( '/docker run[^\n]* -e /', $script );
		$this->assertDoesNotMatchRegularExpression( '/--(?:password|encryption)/', $script );
	}

	public function test_n8n_creates_the_owner_before_the_web_server_and_the_firewall_open(): void {
		$script = $this->n8n_script();
		$owner  = strpos( $script, '/rest/owner/setup' );
		$this->assertNotFalse( $owner );
		$this->assertLessThan( strpos( $script, 'systemctl enable --now caddy' ), $owner );
		$this->assertLessThan( strpos( $script, 'ufw --force enable' ), $owner );
		$this->assertStringContainsString( '--data @-', $script, 'The password goes in on stdin.' );
	}

	public function test_n8n_min_plan_is_starter_and_the_text_has_the_required_words(): void {
		$file = BELTOFT_SERVICES_PATH . 'apps/n8n/app.json';
		$raw  = (string) file_get_contents( $file );
		$app  = Apps::current( 'n8n' );
		$this->assertSame( array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ), $app['min'] );
		$this->assertSame( 'starter', $app['min_plan'] );
		foreach ( array( 'not encrypted', 'port 587', 'Sustainable Use License', '/root/.beltoft-app/credentials.json', 'encryption key' ) as $word ) {
			$this->assertStringContainsString( $word, $app['getting_started'] );
		}
		$this->assertStringNotContainsString( "\u{2014}", $raw );
		$this->assertStringNotContainsString( "\u{2014}", $this->n8n_script() );
		$this->assertStringNotContainsStringIgnoringCase( 'hetzner', $raw );
	}

	public function test_every_app_step_has_a_label(): void {
		foreach ( Apps::ids() as $id ) {
			foreach ( Apps::definition( $id )['steps'] as $code ) {
				$this->assertArrayHasKey( $code, \BeltoftServices\Proxmox\AppTab::step_labels(), $id . ' ' . $code );
			}
		}
	}

	public function test_the_n8n_logo_is_inline_safe(): void {
		$svg = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/n8n/logo.svg' );
		$this->assertLessThan( 8192, strlen( $svg ) );
		$this->assertDoesNotMatchRegularExpression( '/<(script|foreignObject|iframe|image|use|a)\b|\son[a-z]+\s*=|href\s*=/i', preg_replace( '/<!--.*?-->/s', '', $svg ) );
	}
}
