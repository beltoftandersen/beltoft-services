<?php
/** Domain choices on a server reinstall, with fake DNS and Proxmox. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppDomainRelease;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Domains\Records;
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
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_domain_want' => 'old.mine.example', 'app_zone' => 'mine.example', 'app_domain_error' => 'old failure' ) );
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
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example', 'app_domain_old' => array( array( 'zone' => 'first.example', 'name' => 'old.first.example', 'ip' => '192.0.2.10', 'tries' => 1 ) ) ) );
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
		$r = $this->server( '', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example' ) );
		$meta = AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'none' ) );
		$this->assertNull( $meta['app_domain'] );
		$this->assertNull( $meta['app_zone'] );
		$this->assertSame( 'old.mine.example', $meta['app_domain_old'][0]['name'] );
	}

	public function test_adopted_customer_record_is_never_queued_for_removal_and_has_a_dns_link(): void {
		$r = $this->server();
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$r->set( array( 'app_zone' => 'mine.example', 'app_domain_want' => 'old.mine.example' ) );
		$this->assertSame( 'old.mine.example', ( new \ReflectionMethod( AppInstall::class, 'domain' ) )->invoke( null, $r, '192.0.2.10', (int) $r->license()['user_id'] ) );
		$this->assertFalse( $r->get( 'app_domain_made' ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'new' ) );
		$this->assertArrayNotHasKey( 'app_domain_old', $meta );
		$this->assertSame( 'old.mine.example', $meta['app_domain_left']['name'] );
		$r->set( $meta );
		$this->assertSame( 'old.mine.example', AppTab::view( $r )['domain_left'] );
		$this->assertStringContainsString( '/dns', AppTab::view( $r )['domain_left_url'] );
		AppDomainRelease::run( $r->id() );
		$this->assertNotEmpty( DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
	}

	public function test_legacy_domain_without_ownership_flag_is_left_in_place(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_zone' => 'mine.example' ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertArrayNotHasKey( 'app_domain_old', $meta );
		$this->assertSame( 'old.mine.example', $meta['app_domain_left']['name'] );
		$r->set( $meta );
		AppDomainRelease::run( $r->id() );
		$this->assertNotEmpty( DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
	}

	public function test_no_app_still_shows_the_left_record_notice_on_its_app_tab(): void {
		$r = $this->server( '', array( 'app_domain_left' => array( 'zone' => 'mine.example', 'name' => 'old.mine.example' ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertArrayHasKey( 'app', AppTab::tabs( array( 'overview' => 'Overview' ), $r->id() ) );
		ob_start();
		AppTab::pane( 'app', $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'old.mine.example', $html );
		$this->assertStringContainsString( '/dns', $html );
	}

	public function test_record_written_by_app_is_removed_at_reinstall_start(): void {
		$r = $this->server();
		$this->zone( (int) $r->license()['user_id'] );
		$r->set( array( 'app_zone' => 'mine.example', 'app_domain_want' => 'old.mine.example' ) );
		$this->assertSame( 'old.mine.example', ( new \ReflectionMethod( AppInstall::class, 'domain' ) )->invoke( null, $r, '192.0.2.10', (int) $r->license()['user_id'] ) );
		$this->assertTrue( $r->get( 'app_domain_made' ) );
		$meta = AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) );
		$this->assertCount( 1, $meta['app_domain_old'] );
		$r->set( $meta );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		$this->assertSame( array(), DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
	}

	public function test_customer_edit_to_app_record_is_kept(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 600, 'value' => '192.0.2.10' ) ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		$this->assertNotEmpty( DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
	}

	public function test_release_preserves_other_rrsets_and_is_scheduled_by_reinstall_event(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array(
			array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ),
			array( 'name' => '@', 'type' => 'MX', 'ttl' => 600, 'priority' => 10, 'value' => 'mail.mine.example' ),
		) ) );
		$before = DnsClients::dns()->zone( 'mine.example' )['rrsets'];
		$dns = new FakeDns( true );
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		do_action( 'beltoft_services_reinstall_started', $r, array() );
		$this->assertNotFalse( as_has_scheduled_action( AppDomainRelease::HOOK, array( $r->id() ), \BeltoftServices\Jobs::GROUP ) );
		AppDomainRelease::run( $r->id() );
		$this->assertCount( 1, $dns->calls() );
		$this->assertSame( 'DELETE', $dns->calls()[0][2][0]['changetype'] );
		$this->assertSame( 'A', $dns->calls()[0][2][0]['type'] );
		$this->assertSame( array( $before[1] ), DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_paused_zone_is_left_alone_without_a_release_alert(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		foreach ( \BeltoftLicenses\Licenses::where( 'user_id', (int) $r->license()['user_id'] ) as $license ) {
			$domain = Resource::for_license( (int) $license['id'] );
			if ( $domain && 'mine.example' === $domain->get( 'domain' ) ) {
				$domain->set( array( 'records_off' => true ) );
			}
		}
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		$this->assertNotEmpty( DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
		$this->assertFalse( (bool) $r->get( 'app_domain_release_alerted' ) );
	}

	public function test_release_transport_errors_retry_four_times_then_alert_once(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$dns = new class(true) extends FakeDns {
			public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error {
				return new \WP_Error( 'transport', 'Temporary DNS error' );
			}
		};
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$mails = array();
		$capture = static function ( $pre, $mail ) use ( &$mails ) { $mails[] = $mail; return true; };
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		for ( $attempt = 1; $attempt <= 4; ++$attempt ) {
			AppDomainRelease::run( $r->id() );
			$this->assertSame( $attempt, Resource::find( $r->id() )->get( 'app_domain_old' )[0]['tries'] );
		}
		$this->assertSame( array(), $mails );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		$this->assertCount( 1, $mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $mails[0]['to'] );
		AppDomainRelease::run( $r->id() );
		$this->assertCount( 1, $mails );
		remove_filter( 'pre_wp_mail', $capture, 10 );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_release_meta_update_keeps_provision_progress_written_during_dns(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 ) ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$dns = new class(true) extends FakeDns {
			public int $resource_id = 0;
			public function replace_rrsets( string $zone, array $rrsets ): bool|\WP_Error {
				if ( 'DELETE' === ( $rrsets[0]['changetype'] ?? '' ) ) {
					Resource::find( $this->resource_id )->set( array( 'task' => 'provision-task', 'steps' => array( 'ip', 'vmid', 'clone' ) ) );
				}
				return parent::replace_rrsets( $zone, $rrsets );
			}
		};
		$dns->resource_id = $r->id();
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		AppDomainRelease::run( $r->id() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'provision-task', $fresh->get( 'task' ) );
		$this->assertSame( array( 'ip', 'vmid', 'clone' ), $fresh->get( 'steps' ) );
		$this->assertSame( array(), (array) $fresh->get( 'app_domain_old' ) );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_confirmation_mentions_removal_only_for_a_record_the_app_made(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example' ) );
		$this->zone( (int) $r->license()['user_id'] );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$html = $this->html( $r );
		$this->assertStringContainsString( 'data-domain-made="1"', $html );
		$this->assertStringContainsString( 'data-app-domain-removal hidden', $html );
		$this->assertStringContainsString( 'We remove the A record we made for old.mine.example when the reinstall starts.', $html );
		$this->assertStringContainsString( 'beltoft-services-app-reinstall__removal', $html );
		ob_start();
		AppReinstall::confirm( $r->id() );
		$this->assertStringContainsString( 'data-app-domain-confirm hidden', (string) ob_get_clean() );
		$r->set( array( 'app_domain_made' => false ) );
		$this->assertStringNotContainsString( 'data-app-domain-removal', $this->html( $r ) );
		ob_start();
		AppReinstall::confirm( $r->id() );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_junk_release_queue_is_cleared_and_sweep_does_not_reschedule_it(): void {
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( 'x' ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		AppDomainRelease::sweep();
		$this->assertFalse( as_has_scheduled_action( AppDomainRelease::HOOK, array( $r->id() ), \BeltoftServices\Jobs::GROUP ) );
	}

	public function test_junk_release_entry_is_skipped_and_the_next_valid_record_is_removed(): void {
		$entry = array( 'zone' => 'mine.example', 'name' => 'old.mine.example', 'ip' => '192.0.2.10', 'tries' => 0 );
		$r = $this->server( 'wordpress', array( 'app_domain_old' => array( 'x', $entry ) ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( array(), (array) Resource::find( $r->id() )->get( 'app_domain_old' ) );
		$this->assertSame( array(), DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
	}

	public function test_keep_domain_pipeline_makes_no_dns_write(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example' ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$dns = new FakeDns( true );
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$r->set( AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'keep' ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( 'old.mine.example', ( new \ReflectionMethod( AppInstall::class, 'domain' ) )->invoke( null, $r, '192.0.2.10', (int) $r->license()['user_id'] ) );
		$this->assertSame( array(), $dns->calls() );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_change_domain_pipeline_deletes_old_a_then_adds_new_a(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example' ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$dns = new FakeDns( true );
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$r->set( AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'change', 'app_zone' => 'mine.example', 'app_sub' => 'new' ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( 'new.mine.example', ( new \ReflectionMethod( AppInstall::class, 'domain' ) )->invoke( null, $r, '192.0.2.10', (int) $r->license()['user_id'] ) );
		$this->assertSame( array( 'DELETE', 'REPLACE' ), array_column( array_map( static fn( $call ) => $call[2][0], $dns->calls() ), 'changetype' ) );
		$this->assertSame( array( 'old.mine.example.', 'new.mine.example.' ), array_column( array_map( static fn( $call ) => $call[2][0], $dns->calls() ), 'name' ) );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}

	public function test_no_domain_pipeline_deletes_old_a_without_adding_another(): void {
		$r = $this->server( 'wordpress', array( 'app_domain' => 'old.mine.example', 'app_domain_made' => true, 'app_zone' => 'mine.example' ) );
		$this->zone( (int) $r->license()['user_id'] );
		$this->assertTrue( Records::replace( 'mine.example', array( array( 'name' => 'old', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$dns = new FakeDns( true );
		$filter = static fn() => $dns;
		add_filter( 'beltoft_services_domains_dns', $filter, 20 );
		$r->set( AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'none' ) ) );
		AppDomainRelease::run( $r->id() );
		$this->assertSame( '', ( new \ReflectionMethod( AppInstall::class, 'domain' ) )->invoke( null, $r, '192.0.2.10', (int) $r->license()['user_id'] ) );
		$this->assertCount( 1, $dns->calls() );
		$this->assertSame( 'DELETE', $dns->calls()[0][2][0]['changetype'] );
		$this->assertSame( 'A', $dns->calls()[0][2][0]['type'] );
		remove_filter( 'beltoft_services_domains_dns', $filter, 20 );
	}
}
