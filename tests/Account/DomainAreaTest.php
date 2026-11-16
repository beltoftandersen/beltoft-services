<?php
/** The Domains service area (Account\DomainArea): addresses, request, buttons, and the same output as the static Domains class. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\DomainArea;
use BeltoftServices\Account\DomainRows;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainAreaTest extends TestCase {
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

	private function area(): DomainArea {
		return ServiceArea::get( DomainArea::class );
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

		$this->assertSame( Domains::url( $license ), $area->url( $license ) );
		$this->assertSame( Domains::url( $license, 'dns' ), $area->url( $license, 'dns' ) );
		$this->assertSame( Domains::url( $license ), $area->url( $license, 'bogus' ) );
		$this->assertSame( Domains::url_add(), $area->url_add() );
		$this->assertSame( Domains::url_list(), $area->url_list() );
		$this->assertSame( Domains::tabs(), $area->tabs() );
		add_filter( 'beltoft_services_domain_page_tabs', static fn( $tabs ) => array_merge( $tabs, array( 'extra' ) ) );
		$this->assertContains( 'extra', $area->tabs() );
		add_filter( 'beltoft_services_domains_slug', static fn() => 'names' );
		$this->assertSame( 'names', $area->slug() );
		$this->assertSame( Domains::url_list(), $area->url_list() );
	}

	public function test_the_request_reads_the_id_the_tab_and_add(): void {
		$wp = $GLOBALS['wp'];

		foreach ( array( '', '12', '12/dns', '12/danger/', '12/nonsense', 'add', 'x/dns' ) as $value ) {
			$wp->query_vars['domains'] = $value;
			$this->assertSame( Domains::requested(), array_diff_key( $this->area()->requested(), array( 'path' => 1 ) ), 'value ' . $value );
		}
	}

	public function test_the_register_url_the_buttons_and_the_order_url(): void {
		$area = $this->area();
		$this->assertSame( Domains::url_register(), $area->url_register() );
		$this->assertSame( array(), $area->buttons() );
		$this->settings( array( 'domain_search_url' => '/domains/', 'domain_dns_claims_enabled' => 1 ) );
		$this->assertSame( Domains::buttons(), $area->buttons() );
		$this->assertSame( array( 'Add a domain', 'Register a domain' ), array_column( $area->buttons(), 'label' ) );

		$domain = $this->service( 'order.example' );
		$this->assertSame( Domains::url_for_order( 0, array( 'order.example' ) ), $area->url_for_order( 0, array( 'order.example' ) ) );
		$this->assertSame( Domains::url_for_order( (int) $domain->license()['order_id'], array( 'order.example' ) ), $area->url_for_order( (int) $domain->license()['order_id'], array( 'order.example' ) ) );
	}

	public function test_the_claim_rows_come_first_and_the_rows_equal_the_overview_rows(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$domain = $this->service( 'have.example' );
		DnsClaims::create( $this->user, 'waiting.example' );
		$views  = $this->views( $domain );
		$id     = (int) $domain->license()['id'];
		$rows   = $this->area()->rows( array( $id => $views[0] ), array( $id => $domain ) );

		$this->assertSame( array( 'waiting.example', 'have.example' ), array_column( $rows, 'name' ) );
		$this->assertSame( Domains::claim_rows( $this->user ), $this->area()->claim_rows( $this->user ) );
		$this->assertSame( DomainRows::row( $views[0], $domain ), $rows[1] );
	}

	/**
	 * The area prints what the static class printed, for the list, the notice, the add page and a domain page.
	 */
	public function test_it_prints_what_the_static_class_prints(): void {
		$mine    = $this->service( 'mine.example' );
		$hosted  = $this->service( 'hosted.example', 'dns' );
		$folded  = $this->service( 'folded.example', 'dns', array( 'folded_into' => 99 ) );
		$deleted = $this->service( 'deleted.example', 'domain', array(), 'deleted' );
		$views   = $this->views( $mine, $hosted, $folded, $deleted );
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_search_url' => '/domains/' ) );
		DnsClaims::create( $this->user, 'waiting.example' );

		foreach ( array( '', 'add', 'nope', (string) $folded->license()['id'], (string) $mine->license()['id'], (string) $mine->license()['id'] . '/dns', (string) $mine->license()['id'] . '/danger', (string) $hosted->license()['id'] . '/nameservers', (string) $deleted->license()['id'] ) as $value ) {
			$GLOBALS['wp']->query_vars['domains'] = $value;
			ob_start();
			Domains::render( $views );
			$old = (string) ob_get_clean();
			ob_start();
			$this->area()->render( $views );
			$new = (string) ob_get_clean();

			$this->assertSame( $this->flat( $old ), $this->flat( $new ), 'value ' . $value );
			$this->assertNotSame( '', $new );
		}
	}

	private function flat( string $html ): string {
		return trim( (string) preg_replace( '/\s+(<\/)/', '$1', (string) preg_replace( '/>\s+</', '><', (string) preg_replace( '/\s+/', ' ', $html ) ) ) );
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
}
