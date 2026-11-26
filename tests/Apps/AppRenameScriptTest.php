<?php
/**
 * The scripts that run inside the guest when the domain of a running app is changed (apps/rename-common.script and the app's
 * rename.script), run here against stub commands (tests/fixtures/guest-stubs) and temporary Caddy, state and app folders. Nothing
 * touches this machine's web server, Docker or network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppRename;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Tests\TestCase;

class AppRenameScriptTest extends TestCase {

	private const IP     = '192.0.2.10';
	private const OLD    = 'old.example.com';
	private const NEW    = 'shop.example.com';
	private const SECRET = 's3cr3t-value-never-printed';

	/** @var string */
	private $dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->dir = sys_get_temp_dir() . '/bs-ren-' . wp_generate_password( 8, false );

		foreach ( array( 'stub', 'state', 'etc', 'bin', 'app' ) as $sub ) {
			mkdir( $this->dir . '/' . $sub, 0700, true );
		}

		// Only the commands the script needs, so the machine's own caddy, docker and curl are never found.
		foreach ( array( 'awk', 'sed', 'grep', 'tr', 'mktemp', 'date', 'head', 'tail', 'cp', 'rm', 'mv', 'mkdir', 'chmod', 'cat', 'ls', 'jq', 'flock', 'sleep', 'timeout', 'wc', 'touch', 'cmp', 'sort', 'dirname', 'basename', 'id' ) as $tool ) {
			$path = trim( (string) shell_exec( 'command -v ' . $tool ) );
			$this->assertNotSame( '', $path, $tool );
			symlink( $path, $this->dir . '/bin/' . $tool );
		}

		foreach ( array( 'caddy', 'curl', 'docker', 'wp', 'systemctl', 'runuser', 'php' ) as $name ) {
			copy( BELTOFT_SERVICES_PATH . 'tests/fixtures/guest-stubs/' . $name, $this->dir . '/bin/' . $name );
			chmod( $this->dir . '/bin/' . $name, 0755 );
		}
	}

	public function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tear_down();
	}

	/** The site body the recipe of an app writes, read from its install script. */
	private function recipe_body( string $app ): string {
		$text = (string) file_get_contents( $this->install_script( $app ) );
		$this->assertSame( 1, preg_match( '~printf \'([^\']*)\' > /etc/caddy/beltoft-site\.body~', $text, $m ), $app );

		return str_replace( '\n', "\n", $m[1] );
	}

	private function install_script( string $app ): string {
		$versions = array( 'wordpress' => '7.1.4/', 'n8n' => '2/', 'nextcloud' => '35/', 'odoo' => '20/', 'easyappointments' => '1.6/' );

		return BELTOFT_SERVICES_PATH . 'apps/' . $app . '/' . $versions[ $app ] . 'install.script';
	}

	/** The recipe's own generator, extracted and pointed at the test folder. */
	private function recipe_generator( string $app ): string {
		$text = (string) file_get_contents( $this->install_script( $app ) );
		$this->assertSame( 1, preg_match( "/<<'CADDY'\n(.*?)\nCADDY\n/s", $text, $m ), $app );
		$path = $this->dir . '/bin/beltoft-caddy-recipe';
		file_put_contents( $path, str_replace( '/etc/caddy', $this->dir . '/etc', $m[1] ) . "\n" );
		chmod( $path, 0755 );

		return $path;
	}

	/** Puts the app on its starting state: a body file, an old Caddyfile and the app's own host setting. */
	private function install( string $app, string $old = self::OLD, bool $body = true ): void {
		$text = $this->recipe_body( $app );

		if ( $body ) {
			file_put_contents( $this->dir . '/etc/beltoft-site.body', $text );
		}

		$block = '' === $old ? 'http://' . self::IP : $old;
		file_put_contents( $this->dir . '/etc/Caddyfile', $block . " {\n" . $text . "}\n" );
		$address = '' === $old ? 'http://' . self::IP : 'https://' . $old;

		if ( 'wordpress' === $app ) {
			is_dir( $this->dir . '/stub/wp' ) || mkdir( $this->dir . '/stub/wp' );
			file_put_contents( $this->dir . '/stub/wp/siteurl', $address . "\n" );
			file_put_contents( $this->dir . '/stub/wp/home', $address . "\n" );
		} elseif ( 'n8n' === $app ) {
			$host = '' === $old ? self::IP : $old;
			$prot = '' === $old ? 'http' : 'https';
			file_put_contents( $this->dir . '/app/n8n.env', "DB_TYPE=postgresdb\nDB_POSTGRESDB_PASSWORD=" . self::SECRET . "\nN8N_ENCRYPTION_KEY=" . self::SECRET . "\nN8N_HOST={$host}\nN8N_PROTOCOL={$prot}\nN8N_WEBHOOK_URL={$prot}://{$host}/\nN8N_EDITOR_BASE_URL={$prot}://{$host}/\nN8N_PROXY_HOPS=1\nN8N_SECURE_COOKIE=" . ( '' === $old ? 'false' : 'true' ) . "\nTZ=UTC\n" );
			chmod( $this->dir . '/app/n8n.env', 0600 );
		} elseif ( 'nextcloud' === $app ) {
			$extra = '' === $old ? 'OVERWRITECLIURL=http://' . self::IP . "\n" : "OVERWRITEPROTOCOL=https\nOVERWRITEHOST={$old}\nOVERWRITECLIURL=https://{$old}\n";
			file_put_contents( $this->dir . '/app/nextcloud.env', 'POSTGRES_PASSWORD=' . self::SECRET . "\nNEXTCLOUD_TRUSTED_DOMAINS=" . self::IP . " {$old}\n" . $extra );
			chmod( $this->dir . '/app/nextcloud.env', 0600 );
			is_dir( $this->dir . '/stub/nc' ) || mkdir( $this->dir . '/stub/nc' );
			file_put_contents( $this->dir . '/stub/nc/trusted.0', self::IP . "\n" );

			if ( '' !== $old ) {
				file_put_contents( $this->dir . '/stub/nc/trusted.1', $old . "\n" );
			}

			file_put_contents( $this->dir . '/stub/nc/overwrite.cli.url', $address . "\n" );
		} elseif ( 'odoo' === $app ) {
			file_put_contents( $this->dir . '/stub/odoo_base_url', $address . "\n" );
		} elseif ( 'easyappointments' === $app ) {
			file_put_contents( $this->dir . '/app/config.php', "<?php\nclass Config {\n    const BASE_URL      = '{$address}';\n    const LANGUAGE = 'english';\n    const DB_PASSWORD = '" . self::SECRET . "';\n}\n" );
			chmod( $this->dir . '/app/config.php', 0640 );
		}
	}

	/** Writes the request. */
	private function want( string $domain = self::NEW, string $www = '', string $old = self::OLD, string $old_www = '', string $run = 'run00001abc', array $extra = array() ): void {
		file_put_contents( $this->dir . '/state/rename.json', wp_json_encode( array_merge( array( 'v' => 1, 'run' => $run, 'mode' => 'domain', 'domain' => $domain, 'www' => $www, 'old_domain' => $old, 'old_www' => $old_www, 'ip' => self::IP, 'app' => 'x', 'recipe' => 1 ), $extra ) ) );
	}

	/**
	 * Runs the assembled script of an app. Returns the status it wrote, the exit code and the output.
	 *
	 * @param array<string, string> $env Extra environment.
	 * @return array{0: array<string, mixed>, 1: int, 2: string}
	 */
	private function run_script( string $app, array $env = array() ): array {
		$script = AppRename::script( $app );
		$this->assertNotSame( '', $script, $app );
		file_put_contents( $this->dir . '/script.sh', $script );
		$vars = array_merge(
			array(
				'PATH'                => $this->dir . '/bin',
				'STUB_DIR'            => $this->dir . '/stub',
				'BELTOFT_REN_STATE'   => $this->dir . '/state',
				'BELTOFT_REN_ETC'     => $this->dir . '/etc',
				'BELTOFT_REN_APP'     => $this->dir . '/app',
				'BELTOFT_REN_TRIES'   => '2',
				'BELTOFT_REN_SLEEP'   => '0',
				'BELTOFT_REN_WP_BIN'  => $this->dir . '/bin/wp',
				'BELTOFT_REN_WP_PATH' => $this->dir . '/app',
			),
			$env
		);
		$line = 'env -i';

		foreach ( $vars as $name => $value ) {
			$line .= ' ' . $name . '=' . escapeshellarg( $value );
		}

		exec( $line . ' /bin/bash ' . escapeshellarg( $this->dir . '/script.sh' ) . ' 2>&1', $out, $code );
		$status = json_decode( (string) @file_get_contents( $this->dir . '/state/rename.status.json' ), true );

		return array( is_array( $status ) ? $status : array(), $code, implode( "\n", $out ) );
	}

	private function read( string $path ): string {
		return (string) @file_get_contents( $this->dir . '/' . $path );
	}

	private function flag( string $name, string $content = '1' ): void {
		file_put_contents( $this->dir . '/stub/' . $name, $content );
	}

	/** @return array<string, array<int, string>> */
	public static function apps(): array {
		return array(
			'wordpress'        => array( 'wordpress' ),
			'n8n'              => array( 'n8n' ),
			'nextcloud'        => array( 'nextcloud' ),
			'odoo'             => array( 'odoo' ),
			'easyappointments' => array( 'easyappointments' ),
		);
	}

	// -- The files ----------------------------------------------------------------------------

	/** @dataProvider apps */
	public function test_every_script_is_valid_bash_and_never_restarts_the_machine( string $app ): void {
		$this->assertSame( '#!/bin/bash', strtok( AppRename::script( $app ), "\n" ) );
		file_put_contents( $this->dir . '/check.sh', AppRename::script( $app ) );
		exec( 'bash -n ' . escapeshellarg( $this->dir . '/check.sh' ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$this->assertDoesNotMatchRegularExpression( '/\b(reboot|shutdown|search-replace|systemctl (restart|stop)|ufw)\b/', (string) preg_replace( '/^\s*#.*$/m', '', AppRename::script( $app ) ), 'Nothing in the customer content or the machine is touched.' );
		$this->assertStringEndsWith( "\nbeltoft_rename_main\n", AppRename::script( $app ) );
	}

	/** @dataProvider apps */
	public function test_the_final_caddyfile_equals_the_recipe_generator_output( string $app ): void {
		foreach ( array( '', 'www.' . self::NEW ) as $www ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
			$this->install( $app );
			$this->want( self::NEW, $www );
			list( $status, $code ) = $this->run_script( $app );
			$this->assertSame( 0, $code, wp_json_encode( $status ) );
			$this->assertSame( 'ready', $status['state'] );
			$ours = $this->read( 'etc/Caddyfile' );

			$gen = $this->recipe_generator( $app );
			exec( 'env -i PATH=' . escapeshellarg( $this->dir . '/bin' ) . ' STUB_DIR=' . escapeshellarg( $this->dir . '/stub' ) . ' ' . escapeshellarg( $gen ) . ' ' . escapeshellarg( self::NEW ) . ' ' . escapeshellarg( $www ) . ' 2>&1', $out, $gcode );
			$this->assertSame( 0, $gcode, implode( "\n", $out ) );
			$this->assertSame( $this->read( 'etc/Caddyfile' ), $ours, $app . ' with www "' . $www . '"' );
			$this->assertSame( '' === $www ? 0 : 1, substr_count( $ours, 'redir https://' . self::NEW . '{uri} permanent' ) );
		}
	}

	// -- The order ---------------------------------------------------------------------------

	/** @dataProvider apps */
	public function test_dual_name_file_is_written_before_the_app_is_touched( string $app ): void {
		$this->install( $app );
		$this->want( self::NEW, '', self::OLD );
		list( $status ) = $this->run_script( $app );
		$this->assertSame( 'ready', $status['state'], wp_json_encode( $status ) );

		$dual = $this->read( 'stub/snap_tls' );
		$this->assertStringContainsString( self::NEW . " {\n", $dual );
		$this->assertStringContainsString( self::OLD . " {\n", $dual, 'The old name is served together with the new one.' );
		$this->assertSame( $this->recipe_body( $app ), substr( $dual, strlen( self::NEW . " {\n" ), strlen( $this->recipe_body( $app ) ) ), 'Same body.' );

		$at_app = $this->read( 'stub/snap_app' );
		$this->assertSame( $dual, $at_app, 'When the app is first touched the file still serves both names, and the certificate was probed first.' );

		$final = $this->read( 'etc/Caddyfile' );
		$this->assertStringContainsString( self::NEW . " {\n", $final );
		$this->assertStringNotContainsString( self::OLD, $final );
		$this->assertSame( 'done', $status['step'] );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev', 'The saved copies (with secrets) are removed at the end.' );
	}

	public function test_an_app_without_a_domain_is_served_at_its_address_until_the_name_is_proven(): void {
		$this->install( 'wordpress', '' );
		$this->want( self::NEW, '', '' );
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'ready', $status['state'], wp_json_encode( $status ) );
		$dual = $this->read( 'stub/snap_tls' );
		$this->assertStringContainsString( 'http://' . self::IP . " {\n", $dual );
		$this->assertStringNotContainsString( 'http://', $this->read( 'etc/Caddyfile' ), 'After the change the plain address is gone.' );
	}

	/** @dataProvider apps */
	public function test_a_failed_certificate_probe_restores_the_old_caddyfile_and_leaves_the_app_setting( string $app ): void {
		$this->install( $app );
		$before = $this->read( 'etc/Caddyfile' );
		$this->flag( 'curl_tls_fail' );
		$this->want();
		list( $status, $code ) = $this->run_script( $app );

		$this->assertSame( 1, $code );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'certificate', $status['reason'] );
		$this->assertSame( 'done', $status['step'] );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ), 'The old Caddyfile is back, byte for byte.' );
		$this->assertFileDoesNotExist( $this->dir . '/stub/snap_app', 'The app was never touched.' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev' );
	}

	public function test_a_failed_validation_of_the_dual_file_changes_nothing(): void {
		$this->install( 'wordpress' );
		$before = $this->read( 'etc/Caddyfile' );
		$this->flag( 'caddy_validate_fail' );
		$this->want();
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'caddy', $status['reason'] );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ) );
		$this->assertSame( array(), glob( $this->dir . '/etc/Caddyfile.*' ), 'No temporary file is left behind.' );
	}

	/** The failing step of each app: WordPress option update, n8n and Nextcloud recreate, Odoo shell, EA config check. */
	public static function failing(): array {
		return array(
			'wordpress'        => array( 'wordpress', 'wp_update_fail', '1' ),
			'n8n'              => array( 'n8n', 'docker_up_fail', '1' ),
			'nextcloud'        => array( 'nextcloud', 'docker_up_fail', '1' ),
			'odoo'             => array( 'odoo', 'odoo_set_fail', '1' ),
			'easyappointments' => array( 'easyappointments', 'php_fail', '1' ),
		);
	}

	/** @dataProvider failing */
	public function test_a_failed_app_step_restores_both( string $app, string $flag, string $value ): void {
		$this->install( $app );
		$caddy = $this->read( 'etc/Caddyfile' );
		$files = array(
			'wordpress'        => array( 'stub/wp/siteurl', 'stub/wp/home' ),
			'n8n'              => array( 'app/n8n.env' ),
			'nextcloud'        => array( 'app/nextcloud.env', 'stub/nc/trusted.0', 'stub/nc/trusted.1', 'stub/nc/overwrite.cli.url' ),
			'odoo'             => array( 'stub/odoo_base_url' ),
			'easyappointments' => array( 'app/config.php' ),
		)[ $app ];
		$old = array();

		foreach ( $files as $f ) {
			$old[ $f ] = $this->read( $f );
		}

		$this->flag( $flag, $value );

		$this->want();
		list( $status, $code, $out ) = $this->run_script( $app );

		$this->assertSame( 1, $code, $out );
		$this->assertSame( 'failed', $status['state'], wp_json_encode( $status ) . $out );
		$this->assertSame( 'app', $status['reason'] );
		$this->assertSame( 'done', $status['step'], 'The rollback worked.' );
		$this->assertSame( $caddy, $this->read( 'etc/Caddyfile' ), 'The Caddyfile is the old one again.' );

		foreach ( $old as $f => $content ) {
			$this->assertSame( $content, $this->read( $f ), $f . ' is back as it was.' );
		}

		$this->assertStringNotContainsString( self::SECRET, $out, 'Nothing secret is printed.' );
	}

	public function test_a_failed_final_probe_also_puts_everything_back(): void {
		$this->install( 'wordpress' );
		$caddy = $this->read( 'etc/Caddyfile' );
		$this->flag( 'tls_ok_limit', '1' );
		$this->want();
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'probe', $status['reason'] );
		$this->assertSame( $caddy, $this->read( 'etc/Caddyfile' ) );
		$this->assertSame( 'https://' . self::OLD, trim( $this->read( 'stub/wp/siteurl' ) ) );
	}

	public function test_a_rollback_that_cannot_finish_says_so(): void {
		$this->install( 'wordpress' );
		$this->flag( 'curl_tls_fail' );
		$this->flag( 'systemctl_caddy_fail' );
		$this->want();
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'rollback', $status['step'] );
	}

	// -- Runs --------------------------------------------------------------------------------

	public function test_second_run_of_the_same_token_changes_nothing(): void {
		$this->install( 'n8n' );
		$this->want();
		list( $first ) = $this->run_script( 'n8n' );
		$this->assertSame( 'ready', $first['state'] );
		$caddy = $this->read( 'etc/Caddyfile' );
		$env   = $this->read( 'app/n8n.env' );
		$calls = $this->read( 'stub/calls.log' );
		$stat  = $this->read( 'state/rename.status.json' );

		list( $second, $code ) = $this->run_script( 'n8n' );
		$this->assertSame( 0, $code );
		$this->assertSame( $caddy, $this->read( 'etc/Caddyfile' ) );
		$this->assertSame( $env, $this->read( 'app/n8n.env' ) );
		$this->assertSame( $calls, $this->read( 'stub/calls.log' ), 'Nothing was run again.' );
		$this->assertSame( $stat, $this->read( 'state/rename.status.json' ) );
		$this->assertSame( $first, $second );
	}

	public function test_a_run_that_was_cut_off_is_finished_by_the_same_token_without_losing_the_original_state(): void {
		$this->install( 'wordpress' );
		$original = $this->read( 'etc/Caddyfile' );
		$this->want();

		// The first run dies after the dual file and the saved copies: simulate it with a failing app step that skips the rollback.
		mkdir( $this->dir . '/state/rename.prev', 0700 );
		copy( $this->dir . '/etc/Caddyfile', $this->dir . '/state/rename.prev/Caddyfile' );
		file_put_contents( $this->dir . '/state/rename.prev/siteurl', "https://" . self::OLD . "\n" );
		file_put_contents( $this->dir . '/state/rename.prev/home', "https://" . self::OLD . "\n" );
		file_put_contents( $this->dir . '/state/rename.prev/run', "run00001abc\n" );
		touch( $this->dir . '/state/rename.prev/app.touched' );
		file_put_contents( $this->dir . '/etc/Caddyfile', self::NEW . " {\n}\n" . self::OLD . " {\n}\n" );
		file_put_contents( $this->dir . '/state/rename.status.json', wp_json_encode( array( 'v' => 1, 'run' => 'run00001abc', 'state' => 'running', 'step' => 'app', 'reason' => 'none', 'domain' => self::NEW, 'updated' => gmdate( 'c' ) ) ) );

		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'ready', $status['state'], wp_json_encode( $status ) );
		$this->assertStringContainsString( $this->recipe_body( 'wordpress' ), $this->read( 'etc/Caddyfile' ) );

		// And a failure now still returns to the state saved by the first run.
		$this->install( 'wordpress' );
		$this->want( self::NEW, '', self::OLD, '', 'run00002abc' );
		$this->flag( 'curl_tls_fail' );
		list( $failed ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $failed['state'] );
		$this->assertSame( $original, $this->read( 'etc/Caddyfile' ) );
	}

	public function test_another_token_while_a_run_holds_the_lock_is_told_it_is_busy(): void {
		$this->install( 'wordpress' );
		$this->want( self::NEW, '', self::OLD, '', 'run00002abc' );
		$holder = proc_open( 'flock ' . escapeshellarg( $this->dir . '/state/rename.lock' ) . ' sleep 5', array(), $pipes );
		usleep( 300000 );
		list( $status, $code ) = $this->run_script( 'wordpress' );
		proc_terminate( $holder );
		proc_close( $holder );

		$this->assertSame( 0, $code );
		$this->assertSame( 'busy', $status['reason'] ?? '' );
		$this->assertSame( 'run00002abc', $status['run'] ?? '' );
		$this->assertFileDoesNotExist( $this->dir . '/stub/calls.log', 'Nothing ran.' );
	}

	public function test_a_request_with_a_bad_name_is_refused_before_anything_changes(): void {
		$this->install( 'wordpress' );
		$before = $this->read( 'etc/Caddyfile' );

		foreach ( array( 'shop', 'Shop.Example.com', 'a b.example.com', 'x.example.com; rm -rf /' ) as $bad ) {
			$this->want( $bad );
			$this->run_script( 'wordpress' );
			$this->assertSame( $before, $this->read( 'etc/Caddyfile' ), $bad );
			$this->assertFileDoesNotExist( $this->dir . '/stub/calls.log' );
		}

		$this->want( self::OLD );
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $status['state'], 'The same name as now is not a change.' );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ) );
	}

	public function test_legacy_recipe_without_body_file_gets_the_body_written(): void {
		foreach ( array( 'wordpress', 'n8n' ) as $app ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
			@unlink( $this->dir . '/etc/beltoft-site.body' );
			$this->install( $app, self::OLD, false );
			$this->assertFileDoesNotExist( $this->dir . '/etc/beltoft-site.body' );
			$this->want();
			list( $status ) = $this->run_script( $app );
			$this->assertSame( 'ready', $status['state'], $app . wp_json_encode( $status ) );
			$this->assertSame( $this->recipe_body( $app ), $this->read( 'etc/beltoft-site.body' ), $app );
		}

		// A failure takes the file it made away again.
		exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
		unlink( $this->dir . '/etc/beltoft-site.body' );
		$this->install( 'wordpress', self::OLD, false );
		$before = $this->read( 'etc/Caddyfile' );
		$this->flag( 'curl_tls_fail' );
		$this->want();
		$this->run_script( 'wordpress' );
		$this->assertFileDoesNotExist( $this->dir . '/etc/beltoft-site.body' );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ) );
	}

	public function test_an_app_without_a_legacy_body_and_without_a_body_file_fails_closed(): void {
		$this->install( 'odoo', self::OLD, false );
		$before = $this->read( 'etc/Caddyfile' );
		$this->want();
		list( $status ) = $this->run_script( 'odoo' );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ) );
		$this->assertFileDoesNotExist( $this->dir . '/stub/odoo_base_url.new' );
	}

	// -- The app's own host setting -----------------------------------------------------------

	public function test_wordpress_changes_siteurl_and_home_only(): void {
		$this->install( 'wordpress' );
		$this->want();
		$this->run_script( 'wordpress' );
		$this->assertSame( 'https://' . self::NEW, trim( $this->read( 'stub/wp/siteurl' ) ) );
		$this->assertSame( 'https://' . self::NEW, trim( $this->read( 'stub/wp/home' ) ) );
		$calls = $this->read( 'stub/calls.log' );
		$this->assertSame( 2, preg_match_all( '/^wp option update /m', $calls ), 'Two options, nothing else is written.' );
		$this->assertDoesNotMatchRegularExpression( '/^wp (search-replace|db|post|core)/m', $calls );
	}

	public function test_n8n_sets_the_five_keys_and_a_true_secure_cookie_and_keeps_every_other_line(): void {
		$this->install( 'n8n', '' );
		$this->want( self::NEW, '', '' );
		$before = array_map( 'trim', file( $this->dir . '/app/n8n.env' ) );
		$this->assertContains( 'N8N_SECURE_COOKIE=false', $before );
		list( $status, , $out ) = $this->run_script( 'n8n' );
		$this->assertSame( 'ready', $status['state'], $out );

		$after = array_map( 'trim', file( $this->dir . '/app/n8n.env' ) );
		$this->assertContains( 'N8N_HOST=' . self::NEW, $after );
		$this->assertContains( 'N8N_PROTOCOL=https', $after );
		$this->assertContains( 'N8N_WEBHOOK_URL=https://' . self::NEW . '/', $after );
		$this->assertContains( 'N8N_EDITOR_BASE_URL=https://' . self::NEW . '/', $after );
		$this->assertContains( 'N8N_SECURE_COOKIE=true', $after );
		$changed = array_values( array_diff( $after, $before ) );
		$this->assertCount( 5, $changed );
		$this->assertSame( array(), array_values( array_diff( $before, $after, array( 'N8N_HOST=' . self::IP, 'N8N_PROTOCOL=http', 'N8N_WEBHOOK_URL=http://' . self::IP . '/', 'N8N_EDITOR_BASE_URL=http://' . self::IP . '/', 'N8N_SECURE_COOKIE=false' ) ) ), 'Only the five keys differ.' );
		$this->assertSame( '0600', substr( sprintf( '%o', fileperms( $this->dir . '/app/n8n.env' ) ), -4 ) );
		$this->assertStringNotContainsString( self::SECRET, $out );
		$this->assertStringContainsString( 'docker compose up -d --no-deps --force-recreate n8n', $this->read( 'stub/calls.log' ) );
	}

	public function test_nextcloud_adds_the_names_at_the_next_free_trusted_domain_indexes(): void {
		$this->install( 'nextcloud' );
		$this->want( self::NEW, 'www.' . self::NEW );
		list( $status, , $out ) = $this->run_script( 'nextcloud' );
		$this->assertSame( 'ready', $status['state'], $out );

		$this->assertSame( self::IP, trim( $this->read( 'stub/nc/trusted.0' ) ) );
		$this->assertSame( self::OLD, trim( $this->read( 'stub/nc/trusted.1' ) ), 'The old name stays trusted.' );
		$this->assertSame( self::NEW, trim( $this->read( 'stub/nc/trusted.2' ) ) );
		$this->assertSame( 'www.' . self::NEW, trim( $this->read( 'stub/nc/trusted.3' ) ) );
		$this->assertSame( 'https://' . self::NEW, trim( $this->read( 'stub/nc/overwrite.cli.url' ) ) );

		$env = $this->read( 'app/nextcloud.env' );
		$this->assertStringContainsString( "OVERWRITEPROTOCOL=https\n", $env );
		$this->assertStringContainsString( 'OVERWRITEHOST=' . self::NEW . "\n", $env );
		$this->assertStringContainsString( 'OVERWRITECLIURL=https://' . self::NEW . "\n", $env );
		$this->assertSame( 1, substr_count( $env, 'OVERWRITEHOST=' ) );
		$this->assertStringContainsString( 'POSTGRES_PASSWORD=' . self::SECRET, $env );
		$this->assertStringNotContainsString( self::SECRET, $out );

		// A second change to another name takes the next indexes and a rollback removes only what the run added.
		$this->want( 'next.example.com', '', self::NEW, '', 'run00002abc' );
		$this->flag( 'docker_fail_match', 'config:system:set overwrite.cli.url' );
		list( $failed ) = $this->run_script( 'nextcloud' );
		$this->assertSame( 'failed', $failed['state'] );
		$this->assertFileDoesNotExist( $this->dir . '/stub/nc/trusted.4', 'The index the failed run added is deleted again.' );
		$this->assertSame( 'www.' . self::NEW, trim( $this->read( 'stub/nc/trusted.3' ) ) );
	}

	public function test_odoo_sets_only_the_base_url_parameter_through_the_orm(): void {
		$this->install( 'odoo' );
		$this->want();
		list( $status, , $out ) = $this->run_script( 'odoo' );
		$this->assertSame( 'ready', $status['state'], $out );
		$this->assertSame( 'https://' . self::NEW, trim( $this->read( 'stub/odoo_base_url' ) ) );
		$this->assertSame( 'https://' . self::NEW . "\n", $this->read( 'stub/odoo_sets' ), 'One write, of this parameter.' );
		$this->assertDoesNotMatchRegularExpression( '/up -d|restart|stop/', $this->read( 'stub/calls.log' ), 'No restart.' );
		$this->assertStringNotContainsString( 'freeze', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/rename.script' ), 'The freeze flag is not touched (to be confirmed live).' );
	}

	public function test_odoo_without_a_stored_base_url_gets_none_back_on_rollback(): void {
		$this->install( 'odoo' );
		unlink( $this->dir . '/stub/odoo_base_url' );
		$this->flag( 'curl_tls_fail' );
		$this->want();
		list( $status ) = $this->run_script( 'odoo' );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertFileDoesNotExist( $this->dir . '/stub/odoo_base_url' );

		// The certificate worked, the app step failed: the parameter that did not exist is removed again.
		$this->flag( 'curl_tls_fail', '' );
		unlink( $this->dir . '/stub/curl_tls_fail' );
		$this->install( 'odoo' );
		unlink( $this->dir . '/stub/odoo_base_url' );
		$this->flag( 'curl_web_fail' );
		$this->want( self::NEW, '', self::OLD, '', 'run00003abc' );
		list( $failed ) = $this->run_script( 'odoo' );
		$this->assertSame( 'app', $failed['reason'] );
		$this->assertFileDoesNotExist( $this->dir . '/stub/odoo_base_url' );
	}

	public function test_easyappointments_replaces_base_url_exactly_once_and_keeps_the_rest(): void {
		$this->install( 'easyappointments' );
		$before = $this->read( 'app/config.php' );
		$this->want( self::NEW, 'www.' . self::NEW );
		list( $status, , $out ) = $this->run_script( 'easyappointments' );
		$this->assertSame( 'ready', $status['state'], $out );
		$after = $this->read( 'app/config.php' );
		$this->assertSame( str_replace( "'https://" . self::OLD . "'", "'https://" . self::NEW . "'", $before ), $after, 'Only the value of BASE_URL differs; the spacing of the line is kept.' );
		$this->assertSame( 1, substr_count( $after, 'const BASE_URL' ) );
		$this->assertSame( '0640', substr( sprintf( '%o', fileperms( $this->dir . '/app/config.php' ) ), -4 ) );
		$this->assertStringContainsString( 'systemctl reload php8.3-fpm', $this->read( 'stub/calls.log' ) );
		$this->assertStringNotContainsString( self::SECRET, $out );
	}

	public function test_easyappointments_fails_closed_when_base_url_is_not_found_exactly_once(): void {
		foreach ( array( 'none' => "<?php\nclass Config {\n    const LANGUAGE = 'english';\n}\n", 'twice' => "<?php\nclass Config {\n    const BASE_URL = 'https://" . self::OLD . "';\n    const BASE_URL = 'https://other.example.com';\n}\n" ) as $name => $config ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
			$this->install( 'easyappointments' );
			file_put_contents( $this->dir . '/app/config.php', $config );
			$caddy = $this->read( 'etc/Caddyfile' );
			$this->want();
			list( $status ) = $this->run_script( 'easyappointments' );
			$this->assertSame( 'failed', $status['state'], $name );
			$this->assertSame( $config, $this->read( 'app/config.php' ), $name . ': the file is not written.' );
			$this->assertSame( $caddy, $this->read( 'etc/Caddyfile' ), $name );
		}
	}

	// -- The overall deadline (F1) -------------------------------------------------------------

	public function test_the_run_has_one_overall_deadline_and_ends_in_the_normal_failure_path(): void {
		$this->install( 'wordpress' );
		$caddy = $this->read( 'etc/Caddyfile' );
		$this->flag( 'curl_tls_fail' );
		$this->want( self::NEW, '', self::OLD, '', 'run00001abc', array( 'deadline' => 3 ) );
		$t = microtime( true );
		list( $status, $code ) = $this->run_script( 'wordpress', array( 'BELTOFT_REN_TRIES' => '60', 'BELTOFT_REN_SLEEP' => '1', 'BELTOFT_REN_MIN_DEADLINE' => '1' ) );
		$took = microtime( true ) - $t;

		$this->assertLessThan( 15, $took, 'The waits stop at the deadline, they do not run all their tries.' );
		$this->assertSame( 1, $code );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'late', $status['reason'] );
		$this->assertSame( 'done', $status['step'], 'The rollback worked.' );
		$this->assertSame( $caddy, $this->read( 'etc/Caddyfile' ) );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev' );
	}

	public function test_the_deadline_also_stops_the_wait_for_the_app_and_puts_the_app_value_back(): void {
		$this->install( 'wordpress' );
		$this->flag( 'curl_web_fail' );
		$this->want( self::NEW, '', self::OLD, '', 'run00001abc', array( 'deadline' => 3 ) );
		list( $status ) = $this->run_script( 'wordpress', array( 'BELTOFT_REN_TRIES' => '60', 'BELTOFT_REN_SLEEP' => '1', 'BELTOFT_REN_MIN_DEADLINE' => '1' ) );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'late', $status['reason'] );
		$this->assertSame( 'https://' . self::OLD, trim( $this->read( 'stub/wp/siteurl' ) ) );
	}

	public function test_a_deadline_that_is_not_a_sane_number_is_ignored(): void {
		foreach ( array( 'soon', -5, 0, 1000000, 12.5, array( 1 ) ) as $bad ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
			$this->install( 'wordpress' );
			$this->want( self::NEW, '', self::OLD, '', 'run00001abc', array( 'deadline' => $bad ) );
			list( $status ) = $this->run_script( 'wordpress' );
			$this->assertSame( 'ready', $status['state'], wp_json_encode( $bad ) );
		}
	}

	// -- A run cut off earlier (F3) ------------------------------------------------------------

	public function test_a_new_token_first_restores_what_a_cut_off_run_saved(): void {
		$this->install( 'wordpress' );
		$original = $this->read( 'etc/Caddyfile' );
		$this->want( self::NEW, '', self::OLD, '', 'runAAAAAAAA' );

		// Run A saved the original, changed the Caddyfile and the app value, and was cut off.
		mkdir( $this->dir . '/state/rename.prev', 0700 );
		copy( $this->dir . '/etc/Caddyfile', $this->dir . '/state/rename.prev/Caddyfile' );
		file_put_contents( $this->dir . '/state/rename.prev/siteurl', 'https://' . self::OLD . "\n" );
		file_put_contents( $this->dir . '/state/rename.prev/home', 'https://' . self::OLD . "\n" );
		file_put_contents( $this->dir . '/state/rename.prev/run', "runAAAAAAAA\n" );
		touch( $this->dir . '/state/rename.prev/app.touched' );
		file_put_contents( $this->dir . '/etc/Caddyfile', self::NEW . " {\n}\n" . self::OLD . " {\n}\n" );
		file_put_contents( $this->dir . '/stub/wp/siteurl', 'https://' . self::NEW . "\n" );
		file_put_contents( $this->dir . '/stub/wp/home', 'https://' . self::NEW . "\n" );

		// Run B (a new token) fails: the true original is back, not the half-changed state run A left.
		$this->want( self::NEW, '', self::OLD, '', 'runBBBBBBBB' );
		$this->flag( 'curl_tls_fail' );
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $status['state'], wp_json_encode( $status ) );
		$this->assertSame( 'done', $status['step'] );
		$this->assertSame( $original, $this->read( 'etc/Caddyfile' ) );
		$this->assertSame( 'https://' . self::OLD, trim( $this->read( 'stub/wp/siteurl' ) ) );
		$this->assertSame( 'https://' . self::OLD, trim( $this->read( 'stub/wp/home' ) ) );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev' );
	}

	public function test_a_new_token_that_cannot_restore_the_earlier_run_changes_nothing_more(): void {
		$this->install( 'wordpress' );
		mkdir( $this->dir . '/state/rename.prev', 0700 );
		copy( $this->dir . '/etc/Caddyfile', $this->dir . '/state/rename.prev/Caddyfile' );
		file_put_contents( $this->dir . '/state/rename.prev/run', "runAAAAAAAA\n" );
		$this->flag( 'systemctl_caddy_fail' );
		$this->want( self::NEW, '', self::OLD, '', 'runBBBBBBBB' );
		list( $status, $code ) = $this->run_script( 'wordpress' );
		$this->assertSame( 1, $code );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'rollback', $status['step'] );
		$this->assertSame( 'runBBBBBBBB', $status['run'] );
		$this->assertFileExists( $this->dir . '/state/rename.prev/run', 'The saved copy is kept for a person to use.' );
		$this->assertSame( 'runAAAAAAAA', trim( $this->read( 'state/rename.prev/run' ) ) );
		$this->assertStringNotContainsString( 'caddy validate', $this->read( 'stub/calls.log' ), 'No new Caddyfile was made.' );
		$this->assertStringNotContainsString( 'wp option update', $this->read( 'stub/calls.log' ) );
	}

	public function test_a_finished_run_leaves_nothing_to_restore(): void {
		$this->install( 'n8n' );
		$this->want();
		list( $ready ) = $this->run_script( 'n8n' );
		$this->assertSame( 'ready', $ready['state'] );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev' );

		exec( 'rm -rf ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) . ' && mkdir -p ' . escapeshellarg( $this->dir . '/state' ) . ' ' . escapeshellarg( $this->dir . '/stub' ) );
		$this->install( 'n8n' );
		$this->flag( 'docker_up_fail' );
		$this->want();
		list( $failed ) = $this->run_script( 'n8n' );
		$this->assertSame( 'done', $failed['step'] );
		$this->assertDirectoryDoesNotExist( $this->dir . '/state/rename.prev' );
	}

	// -- www and the bare domain (F5) -----------------------------------------------------------

	public function test_moving_from_www_to_the_bare_domain_serves_each_host_once(): void {
		$this->install( 'wordpress', 'www.example.com' );
		$this->want( 'example.com', 'www.example.com', 'www.example.com', '' );
		list( $status, , $out ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'ready', $status['state'], wp_json_encode( $status ) . $out );

		$dual = $this->read( 'stub/snap_tls' );
		$this->assertSame( 1, substr_count( $dual, "www.example.com {\n" ), 'While both must answer the www name serves the site once.' );
		$this->assertStringNotContainsString( 'redir', $dual, 'The name that is in both sets serves the site, not a redirect.' );
		$this->assertStringContainsString( "\nexample.com {\n", "\n" . $dual );

		$final = $this->read( 'etc/Caddyfile' );
		$this->assertSame( 1, substr_count( $final, "\nexample.com {\n" ) + ( str_starts_with( $final, "example.com {\n" ) ? 1 : 0 ) );
		$this->assertSame( 1, substr_count( $final, "www.example.com {\n  redir https://example.com{uri} permanent\n}\n" ), 'In the end www redirects to the domain.' );
		$this->assertSame( 1, substr_count( $final, 'www.example.com {' ) );
		$this->assertSame( 'https://example.com', trim( $this->read( 'stub/wp/siteurl' ) ) );
	}

	public function test_moving_from_the_bare_domain_to_its_www_name_works_and_drops_the_old_name(): void {
		$this->install( 'wordpress', 'example.com' );
		$this->want( 'www.example.com', '', 'example.com', 'www.example.com' );
		list( $status, , $out ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'ready', $status['state'], wp_json_encode( $status ) . $out );

		$dual = $this->read( 'stub/snap_tls' );
		$this->assertSame( 1, substr_count( $dual, "www.example.com {\n" ) );
		$this->assertStringNotContainsString( 'redir', $dual );
		$this->assertSame( 1, substr_count( $dual, "\nexample.com {\n" . $this->recipe_body( 'wordpress' ) ) + ( str_starts_with( $dual, "example.com {\n" ) ? 1 : 0 ) );

		$final = $this->read( 'etc/Caddyfile' );
		$this->assertSame( "www.example.com {\n" . $this->recipe_body( 'wordpress' ) . "}\n", $final, 'Only the new name, no redirect to itself.' );
		$this->assertSame( 'https://www.example.com', trim( $this->read( 'stub/wp/siteurl' ) ) );
	}

	public function test_a_failure_of_a_www_move_puts_the_old_caddyfile_back(): void {
		$this->install( 'wordpress', 'example.com' );
		$before = $this->read( 'etc/Caddyfile' );
		$this->flag( 'curl_tls_fail' );
		$this->want( 'www.example.com', '', 'example.com', 'www.example.com' );
		list( $status ) = $this->run_script( 'wordpress' );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( $before, $this->read( 'etc/Caddyfile' ) );
	}
}
