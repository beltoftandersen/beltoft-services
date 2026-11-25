<?php
/** The two editions of the Odoo one-click app: standard and Danish accounting, chosen with the version select. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppField;
use BeltoftServices\Apps\AppReinstall;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * No network: FakeClient only, and the install scripts are only read, never run.
 */
final class AppOdooEditionTest extends PaidAppCase {

	private const SNIPPET = 'local:snippets/vm-vendor-cloudinit-v4.yaml';

	private const HELPER_URL    = 'https://github.com/beltoftandersen/odoo-helper-module/archive/9192788d308630f319e838f45bf236d9cdc8ed85.tar.gz';
	private const HELPER_SHA256 = 'a349cfab915e2b7980decd78e91aa1acdc267b887fba22f815c6af075ca2d364';
	private const BUNDLE_URL    = 'https://github.com/beltoftandersen/odoo-dk-accounting/archive/refs/tags/20.0.1.0.0.tar.gz';
	private const BUNDLE_SHA256 = '47532a727308665894bc9af4afb04716a4093047c30dd80165391e2f2d8d49b4';

	private const BUNDLE_MODULES = array(
		'account_usability', 'account_statement_base', 'account_statement_import_base', 'account_statement_import_file',
		'account_statement_import_sheet_file', 'account_reconcile_oca', 'account_statement_import_file_reconcile_oca',
		'account_in_payment', 'date_range', 'report_xlsx', 'account_financial_report', 'account_tax_balance',
		'mis_builder', 'l10n_dk_mis_reports', 'l10n_dk_saft_header',
	);

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => '9001 = Ubuntu 24.04', 'default_template' => '',
			'vendor_snippet' => self::SNIPPET,
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'odoo' ),
			'template_families_text' => '9001 = ubuntu-24.04',
		) ) );
	}

	private function script( string $version ): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/' . $version . '/install.script' );
	}

	/** The script without its edition block: what both editions must share. */
	private function shared( string $version ): string {
		$script = $this->script( $version );
		$from   = strpos( $script, '# >>> EDITION BLOCK' );
		$to     = strpos( $script, '# <<< EDITION BLOCK' );
		$this->assertNotFalse( $from, $version );
		$this->assertNotFalse( $to, $version );
		$this->assertSame( 1, substr_count( $script, '# >>> EDITION BLOCK' ) );
		return substr( $script, 0, $from ) . substr( $script, $to );
	}

	private function edition( string $version ): string {
		$script = $this->script( $version );
		$from   = (int) strpos( $script, '# >>> EDITION BLOCK' );
		return substr( $script, $from, (int) strpos( $script, '# <<< EDITION BLOCK' ) - $from );
	}

	/** A ready server with Odoo of the given version. */
	private function odoo_server( string $version, int $vmid ): Resource {
		$o = $this->ordered( null, 'basic', $vmid );
		$this->assertTrue( $o['server']->set( array( 'app_id' => 'odoo', 'app_version' => $version, 'app_state' => 'ready', OsField::META => 9001, 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 100 ) ) ) );
		return Resource::find( $o['server']->id() );
	}

	// -- Manifest ------------------------------------------------------------------------------------

	public function test_two_versions_with_matching_checksums_and_the_standard_one_is_the_current_and_first(): void {
		$raw = json_decode( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/odoo/app.json' ), true );
		$this->assertSame( array( '20', '20-dk' ), array_column( $raw['versions'], 'id' ) );
		$this->assertSame( 'Odoo 20, Danish accounting', $raw['versions'][1]['label'] );
		$this->assertSame( 'available', $raw['versions'][1]['status'] );
		$this->assertSame( array( 'ubuntu-24.04' ), $raw['versions'][1]['os'] );
		$this->assertSame( $raw['versions'][0]['min_plan'], $raw['versions'][1]['min_plan'] );
		$this->assertSame( 'current', $raw['versions'][0]['status'] );
		$this->assertArrayNotHasKey( 'default', $raw['versions'][1] );

		$sums = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' );
		foreach ( $raw['versions'] as $v ) {
			$hash = hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/odoo/' . $v['id'] . '/install.script' );
			$this->assertSame( $hash, $v['sha256'], $v['id'] );
			$this->assertStringContainsString( $hash . '  odoo/' . $v['id'] . '/install.script', $sums );
			$this->assertNotNull( Apps::version( 'odoo', $v['id'] ), $v['id'] );
		}
		$this->assertSame( '20', Apps::current( 'odoo' )['version_id'], 'One current version, the standard edition.' );
	}

	public function test_checkout_offers_both_editions_and_the_standard_one_leads(): void {
		$offered = Apps::offered( (array) Clusters::get( 'lab' ) );
		$this->assertSame( array( '20', '20-dk' ), array_map( 'strval', array_keys( $offered['odoo']['choices'] ) ) );
		$this->assertSame( 'Odoo 20, Danish accounting', $offered['odoo']['choices']['20-dk']['label'] );
		$offer = AppField::offer_for( $this->vm_product()->get_id() );
		$this->assertSame( array( '20', '20-dk' ), array_map( 'strval', array_keys( $offer['apps']['odoo']['choices'] ) ) );
	}

	public function test_the_text_names_the_danish_edition_and_the_saft_place_without_em_dash_or_middle_dot(): void {
		$text = (string) Apps::current( 'odoo' )['getting_started'];
		$this->assertStringContainsString( 'The Danish accounting edition also sets up', $text );
		$this->assertStringContainsString( 'SAF-T file, which you find under Accounting, Reporting', $text );
		$this->assertStringNotContainsString( "\u{2014}", $text );
		$this->assertStringNotContainsString( "\u{00B7}", $text );
		$this->assertArrayHasKey( $text, \BeltoftServices\Apps\AppTexts::all() );
	}

	// -- Scripts -------------------------------------------------------------------------------------

	public function test_both_scripts_are_valid_bash_and_differ_only_inside_the_edition_block(): void {
		foreach ( array( '20', '20-dk' ) as $v ) {
			exec( 'bash -n ' . escapeshellarg( BELTOFT_SERVICES_PATH . 'apps/odoo/' . $v . '/install.script' ), $out, $code );
			$this->assertSame( 0, $code, $v );
		}
		$this->assertSame( $this->shared( '20' ), $this->shared( '20-dk' ), 'Everything outside the edition block is the same in both scripts.' );
		$this->assertNotSame( $this->edition( '20' ), $this->edition( '20-dk' ) );
	}

	public function test_both_editions_install_the_helper_with_a_pinned_checksum_and_fail_closed(): void {
		foreach ( array( '20', '20-dk' ) as $v ) {
			$script = $this->script( $v );
			$this->assertStringContainsString( 'helper_url=' . self::HELPER_URL, $script, $v );
			$this->assertStringContainsString( 'helper_sha256=' . self::HELPER_SHA256, $script, $v );
			$this->assertStringContainsString( "curl --proto '=https' --tlsv1.2", $script );
			$this->assertStringContainsString( 'sha256sum', $script );
			$this->assertStringContainsString( 'extra-addons/odoowoo_helper', $script );
			$this->assertStringContainsString( "--exclude='*/qa' --exclude='*/tests'", $script );
			$this->assertStringContainsString( '/opt/odoo/extra-addons:/mnt/extra-addons:ro', $script );
			$this->assertStringContainsString( 'addons_path = /mnt/extra-addons', $script );
			$this->assertStringContainsString( 'set -Eeuo pipefail', $script );
		}
		$this->assertStringContainsString( 'modules=odoowoo_helper', $this->edition( '20' ) );
		$this->assertStringNotContainsString( 'l10n_dk', $this->script( '20' ) );
		$this->assertStringNotContainsString( 'odoo-dk-accounting', $this->script( '20' ) );
		$this->assertStringNotContainsString( 'da_DK', $this->script( '20' ) );
	}

	public function test_the_danish_edition_installs_the_bundle_and_sets_up_denmark(): void {
		$script = $this->script( '20-dk' );
		$this->assertStringContainsString( 'bundle_url=' . self::BUNDLE_URL, $script );
		$this->assertStringContainsString( 'bundle_sha256=' . self::BUNDLE_SHA256, $script );
		$block = $this->edition( '20-dk' );
		foreach ( self::BUNDLE_MODULES as $m ) {
			$this->assertMatchesRegularExpression( '/bundle_modules=\'[^\']*\b' . $m . '\b/', $block, $m );
		}
		$this->assertStringContainsString( 'modules="account,l10n_dk,odoowoo_helper,', $block );
		$this->assertStringContainsString( '--load-language=da_DK', $block );
		$this->assertStringContainsString( 'base.dk', $block );
		$this->assertStringContainsString( 'base.DKK', $block );
		$this->assertStringContainsString( 'lang = "da_DK"', $block );
		// The company is in Denmark before the Danish package is installed, and the language is loaded with the first init.
		$this->assertLessThan( strpos( $script, '-i "$modules"' ), strpos( $script, 'printf \'%s\' "$edition_python"' ) );
		$this->assertStringContainsString( '-i base --without-demo=all "${language_args[@]}"', $script );
	}

	// -- Reinstall -----------------------------------------------------------------------------------

	public function test_a_standard_server_can_be_reinstalled_as_the_danish_edition(): void {
		$this->switch_edition( '20', '20-dk' );
	}

	public function test_a_danish_server_can_be_reinstalled_as_the_standard_edition(): void {
		$this->switch_edition( '20-dk', '20' );
	}

	private function switch_edition( string $from, string $to ): void {
		$r = $this->odoo_server( $from, 5000 );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$options = AppReinstall::options( $r );
		$this->assertArrayHasKey( AppReinstall::KEEP, $options['choices'] );
		$this->assertArrayHasKey( $to, $options['choices'], 'The other edition is offered.' );
		$this->assertArrayNotHasKey( $from, $options['choices'], 'The own edition is the keep choice.' );
		$this->assertStringContainsString( 'Odoo 20', $options['choices'][ $to ] );

		$args = array( 'app_version' => $to, 'app_domain_mode' => 'none' );
		$this->assertNull( AppReinstall::check( null, $r, $args ), $from . ' to ' . $to );
		$meta = AppReinstall::meta( array(), $r, $args );
		$this->assertSame( $to, $meta['app_version'] );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => 'nope' ) ) );
		$keep = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertSame( $from, $keep['app_version'] );
	}
}
