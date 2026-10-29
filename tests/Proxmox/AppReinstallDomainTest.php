<?php
/** Domain choices on a server reinstall, with fake DNS and Proxmox. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

final class AppReinstallDomainTest extends MonitoringTestCase {
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
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_option( FakeDns::OPTION );
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_dequeue_script( AppReinstall::HANDLE );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function server( string $app = 'wordpress', array $meta = array(), int $vmid = 5000 ): Resource {
		$r = $this->ready_vm( $vmid );
		$address = '192.0.2.' . ( 10 + $vmid - 5000 );
		$r->set( array_merge( array(
			'ip' => $address,
			'app_id' => $app,
			'app_version' => 'wordpress' === $app ? '7.1.4' : ( 'coolify' === $app ? '4.5.1' : '29' ),
			OsField::META => 9001,
			'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
		), $meta ) );
		IpPool::add( $address );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );
		return Resource::find( $r->id() );
	}

	private function zone( int $owner, string $name = 'mine.example' ): void {
		$product = $this->service_product( 'domain', array() );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $owner ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( DnsClients::dns()->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );
	}

	private function html( Resource $r ): string {
		ob_start();
		AppReinstall::fields( $r->id() );
		return (string) ob_get_clean();
	}

	public function test_domain_form_is_offered_only_for_supported_apps_and_owned_zones(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'shop.mine.example', 'app_zone' => 'mine.example' ) );
		$this->assertStringNotContainsString( 'name="app_domain_mode"', $this->html( $r ) );
		$this->zone( (int) $r->license()['user_id'] );
		$html = $this->html( $r );
		$this->assertStringContainsString( 'data-domain="1"', $html );
		$this->assertStringContainsString( 'name="app_domain_mode" value="keep" checked', $html );
		$this->assertStringContainsString( 'Keep shop.mine.example', $html );
		$this->assertStringContainsString( '<option value="mine.example">', $html );
		$this->assertStringContainsString( 'name="app_sub" maxlength="120"', $html );
		$this->assertStringContainsString( 'name="app_domain_mode" value="change"', $html );
		$this->assertStringContainsString( 'name="app_domain_mode" value="none"', $html );

		$coolify = $this->server( 'coolify', array(), 5001 );
		$this->assertStringNotContainsString( 'name="app_domain_mode"', $this->html( $coolify ) );
	}

	public function test_plain_server_offers_a_domain_only_for_wordpress_and_loads_controls(): void {
		$r = $this->server( '' );
		$this->zone( (int) $r->license()['user_id'] );
		$html = $this->html( $r );
		$this->assertStringContainsString( 'name="app_domain_mode" value="none" checked', $html );
		$this->assertStringNotContainsString( 'name="app_domain_mode" value="keep"', $html );
		$this->assertMatchesRegularExpression( '#name="app_version" value="wordpress/7\.1\.4"[^>]*data-domain="1"#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="app_version" value="(?:docker/29|coolify/4\.5\.1)"[^>]*data-domain="1"#', $html );
		$args = array( 'app_version' => 'wordpress/7.1.4', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'new' );
		$this->assertNull( AppReinstall::check( null, $r, $args ) );
		$this->assertSame( 'new.mine.example', AppReinstall::meta( array(), $r, $args )['app_domain_want'] );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'docker/29', 'app_domain_mode' => 'none' ) ) );
		$this->assertSame( 'This app does not use a domain at install. You can add one later.', AppReinstall::check( null, $r, array( 'app_version' => 'docker/29', 'app_domain_mode' => 'change' ) ) );

		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		AppReinstall::assets( 'beltoft-services-account', Servers::slug() );
		$this->assertTrue( wp_script_is( AppReinstall::HANDLE, 'enqueued' ) );
	}

	public function test_change_checks_ownership_name_and_the_servers_own_a_record(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$this->zone( $owner );
		$args = array( 'app_version' => 'keep', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'shop' );
		$this->assertNull( AppReinstall::check( null, $r, $args ) );
		$this->assertSame( 'This server has no domain to keep.', AppReinstall::check( null, $r, array( 'app_domain_mode' => 'keep' ) ) );
		$this->assertSame( 'Choose one of your domains that uses our name servers.', AppReinstall::check( null, $r, array_merge( $args, array( 'app_zone' => 'not-ours.example' ) ) ) );
		$this->assertStringContainsString( 'subdomain', (string) AppReinstall::check( null, $r, array_merge( $args, array( 'app_sub' => 'bad name' ) ) ) );
		$this->assertSame( 'This app does not use a domain at install. You can add one later.', AppReinstall::check( null, $this->server( 'docker', array(), 5001 ), $args ) );

		$this->assertTrue( \BeltoftServices\Domains\Records::replace( 'mine.example', array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.99' ) ) ) );
		$this->assertSame( 'shop.mine.example already has a DNS record. Choose another subdomain.', AppReinstall::check( null, $r, $args ) );
		$this->assertTrue( \BeltoftServices\Domains\Records::replace( 'mine.example', array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$this->assertNull( AppReinstall::check( null, $r, $args ), 'The server already owns this A record.' );
	}

	public function test_meta_keeps_changes_and_clears_domain_without_writing_dns(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_want' => 'old.mine.example', 'app_zone' => 'mine.example', 'app_domain_error' => 'old failure' ) );
		$this->zone( (int) $r->license()['user_id'] );
		$dns = new class(true) extends FakeDns {
			public int $reads = 0;
			public function zone( string $name ): array|\WP_Error|null {
				++$this->reads;
				return parent::zone( $name );
			}
		};
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );

		$keep = AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'keep' ) );
		$this->assertNull( $keep['app_domain_error'] );
		$this->assertNull( $keep['app_domain'] );
		$this->assertSame( 'old.mine.example', $keep['app_domain_want'] );
		$this->assertArrayNotHasKey( 'app_domain_old', $keep );

		$same = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'old' ) );
		$this->assertArrayNotHasKey( 'app_domain_old', $same );
		$other = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'new' ) );
		$this->assertSame( 'new.mine.example', $other['app_domain_want'] );
		$this->assertNull( $other['app_domain'] );
		$this->assertSame( array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ), $other['app_domain_old'] );
		$none = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertNull( $none['app_domain_want'] );
		$this->assertNull( $none['app_zone'] );
		$this->assertCount( 1, $none['app_domain_old'] );
		$this->assertSame( 0, $dns->reads, 'Metadata preparation does not read DNS after validation.' );
		$this->assertSame( array(), $dns->calls(), 'Metadata preparation never changes DNS.' );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_no_app_and_second_reinstall_preserve_old_record_requests(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example', 'app_domain_old' => array( array( 'zone' => 'first.example', 'name' => 'old.first.example', 'ip' => '192.0.2.10', 'tries' => 1 ) ) ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'none' ) );
		$this->assertSame( '', $meta['app_id'] );
		$this->assertNull( $meta['app_domain'] );
		$this->assertCount( 2, $meta['app_domain_old'] );
		$this->assertSame( 'old.first.example', $meta['app_domain_old'][0]['name'] );
		$this->assertSame( 'old.mine.example', $meta['app_domain_old'][1]['name'] );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'none', 'app_domain_mode' => 'keep' ) ), 'No app normalises a keep request to no domain.' );
		$this->assertSame( array(), AppReinstall::meta( array(), $r, array( 'app_version' => '9.9', 'app_domain_mode' => 'none' ) ), 'An invalid version changes nothing.' );

		$r->set( array( 'app_domain_old' => array_fill( 0, 3, $meta['app_domain_old'][0] ) ) );
		$this->assertSame( 'The old domain records are still being checked. Try again later.', AppReinstall::check( null, Resource::find( $r->id() ), array( 'app_version' => 'none' ) ) );
	}

	public function test_plain_server_with_a_leftover_published_domain_clears_and_queues_it(): void {
		$r = $this->server( '', array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example' ) );
		$meta = AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'none' ) );
		$this->assertNull( $meta['app_domain'] );
		$this->assertNull( $meta['app_zone'] );
		$this->assertSame( 'old.mine.example', $meta['app_domain_old'][0]['name'] );
	}
}
