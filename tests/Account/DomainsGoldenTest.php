<?php
/**
 * Characterisation of every domains page through the real template entry point (the wc_get_template filter for
 * myaccount/licenses.php, then the located file), so it survives the move of the Domains code into a service area.
 * Whitespace between tags is not compared; license and resource ids, nonces and the host are replaced by placeholders.
 * Regenerate with BS_WRITE_GOLDEN=1 only on purpose.
 *
 * @package BeltoftServices
 */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Domains\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Account\Tabs;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Panels;
use BeltoftServices\Domains\Records;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

final class DomainsGoldenTest extends TestCase {
	private const ORDERS = array( 'reg' => 910001, 'other' => 910002 );

	private int $user = 0;
	private FakeDns $dns;

	/** @var array<string, Resource> */
	private array $res = array();

	/** @var array<int, array{0: string, 1: string}> */
	private array $fired = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net', 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$this->dns = new FakeDns();
		$dns       = $this->dns;
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		add_filter( 'woocommerce_is_account_page', '__return_true' );
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'yes' );
		add_filter( 'woocommerce_subscription_is_manual', static fn( $manual, $sub ) => true === $sub->get_requires_manual_renewal(), 10, 2 );
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
		\BeltoftServices\Domains\DomainDanger::register();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_dns' );
		remove_all_filters( 'woocommerce_is_account_page' );
		remove_all_filters( 'woocommerce_subscription_is_manual' );
		remove_all_filters( 'beltoft_services_domains_slug' );
		remove_all_filters( 'beltoft_services_domains_add_url' );
		remove_all_filters( 'beltoft_services_account_page_redirect' );
		remove_all_filters( 'beltoft_services_account_page_body' );
		remove_all_filters( 'beltoft_services_account_page_chrome' );
		delete_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle' );
		foreach ( array( 'domains', 'databases', 'email', 'hosting', 'ssl-certificates', 'apps', 'licences' ) as $slug ) {
			unset( $GLOBALS['wp']->query_vars[ $slug ] );
		}
		unset( $_SERVER['QUERY_STRING'] );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function subscription( int $user ): \WC_Subscription {
		$sub     = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Domain' );
		$product->set_regular_price( '12.00' );
		$product->save();
		$sub->add_product( $product, 1 );
		$sub->set_payment_method( 'bacs' );
		$sub->set_requires_manual_renewal( false );
		$sub->calculate_totals();
		// A fixed day, like the expiry dates below: "today plus 200 days" printed another date every day and the stored pages only matched on the day they were written.
		$sub->update_dates( array( 'next_payment' => '2027-06-04 12:00:00' ) );
		$sub->save();

		return $sub;
	}

	private function service( string $key, int $user, string $name, string $driver = 'domain', array $meta = array(), ?\WC_Subscription $sub = null, string $state = 'ready' ): Resource {
		$product  = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . $key ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub ? $sub->get_id() : null, 'order_id' => self::ORDERS[ $key ] ?? 0 ) );
		$resource = Resource::create( $license, 'domain' === $driver ? new DomainDriver() : new DnsDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'domain' === $driver ? 'register' : 'dns', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => $state, 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );
		$this->res[ $key ] = Resource::find( $resource->id() );

		return $this->res[ $key ];
	}

	/** The fixture services: one of each kind the pages tell apart. */
	private function fixtures(): void {
		$this->service( 'reg', $this->user, 'golden-reg.example', 'domain', array( 'is_locked' => true, 'expires_at' => '2027-11-02 00:00:00' ), $this->subscription( $this->user ) );
		$this->service( 'other', $this->user, 'golden-other.example', 'domain', array( 'dns_external' => true, 'expires_at' => '2027-05-01 00:00:00' ) );
		$this->service( 'dnsonly', $this->user, 'golden-dns.example', 'dns', array( 'delegation' => 'other' ), $this->subscription( $this->user ) );
		$this->service( 'paused', $this->user, 'golden-paused.example', 'dns', array( 'records_off' => true ) );
		Records::set_disabled( 'golden-paused.example', true );
		$this->res['paused'] = Resource::find( $this->res['paused']->id() );
		$this->service( 'transfer', $this->user, 'golden-transfer.example', 'domain', array( 'mode' => 'transfer' ), null, 'provisioning' );
		$this->service( 'deleted', $this->user, 'golden-deleted.example', 'domain', array(), null, 'deleted' );
		$this->service( 'folded', $this->user, 'golden-folded.example', 'dns', array( 'folded_into' => 99 ) );
		$this->service( 'foreign', self::factory()->user->create(), 'golden-foreign.example' );
		Records::replace( 'golden-reg.example', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.7' ) ) );
	}

	/** @param array<int, string> $keys */
	private function views( array $keys ): array {
		return array_map( fn( string $k ): array => View::license( $this->res[ $k ]->license() ), $keys );
	}

	/**
	 * The real entry: the licenses template of Beltoft Licenses on one endpoint, taken over by the plugin.
	 *
	 * @param array<int, array<string, mixed>> $views Views on the tab.
	 */
	private function entry( string $endpoint, array $views, string $value = '', string $empty = '' ): string {
		// A request has one endpoint: the others must not be left over from an earlier page of this test.
		foreach ( array( 'domains', 'databases', 'email', 'hosting', 'ssl-certificates', 'apps', 'licences' ) as $other ) {
			unset( $GLOBALS['wp']->query_vars[ $other ] );
		}

		$GLOBALS['wp']->query_vars[ $endpoint ] = $value;
		$args    = array( 'licenses' => $views );
		$located = apply_filters( 'wc_get_template', '/default.php', 'myaccount/licenses.php', $args, '', '' );

		if ( '' !== $empty ) {
			$args['empty'] = $empty;
		}

		$this->assertStringEndsWith( '.php', (string) $located );
		$this->assertNotSame( '/default.php', $located, $endpoint );
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args );
		ob_start();
		include $located;

		return (string) ob_get_clean();
	}

	private function normal( string $text ): string {
		$ids = array();

		foreach ( $this->res as $key => $resource ) {
			$ids[ (int) $resource->license()['id'] ] = '{L:' . $key . '}';
		}

		foreach ( $this->res as $key => $resource ) {
			$text = (string) preg_replace( '/(?<![\d])' . (int) $resource->id() . '(?![\d])/', '{R:' . $key . '}', $text );
		}

		foreach ( $ids as $id => $label ) {
			$text = (string) preg_replace( '/(?<![\d])' . $id . '(?![\d])/', $label, $text );
		}

		$text = str_replace( array( home_url( '/' ), home_url() ), array( '{home}/', '{home}' ), $text );
		$text = (string) preg_replace( '/(_wpnonce[^>]*?value=")[0-9a-f]{10}"/', '$1{nonce}"', $text );
		$text = (string) preg_replace( '/(?<![\w])[0-9a-f]{10}(?![\w])/', '{hex10}', $text );
		$text = (string) preg_replace( '/\?product=[a-z0-9-]+/', '?product={slug}', $text );
		$text = (string) preg_replace( '/claim-\d+/', 'claim-{N}', $text );
		$text = (string) preg_replace( '/(subscription|order|sub)(_id)?=\d+/', '$1$2={N}', $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );

		return trim( (string) preg_replace( '/\s+(<\/)/', '$1', (string) preg_replace( '/>\s+</', '><', $text ) ) );
	}

	/** @return array<string, string> */
	private function render_all(): array {
		$this->fixtures();
		$out  = array();
		$mine = array( 'reg', 'other', 'dnsonly', 'paused', 'transfer', 'deleted', 'folded', 'foreign' );
		$all  = $this->views( $mine );

		// (a) the empty list.
		$out['a|empty'] = $this->normal( $this->entry( 'domains', array() ) );
		$out['a|empty-text'] = $this->normal( $this->entry( 'domains', array(), '', 'Nothing here yet.' ) );

		// (b) the list, with claims on and a search page.
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_search_url' => '/domains/' ) );
		DnsClaims::create( $this->user, 'golden-claim.example' );
		$verified = DnsClaims::create( $this->user, 'golden-verified.example' );
		DnsClaims::set_status( $verified['id'], 'verified' );
		$done = DnsClaims::create( $this->user, 'golden-done.example' );
		DnsClaims::set_status( $done['id'], 'cancelled' );
		DnsClaims::create( self::factory()->user->create(), 'golden-theirs.example' );
		$out['b|list'] = $this->normal( $this->entry( 'domains', $all ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		$out['b|list-plain'] = $this->normal( $this->entry( 'domains', $all ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '/domains/' ) );
		$out['b|list-search-only'] = $this->normal( $this->entry( 'domains', $all ) );

		// (c) the add page.
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_search_url' => '/domains/' ) );
		$out['c|add'] = $this->normal( $this->entry( 'domains', $all, 'add' ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );

		// (d) every kind of domain at every tab.
		foreach ( array( 'reg', 'other', 'dnsonly', 'paused', 'transfer' ) as $key ) {
			foreach ( array( '', 'dns', 'nameservers', 'transfer', 'danger', 'overview', 'bogus' ) as $tab ) {
				$value = (string) Slugs::value( $this->res[ $key ]->license() ) . ( '' !== $tab ? '/' . $tab : '' );
				$out[ 'd|' . $key . '|' . ( '' !== $tab ? $tab : 'overview-default' ) ] = $this->normal( $this->entry( 'domains', $all, $value ) );
			}
		}

		// The same by license id (the page shows; the redirect is a separate hook).
		$out['d|reg|by-id'] = $this->normal( $this->entry( 'domains', $all, (string) $this->res['reg']->license()['id'] . '/dns' ) );

		// A tab and a pane added through the three filters.
		$tabs = static fn( $slugs ) => array_merge( $slugs, array( 'extra' ) );
		$more = static fn( $list ) => $list + array( 'extra' => 'Extra tab' );
		$pane = static function ( $slug ): void {
			echo '<p id="pane-' . esc_attr( $slug ) . '">pane</p>';
		};
		add_filter( 'beltoft_services_domain_page_tabs', $tabs );
		add_filter( 'beltoft_services_domain_tabs', $more );
		add_action( 'beltoft_services_domain_tab_pane', $pane );
		foreach ( array( 'extra', 'dns' ) as $tab ) {
			$out[ 'd|filters|' . $tab ] = $this->normal( $this->entry( 'domains', $all, Slugs::value( $this->res['reg']->license() ) . '/' . $tab ) );
		}
		$out['d|filters|reg-slug-reserved'] = Slugs::reserved( 'extra', 'domains' ) ? 'reserved' : 'free';
		remove_filter( 'beltoft_services_domain_page_tabs', $tabs );
		remove_filter( 'beltoft_services_domain_tabs', $more );
		remove_action( 'beltoft_services_domain_tab_pane', $pane );

		// (e) unknown, foreign, folded, deleted.
		foreach ( array( 'unknown' => 'no-such-domain', 'unknown-tab' => 'no-such-domain/dns', 'foreign-id' => (string) $this->res['foreign']->license()['id'], 'foreign-slug' => Slugs::value( $this->res['foreign']->license() ), 'folded-id' => (string) $this->res['folded']->license()['id'], 'folded-slug' => Slugs::value( $this->res['folded']->license() ), 'missing-id' => '999999', 'deleted' => Slugs::value( $this->res['deleted']->license() ), 'deleted-dns' => Slugs::value( $this->res['deleted']->license(), 'dns' ) ) as $name => $value ) {
			$out[ 'e|' . $name ] = $this->normal( $this->entry( 'domains', $all, $value ) );
		}

		// (f) the Overview domains section.
		Overview::reset();
		$out['f|overview-rows'] = $this->normal( Overview::section( 'domains' ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_search_url' => '/domains/' ) );
		Overview::reset();
		$out['f|overview-rows-claims'] = $this->normal( Overview::section( 'domains' ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		wp_set_current_user( self::factory()->user->create() );
		Overview::reset();
		$out['f|overview-empty'] = $this->normal( Overview::section( 'domains' ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 1, 'domain_search_url' => '/domains/' ) );
		Overview::reset();
		$out['f|overview-empty-buttons'] = $this->normal( Overview::section( 'domains' ) );
		$this->settings( array( 'domain_dns_claims_enabled' => 0, 'domain_search_url' => '' ) );
		wp_set_current_user( $this->user );
		Overview::reset();

		// (g) the fall-through card, with no domain page set.
		foreach ( array( 'reg', 'dnsonly', 'other' ) as $key ) {
			ob_start();
			Panels::account( $this->res[ $key ] );
			$out[ 'g|card|' . $key ] = $this->normal( (string) ob_get_clean() );
		}

		// (i) the other areas, so a later step shows it did not move them.
		foreach ( array( 'databases', 'email', 'hosting', 'ssl-certificates', 'apps', 'licences' ) as $endpoint ) {
			$out[ 'i|' . $endpoint . '|list' ] = $this->normal( $this->entry( $endpoint, array() ) );
			$out[ 'i|' . $endpoint . '|unknown' ] = $this->normal( $this->entry( $endpoint, array(), 'no-such' ) );
		}

		ksort( $out );

		return $out;
	}

	/** @return array<string, string> */
	private function table(): array {
		$this->fixtures();
		$out = array();
		$reg = $this->res['reg']->license();

		$out['url|reg']        = $this->normal( ServiceArea::get( Domains::class )->url( $reg ) );
		$out['url|reg|dns']    = $this->normal( ServiceArea::get( Domains::class )->url( $reg, 'dns' ) );
		$out['url|reg|bogus']  = $this->normal( ServiceArea::get( Domains::class )->url( $reg, 'bogus' ) );
		$out['url|reg|danger'] = $this->normal( ServiceArea::get( Domains::class )->url( $reg, 'danger' ) );
		$out['url_list']       = $this->normal( ServiceArea::get( Domains::class )->url_list() );
		$out['url_add']        = $this->normal( ServiceArea::get( Domains::class )->url_add() );
		$out['url_for_order|one']  = $this->normal( ServiceArea::get( Domains::class )->url_for_order( 910001, array( 'golden-reg.example' ) ) );
		$out['url_for_order|two']  = $this->normal( ServiceArea::get( Domains::class )->url_for_order( 910001, array( 'golden-reg.example', 'x.example' ) ) );
		$out['url_for_order|wrong'] = $this->normal( ServiceArea::get( Domains::class )->url_for_order( 910001, array( 'golden-other.example' ) ) );
		$out['url_for_order|none']  = $this->normal( ServiceArea::get( Domains::class )->url_for_order( 0, array( 'golden-reg.example' ) ) );

		foreach ( array( '', '/domains/', 'https://example.org/find', '//evil.example/x' ) as $setting ) {
			$this->settings( array( 'domain_search_url' => $setting ) );
			$out[ 'url_register|' . $setting ] = $this->normal( ServiceArea::get( Domains::class )->url_register() );
		}

		$this->settings( array( 'domain_search_url' => '' ) );
		add_filter( 'beltoft_services_domains_add_url', static fn() => 'https://example.org/filtered' );
		$out['url_register|filter'] = ServiceArea::get( Domains::class )->url_register();
		remove_all_filters( 'beltoft_services_domains_add_url' );

		add_filter( 'beltoft_services_domains_slug', static fn() => 'names' );
		$out['slug|filter']     = ServiceArea::get( Domains::class )->slug();
		$out['url_list|filter'] = $this->normal( ServiceArea::get( Domains::class )->url_list() );
		$out['url|filter']      = $this->normal( ServiceArea::get( Domains::class )->url( $reg, 'dns' ) );
		remove_all_filters( 'beltoft_services_domains_slug' );

		$id = (string) $reg['id'];

		foreach ( array( '', $id, $id . '/dns', $id . '/danger/', $id . '/overview', $id . '/bogus', 'add', 'x/dns', Slugs::value( $reg ), Slugs::value( $reg, 'transfer' ), Slugs::value( $reg, 'nameservers' ), 'add/dns' ) as $value ) {
			$GLOBALS['wp']->query_vars['domains'] = $value;
			$r = ServiceArea::get( Domains::class )->requested();
			$out[ 'requested|' . $this->label( $value ) ] = $this->normal( wp_json_encode( array( $r['id'], $r['tab'], $r['add'] ) ) );
		}

		unset( $GLOBALS['wp']->query_vars['domains'] );
		$out['tabs']     = wp_json_encode( ServiceArea::get( Domains::class )->tabs() );
		$out['buttons|off'] = $this->normal( wp_json_encode( ServiceArea::get( Domains::class )->buttons() ) );

		$groups = Tabs::groups( array() );
		$out['groups|order'] = implode( ',', array_column( $groups, 'slug' ) );
		$domains_group = $groups[0];
		$out['groups|domains'] = wp_json_encode( array( $domains_group['slug'], $domains_group['label'], $domains_group['empty'] ) );
		$out['groups|match'] = wp_json_encode( array( $domains_group['match']( $this->res['reg']->license() ), $domains_group['match']( $this->res['foreign']->license() ) ) );
		$menu = Tabs::always( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
		$out['menu|always'] = implode( ',', array_keys( $menu ) );

		foreach ( array( 'add', 'dns', 'nameservers', 'transfer', 'danger', 'overview', '123', 'golden-reg-example' ) as $word ) {
			$out[ 'reserved|' . $word ] = Slugs::reserved( $word, 'domains' ) ? 'yes' : 'no';
		}

		$out['type_of|reg'] = Slugs::type_of( $this->res['reg']->license() );
		$out['type_of|dns'] = Slugs::type_of( $this->res['dnsonly']->license() );

		// The numeric id goes to the slug address, the query string kept; a slug stays; a foreign id stays.
		$_SERVER['QUERY_STRING'] = 'plan=7&a=b';
		foreach ( array( $id, $id . '/dns', $id . '/danger', Slugs::value( $reg ), (string) $this->res['foreign']->license()['id'], 'add', '' ) as $value ) {
			$GLOBALS['wp']->query_vars['domains'] = $value;
			$out[ 'redirect|' . $this->label( $value ) ] = $this->normal( $this->redirect_of() );
		}
		unset( $_SERVER['QUERY_STRING'], $GLOBALS['wp']->query_vars['domains'] );

		// The scripts: the add page and a domain page.
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		foreach ( array( 'add' => 'add', 'list' => '', 'page' => '12/dns' ) as $name => $value ) {
			foreach ( array( 'beltoft-services-dns-claim', 'beltoft-services-dns-editor', 'beltoft-services-domain' ) as $handle ) {
				wp_dequeue_script( $handle );
			}
			$GLOBALS['wp']->query_vars['domains'] = $value;
			\BeltoftServices\Domains\DnsClaimPanel::assets();
			Panels::assets( 'beltoft-services-account', 'domains' );
			$handles = array_values( array_filter( array( 'beltoft-services-dns-claim', 'beltoft-services-dns-editor', 'beltoft-services-domain' ), static fn( $h ) => wp_script_is( $h, 'enqueued' ) ) );
			$out[ 'scripts|' . $name ] = implode( ',', $handles );
		}
		foreach ( array( 'beltoft-services-dns-claim', 'beltoft-services-dns-editor', 'beltoft-services-domain' ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Runs the plugin's own template_redirect callbacks (not the whole WordPress chain) and returns where the request
	 * would go, or '' when none redirects.
	 */
	private function redirect_of(): string {
		global $wp_filter;

		$caught = static function ( $location ) {
			throw new \RuntimeException( 'redirect:' . $location );
		};
		add_filter( 'wp_redirect', $caught, 1 );

		try {
			foreach ( $wp_filter['template_redirect']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];

					if ( is_array( $fn ) && is_string( $fn[0] ) && 0 === strpos( $fn[0], 'BeltoftServices\\' ) || is_array( $fn ) && is_object( $fn[0] ) && 0 === strpos( get_class( $fn[0] ), 'BeltoftServices\\' ) ) {
						$_POST = array();
						call_user_func( $fn );
					}
				}
			}
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $caught, 1 );
		}

		return '';
	}

	/** A request value with the license ids replaced, for use in a key. */
	private function label( string $value ): string {
		foreach ( $this->res as $key => $resource ) {
			$value = (string) preg_replace( '/^' . (int) $resource->license()['id'] . '(?=\/|$)/', '{L:' . $key . '}', $value );
		}

		return $value;
	}

	private function compare( string $name, array $now ): void {
		$file = BELTOFT_SERVICES_PATH . 'tests/fixtures/golden/' . $name . '.json';

		if ( getenv( 'BS_WRITE_GOLDEN' ) ) {
			file_put_contents( $file, wp_json_encode( $now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$golden = json_decode( (string) file_get_contents( $file ), true );

		$this->assertSame( array_keys( $golden ), array_keys( $now ) );

		foreach ( $golden as $key => $text ) {
			$this->assertSame( $text, $now[ $key ], $key );
		}
	}

	public function test_every_domains_page_prints_what_it_printed_before_the_move(): void {
		$this->compare( 'domains', $this->render_all() );
	}

	public function test_every_address_form_and_link_answers_as_before(): void {
		$this->compare( 'domains-table', $this->table() );
	}

	public function test_the_plugin_page_filters_do_not_fire_on_a_domain_page(): void {
		$this->fixtures();
		$fired = array();

		foreach ( array( 'beltoft_services_account_page_redirect', 'beltoft_services_account_page_body', 'beltoft_services_account_page_chrome' ) as $hook ) {
			add_filter(
				$hook,
				static function ( $value ) use ( $hook, &$fired ) {
					$fired[] = $hook;

					return $value;
				},
				10,
				5
			);
		}

		$views = $this->views( array( 'reg', 'other', 'dnsonly' ) );

		foreach ( array( 'dns', 'nameservers', 'danger' ) as $tab ) {
			$GLOBALS['wp']->query_vars['domains'] = Slugs::value( $this->res['reg']->license(), $tab );
			$this->redirect_of();
			$this->entry( 'domains', $views, Slugs::value( $this->res['reg']->license(), $tab ) );
		}

		$this->assertSame( array(), $fired );
	}
}
