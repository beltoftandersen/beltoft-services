<?php
/** The Domains service area (Account\Domains): addresses, request, buttons, and the same output as the static Domains class. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\DomainRows;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainsAreaTest extends TestCase {
	private int $user = 0;
	private FakeDns $dns;

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'beltoft_services_domain_page_tabs' );
		remove_all_filters( 'beltoft_services_domains_slug' );
		unset( $GLOBALS['wp']->query_vars['domains'] );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function area(): Domains {
		return ServiceArea::get( Domains::class );
	}

	private function service( string $name, string $driver = 'domain', array $meta = array(), string $state = 'ready' ): Resource {
		$product  = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, 'domain' === $driver ? new DomainDriver() : new DnsDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'domain' === $driver ? 'register' : 'dns', 'zone_ready' => true ), $meta ) );
		$resource->set( array( 'state' => $state, 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );

		return Resource::find( $resource->id() );
	}

	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	public function test_the_area_is_the_domains_tab_for_domain_and_dns_services(): void {
		$area = $this->area();

		$this->assertSame( 'domains', $area->slug() );
		$this->assertSame( 'domains', $area->key_name() );
		$this->assertSame( 'Domains', $area->label() );
		$this->assertSame( 'All domains', $area->all_label() );
		$this->assertSame( 'You have no domains yet.', $area->empty_line() );
		$this->assertTrue( $area->is_member( $this->service( 'a.example' )->license() ) );
		$this->assertTrue( $area->is_member( $this->service( 'b.example', 'dns' )->license() ) );
		$this->assertFalse( $area->is_member( $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) ) ) );
		$this->assertSame( array( 'domains', 'Domains', 'You have no domains yet.' ), array( $area->group()['slug'], $area->group()['label'], $area->group()['empty'] ) );
	}

	public function test_addresses_and_the_slug_filter_are_the_same_as_the_static_class(): void {
		$area    = $this->area();
		$license = $this->service( 'urls.example' )->license();

		$this->assertSame( home_url() . '?domains=urls.example', $area->url( $license ) );
		$this->assertSame( home_url() . '?domains=urls.example/dns', $area->url( $license, 'dns' ) );
		$this->assertSame( $area->url( $license ), $area->url( $license, 'bogus' ) );
		$this->assertSame( home_url() . '?domains=add', $area->url_add() );
		$this->assertSame( home_url() . '?domains', $area->url_list() );
		$this->assertSame( array( 'dns', 'nameservers', 'transfer', 'danger' ), $area->tabs() );
		add_filter( 'beltoft_services_domain_page_tabs', static fn( $tabs ) => array_merge( $tabs, array( 'extra' ) ) );
		$this->assertContains( 'extra', $area->tabs() );
		add_filter( 'beltoft_services_domains_slug', static fn() => 'names' );
		$this->assertSame( 'names', $area->slug() );
		$this->assertSame( home_url() . '?names', $area->url_list() );
	}

	public function test_the_request_reads_the_id_the_tab_and_add(): void {
		$wp = $GLOBALS['wp'];

		$expected = array(
			''            => array( 0, '', false ),
			'12'          => array( 12, '', false ),
			'12/dns'      => array( 12, 'dns', false ),
			'12/danger/'  => array( 12, 'danger', false ),
			'12/nonsense' => array( 12, '', false ),
			'add'         => array( 0, '', true ),
			'x/dns'       => array( 0, 'dns', false ),
		);

		foreach ( array_keys( $expected ) as $value ) {
			$wp->query_vars['domains'] = $value;
			$got = $this->area()->requested();
			$this->assertSame( $expected[ $value ], array( $got['id'], $got['tab'], $got['add'] ), 'value ' . $value );
			$this->assertSame( array( 'id', 'tab', 'add', 'path' ), array_keys( $got ) );
		}
	}

	public function test_the_register_url_the_buttons_and_the_order_url(): void {
		$area = $this->area();
		$this->assertSame( '', $area->url_register() );
		$this->assertSame( array(), $area->buttons() );
		$this->settings( array( 'domain_search_url' => '/domains/', 'domain_dns_claims_enabled' => 1 ) );
		$this->assertSame( array( 'Add a domain', 'Register a domain' ), array_column( $area->buttons(), 'label' ) );

		$domain = $this->service( 'order.example' );
		$this->assertSame( $area->url_list(), $area->url_for_order( 0, array( 'order.example' ) ) );
		$this->assertSame( $area->url_list(), $area->url_for_order( (int) $domain->license()['order_id'], array( 'order.example', 'other.example' ) ), 'Two names: the list.' );
		$this->assertSame( $area->url_list(), $area->url_for_order( (int) $domain->license()['order_id'], array( 'order.example' ) ), 'No order on the license: the list.' );
	}

	public function test_the_claim_rows_come_first_and_the_rows_equal_the_overview_rows(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$domain = $this->service( 'have.example' );
		DnsClaims::create( $this->user, 'waiting.example' );
		$views  = $this->views( $domain );
		$id     = (int) $domain->license()['id'];
		$rows   = $this->area()->rows( array( $id => $views[0] ), array( $id => $domain ) );

		$this->assertSame( array( 'waiting.example', 'have.example' ), array_column( $rows, 'name' ) );
		$this->assertSame( array( 'waiting.example' ), array_column( $this->area()->claim_rows( $this->user ), 'name' ) );
		$this->assertSame( array(), $this->area()->claim_rows( 0 ) );
		$this->assertSame( DomainRows::row( $views[0], $domain ), $rows[1] );
	}

	public function test_a_domain_in_state_deleted_is_still_listed_and_a_folded_one_is_not(): void {
		$deleted = $this->service( 'gone.example', 'domain', array(), 'deleted' );
		$folded  = $this->service( 'folded.example', 'dns', array( 'folded_into' => 99 ) );
		$GLOBALS['wp']->query_vars['domains'] = '';
		ob_start();
		$this->area()->render( $this->views( $deleted, $folded ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'gone.example', $html );
		$this->assertStringContainsString( 'Removed', $html );
		$this->assertStringNotContainsString( 'folded.example', $html );

		$GLOBALS['wp']->query_vars['domains'] = (string) $folded->license()['id'];
		ob_start();
		$this->area()->render( $this->views( $deleted, $folded ) );
		$this->assertStringContainsString( 'That domain was not found. Here are your domains.', (string) ob_get_clean() );
	}

	// -- The switch: one registration, one order of hooks ----------------------------------------

	public function test_the_takeover_and_the_redirect_are_registered_once_and_the_area_is_first(): void {
		$count = static function ( string $hook ): int {
			global $wp_filter;
			$n = 0;
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && ( ServiceArea::class === $fn[0] || ( is_object( $fn[0] ) && $fn[0] instanceof ServiceArea ) ) || is_array( $fn ) && 'BeltoftServices\\Account\\Domains' === $fn[0] ) {
						++$n;
					}
				}
			}

			return $n;
		};

		$this->assertSame( 1, $count( 'wc_get_template' ) );
		$this->assertSame( 1, $count( 'template_redirect' ) );
		$this->assertInstanceOf( Domains::class, ServiceArea::all()[0] );
		$this->assertSame( array( 'domains', 'vps' ), array_slice( array_column( \BeltoftServices\Account\Tabs::groups( array() ), 'slug' ), 0, 2 ) );
	}

	public function test_the_head_of_a_domain_page_comes_from_the_area_with_the_domain_values(): void {
		global $wp_filter;
		$listeners = array();

		foreach ( $wp_filter['beltoft_services_account_page_head']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				$listeners[] = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1] : 'closure';
			}
		}

		$this->assertNotContains( \BeltoftServices\Account\DomainPage::class . '::head', $listeners );
		$this->assertContains( ServiceArea::class . '::head', $listeners );

		$domain = $this->service( 'head.example' );
		$GLOBALS['wp']->query_vars['domains'] = (string) $domain->license()['id'];
		ob_start();
		$this->area()->render( $this->views( $domain ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<h2 class="beltoft-services-page__name">head.example</h2>', $html );
		$this->assertStringContainsString( 'data-badge>Active</span>', $html );
		$this->assertStringNotContainsString( 'beltoft-services-page__renews', $html );
		$this->assertStringNotContainsString( 'beltoft-services-page__plan', $html );
	}

	public function test_url_for_license_now_answers_for_a_domain_license(): void {
		$domain = $this->service( 'portal.example' );

		$this->assertSame( $this->area()->url( $domain->license() ), ServiceArea::url_for_license( $domain->license() ) );
		$this->assertStringContainsString( 'portal.example', ServiceArea::url_for_license( $domain->license() ) );
		$this->assertStringContainsString( 'portal.example/dns', $this->area()->url( $domain->license(), 'dns' ) );
	}
}
