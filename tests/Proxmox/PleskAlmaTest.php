<?php
/** Plesk on AlmaLinux 9 next to Plesk on Ubuntu 24.04: one app, a version per system, the system chosen by the customer. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\PleskProductCommand;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * No network: FakeClient only. The cluster has both an Ubuntu 24.04 and an AlmaLinux 9 template unless a test says otherwise.
 */
final class PleskAlmaTest extends PaidAppCase {

	private const SNIPPET = 'local:snippets/vm-vendor-cloudinit-v4.yaml';

	public function set_up(): void {
		parent::set_up();
		$this->cluster();
	}

	public function tear_down(): void {
		unset( $_POST[ AppField::POST_APP ], $_POST[ AppField::POST_VERSION ], $_POST[ OsField::POST_KEY ] );
		parent::tear_down();
	}

	/**
	 * The lab cluster with the given templates and families.
	 *
	 * @param string $templates Template list text.
	 * @param string $families  Family list text.
	 * @param array<string, mixed> $over Overrides.
	 */
	private function cluster( string $templates = "9000 = Debian 12\n9001 = Ubuntu 24.04\n9003 = AlmaLinux 9", string $families = "9001 = ubuntu-24.04\n9000 = debian-12\n9003 = almalinux-9", array $over = array() ): void {
		$this->assertTrue( Clusters::save( 'lab', array_merge( array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => $templates, 'default_template' => '',
			'vendor_snippet' => self::SNIPPET,
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'plesk' ),
			'template_families_text' => $families,
		), $over ) ) );
	}

	/** @return array<string, mixed> */
	private function lab(): array {
		return (array) Clusters::get( 'lab' );
	}

	/** @return array<string, \WC_Product_Variation> */
	private function plesk_tiers(): array {
		$id      = PleskProductCommand::create();
		$product = wc_get_product( $id );
		$product->set_status( 'publish' );
		$product->save();
		$prices = array( 'admin' => '8', 'pro' => '12', 'host' => '25' );
		$out    = array();

		foreach ( $product->get_children() as $vid ) {
			$v   = wc_get_product( $vid );
			$key = (string) get_post_meta( $vid, \BeltoftServices\Product::META_PLAN . 'tier', true );
			$v->set_regular_price( $prices[ $key ] );
			update_post_meta( $vid, '_subscription_price', $prices[ $key ] );
			$v->save();
			$out[ $key ] = wc_get_product( $vid );
		}

		\WC_Product_Variable::sync( $id );

		return $out;
	}

	/** A ready server without an app on the Ubuntu or the Alma template. */
	private function plain_server( int $template = 9001, int $vmid = 5000 ): Resource {
		$o = $this->ordered( null, 'basic', $vmid );
		$this->assertTrue( $o['server']->set( array( 'app_id' => '', 'app_version' => '', OsField::META => $template, 'plan' => array( 'cluster' => 'lab', 'template' => $template, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );

		return Resource::find( $o['server']->id() );
	}

	// -- The recipe ----------------------------------------------------------------------------------

	public function test_the_alma_version_is_next_to_the_ubuntu_one_and_the_ubuntu_one_is_unchanged(): void {
		$ubuntu = Apps::version( 'plesk', '18' );
		$alma   = Apps::version( 'plesk', '18-alma' );

		$this->assertNotNull( $ubuntu );
		$this->assertNotNull( $alma, 'The checksum in app.json matches the script.' );
		$this->assertSame( array( 'ubuntu-24.04' ), $ubuntu['os'] );
		$this->assertSame( array( 'almalinux-9' ), $alma['os'] );
		$this->assertSame( 'current', $ubuntu['status'] );
		$this->assertSame( '18-alma', $alma['version_id'] );
		$this->assertSame( 'Plesk', $alma['name'] );
		$this->assertSame( $ubuntu['min'], $alma['min'] );
		$this->assertSame( '18', Apps::current( 'plesk' )['version_id'], 'One current version: the Ubuntu one stays it.' );
		$this->assertSame( 'a9b70c20bfd4b91b7cbb1fda71cf7fd90bf47bb53600d0217c0efbae2b6087ff', $ubuntu['sha256'], 'The Ubuntu script differs from the first release only in the retry-safe swap step.' );

		$file = BELTOFT_SERVICES_PATH . 'apps/plesk/18-alma/install.script';
		$this->assertStringContainsString( hash_file( 'sha256', $file ) . '  plesk/18-alma/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$check = array();
		$code  = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );
	}

	public function test_the_alma_script_uses_dnf_and_firewalld_and_the_same_licence_handling_as_the_ubuntu_one(): void {
		$ubuntu = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/plesk/18/install.script' );
		$alma   = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/plesk/18-alma/install.script' );

		$this->assertStringContainsString( 'almalinux', $alma );
		$this->assertStringContainsString( 'dnf install', $alma );
		$this->assertStringContainsString( '--add-port="$port/tcp"', $alma );
		$this->assertStringNotContainsString( 'apt-get', $alma );
		$this->assertStringNotContainsString( 'ufw', $alma );
		$this->assertStringContainsString( 'for port in 22 80 443 8443; do', $alma, 'The same four ports as the app definition.' );
		// Owner decision: no port 25 (or any other mail, ftp, dns or panel port) by default. Plesk's own firewalld additions are removed.
		$fw = substr( $alma, (int) strpos( $alma, 'step=firewall' ), (int) strpos( $alma, 'step=verify' ) - (int) strpos( $alma, 'step=firewall' ) );
		$this->assertSame( array( '22', '80', '443', '8443' ), array_map( 'trim', explode( ' ', (string) preg_replace( '/^.*for port in ([0-9 ]+); do firewall-cmd.*$/s', '$1', $fw ) ) ) );
		$this->assertDoesNotMatchRegularExpression( '/--add-(port|service)=?[^\n]*\b(25|587|465|993|995|143|110|21|53|8447|8880|smtp|imap|pop3|ftp|dns)\b/', $fw );
		$this->assertStringContainsString( '--list-services', $fw );
		$this->assertStringContainsString( '--remove-service', $fw );
		$this->assertStringContainsString( '--remove-port', $fw );
		$this->assertStringContainsString( '--list-rich-rules', $fw );
		$this->assertStringNotContainsString( 'if [ ! -e "$state/stage.5" ]', $fw, 'Run on every attempt: a retry ends in the same state.' );
		$this->assertStringContainsString( '22/tcp 443/tcp 80/tcp 8443/tcp', $fw, 'The step ends by checking that exactly these four remain.' );

		// The key and licence helpers, the status files and the trial/licence step are the same text in both scripts.
		foreach ( array( 'beltoft-plesk-key', 'beltoft-plesk-licence', 'licence.status.json', 'beltoft-licence.path', '-trial_license "$trial"', 'plesk bin license --install' ) as $needle ) {
			$this->assertStringContainsString( $needle, $alma, $needle );
		}
		$block = static function ( string $script ): string {
			$a = strpos( $script, "install -m 0755 /dev/stdin /usr/local/sbin/beltoft-plesk-key" );
			$b = strpos( $script, "touch \"\$state/stage.1\"" );
			return substr( $script, (int) $a, (int) $b - (int) $a );
		};
		$this->assertSame( $block( $ubuntu ), $block( $alma ) );
		$step = static function ( string $script ): string {
			$a = strpos( $script, "step=licence\n" );
			$b = strpos( $script, "step=firewall\n" );
			return substr( $script, (int) $a, (int) $b - (int) $a );
		};
		$this->assertSame( $step( $ubuntu ), $step( $alma ) );
		$this->assertStringNotContainsString( '—', $alma );
	}

	// -- The cluster ---------------------------------------------------------------------------------

	public function test_the_template_of_a_family_and_of_a_version(): void {
		$lab = $this->lab();

		$this->assertSame( 9001, Apps::os_template( $lab ), 'The default family is unchanged.' );
		$this->assertSame( 9003, Apps::os_template( $lab, 'almalinux-9' ) );
		$this->assertSame( 0, Apps::os_template( $lab, 'rocky-9' ) );
		$this->assertSame( 'almalinux-9', Apps::family_of( $lab, 9003 ) );

		$alma   = (array) Apps::version( 'plesk', '18-alma' );
		$ubuntu = (array) Apps::version( 'plesk', '18' );
		$this->assertSame( 9003, Apps::version_template( $lab, $alma ) );
		$this->assertSame( 9003, Apps::version_template( $lab, $alma, 9003 ) );
		$this->assertSame( 9003, Apps::version_template( $lab, $alma, 9001 ), 'A system the version does not run on is ignored.' );
		$this->assertSame( 9001, Apps::version_template( $lab, $ubuntu, 9003 ) );
		$this->assertSame( 0, Apps::version_template( $this->lab_without( 9003 ), $alma ) );
	}

	/** @return array<string, mixed> The lab cluster data without a template. */
	private function lab_without( int $vmid ): array {
		$lab = $this->lab();
		unset( $lab['templates'][ $vmid ], $lab['template_families'][ $vmid ] );

		return $lab;
	}

	public function test_apps_are_ready_on_a_cluster_with_only_an_alma_template_and_not_with_only_another_system(): void {
		$this->cluster( "9003 = AlmaLinux 9", "9003 = almalinux-9" );
		$this->assertTrue( Apps::cluster_ready( $this->lab() ) );
		$offered = Apps::offered( $this->lab() );
		$this->assertSame( array( 'plesk' ), array_keys( $offered ), 'Only the app that runs on Alma is offered there.' );
		$this->assertSame( array( '18-alma' ), array_keys( $offered['plesk']['choices'] ) );

		$this->cluster( "9000 = Debian 12", "9000 = debian-12" );
		$this->assertFalse( Apps::cluster_ready( $this->lab() ) );
		$this->assertSame( array(), Apps::offered( $this->lab() ) );

		$this->cluster( "9001 = Ubuntu 24.04", "9001 = ubuntu-24.04" );
		$this->assertTrue( Apps::cluster_ready( $this->lab() ) );
		$offered = Apps::offered( $this->lab() );
		$ids = array_keys( $offered );
		sort( $ids );
		$this->assertSame( array( 'docker', 'plesk', 'wordpress' ), $ids );
		$this->assertSame( array( '18' ), array_map( 'strval', array_keys( $offered['plesk']['choices'] ) ), 'No Alma template: no Alma version.' );
	}

	public function test_alma_needs_the_v4_snippet_ubuntu_works_with_v2_or_later_and_a_lookalike_is_not_accepted(): void {
		$plan = array( 'template' => 9003, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 );
		$two  = array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml' );

		// v2 cannot install on Alma (no jq, the agent filters file and exec calls): no Alma offer, no Alma sale.
		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9", "9001 = ubuntu-24.04\n9003 = almalinux-9", $two );
		$this->assertTrue( Apps::cluster_ready( $this->lab() ), 'Ubuntu apps still work with v2.' );
		$this->assertSame( 0, Apps::version_template( $this->lab(), (array) Apps::version( 'plesk', '18-alma' ) ) );
		$this->assertFalse( Apps::family_supported( $this->lab(), 'almalinux-9' ) );
		$this->assertSame( array( '18' ), array_map( 'strval', array_keys( Apps::offered( $this->lab() )['plesk']['choices'] ) ) );
		$this->assertStringContainsString( 'vm-vendor-cloudinit-v4', (string) Apps::check( $this->lab(), $plan, 'plesk', '18-alma' ) );
		$this->assertNull( Apps::check( $this->lab(), array( 'template' => 9001 ) + $plan, 'plesk', '18' ) );

		$this->cluster( "9003 = AlmaLinux 9", "9003 = almalinux-9", $two );
		$this->assertFalse( Apps::cluster_ready( $this->lab() ), 'Only an Alma template and v2: nothing can be installed.' );
		$this->assertSame( array(), Apps::offered( $this->lab() ) );

		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9", "9001 = ubuntu-24.04\n9003 = almalinux-9" );
		$this->assertTrue( Apps::cluster_ready( $this->lab() ) );
		$this->assertNull( Apps::check( $this->lab(), $plan, 'plesk', '18-alma' ), 'v4 installs on Alma.' );

		// v3 reads the job before it is written on Alma (jq 1.6 passes an empty file): Ubuntu only.
		$three = array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v3.yaml' );
		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9", "9001 = ubuntu-24.04\n9003 = almalinux-9", $three );
		$this->assertTrue( Apps::cluster_ready( $this->lab() ) );
		$this->assertFalse( Apps::family_supported( $this->lab(), 'almalinux-9' ) );
		$this->assertTrue( Apps::family_supported( $this->lab(), 'ubuntu-24.04' ) );
		$this->assertSame( array( '18' ), array_map( 'strval', array_keys( Apps::offered( $this->lab() )['plesk']['choices'] ) ) );
		$this->assertStringContainsString( 'vm-vendor-cloudinit-v4', (string) Apps::check( $this->lab(), $plan, 'plesk', '18-alma' ) );

		$this->cluster( "9001 = Ubuntu 24.04", "9001 = ubuntu-24.04", array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v5.yaml' ) );
		$this->assertFalse( Apps::cluster_ready( $this->lab() ) );
	}

	public function test_the_picker_and_the_reinstall_tab_do_not_offer_alma_on_a_v2_cluster(): void {
		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9", "9001 = ubuntu-24.04\n9003 = almalinux-9", array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml' ) );
		$this->plesk_tiers();
		$srv = $this->plain_server( 9003 );
		$offer = AppField::offer_for( $this->vm_product()->get_id() );
		$this->assertSame( array( '18' ), array_map( 'strval', array_keys( $offer['apps']['plesk']['choices'] ) ) );
		$this->assertStringNotContainsString( '18-alma', $this->reinstall_html( $srv ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $srv, array( 'confirm' => 'REINSTALL', 'app_version' => 'plesk/18-alma', 'os' => '9003', 'app_tier' => 'pro' ) ) );
	}

	public function test_both_versions_are_offered_with_both_systems_and_the_other_apps_stay_on_ubuntu(): void {
		$offered = Apps::offered( $this->lab() );

		$this->assertSame( array( '18', '18-alma' ), array_map( 'strval', array_keys( $offered['plesk']['choices'] ) ) );
		$this->assertSame( array( '29' ), array_map( 'strval', array_keys( $offered['docker']['choices'] ) ) );
		$this->assertSame( array( 'ubuntu-24.04', 'almalinux-9' ), Apps::families( $offered['plesk']['choices'] ) );
		$this->assertSame( 'Runs on Ubuntu 24.04 or AlmaLinux 9', Apps::runs_on( $this->lab(), Apps::families( $offered['plesk']['choices'] ) ) );
	}

	public function test_the_alma_10_version_is_its_own_recipe_and_is_offered_only_with_an_alma_10_template(): void {
		$v10  = Apps::version( 'plesk', '18-alma10' );
		$file = BELTOFT_SERVICES_PATH . 'apps/plesk/18-alma10/install.script';
		$this->assertNotNull( $v10, 'The checksum in app.json matches the script.' );
		$this->assertSame( array( 'almalinux-10' ), $v10['os'] );
		$this->assertSame( 'available', $v10['status'] );
		$this->assertSame( '18', Apps::current( 'plesk' )['version_id'] );
		$this->assertStringContainsString( hash_file( 'sha256', $file ) . '  plesk/18-alma10/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
		$script = (string) file_get_contents( $file );
		$this->assertStringContainsString( "grep -qE '^VERSION_ID=\"?10[.\"]' /etc/os-release", $script, 'Refuses any release but 10.' );
		$this->assertSame( (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/plesk/18-alma/install.script' ), str_replace( array( "AlmaLinux 10 (any 10.x), the AlmaLinux 9 recipe with the release check changed.", 'VERSION_ID="?10[."]' ), array( 'AlmaLinux 9 (any 9.x).', 'VERSION_ID="?9[."]' ), $script ), 'Otherwise the AlmaLinux 9 recipe.' );
		$check = array();
		$code  = 1;
		exec( 'bash -n ' . escapeshellarg( $file ), $check, $code );
		$this->assertSame( 0, $code );

		// Not on the lab cluster (no AlmaLinux 10 template).
		$this->assertSame( array( '18', '18-alma' ), array_map( 'strval', array_keys( Apps::offered( $this->lab() )['plesk']['choices'] ) ) );

		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9\n9006 = AlmaLinux 10", "9001 = ubuntu-24.04\n9003 = almalinux-9\n9006 = almalinux-10" );
		$lab  = $this->lab();
		$plan = array( 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 );
		$this->assertSame( array( '18-alma10', '18', '18-alma' ), array_map( 'strval', array_keys( Apps::offered( $lab )['plesk']['choices'] ) ), 'AlmaLinux 10 is the default and comes first.' );
		$this->assertNull( Apps::check( $lab, $plan + array( 'template' => 9006 ), 'plesk', '18-alma10' ) );
		$this->assertSame( 'The app does not support the selected operating system', Apps::check( $lab, $plan + array( 'template' => 9003 ), 'plesk', '18-alma10' ) );
		$this->assertSame( 'The app does not support the selected operating system', Apps::check( $lab, $plan + array( 'template' => 9006 ), 'plesk', '18-alma' ) );
		$this->assertSame( 9006, Apps::version_template( $lab, (array) $v10 ) );

		// Like AlmaLinux 9 it needs snippet v4.
		$this->cluster( "9001 = Ubuntu 24.04\n9006 = AlmaLinux 10", "9001 = ubuntu-24.04\n9006 = almalinux-10", array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v3.yaml' ) );
		$this->assertSame( array( '18' ), array_map( 'strval', array_keys( Apps::offered( $this->lab() )['plesk']['choices'] ) ) );
	}

	public function test_alma_10_is_the_default_plesk_version_only_where_the_cluster_can_install_it(): void {
		$this->assertTrue( (bool) Apps::version( 'plesk', '18-alma10' )['default'] );
		$this->assertArrayNotHasKey( 'default', (array) Apps::version( 'plesk', '18' ) );

		// With the template and snippet v4: first, and the first family the picker offers.
		$this->cluster( "9001 = Ubuntu 24.04\n9003 = AlmaLinux 9\n9006 = AlmaLinux 10", "9001 = ubuntu-24.04\n9003 = almalinux-9\n9006 = almalinux-10" );
		$plesk = Apps::offered( $this->lab() )['plesk'];
		$this->assertSame( '18-alma10', (string) array_key_first( $plesk['choices'] ) );
		$this->assertSame( array( 'almalinux-10', 'ubuntu-24.04', 'almalinux-9' ), Apps::families( $plesk['choices'] ) );

		// No template for it: the Ubuntu version stays first.
		$this->cluster();
		$this->assertSame( '18', (string) array_key_first( Apps::offered( $this->lab() )['plesk']['choices'] ) );

		// A template but an older snippet: also the Ubuntu version.
		$this->cluster( "9001 = Ubuntu 24.04\n9006 = AlmaLinux 10", "9001 = ubuntu-24.04\n9006 = almalinux-10", array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v3.yaml' ) );
		$this->assertSame( '18', (string) array_key_first( Apps::offered( $this->lab() )['plesk']['choices'] ) );

		// The checkout resolves a version asked for by nobody to the default.
		$this->cluster( "9001 = Ubuntu 24.04\n9006 = AlmaLinux 10", "9001 = ubuntu-24.04\n9006 = almalinux-10" );
		$offer = $this->offer();
		$got   = AppField::resolve( $offer, array( 'app' => 'plesk', 'tier' => 'admin' ), 0, false );
		$this->assertIsArray( $got );
		$this->assertSame( '18-alma10', $got['version'] );
		$this->assertSame( 9006, $got['os'] );
	}

	public function test_the_picker_script_moves_to_the_apps_first_system_until_the_customer_chooses_one(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );
		$this->assertStringContainsString( 'wanted[ 0 ] === family( system )', $js );
		$this->assertStringContainsString( 'touched = true;', $js );
		$this->assertStringContainsString( 'each( wanted, function ( name )', $js );
	}

	public function test_check_matches_the_version_to_the_system(): void {
		$lab  = $this->lab();
		$plan = array( 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 );

		$this->assertNull( Apps::check( $lab, $plan + array( 'template' => 9003 ), 'plesk', '18-alma' ) );
		$this->assertNull( Apps::check( $lab, $plan + array( 'template' => 9001 ), 'plesk', '18' ) );
		$this->assertSame( 'The app does not support the selected operating system', Apps::check( $lab, $plan + array( 'template' => 9001 ), 'plesk', '18-alma' ) );
		$this->assertSame( 'The app does not support the selected operating system', Apps::check( $lab, $plan + array( 'template' => 9003 ), 'plesk', '18' ) );
		$this->assertSame( 'The app does not support the selected operating system', Apps::check( $lab, $plan + array( 'template' => 9003 ), 'docker', '29' ) );
	}

	// -- Checkout and the picker ---------------------------------------------------------------------

	private function offer(): array {
		$this->plesk_tiers();
		$product = $this->vm_product();
		$offer   = AppField::offer_for( $product->get_id() );
		$this->assertNotNull( $offer );

		return $offer;
	}

	public function test_the_offer_lists_both_versions_and_keeps_ubuntu_as_its_default_system(): void {
		$offer = $this->offer();

		$this->assertSame( 9001, $offer['os'] );
		$this->assertSame( array( '18', '18-alma' ), array_map( 'strval', array_keys( $offer['apps']['plesk']['choices'] ) ) );
		$this->assertArrayHasKey( 'wordpress', $offer['apps'] );
	}

	public function test_the_right_version_follows_the_system_the_customer_picked(): void {
		$offer = $this->offer();
		$in    = array( 'app' => 'plesk', 'tier' => 'pro' );

		$alma = AppField::resolve( $offer, $in + array( 'os' => '9003' ), 0, false );
		$this->assertIsArray( $alma );
		$this->assertSame( '18-alma', $alma['version'] );
		$this->assertSame( 9003, $alma['os'] );

		$ubuntu = AppField::resolve( $offer, $in + array( 'os' => '9001' ), 0, false );
		$this->assertSame( '18', $ubuntu['version'] );
		$this->assertSame( 9001, $ubuntu['os'] );

		$none = AppField::resolve( $offer, $in, 0, false );
		$this->assertSame( '18', $none['version'], 'No system said: the first version, as before.' );
		$this->assertSame( 9001, $none['os'] );

		$other = AppField::resolve( $offer, $in + array( 'os' => '9000' ), 0, false );
		$this->assertSame( '18', $other['version'], 'Debian: the app has the last word on the system.' );
		$this->assertSame( 9001, $other['os'] );

		$explicit = AppField::resolve( $offer, $in + array( 'version' => '18-alma' ), 0, false );
		$this->assertSame( '18-alma', $explicit['version'] );
		$this->assertSame( 9003, $explicit['os'], 'A version asked for by name, no system said: its own system.' );
		$this->assertSame( 9003, AppField::resolve( $offer, $in + array( 'version' => '18-alma', 'os' => '9003' ), 0, false )['os'] );

		$clash = AppField::resolve( $offer, $in + array( 'version' => '18-alma', 'os' => '9001' ), 0, false );
		$this->assertIsString( $clash, 'A version that does not run on the chosen system, while another version does, is refused.' );
		$this->assertStringContainsString( 'needs AlmaLinux 9', $clash );
		$this->assertIsString( AppField::resolve( $offer, $in + array( 'version' => '18', 'os' => '9003' ), 0, false ) );

		$this->assertIsString( AppField::resolve( $offer, $in + array( 'version' => '18-nope' ), 0, false ) );
	}

	public function test_an_app_that_runs_on_ubuntu_only_keeps_ubuntu_whatever_system_was_picked(): void {
		$offer = $this->offer();
		$docker = AppField::resolve( $offer, array( 'app' => 'wordpress', 'os' => '9003' ), 0, false );

		$this->assertIsArray( $docker );
		$this->assertSame( 9001, $docker['os'] );
		$this->assertSame( '7.1.4', $docker['version'] );
	}

	public function test_the_picker_hands_each_version_its_systems_to_the_script_and_the_order_form_prints_the_same(): void {
		$offer = $this->offer();
		ob_start();
		AppField::print_fields( $offer );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-families="ubuntu-24.04,almalinux-9"', $html );
		$this->assertStringContainsString( 'Runs on Ubuntu 24.04 or AlmaLinux 9', $html );
		$this->assertMatchesRegularExpression( '/&quot;id&quot;:&quot;18-alma&quot;[^}]*&quot;families&quot;:\[&quot;almalinux-9&quot;\]/', $html );
		$this->assertMatchesRegularExpression( '/&quot;id&quot;:&quot;18&quot;[^}]*&quot;families&quot;:\[&quot;ubuntu-24\.04&quot;\]/', $html );
		$this->assertStringContainsString( 'data-os="9001"', $html );
		$this->assertStringContainsString( 'data-families="ubuntu-24.04"', $html, 'The Ubuntu-only apps still say Ubuntu.' );
	}

	public function test_the_hand_over_to_the_server_uses_the_chosen_system(): void {
		$tiers = $this->plesk_tiers();
		$vm    = $this->vm_product();
		$user  = self::factory()->user->create( array( 'user_email' => 'christian.beltoft@gmail.com' ) );

		foreach ( array( '18-alma' => 9003, '18' => 9001 ) as $version => $template ) {
			$version = (string) $version;
			$order = wc_create_order( array( 'customer_id' => $user ) );
			$item  = $order->get_item( $order->add_product( $vm, 1 ) );
			$item->add_meta_data( AppField::ITEM_APP, 'plesk', true );
			$item->add_meta_data( AppField::ITEM_VERSION, $version, true );
			$item->add_meta_data( OsField::ITEM_META, (string) $template, true );
			$item->add_meta_data( AppField::ITEM_TIER, 'pro', true );
			$item->save();
			$order->save();
			$license = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'email' => 'christian.beltoft@gmail.com' ) );
			$meta    = AppField::initial_meta( array(), $license, Registry::get( 'proxmox' ) );

			$this->assertSame( 'plesk', $meta['app_id'] );
			$this->assertSame( $version, (string) $meta['app_version'] );
			$this->assertSame( $template, $meta[ OsField::META ] );
			$this->assertSame( 'pro', $meta['app_tier'] );
		}

		$this->assertArrayHasKey( 'pro', $tiers );
	}

	// -- The Reinstall tab ---------------------------------------------------------------------------

	private function reinstall_html( Resource $r ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		ob_start();
		AppReinstall::fields( $r->id() );

		return (string) ob_get_clean();
	}

	public function test_a_server_without_an_app_is_offered_both_plesk_versions_with_their_systems(): void {
		$this->plesk_tiers();
		$html = $this->reinstall_html( $this->plain_server() );

		$this->assertStringContainsString( 'value="plesk/18"', $html );
		$this->assertStringContainsString( 'data-families="ubuntu-24.04,almalinux-9"', $html );
		$this->assertMatchesRegularExpression( '#&quot;value&quot;:&quot;plesk\\\\/18-alma&quot;[^}]*&quot;families&quot;:\[&quot;almalinux-9&quot;\]#', $html );
	}

	public function test_a_paid_reinstall_with_plesk_works_for_an_alma_server_and_checks_the_system(): void {
		$this->plesk_tiers();
		$r = $this->plain_server( 9003 );

		\BeltoftServices\Proxmox\AppReinstallPaid::register();
		$ask = static fn( string $version, string $os ) => array( 'confirm' => 'REINSTALL', 'app_version' => $version, 'os' => $os, 'app_tier' => 'pro' );

		// The licence is paid at checkout first; the question the form asks is the same for the system.
		$this->assertStringContainsString( 'needs Ubuntu 24.04', (string) AppReinstall::check( null, $r, $ask( 'plesk/18', '9003' ) ) );
		$this->assertStringContainsString( 'needs AlmaLinux 9', (string) AppReinstall::check( null, $r, $ask( 'plesk/18-alma', '9001' ) ) );
		$this->assertStringNotContainsString( 'chosen system', (string) AppReinstall::check( null, $r, $ask( 'plesk/18-alma', '9003' ) ), 'The Alma version on the Alma system passes the system check (the licence comes next).' );
		$this->assertStringNotContainsString( 'chosen system', (string) AppReinstall::check( null, $r, $ask( 'plesk/18-alma', '' ) ), 'No system posted: the version brings its own.' );

		$meta = array( 'wipe' => true );
		$out  = AppReinstall::meta( $meta, $r, $ask( 'plesk/18-alma', '9003' ) );
		$this->assertSame( 'plesk', $out['app_id'] );
		$this->assertSame( '18-alma', $out['app_version'] );
		$this->assertSame( 9003, $out[ OsField::META ] );
		$this->assertSame( 'pro', $out['app_tier'] );

		$out = AppReinstall::meta( $meta, $r, $ask( 'plesk/18-alma', '9001' ) );
		$this->assertSame( 9003, $out[ OsField::META ], 'The version has the last word on the system, as at checkout.' );

		$out = AppReinstall::meta( $meta, $r, $ask( 'plesk/18', '' ) );
		$this->assertSame( 9001, $out[ OsField::META ] );
		$this->assertSame( '18', $out['app_version'] );
	}

	public function test_a_server_with_plesk_on_alma_keeps_its_system_and_is_not_told_an_ubuntu_version_is_newer(): void {
		$this->plesk_tiers();
		$r = $this->plain_server( 9003 );
		$this->assertTrue( $r->set( array( 'app_id' => 'plesk', 'app_version' => '18-alma', 'app_state' => 'ready' ) ) );
		$r = Resource::find( $r->id() );

		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'os' => '9003' ) ) );
		$this->assertStringContainsString( 'needs AlmaLinux 9', (string) AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'os' => '9001' ) ) );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep' ) ), 'The server own system is used when none is posted.' );

		$view = AppTab::view( $r );
		$this->assertSame( '', $view['newer'], 'Plesk on Ubuntu is not an update of Plesk on AlmaLinux.' );

		$options = AppReinstall::options( $r );
		$this->assertArrayHasKey( '18', $options['choices'] );
		$html = $this->reinstall_html( $r );
		$this->assertMatchesRegularExpression( '/&quot;value&quot;:&quot;18&quot;[^}]*&quot;families&quot;:\[&quot;ubuntu-24\.04&quot;\]/', $html );
		$this->assertMatchesRegularExpression( '/&quot;value&quot;:&quot;keep&quot;[^}]*&quot;families&quot;:\[&quot;almalinux-9&quot;\]/', $html );
	}

	public function test_a_cluster_without_an_alma_template_does_not_offer_the_alma_version_on_the_reinstall_tab(): void {
		$this->cluster( "9000 = Debian 12\n9001 = Ubuntu 24.04", "9001 = ubuntu-24.04\n9000 = debian-12" );
		$this->plesk_tiers();
		$r = $this->plain_server();
		$this->assertTrue( $r->set( array( 'app_id' => 'plesk', 'app_version' => '18', 'app_state' => 'ready' ) ) );
		$options = AppReinstall::options( Resource::find( $r->id() ) );

		$this->assertSame( array( 'keep', 'none' ), array_map( 'strval', array_keys( $options['choices'] ) ) );
		$this->assertStringNotContainsString( '18-alma', $this->reinstall_html( Resource::find( $r->id() ) ) );
	}

	// -- The script of the page ----------------------------------------------------------------------

	public function test_the_tiles_script_filters_versions_by_the_chosen_system(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );

		$this->assertStringContainsString( 'function fitting( radio )', $js );
		$this->assertStringContainsString( 'entry.families', $js );
		$this->assertStringContainsString( 'fill();', $js );
	}
}
