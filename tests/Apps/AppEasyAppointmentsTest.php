<?php
/** The Easy!Appointments one-click app recipe. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppTab;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Tests\TestCase;

final class AppEasyAppointmentsTest extends TestCase {
	private function script(): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/easyappointments/1.6/install.script' );
	}

	public function test_the_definition_loads_with_a_matching_checksum_and_the_logo_exists(): void {
		$app = Apps::current( 'easyappointments' );
		$this->assertNotNull( $app );
		$this->assertSame( '1.6', $app['version_id'] );
		$this->assertSame( '1.6.1', $app['upstream'] );
		$this->assertSame( 'Easy!Appointments', $app['name'] );
		$this->assertSame( hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/easyappointments/1.6/install.script' ), $app['sha256'] );
		$this->assertStringContainsString( hash( 'sha256', $this->script() ) . '  easyappointments/1.6/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/logos/easyappointments.svg' );
		$this->assertLessThanOrEqual( 12288, filesize( BELTOFT_SERVICES_PATH . 'assets/logos/easyappointments.svg' ) );
	}

	/** Owner 2026-11-27: the time zone choice of the booking page is hidden on every server, installed or updated, once. */
	public function test_ea_place_hides_the_time_zone_choice_once_for_install_and_update(): void {
		$script = $this->script();
		$rule   = 'label[for="select-timezone"],#select-timezone{display:none!important}';
		$this->assertSame( 1, substr_count( $script, $rule ) );
		$this->assertStringContainsString( 'cp -a "$src/assets" "$work/assets"' . "\n" . '  # beltoft: the time zone choice', $script );
		// Both files: the booking page loads frontend.min.css when DEBUG_MODE is off (production), frontend.css otherwise.
		$this->assertStringContainsString( 'for css in frontend.css frontend.min.css; do', $script );
		$this->assertStringContainsString( "grep -q 'select-timezone{display:none' \"\$work/assets/css/\$css\"", $script );
	}

	public function test_the_minimum_is_the_smallest_plan_and_the_definition_declares_domain_www_steps_and_credentials(): void {
		$app = Apps::current( 'easyappointments' );
		$raw = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/easyappointments/app.json' ), true );
		$this->assertSame( array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ), $app['min'] );
		$this->assertSame( 'starter', $app['min_plan'] );
		$this->assertNull( Apps::plan_refusal( $app, array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 40 ) ) );
		$this->assertTrue( $raw['domain'] );
		$this->assertSame( array( 'since_recipe' => 1 ), $raw['www'] );
		$this->assertTrue( $raw['needs_email'] );
		$this->assertSame( array( 'ubuntu-24.04' ), $raw['versions'][0]['os'] );
		$this->assertSame( array( 'admin_user', 'admin_password' ), $app['credentials'] );
		$labels = AppTab::step_labels();
		foreach ( $raw['steps'] as $step ) {
			$this->assertArrayHasKey( $step, $labels );
		}
		$this->assertStringContainsString( "jq '{admin_user,admin_password}' \"\$secrets/easyappointments.json\" > \"\$secrets/credentials.json\"", $this->script() );
	}

	public function test_the_getting_started_text_names_the_mail_file_the_update_helpers_and_has_no_forbidden_characters(): void {
		$text = (string) Apps::current( 'easyappointments' )['getting_started'];
		$this->assertStringContainsString( '/opt/easyappointments/application/config/email.php', $text );
		$this->assertStringContainsString( 'port 25 is blocked', $text );
		$this->assertStringContainsString( '587 or 465', $text );
		$this->assertStringContainsString( 'easyappointments-update', $text );
		$this->assertStringContainsString( 'easyappointments-rollback', $text );
		$this->assertStringContainsString( 'do not copy a new release over /opt/easyappointments by hand', $text );
		$this->assertStringContainsString( "LANGUAGE to 'danish'", $text );
		$this->assertStringNotContainsString( "\u{2014}", $text );
		$this->assertStringNotContainsString( "\u{00B7}", $text );
		$this->assertStringNotContainsString( 'Hetzner', $text );
	}

	public function test_the_script_is_strict_valid_bash_with_the_release_pinned_by_url_and_sha256(): void {
		$file   = BELTOFT_SERVICES_PATH . 'apps/easyappointments/1.6/install.script';
		$script = $this->script();
		exec( 'bash -n ' . escapeshellarg( $file ), $out, $code );
		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'set -Eeuo pipefail', $script );
		$this->assertStringContainsString( "curl --proto '=https' --tlsv1.2 -fsSL https://github.com/alextselegidis/easyappointments/releases/download/1.6.1/easyappointments-1.6.1.zip", $script );
		$this->assertStringContainsString( '1671014c371519fa26e0a0a0dbdaa31047f5dda3156329faaea0662f095042f2  /var/lib/beltoft-app/easyappointments.zip', $script );
		$this->assertStringContainsString( "| sha256sum -c -", $script );
		$this->assertStringContainsString( 'caddy=2.11.7', $script );
		$this->assertDoesNotMatchRegularExpression( '/curl[^\n]*\|\s*(?:sh|bash|php)|wget[^\n]*\|\s*(?:sh|bash)|chmod\s+777/', $script );
		$this->assertDoesNotMatchRegularExpression( '/\becho\b[^\n]*(key|password)/i', $script );
	}

	public function test_the_demo_administrator_command_is_never_used_and_the_install_page_is(): void {
		$script = $this->script();
		$this->assertDoesNotMatchRegularExpression( '/console\s+install\b/', preg_replace( '/^\s*#.*$/m', '', $script ) );
		$this->assertStringContainsString( '/index.php/installation/perform', $script );
		$this->assertStringContainsString( 'index.php/installation -o /dev/null', $script );
		$this->assertStringContainsString( 'csrf_token', $script );
		$this->assertStringContainsString( "DELETE FROM ea_users WHERE email IN ('jane@example.org', 'james@example.org');", $script );
		$stage4 = substr( $script, (int) strpos( $script, 'if [ ! -e "$state/stage.4" ]' ), (int) strpos( $script, 'if [ ! -e "$state/stage.5" ]' ) - (int) strpos( $script, 'if [ ! -e "$state/stage.4" ]' ) );
		$this->assertStringContainsString( 'php "$state/write-config.php"', $stage4 );
		$this->assertStringNotContainsString( 'console migrate', $stage4, 'The install page refuses to run once the tables exist: no migrate before it.' );
		$this->assertStringContainsString( '[ "$(mariadb -N easyappointments -e \'SELECT version FROM ea_migrations\')" = 69 ]', $script );
		$this->assertStringContainsString( 'bind-address = 127.0.0.1', $script );
	}

	public function test_only_index_php_and_assets_are_in_the_web_root_and_index_php_is_patched_by_checked_replacements(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'web=/var/www/easyappointments', $script );
		$this->assertStringContainsString( 'app=/opt/easyappointments', $script );
		$this->assertStringContainsString( "root * /var/www/easyappointments", $script );
		$this->assertStringContainsString( "\"\\\$system_path = '/opt/easyappointments/system';\"", $script );
		$this->assertStringContainsString( "\"\\\$application_folder = '/opt/easyappointments/application';\"", $script );
		$this->assertStringContainsString( "\"'/opt/easyappointments/config.php'\", 2", $script );
		$this->assertStringContainsString( "\"'/opt/easyappointments/vendor/autoload.php'\", 2", $script );
		$this->assertStringContainsString( 'if ( substr_count( $s, $e[0] ) !== $e[2] )', $script );
		$this->assertStringContainsString( 'exit( 1 );', $script );
		$this->assertStringContainsString( 'chmod 0640 "$app/config.php"', $script );
		$this->assertStringContainsString( 'chown root:www-data "$app/config.php"', $script );
		$this->assertStringContainsString( 'chown -R www-data:www-data "$app/storage"', $script );
		$this->assertStringContainsString( "if ( 1 !== \$n )", $script );
	}

	public function test_the_patch_script_applies_four_edits_to_a_matching_index_and_refuses_a_changed_one(): void {
		$script = $this->script();
		$this->assertSame( 1, preg_match( "/<<'PATCH'\n(.*?)\nPATCH\n/s", $script, $m ) );
		$dir = trailingslashit( get_temp_dir() ) . 'bs-ea-' . wp_generate_password( 8, false );
		mkdir( $dir, 0700, true );
		file_put_contents( $dir . '/patch.php', $m[1] );
		$index = "<?php\nif (!file_exists(__DIR__ . '/config.php')) { die('x'); }\nrequire_once __DIR__ . '/config.php';\nif (!file_exists(__DIR__ . '/vendor/autoload.php')) { die('y'); }\nrequire_once __DIR__ . '/vendor/autoload.php';\n\$system_path = 'system';\n\$application_folder = 'application';\n";
		file_put_contents( $dir . '/index.php', $index );
		exec( 'php ' . escapeshellarg( $dir . '/patch.php' ) . ' ' . escapeshellarg( $dir . '/index.php' ) . ' ' . escapeshellarg( $dir . '/out.php' ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$patched = (string) file_get_contents( $dir . '/out.php' );
		$this->assertSame( 2, substr_count( $patched, "'/opt/easyappointments/config.php'" ) );
		$this->assertSame( 2, substr_count( $patched, "'/opt/easyappointments/vendor/autoload.php'" ) );
		$this->assertStringContainsString( "\$system_path = '/opt/easyappointments/system';", $patched );
		$this->assertStringContainsString( "\$application_folder = '/opt/easyappointments/application';", $patched );
		$this->assertStringNotContainsString( '__DIR__', $patched );
		// A changed upstream line makes the patch fail and nothing is written.
		file_put_contents( $dir . '/index.php', str_replace( "\$system_path = 'system';", "\$system_path = 'sys';", $index ) );
		unlink( $dir . '/out.php' );
		$out = array();
		exec( 'php ' . escapeshellarg( $dir . '/patch.php' ) . ' ' . escapeshellarg( $dir . '/index.php' ) . ' ' . escapeshellarg( $dir . '/out.php' ) . ' 2>&1', $out, $code );
		$this->assertSame( 1, $code );
		$this->assertFileDoesNotExist( $dir . '/out.php' );
		exec( 'rm -rf ' . escapeshellarg( $dir ) );
	}

	public function test_the_web_server_refuses_the_private_paths_and_the_verify_step_fails_the_install_unless_each_answers_404_or_403(): void {
		$script = $this->script();
		$this->assertStringContainsString( '@private path /storage /storage/* /application /application/* /system /system/* /vendor /vendor/*', $script );
		$this->assertStringContainsString( '/config.php /config-sample.php /composer.json /composer.lock /CHANGELOG.md', $script );
		$this->assertStringContainsString( 'respond @private 404', $script );
		$this->assertStringContainsString( 'for url in /storage/backups/ /application/config/config.php /config.php /vendor/composer/installed.json /composer.json', $script );
		$this->assertStringContainsString( 'case "$code" in 404|403) ;; *)', $script );
		$this->assertStringContainsString( '[ "$fails" = 0 ]', $script );
		$verify = substr( $script, (int) strrpos( $script, 'step=verify' ) );
		$this->assertStringContainsString( 'ea_web_check', $verify, 'The verify step runs the check; the install fails when it does.' );
		$this->assertStringContainsString( "index.php/booking", $verify );
		$this->assertStringContainsString( 'booking page answered $code, expected 200', $script );
		$this->assertStringContainsString( 'ufw allow 22/tcp', $script );
		$this->assertStringContainsString( 'ufw allow 80/tcp', $script );
		$this->assertStringContainsString( 'ufw allow 443/tcp', $script );
		$this->assertDoesNotMatchRegularExpression( '/ufw allow (?!22|80|443)/', $script );
	}

	public function test_cron_runs_sync_and_cleanup_as_the_web_user_and_the_lock_is_a_file_root_and_www_data_can_both_open(): void {
		$script = $this->script();
		$this->assertStringContainsString( 'www-data /usr/bin/flock -n /opt/easyappointments/.lock /usr/bin/php /var/www/easyappointments/index.php console sync', $script );
		$this->assertStringContainsString( 'www-data /usr/bin/flock -n /opt/easyappointments/.lock /usr/bin/php /var/www/easyappointments/index.php console cleanup', $script );
		$this->assertStringContainsString( 'install -m 0660 -o root -g www-data /dev/null "$app/.lock"', $script );
		$this->assertStringContainsString( 'usermod -aG www-data caddy', $script );
	}

	public function test_the_update_and_rollback_helpers_keep_data_check_the_download_and_roll_back_by_themselves(): void {
		$script = $this->script();
		$this->assertSame( 1, preg_match( "/<<'UPDATE'\n(.*?)\nUPDATE\n/s", $script, $update ) );
		$this->assertSame( 1, preg_match( "/<<'ROLLBACK'\n(.*?)\nROLLBACK\n/s", $script, $rollback ) );
		$this->assertSame( 1, preg_match( "/<<'LIB'\n(.*?)\nLIB\n/s", $script, $lib ) );
		$dir = trailingslashit( get_temp_dir() ) . 'bs-ea-' . wp_generate_password( 8, false );
		mkdir( $dir, 0700, true );
		foreach ( array( 'update' => $update[1], 'rollback' => $rollback[1], 'lib' => $lib[1] ) as $name => $body ) {
			file_put_contents( $dir . '/' . $name . '.sh', $body );
			exec( 'bash -n ' . escapeshellarg( $dir . '/' . $name . '.sh' ), $out, $code );
			$this->assertSame( 0, $code, $name );
		}
		exec( 'rm -rf ' . escapeshellarg( $dir ) );
		$this->assertStringContainsString( '^[0-9]+\.[0-9]+\.[0-9]+$', $update[1] );
		$this->assertStringContainsString( 'https://github.com/alextselegidis/easyappointments/releases/download/$version/$file', $update[1] );
		$this->assertStringContainsString( 'sha256sum -c -', $update[1] );
		$this->assertStringContainsString( '.digest', $update[1] );
		$this->assertStringContainsString( 'trap fail EXIT', $update[1] );
		$this->assertStringContainsString( 'ea_restore "$dir"', $update[1] );
		$this->assertStringContainsString( 'console migrate', $update[1] );
		$this->assertStringContainsString( 'ea_web_check', $update[1] );
		$this->assertStringContainsString( 'php "$lib/patch-index.php"', $lib[1] );
		$this->assertStringContainsString( "--exclude='opt/easyappointments/storage'", $lib[1] );
		$this->assertStringContainsString( 'mariadb-dump --single-transaction', $lib[1] );
		$this->assertStringContainsString( 'cp -p "$app/application/config/email.php" "$work/application/config/email.php"', $lib[1] );
		// Only program files are swapped: never config.php or storage.
		$this->assertDoesNotMatchRegularExpression( '#(rm|mv)[^\n]*(config\.php|/storage)#', preg_replace( '#"\$\{web:\?\}/(assets|index\.php)"#', '', $lib[1] ) );
		$this->assertStringContainsString( 'Run as root.', $rollback[1] );
		$this->assertStringContainsString( 'Type yes to continue', $rollback[1] );
		$this->assertStringContainsString( 'ea_restore "$dir"', $rollback[1] );
		$this->assertStringContainsString( 'install -m 0755 /dev/stdin /usr/local/sbin/easyappointments-update', $script );
		$this->assertStringContainsString( 'install -m 0755 /dev/stdin /usr/local/sbin/easyappointments-rollback', $script );
	}
}
