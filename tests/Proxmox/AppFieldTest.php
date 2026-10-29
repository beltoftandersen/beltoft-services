<?php
/**
 * The app choice at checkout, its storage on the resource and the domain picker.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Domains\Clients as DnsClients;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppAlerts;
use BeltoftServices\Proxmox\AppDomains;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppInstall;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Documentation values only. Nothing here reaches a network: the DNS layer is FakeDns, Proxmox is FakeClient.
 */
class AppFieldTest extends TestCase {

	private const SNIPPET = 'local:snippets/vm-vendor-cloudinit-v2.yaml';

	public function set_up(): void {
		parent::set_up();

		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Clusters::OPTION );
		delete_option( FakeDns::OPTION );
		$this->wipe_tables();
		$this->save_cluster();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['proxmox'] = new ProxmoxDriver();
				return $drivers;
			}
		);
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );

		foreach ( array( AppField::POST_APP, AppField::POST_VERSION, AppField::POST_DOMAIN, AppField::POST_ZONE, AppField::POST_SUB, OsField::POST_KEY ) as $key ) {
			unset( $_POST[ $key ] );
		}

		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_initial_meta' );
		remove_all_filters( 'beltoft_services_apps' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		delete_option( FakeDns::OPTION );
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * @param array<string, mixed> $over Form overrides.
	 */
	private function save_cluster( array $over = array() ): void {
		$this->assertTrue(
			Clusters::save(
				'lab',
				array_merge(
					array(
						'label'                  => 'Lab',
						'api_url'                => 'https://pve.example.invalid:8006',
						'token_id'               => 'svc@pve!beltoft',
						'token_secret'           => 'secret-secret-secret-1',
						'client'                 => 'fake',
						'nodes'                  => 'node-a',
						'storage'                => 'disk-store',
						'cloudinit_storage'      => 'ci-store',
						'bridge'                 => 'vmbr9',
						'gateway'                => '192.0.2.1',
						'prefix'                 => '24',
						'vmid_min'               => '5000',
						'vmid_max'               => '5099',
						'templates'              => "9000 = Debian 12\n9001 = Ubuntu 24.04",
						'default_template'       => '9000',
						'vendor_snippet'         => self::SNIPPET,
						'apps_ready'             => 1,
						'apps_enabled'           => array( 'docker', 'wordpress', 'coolify' ),
						'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
					),
					$over
				)
			)
		);
	}

	/**
	 * @param array<string, mixed> $plan Plan values.
	 */
	private function vm( array $plan = array() ): \WC_Product {
		return $this->service_product(
			'proxmox',
			array_merge( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ), $plan ),
			array( 'slug' => 'vm-' . wp_generate_password( 5, false ) )
		);
	}

	private function html(): string {
		ob_start();
		AppField::render_classic();

		return (string) ob_get_clean();
	}

	/**
	 * Posts values and runs the classic validation.
	 *
	 * @param array<string, string> $post Values by POST key.
	 * @return string[] Error messages.
	 */
	private function check( array $post ): array {
		do_action( 'woocommerce_checkout_process' );

		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		$errors = new \WP_Error();
		AppField::validate_classic( array(), $errors );

		return $errors->get_error_messages();
	}

	private function customer(): int {
		$id = self::factory()->user->create();
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * A ready domain with DNS served by us, owned by a user, with its zone in FakeDns.
	 *
	 * @param array<int, array<string, mixed>> $rows Existing records, editor rows.
	 */
	private function domain( int $user, string $name, array $meta = array(), array $rows = array() ): Resource {
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'register', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $r );
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( DnsClients::dns()->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) ) );

		if ( $rows ) {
			$this->assertTrue( \BeltoftServices\Domains\Records::replace( $name, $rows ) );
		}

		return Resource::find( $r->id() );
	}

	// -- Hidden unless ready -------------------------------------------------------------------

	public function test_the_field_is_hidden_until_the_cluster_is_ready_and_the_app_is_enabled(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$this->assertStringContainsString( 'name="' . AppField::POST_APP . '"', $this->html() );

		$this->save_cluster( array( 'apps_ready' => 0 ) );
		$this->assertSame( '', $this->html(), 'apps_ready is off' );

		$this->save_cluster( array( 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit.yaml' ) );
		$this->assertSame( '', $this->html(), 'the v1 snippet' );

		$this->save_cluster( array( 'vendor_snippet' => '' ) );
		$this->assertSame( '', $this->html(), 'no snippet' );

		$this->save_cluster( array( 'apps_enabled' => array() ) );
		$this->assertSame( '', $this->html(), 'no app enabled' );

		$this->save_cluster( array( 'template_families_text' => '9000 = debian-12' ) );
		$this->assertSame( '', $this->html(), 'no ubuntu-24.04 family: apps are not offered at all' );

		$this->save_cluster();
		$this->assertNotSame( '', $this->html() );

		$this->save_cluster( array( 'apps_enabled' => array( 'docker' ) ) );
		$html = $this->html();
		$this->assertStringContainsString( 'value="docker"', $html );
		$this->assertStringNotContainsString( 'value="wordpress"', $html, 'only the enabled app is listed' );
	}

	public function test_a_new_cluster_has_apps_off_and_a_form_save_turns_them_on_and_off(): void {
		$d = Clusters::defaults();
		$this->assertSame( 0, $d['apps_ready'] );
		$this->assertSame( array(), $d['apps_enabled'] );
		$this->assertSame( array(), $d['template_families'] );

		$this->assertSame( array( 9001 => 'ubuntu-24.04', 9000 => 'debian-12' ), Clusters::get( 'lab' )['template_families'] );
		$this->assertSame( 9001, Apps::os_template( Clusters::get( 'lab' ) ) );

		$this->save_cluster( array( 'apps_form' => '1', 'apps_ready' => null, 'apps_enabled' => array( 'docker', 'bad id!' ) ) );
		$cluster = Clusters::get( 'lab' );
		$this->assertSame( 0, $cluster['apps_ready'], 'An unticked box on the settings form means off.' );
		$this->assertSame( array( 'docker' ), $cluster['apps_enabled'] );
	}

	public function test_a_renewal_and_a_server_whose_plan_cluster_is_unknown_get_no_field(): void {
		$vm = $this->vm();
		WC()->cart->add_to_cart( $vm->get_id(), 1, 0, array(), array( 'subscription_renewal' => array( 'subscription_id' => 1, 'item_id' => 0, 'next_payment_timestamp' => 0, 'upgraded_or_downgraded' => '' ) ) );
		$this->assertSame( '', $this->html() );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->vm( array( 'cluster' => 'elsewhere' ) )->get_id() );
		$this->assertSame( '', $this->html() );
	}

	// -- Options and versions ------------------------------------------------------------------

	public function test_none_is_first_every_app_is_listed_and_the_plan_minimum_is_shown(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$html = $this->html();

		$this->assertMatchesRegularExpression( '#type="radio" name="' . AppField::POST_APP . '" value="" checked=\'checked\' data-none="1".*?>No app</span>.*?value="docker"#s', $html, 'No app is the first tile and is checked.' );
		$this->assertStringContainsString( '>Docker</span>', $html );
		$this->assertStringContainsString( '>WordPress</span>', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="coolify"[^>]*disabled[^>]*>.*?>Coolify</span>.*?>Needs Performance</span>#s', $html, 'Below its minimum the app is listed but cannot be chosen.' );
		$this->assertStringNotContainsString( '<select name="' . AppField::POST_APP . '"', $html );
		$this->assertStringContainsString( '<svg', $html, 'The apps have logos.' );
		$this->assertStringContainsString( 'App (optional)', $html );
		$this->assertStringContainsString( 'data-apps=', $html );
		$this->assertStringContainsString( 'Ubuntu 24.04', $html );
	}

	public function test_a_big_enough_plan_can_choose_coolify(): void {
		WC()->cart->add_to_cart( $this->vm( array( 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) )->get_id() );
		$html = $this->html();
		$this->assertDoesNotMatchRegularExpression( '#<input[^>]*value="coolify"[^>]*disabled#', $html );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'coolify' ) ) );
	}

	public function test_the_version_defaults_to_current_and_older_supported_ones_show_their_end_date_but_retired_ones_do_not(): void {
		$dir = BELTOFT_SERVICES_PATH . 'apps/docker/28-test';
		mkdir( $dir );
		copy( BELTOFT_SERVICES_PATH . 'apps/docker/29/install.script', $dir . '/install.script' );
		$filter = static function ( $data, $id ) {
			if ( 'docker' === $id && is_array( $data ) ) {
				$old                 = $data['versions'][0];
				$old['id']           = '28-test';
				$old['label']        = 'Docker Engine 28';
				$old['status']       = 'available';
				$old['eol']          = '2027-03';
				$data['versions'][]  = $old;
				$gone                = $old;
				$gone['id']          = '27-test';
				$gone['label']       = 'Docker Engine 27';
				$gone['status']      = 'retired';
				$data['versions'][]  = $gone;
			}

			return $data;
		};
		add_filter( 'beltoft_services_apps', $filter, 10, 2 );

		try {
			WC()->cart->add_to_cart( $this->vm()->get_id() );
			$offer = AppField::offer();
			$this->assertSame( array( '29', '28-test' ), array_map( 'strval', array_keys( $offer['apps']['docker']['choices'] ) ), 'The current version leads; retired ones are never listed.' );

			$html = $this->html();
			$this->assertStringContainsString( 'Docker Engine 29', $html );
			$this->assertStringContainsString( 'Docker Engine 28 (supported until March 2027)', $html );
			$this->assertStringNotContainsString( 'Docker Engine 27', $html );
			$this->assertStringContainsString( 'name="' . AppField::POST_VERSION . '"', $html );

			// Empty version means the current one; a listed older one is accepted; the retired one is refused.
			$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_VERSION => '' ) ) );
			$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_VERSION => '28-test' ) ) );
			$this->assertSame( array( 'Choose one of the listed versions.' ), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_VERSION => '27-test' ) ) );
			$this->assertSame( array( 'Choose one of the listed versions.' ), $this->check( array( AppField::POST_APP => 'docker', AppField::POST_VERSION => '1.0' ) ) );
		} finally {
			remove_filter( 'beltoft_services_apps', $filter, 10 );
			unlink( $dir . '/install.script' );
			rmdir( $dir );
		}
	}

	public function test_the_plan_minimum_and_unknown_apps_are_refused_before_payment_with_a_plain_message(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		$this->assertSame( array(), $this->check( array( AppField::POST_APP => '' ) ) );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'docker' ) ) );
		$this->assertSame( array( 'Coolify needs the Performance plan or larger. Choose a larger plan or no app.' ), $this->check( array( AppField::POST_APP => 'coolify' ) ) );
		$this->assertSame( array( 'Choose one of the listed apps, or none.' ), $this->check( array( AppField::POST_APP => 'nextcloud' ) ) );
		$this->assertSame( array( 'Choose one of the listed apps, or none.' ), $this->check( array( AppField::POST_APP => '../docker' ) ) );

		$this->save_cluster( array( 'apps_ready' => 0 ) );
		$this->assertSame( array( 'Apps are not available for this server. Choose none.' ), $this->check( array( AppField::POST_APP => 'docker' ) ), 'An app that is no longer offered is not silently dropped.' );
	}

	// -- Operating system and storage ----------------------------------------------------------

	public function test_an_app_sets_the_operating_system_on_the_line_whatever_was_posted(): void {
		$vm = $this->vm();
		WC()->cart->add_to_cart( $vm->get_id() );
		do_action( 'woocommerce_checkout_process' );
		$_POST[ OsField::POST_KEY ]  = '9000';
		$_POST[ AppField::POST_APP ] = 'docker';

		$item = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		OsField::save_on_item( $item );
		$this->assertSame( '9000', $item->get_meta( OsField::ITEM_META ) );
		AppField::save_on_item( $item );

		$this->assertSame( '9001', $item->get_meta( OsField::ITEM_META ), 'Ubuntu 24.04 replaces the other choice.' );
		$this->assertSame( 'docker', $item->get_meta( AppField::ITEM_APP ) );
		$this->assertSame( '29', $item->get_meta( AppField::ITEM_VERSION ), 'The current version when none is posted.' );
		$this->assertSame( '', $item->get_meta( AppField::ITEM_DOMAIN ) );

		// No app: the operating system choice is left alone.
		$_POST[ AppField::POST_APP ] = '';
		$plain = new \WC_Order_Item_Product();
		$plain->set_product( $vm );
		OsField::save_on_item( $plain );
		AppField::save_on_item( $plain );
		$this->assertSame( '9000', $plain->get_meta( OsField::ITEM_META ) );
		$this->assertSame( '', $plain->get_meta( AppField::ITEM_APP ) );
	}

	/**
	 * @param array<string, string> $item_meta Line item meta.
	 * @param array<string, mixed>  $plan      Plan values.
	 * @return array<string, mixed> The license.
	 */
	private function license_with( array $item_meta, array $plan = array(), ?int $user = null ): array {
		$vm    = $this->vm( $plan );
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		$item->set_quantity( 1 );

		foreach ( $item_meta as $k => $v ) {
			$item->add_meta_data( $k, $v );
		}

		$order->add_item( $item );
		$order->save();

		return $this->service_license( $vm, array_filter( array( 'fire' => false, 'order_id' => $order->get_id(), 'user_id' => $user ) ) );
	}

	public function test_the_choice_reaches_the_resource_with_the_os_forced(): void {
		$license = $this->license_with( array( AppField::ITEM_APP => 'wordpress', AppField::ITEM_VERSION => '7.1.4', OsField::ITEM_META => '9000' ) );
		Lifecycle::provide( $license );
		$r = Resource::for_license( (int) $license['id'] );

		$this->assertSame( 'wordpress', $r->get( 'app_id' ) );
		$this->assertSame( '7.1.4', $r->get( 'app_version' ) );
		$this->assertSame( 9001, $r->get( OsField::META ), 'The app has the last word on the system.' );
		$this->assertNull( $r->get( 'app_domain_want' ) );
	}

	public function test_a_choice_that_no_longer_holds_builds_a_server_without_the_app(): void {
		foreach ( array(
			array( AppField::ITEM_APP => 'coolify', AppField::ITEM_VERSION => '4.5.1' ),
			array( AppField::ITEM_APP => 'nextcloud', AppField::ITEM_VERSION => '1' ),
			array( AppField::ITEM_APP => 'docker', AppField::ITEM_VERSION => '0.1' ),
			array( AppField::ITEM_APP => '' ),
		) as $meta ) {
			$license = $this->license_with( $meta + array( OsField::ITEM_META => '9000' ) );
			Lifecycle::provide( $license );
			$r = Resource::for_license( (int) $license['id'] );
			$this->assertNull( $r->get( 'app_id' ), wp_json_encode( $meta ) );
			$this->assertSame( 9000, $r->get( OsField::META ), 'The operating system choice is untouched.' );
		}

		$this->save_cluster( array( 'apps_ready' => 0 ) );
		$license = $this->license_with( array( AppField::ITEM_APP => 'docker', AppField::ITEM_VERSION => '29' ) );
		Lifecycle::provide( $license );
		$this->assertNull( Resource::for_license( (int) $license['id'] )->get( 'app_id' ), 'Apps switched off after the order: no app, a plain server.' );
	}

	public function test_a_server_with_an_app_starts_in_the_pending_state_so_the_install_follows_ready(): void {
		$license = $this->license_with( array( AppField::ITEM_APP => 'docker', AppField::ITEM_VERSION => '29' ) );
		Lifecycle::provide( $license );
		$r = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'docker', $r->get( 'app_id' ) );
		$this->assertContains( $r->get( 'app_state' ), array( null, 'pending' ) );
	}

	// -- The domain picker ---------------------------------------------------------------------

	public function test_only_zones_the_customer_holds_with_our_dns_are_offered(): void {
		$me    = $this->customer();
		$other = self::factory()->user->create();
		$this->domain( $me, 'mine.example' );
		$this->domain( $other, 'theirs.example' );
		$this->domain( $me, 'elsewhere.example', array( 'dns_external' => true ) );
		$this->domain( $me, 'custom-ns.example', array( 'ns_mode' => 'custom' ) );
		$this->domain( $me, 'paused.example', array( 'records_off' => true ) );

		$this->assertSame( array( 'mine.example' ), AppDomains::zones( $me ) );
		$this->assertSame( array( 'theirs.example' ), AppDomains::zones( $other ) );
		$this->assertSame( array(), AppDomains::zones( 0 ) );

		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$html = $this->html();
		$this->assertStringContainsString( 'Point a domain to this server', $html );
		$this->assertStringContainsString( '<option value="mine.example">', $html );
		$this->assertStringNotContainsString( 'theirs.example', $html );
		$this->assertStringNotContainsString( 'elsewhere.example', $html );
		$this->assertStringContainsString( 'name="' . AppField::POST_SUB . '"', $html );
	}

	public function test_a_visitor_or_a_customer_without_domains_gets_no_picker(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$this->assertStringNotContainsString( 'Point a domain', $this->html() );
		$this->customer();
		$this->assertStringNotContainsString( 'Point a domain', $this->html() );
	}

	public function test_the_domain_is_validated_for_ownership_name_and_a_free_record(): void {
		$me    = $this->customer();
		$other = self::factory()->user->create();
		$this->domain( $me, 'mine.example', array(), array( array( 'name' => 'taken', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.99' ), array( 'name' => 'alias', 'type' => 'CNAME', 'ttl' => 300, 'value' => 'mine.example' ) ) );
		$this->domain( $other, 'theirs.example' );
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		$base = array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => '1', AppField::POST_ZONE => 'mine.example' );

		$this->assertSame( array(), $this->check( $base + array( AppField::POST_SUB => 'shop' ) ) );
		$this->assertSame( array(), $this->check( $base + array( AppField::POST_SUB => '' ) ), 'The domain itself, when it has no address record.' );
		$this->assertSame( array( 'Choose one of your domains that uses our name servers.' ), $this->check( array_merge( $base, array( AppField::POST_ZONE => 'theirs.example', AppField::POST_SUB => 'shop' ) ) ), 'Another customer\'s zone.' );
		$this->assertSame( array( 'Choose one of your domains that uses our name servers.' ), $this->check( array_merge( $base, array( AppField::POST_ZONE => 'unknown.example' ) ) ) );
		$this->assertSame( array( 'Choose one of your domains that uses our name servers.' ), $this->check( array_merge( $base, array( AppField::POST_ZONE => '' ) ) ) );
		$this->assertStringContainsString( 'subdomain', $this->check( $base + array( AppField::POST_SUB => 'bad name' ) )[0] );
		$this->assertStringContainsString( 'subdomain', $this->check( $base + array( AppField::POST_SUB => '../x' ) )[0] );
		$this->assertSame( array( 'taken.mine.example already has a DNS record. Choose another subdomain.' ), $this->check( $base + array( AppField::POST_SUB => 'taken' ) ) );
		$this->assertSame( array( 'alias.mine.example already has a DNS record. Choose another subdomain.' ), $this->check( $base + array( AppField::POST_SUB => 'alias' ) ) );
		$this->assertSame( array( 'This app does not use a domain at install. You can add one later.' ), $this->check( array_merge( $base, array( AppField::POST_APP => 'docker', AppField::POST_SUB => 'shop' ) ) ) );

		// Not ticked: the zone and subdomain fields are ignored.
		unset( $_POST[ AppField::POST_DOMAIN ] );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'wordpress', AppField::POST_ZONE => 'theirs.example', AppField::POST_SUB => 'x y' ) ) );
	}

	public function test_a_ticked_domain_is_saved_on_the_line_and_reaches_the_resource_and_an_unticked_one_does_not(): void {
		$me = $this->customer();
		$this->domain( $me, 'mine.example' );
		$vm = $this->vm();
		WC()->cart->add_to_cart( $vm->get_id() );
		do_action( 'woocommerce_checkout_process' );
		$_POST[ AppField::POST_APP ]    = 'wordpress';
		$_POST[ AppField::POST_DOMAIN ] = '1';
		$_POST[ AppField::POST_ZONE ]   = 'mine.example';
		$_POST[ AppField::POST_SUB ]    = 'Shop';

		$item = new \WC_Order_Item_Product();
		$item->set_product( $vm );
		AppField::save_on_item( $item );
		$this->assertSame( 'shop.mine.example', $item->get_meta( AppField::ITEM_DOMAIN ) );
		$this->assertSame( 'mine.example', $item->get_meta( AppField::ITEM_ZONE ) );

		$license = $this->license_with(
			array( AppField::ITEM_APP => 'wordpress', AppField::ITEM_VERSION => '7.1.4', AppField::ITEM_ZONE => 'mine.example', AppField::ITEM_DOMAIN => 'shop.mine.example' ),
			array(),
			$me
		);
		Lifecycle::provide( $license );
		$r = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'shop.mine.example', $r->get( 'app_domain_want' ) );
		$this->assertSame( 'mine.example', $r->get( 'app_zone' ) );
		$this->assertNull( $r->get( 'app_domain' ), 'No record exists until the app is installed.' );
		$this->assertSame( array(), DnsClients::dns()->zone( 'mine.example' )['rrsets'], 'Checkout creates no DNS record.' );

		// A zone that is not the customer's is not stored, even if a line says so.
		$stranger = $this->license_with(
			array( AppField::ITEM_APP => 'wordpress', AppField::ITEM_VERSION => '7.1.4', AppField::ITEM_ZONE => 'mine.example', AppField::ITEM_DOMAIN => 'shop.mine.example' ),
			array(),
			self::factory()->user->create()
		);
		Lifecycle::provide( $stranger );
		$this->assertNull( Resource::for_license( (int) $stranger['id'] )->get( 'app_id' ), 'The whole choice is dropped.' );
	}

	// -- Install: the record and the job -------------------------------------------------------

	/**
	 * A ready VM with an app, a fake Proxmox, an address and an owner.
	 *
	 * @param array<string, mixed> $meta App meta.
	 */
	private function server( int $user, array $meta ): array {
		$fake = new FakeClient();
		$fake->add_node( 'node-a' );
		add_filter( 'beltoft_services_proxmox_client', fn() => $fake );
		$product  = $this->vm();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$r        = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertSame( 5000, VmRecords::allocate( $r->id(), 'lab', 5000, 5000, array(), static fn() => true ) );
		$this->assertTrue( VmRecords::move( $r->id(), 'node-a' ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready', 'ip' => '192.0.2.10' ) ) );
		$fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'running', 'config' => array( 'agent' => '1' ) ) );
		IpPool::add( '192.0.2.10' );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );

		return array( Resource::find( $r->id() ), $fake );
	}

	public function test_install_creates_the_a_record_in_the_owners_zone_keeps_other_records_and_passes_the_domain(): void {
		$me = self::factory()->user->create();
		$this->domain( $me, 'mine.example', array(), array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 600, 'value' => '192.0.2.77' ), array( 'name' => '@', 'type' => 'MX', 'ttl' => 600, 'priority' => 10, 'value' => 'mail.mine.example' ) ) );
		[ $r, $fake ] = $this->server( $me, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_zone' => 'mine.example', 'app_domain_want' => 'shop.mine.example', 'app_state' => 'pending', OsField::META => 9001 ) );

		$result = AppInstall::queue( $r, $fake );
		$this->assertTrue( $result->is_done(), $result->error() );

		$rows = \BeltoftServices\Domains\Records::to_rows( 'mine.example', DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
		$by   = array();
		foreach ( $rows as $row ) {
			$by[ $row['name'] . '|' . $row['type'] ] = $row['value'];
		}
		$this->assertSame( '192.0.2.10', $by['shop|A'] );
		$this->assertSame( '192.0.2.77', $by['www|A'], 'An existing record is kept.' );
		$this->assertSame( 'mail.mine.example', $by['@|MX'] );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'shop.mine.example', $fresh->get( 'app_domain' ) );
		$job = json_decode( $fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertSame( 'shop.mine.example', $job['domain'] );

		// A second round (the guest was slow) makes no second change.
		$calls = count( DnsClients::dns()->calls() );
		$this->assertTrue( AppInstall::queue( Resource::find( $r->id() ), $fake )->is_done() );
		$this->assertCount( $calls, DnsClients::dns()->calls() );
	}

	public function test_without_a_tick_the_job_has_no_domain_and_no_record_is_made(): void {
		$me = self::factory()->user->create();
		$this->domain( $me, 'mine.example' );
		[ $r, $fake ] = $this->server( $me, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_state' => 'pending', OsField::META => 9001 ) );

		$this->assertTrue( AppInstall::queue( $r, $fake )->is_done() );
		$job = json_decode( $fake->guest_file( 5000, Apps::JOB ), true );
		$this->assertSame( '', $job['domain'] );
		$this->assertSame( array(), DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'app_domain' ) );
	}

	public function test_install_never_writes_into_a_zone_the_customer_does_not_hold(): void {
		$me    = self::factory()->user->create();
		$other = self::factory()->user->create();
		$this->domain( $other, 'theirs.example' );
		[ $r, $fake ] = $this->server( $me, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_zone' => 'theirs.example', 'app_domain_want' => 'shop.theirs.example', 'app_state' => 'pending', OsField::META => 9001 ) );

		$this->assertTrue( AppInstall::queue( $r, $fake )->is_done(), 'The install goes on without a domain.' );
		$this->assertSame( array(), DnsClients::dns()->zone( 'theirs.example' )['rrsets'], 'No record in someone else\'s zone.' );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'app_domain' ) );
		$this->assertNotSame( '', (string) $fresh->get( 'app_domain_error' ) );
		$this->assertSame( '', json_decode( $fake->guest_file( 5000, Apps::JOB ), true )['domain'] );
	}

	public function test_install_does_not_replace_a_record_that_appeared_since_checkout(): void {
		$me = self::factory()->user->create();
		$this->domain( $me, 'mine.example', array(), array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.99' ) ) );
		[ $r, $fake ] = $this->server( $me, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_zone' => 'mine.example', 'app_domain_want' => 'shop.mine.example', 'app_state' => 'pending', OsField::META => 9001 ) );

		$this->assertTrue( AppInstall::queue( $r, $fake )->is_done() );
		$rows = \BeltoftServices\Domains\Records::to_rows( 'mine.example', DnsClients::dns()->zone( 'mine.example' )['rrsets'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( '192.0.2.99', $rows[0]['value'] );
		$this->assertSame( '', json_decode( $fake->guest_file( 5000, Apps::JOB ), true )['domain'] );
	}

	public function test_the_checkout_assets_load_only_on_checkout_and_only_when_the_field_is_printed(): void {
		wp_dequeue_script( AppField::HANDLE );
		wp_dequeue_style( AppField::HANDLE );
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		AppField::assets();
		$this->assertFalse( wp_script_is( AppField::HANDLE, 'enqueued' ), 'Not the checkout page.' );

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		AppField::assets();
		$this->assertTrue( wp_script_is( AppField::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( AppField::HANDLE, 'enqueued' ) );

		wp_dequeue_script( AppField::HANDLE );
		wp_dequeue_style( AppField::HANDLE );
		$this->save_cluster( array( 'apps_ready' => 0 ) );
		AppField::assets();
		$this->assertFalse( wp_script_is( AppField::HANDLE, 'enqueued' ), 'No field, no script.' );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
	}

	// -- Review fixes ---------------------------------------------------------------------------

	public function test_the_tiles_assets_load_on_checkout_only_and_only_with_a_server_in_the_cart(): void {
		wp_dequeue_script( \BeltoftServices\Proxmox\Tiles::HANDLE );
		wp_dequeue_style( \BeltoftServices\Proxmox\Tiles::HANDLE );
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		\BeltoftServices\Proxmox\Tiles::checkout_assets();
		$this->assertFalse( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ), 'Not the checkout page.' );

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		\BeltoftServices\Proxmox\Tiles::checkout_assets();
		$this->assertTrue( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ) );

		wp_dequeue_script( \BeltoftServices\Proxmox\Tiles::HANDLE );
		wp_dequeue_style( \BeltoftServices\Proxmox\Tiles::HANDLE );
		WC()->cart->empty_cart();
		\BeltoftServices\Proxmox\Tiles::checkout_assets();
		$this->assertFalse( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ), 'No server in the cart, no tiles.' );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
	}

	public function test_the_checkout_markup_has_the_tiles_the_version_row_and_the_forced_system(): void {
		WC()->cart->add_to_cart( $this->vm()->get_id() );
		$html = $this->html();

		$this->assertStringContainsString( 'data-tiles="app"', $html );
		$this->assertStringContainsString( 'data-os="9001"', $html, 'The system the apps run on.' );
		$this->assertMatchesRegularExpression( '#<input[^>]*value="docker"[^>]*data-versions="\[\{&quot;value&quot;:&quot;29&quot;#', $html );
		$this->assertMatchesRegularExpression( '#<p[^>]*data-tiles-version-row hidden>.*?<select name="' . AppField::POST_VERSION . '"#s', $html, 'The version select is hidden until an app with several versions is chosen.' );
		$this->assertStringContainsString( 'data-app-note', $html );
	}

	public function test_the_script_tells_the_order_summary_when_it_forces_or_restores_the_system(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );
		$this->assertStringContainsString( "new Event( 'change', { bubbles: true } )", $js );
		$this->assertStringContainsString( "window.jQuery( document.body ).trigger( 'update_checkout' )", $js );
		$this->assertMatchesRegularExpression( '/if \( window\.jQuery \)/', $js );
	}

	public function test_a_registered_domain_needs_proof_that_the_zone_is_ours_for_this_customer(): void {
		$me    = self::factory()->user->create();
		$other = self::factory()->user->create();

		$this->domain( $me, 'ready.example' );
		$this->domain( $me, 'claimed.example', array( 'zone_ready' => false, 'zone_claimed' => true ) );
		$this->domain( $me, 'unproven.example', array( 'zone_ready' => false ) );
		$this->domain( $me, 'served.example', array( 'zone_ready' => false ) );
		$this->domain( $me, 'taken.example' );

		global $wpdb;
		$table = \BeltoftServices\Domains\DnsSchema::tables()['assignments'];
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::reserve( $me, 11, 'served.example' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( $table, array( 'status' => 'active' ), array( 'domain' => 'served.example' ) );
		$this->assertTrue( \BeltoftServices\Domains\DnsAssignments::reserve( $other, 12, 'taken.example' ) );

		$this->assertSame( array( 'claimed.example', 'ready.example', 'served.example' ), AppDomains::zones( $me ), 'Not unproven, not assigned to someone else.' );
		$this->assertSame( array( 'Choose one of your domains that uses our name servers.' ), $this->check_for( $me, 'taken.example' ) );
	}

	private function check_for( int $user, string $zone ): array {
		wp_set_current_user( $user );
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		return $this->check( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => '1', AppField::POST_ZONE => $zone, AppField::POST_SUB => 'shop' ) );
	}

	/** A DNS fake that fails a number of reads or writes first, like a name server that is briefly down. */
	private function flaky( int $reads, int $writes ): FakeDns {
		$dns = new class( true ) extends FakeDns {
			public int $reads  = 0;
			public int $writes = 0;

			public function zone( string $name ): array|\WP_Error|null {
				if ( $this->reads > 0 ) {
					--$this->reads;
					return new \WP_Error( 'down', 'down' );
				}

				return parent::zone( $name );
			}

			public function replace_rrsets( string $zone, array $rrsets ): true|\WP_Error {
				if ( $this->writes > 0 ) {
					--$this->writes;
					return new \WP_Error( 'down', 'down' );
				}

				return parent::replace_rrsets( $zone, $rrsets );
			}
		};
		$dns->reads  = $reads;
		$dns->writes = $writes;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );

		return $dns;
	}

	/** Subjects of the mails sent from now on, nothing leaves the test. */
	private function mail_log( array &$sent ): void {
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $args ) use ( &$sent ) {
				$sent[] = $args['subject'];
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft+test@gmail.com' ) );
	}

	private function domain_server( int $user ): array {
		$this->domain( $user, 'mine.example', array(), array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 600, 'value' => '192.0.2.77' ) ) );

		return $this->server( $user, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_zone' => 'mine.example', 'app_domain_want' => 'shop.mine.example', 'app_state' => 'pending', OsField::META => 9001 ) );
	}

	public function test_a_dns_failure_that_passes_is_tried_again_and_then_the_domain_is_made(): void {
		$me = self::factory()->user->create();
		[ $r, $fake ] = $this->domain_server( $me );
		$dns  = $this->flaky( 1, 1 );
		$sent = array();
		$this->mail_log( $sent );

		$first = AppInstall::queue( $r, $fake );
		$this->assertTrue( $first->is_poll(), 'The zone read failed: look again, do not drop the domain.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'app_domain_error' ) );

		$second = AppInstall::queue( Resource::find( $r->id() ), $fake );
		$this->assertTrue( $second->is_poll(), 'The write failed.' );

		$third = AppInstall::queue( Resource::find( $r->id() ), $fake );
		$this->assertTrue( $third->is_done(), $third->error() );
		$this->assertSame( 'shop.mine.example', Resource::find( $r->id() )->get( 'app_domain' ) );
		$this->assertSame( 'shop.mine.example', json_decode( $fake->guest_file( 5000, Apps::JOB ), true )['domain'] );
		$this->assertSame( array(), $sent );
		$this->assertSame( 0, $dns->reads );
	}

	public function test_a_dns_failure_that_stays_drops_the_domain_after_a_few_tries_and_tells_an_admin_once(): void {
		$me = self::factory()->user->create();
		[ $r, $fake ] = $this->domain_server( $me );
		$this->flaky( 99, 0 );
		$sent = array();
		$this->mail_log( $sent );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( AppInstall::queue( Resource::find( $r->id() ), $fake )->is_poll(), 'Try ' . ( $i + 1 ) );
		}

		$this->assertSame( array(), $sent );
		$this->assertTrue( AppInstall::queue( Resource::find( $r->id() ), $fake )->is_done(), 'The install goes on without the domain.' );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'app_domain' ) );
		$this->assertNotSame( '', (string) $fresh->get( 'app_domain_error' ) );
		$this->assertSame( '', json_decode( $fake->guest_file( 5000, Apps::JOB ), true )['domain'] );
		$this->assertSame( array( 'App domain dropped for server ' . $r->id() ), $sent );

		AppAlerts::domain_dropped( Resource::find( $r->id() ), 'shop.mine.example', 'again' );
		$this->assertCount( 1, $sent, 'One alert only.' );
	}

	public function test_a_name_that_already_has_a_record_is_dropped_at_once_with_one_alert(): void {
		$me = self::factory()->user->create();
		$this->domain( $me, 'mine.example', array(), array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.99' ) ) );
		[ $r, $fake ] = $this->server( $me, array( 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_zone' => 'mine.example', 'app_domain_want' => 'shop.mine.example', 'app_state' => 'pending', OsField::META => 9001 ) );
		$sent = array();
		$this->mail_log( $sent );

		$this->assertTrue( AppInstall::queue( $r, $fake )->is_done(), 'No waiting for a definite refusal.' );
		$this->assertSame( 'The name already has a DNS record.', Resource::find( $r->id() )->get( 'app_domain_error' ) );
		$this->assertCount( 1, $sent );
	}

	public function test_only_the_new_a_set_is_written_and_nothing_else_in_the_zone_is_touched(): void {
		$me  = self::factory()->user->create();
		$dns = new FakeDns( true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		[ $r, $fake ] = $this->domain_server( $me );

		$this->assertTrue( AppInstall::queue( $r, $fake )->is_done() );

		$writes = array_values( array_filter( $dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] && 'mine.example' === $c[1] ) );
		$last   = end( $writes );
		$this->assertCount( 1, $last[2], 'One RRset in the write.' );
		$this->assertSame( 'shop.mine.example.', $last[2][0]['name'] );
		$this->assertSame( 'A', $last[2][0]['type'] );
		$this->assertSame( 'REPLACE', $last[2][0]['changetype'] );
		$this->assertSame( '192.0.2.10', $last[2][0]['records'][0]['content'] );
		$this->assertFalse( $last[2][0]['records'][0]['disabled'] );
	}

	public function test_an_address_or_alias_record_at_the_name_stops_the_write(): void {
		$me = self::factory()->user->create();

		foreach ( array( 'AAAA' => '2001:db8::1', 'CNAME' => 'other.mine.example' ) as $type => $value ) {
			$name = strtolower( $type ) . 'host';
			$this->domain( $me, $name . '.example', array(), array( array( 'name' => 'shop', 'type' => $type, 'ttl' => 300, 'value' => $value ) ) );
			$dns    = new FakeDns( true );
			add_filter( 'beltoft_services_domains_dns', static fn() => $dns, 20 );
			$made   = AppDomains::publish( $me, $name . '.example', 'shop.' . $name . '.example', '192.0.2.10' );
			$this->assertSame( 'The name already has a DNS record.', $made, $type );
			$writes = array_filter( $dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] );
			$this->assertSame( array(), array_values( $writes ), 'No write for ' . $type );
		}

		$this->domain( $me, 'same.example', array(), array( array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) );
		$this->assertTrue( AppDomains::publish( $me, 'same.example', 'shop.same.example', '192.0.2.10' ), 'Exactly this server is fine.' );
	}

	public function test_an_app_record_and_an_editor_save_do_not_lose_each_others_sets_and_a_busy_zone_retries(): void {
		$me  = self::factory()->user->create();
		$dns = new FakeDns( true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns, 20 );
		$this->domain( $me, 'both.example', array(), array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ), array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 -all' ) ) );

		// While another connection holds the zone, the app record waits for a retry and nothing is written.
		add_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( \BeltoftServices\Locks::zone( 'both.example' ) ) . "', 0)" );
		$before = count( array_filter( $dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
		$busy   = AppDomains::publish( $me, 'both.example', 'shop.both.example', '192.0.2.10' );
		$this->assertWPError( $busy );
		$this->assertSame( 'transient', $busy->get_error_code() );
		$this->assertCount( $before, array_filter( $dns->calls(), static fn( $c ) => 'replace_rrsets' === $c[0] ) );
		$other->close();

		// The editor saves its list (as the customer saw it), then the app record lands: all three sets are there.
		$this->assertTrue( \BeltoftServices\Domains\Records::replace( 'both.example', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.2' ), array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 -all' ) ) ) );
		$this->assertTrue( AppDomains::publish( $me, 'both.example', 'shop.both.example', '192.0.2.10' ) );
		$names = array_map( static fn( $s ) => $s['name'] . $s['type'], $dns->zone( 'both.example' )['rrsets'] );
		foreach ( array( 'www.both.example.A', 'both.example.TXT', 'shop.both.example.A' ) as $want ) {
			$this->assertContains( $want, $names );
		}

		// The save after the app record only writes the set it changes, so the app record survives it.
		$this->assertTrue( \BeltoftServices\Domains\Records::replace( 'both.example', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.2' ), array( 'name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 ~all' ), array( 'name' => 'shop', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10' ) ) ) );
		$names = array_map( static fn( $s ) => $s['name'] . $s['type'], $dns->zone( 'both.example' )['rrsets'] );
		$this->assertContains( 'shop.both.example.A', $names );
		remove_filter( 'beltoft_services_zone_lock_wait', '__return_zero' );
	}

	public function test_a_single_set_change_leaves_an_app_record_the_editor_never_saw(): void {
		$me  = self::factory()->user->create();
		$dns = new FakeDns( true );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns, 20 );
		$this->domain( $me, 'stale.example', array(), array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.1' ) ) );
		$this->assertTrue( AppDomains::publish( $me, 'stale.example', 'shop.stale.example', '192.0.2.10' ) );
		$this->assertTrue( \BeltoftServices\Domains\Records::change( 'stale.example', 'www', 'A', array( array( 'value' => '192.0.2.3' ) ) ) );
		$names = array_map( static fn( $s ) => $s['name'] . $s['type'], $dns->zone( 'stale.example' )['rrsets'] );
		$this->assertContains( 'shop.stale.example.A', $names, 'a single-set change leaves the app record alone' );
	}

	public function test_a_name_the_dns_layer_would_refuse_is_refused_at_checkout_before_payment(): void {
		$me   = self::factory()->user->create();
		$zone = str_repeat( 'a', 63 ) . '.' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.example';
		$this->domain( $me, $zone );
		$this->domain( $me, 'mine.example' );
		wp_set_current_user( $me );
		WC()->cart->add_to_cart( $this->vm()->get_id() );

		$long = $this->check( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => '1', AppField::POST_ZONE => $zone, AppField::POST_SUB => str_repeat( 'd', 40 ) . '.' . str_repeat( 'e', 40 ) ) );
		$this->assertCount( 1, $long );
		$this->assertStringContainsString( 'too long', $long[0] );
		$this->assertSame( array(), $this->check( array( AppField::POST_APP => 'wordpress', AppField::POST_DOMAIN => '1', AppField::POST_ZONE => 'mine.example', AppField::POST_SUB => 'ok' ) ) );

		$this->assertSame( 'The domain name is too long.', AppDomains::dry_run( 'mine.example', str_repeat( 'x', 64 ) . '.mine.example' ), 'A part over 63.' );
		$this->assertSame( 'The domain name is too long.', AppDomains::dry_run( $zone, str_repeat( 'f', 60 ) . '.' . $zone ) );
		$this->assertNull( AppDomains::dry_run( 'mine.example', 'shop.mine.example' ) );
		$this->assertNotNull( AppDomains::dry_run( 'mine.example', 'bad_name!.mine.example' ) );
	}

	public function test_subdomain_rules(): void {
		$this->assertSame( '', AppDomains::sub( '' ) );
		$this->assertSame( '', AppDomains::sub( '@' ) );
		$this->assertSame( 'shop', AppDomains::sub( ' Shop ' ) );
		$this->assertSame( 'a.b.c', AppDomains::sub( 'a.b.c' ) );
		foreach ( array( 'a.b.c.d', '-a', 'a-', 'a_b', 'a b', '*', str_repeat( 'a', 64 ), array(), null ) as $bad ) {
			$this->assertNull( AppDomains::sub( $bad ), wp_json_encode( $bad ) );
		}
		$this->assertSame( 'mine.example', AppDomains::fqdn( 'mine.example', '' ) );
		$this->assertSame( 'shop.mine.example', AppDomains::fqdn( 'mine.example', 'shop' ) );
	}
}
