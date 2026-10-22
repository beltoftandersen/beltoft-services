<?php
/**
 * Proxmox clusters store.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Crypto;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Tests\TestCase;

/**
 * Validation, secrets and templates of the stored clusters. Documentation values only.
 */
class ClustersTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Clusters::OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_cluster_in_use' );
		delete_option( Clusters::OPTION );

		parent::tear_down();
	}

	/**
	 * A valid form submission.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function input( array $over = array() ): array {
		return array_merge(
			array(
				'label'             => 'Lab cluster',
				'api_url'           => 'https://pve.example.invalid:8006',
				'token_id'          => 'svc@pve!beltoft',
				'token_secret'      => 'secret-secret-secret-1',
				'client'            => 'fake',
				'pool_api'          => 'pools',
				'nodes'             => 'node-a, node-b',
				'storage'           => 'disk-store',
				'cloudinit_storage' => 'ci-store',
				'bridge'            => 'vmbr9',
				'vlan'              => '',
				'pool'              => '',
				'boot_disk'         => 'scsi0',
				'gateway'           => '192.0.2.1',
				'nameservers'       => '192.0.2.53, 192.0.2.54',
				'prefix'            => '24',
				'vmid_min'          => '5000',
				'vmid_max'          => '5099',
				'templates'         => "9000 = Debian 12\n9001 = Ubuntu 24.04",
				'vendor_snippet'    => '',
				'block_smtp'        => '1',
			),
			$over
		);
	}

	private function stored_raw(): string {
		return (string) wp_json_encode( get_option( Clusters::OPTION ) );
	}

	public function test_saves_and_reads_a_cluster(): void {
		$this->assertTrue( Clusters::save( 'lab', $this->input() ) );

		$c = Clusters::get( 'lab' );
		$this->assertSame( 'https://pve.example.invalid:8006', $c['api_url'] );
		$this->assertSame( array( 'node-a', 'node-b' ), $c['nodes'] );
		$this->assertSame( array( '192.0.2.53', '192.0.2.54' ), $c['nameservers'] );
		$this->assertSame( 24, $c['prefix'] );
		$this->assertSame( 5000, $c['vmid_min'] );
		$this->assertSame( 1, $c['block_smtp'] );
		$this->assertSame( 'fake', $c['client'] );
		$this->assertNull( Clusters::get( 'nope' ) );
	}

	public function test_vmid_range_is_validated(): void {
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'vmid_min' => '99' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'vmid_min' => '300', 'vmid_max' => '300' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'vmid_min' => '400', 'vmid_max' => '300' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'vmid_max' => '1000000000' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'vmid_min' => 'abc' ) ) ) );
		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'vmid_min' => '100', 'vmid_max' => '101' ) ) ) );
		$this->assertSame( 100, Clusters::get( 'lab' )['vmid_min'] );
	}

	public function test_a_template_inside_the_customer_range_is_refused(): void {
		$r = Clusters::save( 'lab', $this->input( array( 'templates' => '5010 = Inside' ) ) );

		$this->assertWPError( $r );
		$this->assertSame( 'beltoft_services_cluster_templates', $r->get_error_code() );
	}

	public function test_secret_encrypted_never_echoed_and_at_least_16_characters(): void {
		$this->assertTrue( Clusters::save( 'lab', $this->input() ) );

		$this->assertStringNotContainsString( 'secret-secret-secret-1', $this->stored_raw() );
		$this->assertStringStartsWith( 'bs1:', (string) Clusters::all()['lab']['token_secret'] );
		$this->assertSame( 'secret-secret-secret-1', Clusters::get( 'lab' )['token_secret'] );
		$this->assertTrue( Clusters::has_secret( 'lab' ) );

		$r = Clusters::save( 'short', $this->input( array( 'token_secret' => str_repeat( 'x', 15 ) ) ) );
		$this->assertWPError( $r );
		$this->assertSame( 'beltoft_services_cluster_secret_short', $r->get_error_code() );
		$this->assertNull( Clusters::get( 'short' ) );
		$this->assertTrue( Clusters::save( 'ok16', $this->input( array( 'token_secret' => str_repeat( 'x', 16 ) ) ) ) );
	}

	public function test_a_new_cluster_needs_a_secret(): void {
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'token_secret' => '' ) ) ) );
	}

	public function test_secret_refused_without_the_key(): void {
		remove_all_filters( 'beltoft_services_key' );
		$this->assertFalse( Crypto::available() );

		$r = Clusters::save( 'lab', $this->input() );
		$this->assertWPError( $r );
		$this->assertSame( 'beltoft_services_no_key', $r->get_error_code() );
		$this->assertNull( Clusters::get( 'lab' ) );
	}

	public function test_empty_secret_keeps_the_saved_one(): void {
		Clusters::save( 'lab', $this->input() );
		$before = Clusters::all()['lab']['token_secret'];

		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'token_secret' => '', 'label' => 'Renamed' ) ) ) );

		$this->assertSame( $before, Clusters::all()['lab']['token_secret'] );
		$this->assertSame( 'secret-secret-secret-1', Clusters::get( 'lab' )['token_secret'] );
		$this->assertSame( 'Renamed', Clusters::get( 'lab' )['label'] );
	}

	public function test_live_refused_without_the_constant(): void {
		$this->assertFalse( defined( 'BELTOFT_SERVICES_PROXMOX_LIVE' ) );

		$r = Clusters::save( 'lab', $this->input( array( 'client' => 'live' ) ) );
		$this->assertWPError( $r );
		$this->assertSame( 'beltoft_services_cluster_live', $r->get_error_code() );
		$this->assertNull( Clusters::get( 'lab' ) );

		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'client' => 'bogus' ) ) ) );
	}

	public function test_a_stored_live_cluster_reads_as_fake_without_the_constant(): void {
		$all = array( 'lab' => array_merge( Clusters::defaults(), array( 'label' => 'x', 'client' => 'live' ) ) );
		update_option( Clusters::OPTION, $all, false );

		$this->assertSame( 'fake', Clusters::get( 'lab' )['client'] );
	}

	public function test_all_downgrades_live_too_and_other_writes_keep_the_stored_value(): void {
		$live = array_merge( Clusters::defaults(), array( 'label' => 'x', 'client' => 'live' ) );
		update_option( Clusters::OPTION, array( 'lab' => $live, 'other' => array_merge( $live, array( 'label' => 'y' ) ) ), false );

		$this->assertSame( 'fake', Clusters::all()['lab']['client'] );
		$this->assertSame( 'fake', Clusters::all()['other']['client'] );

		// Deleting one cluster must not rewrite the other one as fake in the database.
		$this->assertTrue( Clusters::delete( 'other' ) );
		$this->assertSame( 'live', get_option( Clusters::OPTION )['lab']['client'] );
	}

	public function test_default_template_is_marked_validated_and_falls_back_to_the_first(): void {
		$list = "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04";

		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'templates' => $list ) ) ) );
		$this->assertSame( 9000, Clusters::default_template( 'lab' ), 'Unmarked: the first.' );

		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'templates' => $list, 'default_template' => '9001' ) ) ) );
		$this->assertSame( 9001, Clusters::default_template( 'lab' ), 'The marker beats the order.' );
		$this->assertSame( 9001, Clusters::get( 'lab' )['default_template'] );

		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => $list, 'default_template' => '9005' ) ) ), 'Not in the list.' );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => $list, 'default_template' => 'abc' ) ) ) );
		$this->assertSame( 9001, Clusters::default_template( 'lab' ), 'A refused save changes nothing.' );

		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'templates' => "9000 = Ubuntu 26.04\n9002 = Debian", 'default_template' => '' ) ) ) );
		$this->assertSame( 9000, Clusters::default_template( 'lab' ), 'Emptied: back to the first.' );
		$this->assertSame( 0, Clusters::default_template( 'nope' ) );
	}

	public function test_templates_parse(): void {
		Clusters::save( 'lab', $this->input( array( 'templates' => "9000 = Debian 12\n\n  9001=Ubuntu = LTS \n" ) ) );

		$this->assertSame( array( 9000 => 'Debian 12', 9001 => 'Ubuntu = LTS' ), Clusters::templates( 'lab' ) );
		$this->assertSame( array(), Clusters::templates( 'nope' ) );

		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => 'no-number = X' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => '50 = Too low' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => '9000 = ' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'templates' => "9000 = A\n9000 = B" ) ) ) );
	}

	public function test_api_url_must_be_https(): void {
		foreach ( array( 'http://pve.example.invalid:8006', 'pve.example.invalid', 'ftp://pve.example.invalid', 'https://', 'https://user:pw@pve.example.invalid', 'https://pve.example.invalid/api2/json', 'https://pve.example.invalid?x=1' ) as $url ) {
			$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'api_url' => $url ) ) ), $url );
		}

		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'api_url' => 'https://pve.example.invalid/' ) ) ) );
		$this->assertSame( 'https://pve.example.invalid', Clusters::get( 'lab' )['api_url'] );
	}

	public function test_ca_file_must_be_readable(): void {
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'ca_file' => '/nonexistent/ca.pem' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'ca_file' => 'relative/ca.pem' ) ) ) );

		$file = wp_tempnam( 'ca' );
		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'ca_file' => $file ) ) ) );
		$this->assertSame( $file, Clusters::get( 'lab' )['ca_file'] );
		wp_delete_file( $file );
	}

	public function test_pin_must_be_a_base64_sha256(): void {
		$good = base64_encode( hash( 'sha256', 'documentation', true ) );

		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'pin_sha256' => 'abc' ) ) ) );
		$this->assertWPError( Clusters::save( 'lab', $this->input( array( 'pin_sha256' => base64_encode( 'short' ) ) ) ) );
		$this->assertTrue( Clusters::save( 'lab', $this->input( array( 'pin_sha256' => $good ) ) ) );
		$this->assertSame( $good, Clusters::get( 'lab' )['pin_sha256'] );
	}

	public function test_field_formats(): void {
		$bad = array(
			array( 'token_id' => 'nobang@pve' ),
			array( 'token_id' => 'svc!tok' ),
			array( 'nodes' => '' ),
			array( 'nodes' => 'bad_node' ),
			array( 'nodes' => 'a;b' ),
			array( 'storage' => 'bad storage' ),
			array( 'cloudinit_storage' => '' ),
			array( 'bridge' => 'vm br' ),
			array( 'vlan' => '0' ),
			array( 'vlan' => '4095' ),
			array( 'pool' => 'bad pool' ),
			array( 'boot_disk' => 'disk0' ),
			array( 'gateway' => '999.1.1.1' ),
			array( 'gateway' => '' ),
			array( 'nameservers' => 'dns.example.invalid' ),
			array( 'prefix' => '33' ),
			array( 'prefix' => '0' ),
			array( 'pool_api' => 'other' ),
			array( 'vendor_snippet' => 'local:snippets/../x.yaml' ),
			array( 'vendor_snippet' => 'local:snippets/a,b.yaml' ),
			array( 'vendor_snippet' => 'vendor.yaml' ),
			array( 'label' => '' ),
		);

		foreach ( $bad as $over ) {
			$this->assertWPError( Clusters::save( 'lab', $this->input( $over ) ), (string) wp_json_encode( $over ) );
		}

		$this->assertTrue(
			Clusters::save(
				'lab',
				$this->input(
					array(
						'vlan'           => '120',
						'pool'           => 'customers',
						'vendor_snippet' => 'local:snippets/vendor.yaml',
						'pool_api'       => 'pools_id',
						'block_smtp'     => '0',
					)
				)
			)
		);
		$c = Clusters::get( 'lab' );
		$this->assertSame( 120, $c['vlan'] );
		$this->assertSame( 'customers', $c['pool'] );
		$this->assertSame( 'local:snippets/vendor.yaml', $c['vendor_snippet'] );
		$this->assertSame( 'pools_id', $c['pool_api'] );
		$this->assertSame( 0, $c['block_smtp'] );
	}

	public function test_id_is_validated(): void {
		foreach ( array( '', 'Lab', 'a b', str_repeat( 'a', 41 ), 'a/b' ) as $id ) {
			$this->assertWPError( Clusters::save( $id, $this->input() ), $id );
		}

		$this->assertTrue( Clusters::save( 'lab_1-x', $this->input() ) );
		$this->assertTrue( Clusters::save( str_repeat( 'a', 40 ), $this->input() ) );
	}

	public function test_no_built_in_default_names_an_infrastructure_value(): void {
		$d = Clusters::defaults();

		foreach ( array( 'api_url', 'token_id', 'token_secret', 'storage', 'cloudinit_storage', 'bridge', 'pool', 'gateway', 'nodes', 'templates', 'vendor_snippet' ) as $key ) {
			$this->assertContains( $d[ $key ], array( '', array() ), $key );
		}

		$this->assertSame( 'scsi0', $d['boot_disk'] );
		$this->assertSame( 32, $d['prefix'] );
		$this->assertSame( 200, $d['vmid_min'] );
		$this->assertSame( 999, $d['vmid_max'] );
		$this->assertSame( 1, $d['block_smtp'] );
		$this->assertSame( 'fake', $d['client'] );
	}

	public function test_cluster_in_use_cannot_be_deleted(): void {
		Clusters::save( 'lab', $this->input() );
		Clusters::save( 'other', $this->input() );

		add_filter( 'beltoft_services_proxmox_cluster_in_use', fn( $n, $id ) => 'lab' === $id ? 2 : $n, 10, 2 );

		$this->assertSame( 2, Clusters::in_use( 'lab' ) );
		$this->assertFalse( Clusters::delete( 'lab' ) );
		$this->assertNotNull( Clusters::get( 'lab' ) );

		$this->assertTrue( Clusters::delete( 'other' ) );
		$this->assertNull( Clusters::get( 'other' ) );
		$this->assertFalse( Clusters::delete( 'missing' ) );
	}

	public function test_the_form_never_prints_the_saved_secret(): void {
		Clusters::save( 'lab', $this->input() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['edit'] = 'lab';

		ob_start();
		\BeltoftServices\Proxmox\ClustersScreen::render();
		$html = (string) ob_get_clean();
		unset( $_GET['edit'] );

		$this->assertStringContainsString( 'name="token_secret"', $html );
		$this->assertStringNotContainsString( 'secret-secret-secret-1', $html );
		$this->assertStringNotContainsString( 'bs1:', $html );
		$this->assertStringContainsString( 'pve.example.invalid', $html );
	}
}
