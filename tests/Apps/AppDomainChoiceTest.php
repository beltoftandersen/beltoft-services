<?php
/** The one parser of the domain choice, for reinstall and for the change of domain. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppDomainChoice;
use BeltoftServices\Apps\AppReinstall;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

final class AppDomainChoiceTest extends MonitoringTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( FakeDns::OPTION );
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'coolify' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
		$this->clear_ips();
	}

	public function tear_down(): void {
		$this->clear_ips();
		delete_option( FakeDns::OPTION );
		parent::tear_down();
	}

	private function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	private function server( array $meta = array() ): Resource {
		$r = $this->ready_vm( 5000 );
		$this->assertTrue( $r->set( array_merge( array(
			'ip' => '192.0.2.10', 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_state' => 'ready', OsField::META => 9001,
			'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
		), $meta ) ) );
		IpPool::add( '192.0.2.10' );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );
		$product = $this->service_product( 'domain', array() );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => (int) $r->license()['user_id'] ) );
		$d = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'mine.example', 'mode' => 'register', 'zone_ready' => true ) );
		$d->set( array( 'state' => 'ready' ) );
		$this->assertTrue( DnsClients::dns()->create_zone( 'mine.example', array( 'ns1.example.net', 'ns2.example.net' ) ) );
		return Resource::find( $r->id() );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
	public static function choices(): array {
		return array(
			'no mode, no domain'    => array( array(), array() ),
			'none'                  => array( array( 'app_domain_mode' => 'none' ), array() ),
			'keep without a name'   => array( array( 'app_domain_mode' => 'keep' ), array() ),
			'keep a name'           => array( array( 'app_domain_mode' => 'keep' ), array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example' ) ),
			'unknown mode'          => array( array( 'app_domain_mode' => 'weird' ), array() ),
			'change, free name'     => array( array( 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'shop' ), array() ),
			'change, foreign zone'  => array( array( 'app_domain_mode' => 'change', 'app_zone' => 'not-ours.example', 'app_sub' => 'shop' ), array() ),
			'change, bad label'     => array( array( 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'bad name' ), array() ),
			'change, the same name' => array( array( 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'old' ), array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example' ) ),
			'own, a name'           => array( array( 'app_domain_mode' => 'own', 'app_own' => 'Shop.Example.COM.' ), array() ),
			'own, empty'            => array( array( 'app_domain_mode' => 'own', 'app_own' => '' ), array() ),
			'own, not a name'       => array( array( 'app_domain_mode' => 'own', 'app_own' => 'https://x/y' ), array() ),
			'own, the same name'    => array( array( 'app_domain_mode' => 'own', 'app_own' => 'elsewhere.test' ), array( 'app_domain' => 'elsewhere.test', 'app_domain_own' => 'elsewhere.test' ) ),
		);
	}

	/**
	 * @dataProvider choices
	 * @param array<string, mixed> $args Posted.
	 * @param array<string, mixed> $meta Server meta.
	 */
	public function test_the_choice_parser_gives_the_same_answers_for_reinstall_and_change( array $args, array $meta ): void {
		$r   = $this->server( $meta );
		$app = (array) Apps::definition( 'wordpress' );
		$via = new \ReflectionMethod( AppReinstall::class, 'domain_choice' );
		foreach ( array( true, false ) as $dns ) {
			$this->assertSame( AppDomainChoice::resolve( $r, $args, $app, $dns ), $via->invoke( null, $r, $args, $app, $dns ) );
		}
	}

	public function test_an_app_without_domain_support_and_no_app_answer_as_the_reinstall_always_did(): void {
		$r = $this->server();
		$this->assertSame( array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' ), AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'change' ), array() ) );
		$docker = (array) Apps::definition( 'docker' );
		$this->assertSame( 'This app does not use a domain at install. You can add one later.', AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'change' ), $docker ) );
		$this->assertSame( array( 'mode' => 'none', 'zone' => '', 'fqdn' => '' ), AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'none' ), $docker ) );
	}

	public function test_the_answers_in_words(): void {
		$r   = $this->server();
		$app = (array) Apps::definition( 'wordpress' );
		$this->assertSame( array( 'mode' => 'change', 'zone' => 'mine.example', 'fqdn' => 'shop.mine.example' ), AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'shop' ), $app ) );
		$this->assertSame( array( 'mode' => 'own', 'zone' => '', 'fqdn' => 'shop.example.com' ), AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'own', 'app_own' => 'Shop.Example.COM.' ), $app ) );
		$this->assertSame( 'Choose a domain option.', AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'weird' ), $app ) );
		$this->assertSame( 'This server has no domain to keep.', AppDomainChoice::resolve( $r, array( 'app_domain_mode' => 'keep' ), $app ) );
	}

	public function test_the_release_refusal_is_the_one_the_reinstall_gives(): void {
		$r = $this->server( array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example', 'app_domain_made' => true, 'app_domain_old' => array( array( 'a' => 1 ), array( 'b' => 2 ), array( 'c' => 3 ) ) ) );
		$this->assertSame( 'The old domain records are still being checked. Try again later.', AppDomainChoice::release_refusal( $r, array( 'mode' => 'change' ) ) );
		$this->assertNull( AppDomainChoice::release_refusal( $r, array( 'mode' => 'keep' ) ) );
		$this->assertSame( 'old.mine.example', AppDomainChoice::current( $r ) );
	}
}
