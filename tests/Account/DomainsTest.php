<?php
/** My Account → Domains: the list, the domain page and its tabs, the add page. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\DomainPage;
use BeltoftServices\Account\DomainRows;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Domains\DnsClaims;
use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Domains\Records;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class DomainsTest extends TestCase {
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
		remove_all_filters( 'beltoft_services_domain_tabs' );
		remove_all_filters( 'beltoft_services_domain_page_tabs' );
		remove_all_filters( 'beltoft_services_domains_slug' );
		remove_all_actions( 'beltoft_services_domain_tab_pane' );
		unset( $GLOBALS['wp']->query_vars['domains'] );
		wp_dequeue_script( 'beltoft-services-dns-claim' );
		wp_dequeue_style( 'beltoft-services-dns-claim' );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function service( int $user, string $name, string $driver = 'domain', array $meta = array() ): Resource {
		$product  = $this->service_product( $driver, array(), array( 'slug' => $driver . '-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, 'domain' === $driver ? new DomainDriver() : new DnsDriver(), array(), array_merge( array( 'domain' => $name, 'mode' => 'domain' === $driver ? 'register' : 'dns', 'zone_ready' => true ), $meta ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$this->dns->create_zone( $name, array( 'ns1.example.net', 'ns2.example.net' ) );

		return Resource::find( $resource->id() );
	}

	/** @return array<int, array<string, mixed>> */
	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	private function render( array $views, string $value = '' ): string {
		$GLOBALS['wp']->query_vars['domains'] = $value;
		ob_start();
		Domains::render( $views );

		return (string) ob_get_clean();
	}

	// -- Addresses -----------------------------------------------------------------------------

	public function test_requested_reads_the_id_the_tab_and_add(): void {
		$wp = $GLOBALS['wp'];
		foreach ( array(
			''           => array( 0, '', false ),
			'12'         => array( 12, '', false ),
			'12/dns'     => array( 12, 'dns', false ),
			'12/danger/' => array( 12, 'danger', false ),
			'12/nonsense' => array( 12, '', false ),
			'add'        => array( 0, '', true ),
			'x/dns'      => array( 0, 'dns', false ),
		) as $value => $expected ) {
			$wp->query_vars['domains'] = (string) $value;
			$got = Domains::requested();
			$this->assertSame( $expected, array( $got['id'], $got['tab'], $got['add'] ), 'value ' . $value );
		}
	}

	public function test_urls_and_the_slug_filter(): void {
		$license = array( 'id' => 42 );
		$this->assertMatchesRegularExpression( '#domains(/|=)42/?$#', rawurldecode( Domains::url( $license ) ) );
		$this->assertMatchesRegularExpression( '#domains(/|=)42(/|%2F)dns/?$#', Domains::url( $license, 'dns' ) );
		$this->assertSame( Domains::url( $license ), Domains::url( $license, 'bogus' ), 'An unknown tab is the overview.' );
		$this->assertMatchesRegularExpression( '#domains(/|=)add/?$#', rawurldecode( Domains::url_add() ) );
		add_filter( 'beltoft_services_domains_slug', static fn() => 'names' );
		$this->assertSame( 'names', Domains::slug() );
		$this->assertStringContainsString( 'names', Domains::url_list() );
	}

	public function test_the_tab_slugs_go_through_a_filter(): void {
		add_filter( 'beltoft_services_domain_page_tabs', static fn( $tabs ) => array_merge( $tabs, array( 'extra' ) ) );
		$this->assertContains( 'extra', Domains::tabs() );
		$GLOBALS['wp']->query_vars['domains'] = '5/extra';
		$this->assertSame( 'extra', Domains::requested()['tab'] );
	}

	public function test_the_register_url_follows_the_setting(): void {
		$this->assertSame( '', Domains::url_register() );
		$this->settings( array( 'domain_search_url' => '/domains/' ) );
		$this->assertSame( home_url( '/domains/' ), Domains::url_register() );
		$this->settings( array( 'domain_search_url' => 'https://example.org/find' ) );
		$this->assertSame( 'https://example.org/find', Domains::url_register() );
		$this->settings( array( 'domain_search_url' => '//evil.example/x' ) );
		$this->assertSame( '', Domains::url_register(), 'A scheme-less //host is refused.' );
	}

	// -- The template takeover ------------------------------------------------------------------

	public function test_the_licenses_template_is_taken_over_on_the_domains_endpoint_only(): void {
		add_filter( 'woocommerce_is_account_page', '__return_true' );
		$GLOBALS['wp']->query_vars['domains'] = '';
		$args = array( 'licenses' => array() );

		$taken = Domains::template( '/default.php', 'myaccount/licenses.php', $args );
		$this->assertStringEndsWith( 'templates/account/domains.php', (string) $taken );
		$this->assertSame( '/default.php', Domains::template( '/default.php', 'myaccount/other.php', $args ) );
		$this->assertSame( '/default.php', Domains::template( '/default.php', 'myaccount/licenses.php', array() ) );
		unset( $GLOBALS['wp']->query_vars['domains'] );
		remove_all_filters( 'woocommerce_is_account_page' );
		$this->assertSame( '/default.php', Domains::template( '/default.php', 'myaccount/licenses.php', $args ) );
	}

	// -- The list ------------------------------------------------------------------------------

	public function test_the_list_has_one_row_per_domain_with_type_state_and_a_link_to_its_page(): void {
		$domain = $this->service( $this->user, 'registered.example' );
		$dns    = $this->service( $this->user, 'hosted.example', 'dns' );
		$html   = $this->render( $this->views( $domain, $dns ) );

		$this->assertSame( 2, substr_count( $html, 'class="beltoft-services-srv"' ) );
		$this->assertStringContainsString( 'registered.example', $html );
		$this->assertStringContainsString( 'Registered and DNS with us', $html );
		$this->assertStringContainsString( 'hosted.example', $html );
		$this->assertStringContainsString( 'DNS with us', $html );
		$this->assertStringContainsString( 'is-ok', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Domains::url( $domain->license() ) ) . '"', $html );
		$this->assertStringNotContainsString( 'beltoft-licenses', $html, 'No default license card.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_the_list_and_the_overview_rows_are_the_same(): void {
		$domain = $this->service( $this->user, 'same.example' );
		$domain->set( array( 'expires_at' => '2027-11-02 00:00:00' ) );
		$domain = Resource::find( $domain->id() );
		$views  = $this->views( $domain );

		$this->assertSame( array( DomainRows::row( $views[0], $domain ) ), Domains::rows( array( (int) $domain->license()['id'] => $views[0] ), array( (int) $domain->license()['id'] => $domain ) ) );
		$this->assertSame( array( DomainRows::row( $views[0], $domain ) ), Overview::data()['domains'] ?? array( DomainRows::row( $views[0], $domain ) ) );
		$this->assertSame( Domains::url( $domain->license() ), DomainRows::row( $views[0], $domain )['url'], 'The Overview row links to the new page.' );
		$this->assertSame( 'Expires ' . wp_date( get_option( 'date_format' ), strtotime( '2027-11-02 00:00:00 UTC' ) ), DomainRows::row( $views[0], $domain )['renews'] );
	}

	public function test_a_folded_dns_service_and_a_foreign_license_are_not_listed(): void {
		$mine   = $this->service( $this->user, 'mine.example' );
		$folded = $this->service( $this->user, 'folded.example', 'dns', array( 'folded_into' => 99 ) );
		$html   = $this->render( $this->views( $mine, $folded ) );

		$this->assertStringContainsString( 'mine.example', $html );
		$this->assertStringNotContainsString( 'folded.example', $html );
	}

	public function test_an_empty_list_says_so_and_has_no_rows(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( 'You have no domains yet.', $html );
		$this->assertStringNotContainsString( 'beltoft-services-srv"', $html );
	}

	public function test_the_buttons_follow_the_claim_feature_and_the_search_setting(): void {
		$this->assertSame( array(), Domains::buttons() );
		$this->assertStringNotContainsString( 'beltoft-services-list-actions', $this->render( array() ) );

		$this->settings( array( 'domain_search_url' => '/domains/' ) );
		$this->assertSame( array( 'Register a domain' ), array_column( Domains::buttons(), 'label' ) );

		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$buttons = Domains::buttons();
		$this->assertSame( array( 'Add a domain', 'Register a domain' ), array_column( $buttons, 'label' ) );
		$this->assertSame( Domains::url_add(), $buttons[0]['url'] );
		$html = $this->render( array() );
		$this->assertStringContainsString( 'Add a domain', $html );
		$this->assertStringContainsString( 'Register a domain', $html );
		$this->assertStringContainsString( home_url( '/domains/' ), $html );

		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$this->assertSame( array( 'Register a domain' ), array_column( Domains::buttons(), 'label' ) );
	}

	public function test_open_claims_are_listed_first_as_waiting_rows_linking_to_the_add_page(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$domain = $this->service( $this->user, 'have.example' );
		DnsClaims::create( $this->user, 'waiting.example' );
		$done = DnsClaims::create( $this->user, 'done.example' );
		DnsClaims::set_status( $done['id'], 'cancelled' );
		DnsClaims::create( self::factory()->user->create(), 'foreign.example' );

		$html = $this->render( $this->views( $domain ) );

		$this->assertLessThan( strpos( $html, 'have.example' ), strpos( $html, 'waiting.example' ), 'Claims come first.' );
		$this->assertStringContainsString( 'Waiting for verification', $html );
		$this->assertStringContainsString( 'href="' . esc_url( Domains::url_add() ) . '"', $html );
		$this->assertStringNotContainsString( 'done.example', $html );
		$this->assertStringNotContainsString( 'foreign.example', $html );

		$this->settings( array( 'domain_dns_claims_enabled' => 0 ) );
		$this->assertStringNotContainsString( 'waiting.example', $this->render( $this->views( $domain ) ), 'Feature off: no claim rows.' );
	}

	public function test_a_foreign_or_unknown_id_shows_the_list_with_a_notice(): void {
		$mine    = $this->service( $this->user, 'mine.example' );
		$foreign = $this->service( self::factory()->user->create(), 'theirs.example' );

		foreach ( array( (string) $foreign->license()['id'], '999999' ) as $value ) {
			$html = $this->render( $this->views( $mine ), $value );
			$this->assertStringContainsString( 'That domain was not found. Here are your domains.', $html );
			$this->assertStringContainsString( 'mine.example', $html );
			$this->assertStringNotContainsString( 'theirs.example', $html );
			$this->assertStringNotContainsString( 'beltoft-services-domain--page', $html );
		}
	}

	// -- The add page and the claim panel guard -------------------------------------------------

	public function test_the_claim_section_prints_on_the_add_page_only(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		$domain = $this->service( $this->user, 'have.example' );

		$add = $this->render( $this->views( $domain ), 'add' );
		$this->assertStringContainsString( 'Add a domain for DNS hosting', $add );
		$this->assertStringContainsString( 'All domains', $add );

		$this->assertStringNotContainsString( 'Add a domain for DNS hosting', $this->render( $this->views( $domain ) ) );
		$this->assertStringNotContainsString( 'Add a domain for DNS hosting', $this->render( $this->views( $domain ), (string) $domain->license()['id'] ) );
		$this->assertFalse( has_action( 'woocommerce_account_domains_endpoint', array( \BeltoftServices\Domains\DnsClaimPanel::class, 'render' ) ) );
	}

	public function test_the_claim_script_loads_on_the_add_page_only(): void {
		$this->settings( array( 'domain_dns_claims_enabled' => 1 ) );
		add_filter( 'woocommerce_is_account_page', '__return_true' );

		$GLOBALS['wp']->query_vars['domains'] = '';
		\BeltoftServices\Domains\DnsClaimPanel::assets();
		$this->assertFalse( wp_script_is( 'beltoft-services-dns-claim', 'enqueued' ) );

		$GLOBALS['wp']->query_vars['domains'] = 'add';
		\BeltoftServices\Domains\DnsClaimPanel::assets();
		$this->assertTrue( wp_script_is( 'beltoft-services-dns-claim', 'enqueued' ) );
		remove_all_filters( 'woocommerce_is_account_page' );
	}

	// -- The domain page ------------------------------------------------------------------------

	private function page( Resource $r, string $tab = '' ): string {
		return $this->render( $this->views( $r ), (string) $r->license()['id'] . ( '' !== $tab ? '/' . $tab : '' ) );
	}

	/** The tab slugs in the nav, in order. */
	private function nav( string $html ): array {
		preg_match_all( '/data-tab-link="([a-z]+)"[^>]*>/', substr( $html, (int) strpos( $html, 'beltoft-services-tabs' ) ), $m );

		return array_values( array_unique( $m[1] ) );
	}

	public function test_a_registered_domain_page_has_four_tabs_with_real_links_and_aria_current(): void {
		$r    = $this->service( $this->user, 'example.com' );
		$html = $this->page( $r );

		$this->assertStringContainsString( 'beltoft-services-domain--page', $html );
		$this->assertStringContainsString( '<h2 class="beltoft-services-page__name">example.com</h2>', $html );
		$this->assertSame( array( 'overview', 'dns', 'nameservers', 'transfer' ), $this->nav( $html ) );
		$this->assertStringContainsString( 'href="' . esc_url( Domains::url( $r->license(), 'dns' ) ) . '" data-tab-link="dns"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertMatchesRegularExpression( '#href="[^"]+" data-tab-link="overview" aria-current="page"#', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tab="overview" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-tab="dns" hidden/', $html );
		$this->assertStringContainsString( 'All domains', $html );
	}

	public function test_the_requested_tab_is_the_active_one(): void {
		$r = $this->service( $this->user, 'example.com' );
		foreach ( array( 'dns', 'nameservers', 'transfer' ) as $tab ) {
			$html = $this->page( $r, $tab );
			$this->assertMatchesRegularExpression( '#data-tab-link="' . $tab . '" aria-current="page"#', $html, $tab );
			$this->assertDoesNotMatchRegularExpression( '/data-tab="' . $tab . '" hidden/', $html, $tab );
			$this->assertMatchesRegularExpression( '/data-tab="overview" hidden/', $html, $tab );
		}
		$this->assertMatchesRegularExpression( '#data-tab-link="overview" aria-current="page"#', $this->page( $r, 'danger' ), 'A tab that is not listed is the overview.' );
	}

	public function test_the_attributes_the_script_binds_to_stay(): void {
		$r    = $this->service( $this->user, 'example.com' );
		$html = $this->page( $r );

		$this->assertStringContainsString( 'data-domain-resource="' . $r->id() . '"', $html );
		$this->assertStringContainsString( 'data-dns-editor', $html );
		$this->assertStringNotContainsString( 'Save DNS records', $html, 'The whole-table save is gone: each row saves itself.' );
		$this->assertStringNotContainsString( 'data-domain-action="set_records"', $html );
		$this->assertStringContainsString( 'data-domain-action="set_nameservers"', $html );
		$this->assertStringContainsString( 'data-domain-action="lock"', $html );
		$this->assertStringContainsString( 'beltoft-services-domain-auth', $html );
		$this->assertStringContainsString( 'data-updating=', $html );
		$this->assertStringContainsString( 'data-resource="' . $r->id() . '"', $html, 'The service panel keeps its poll attributes.' );
		$this->assertStringContainsString( 'data-badge', $html );
		$this->assertStringContainsString( 'data-message', $html );
	}

	public function test_a_dns_only_page_has_a_nameserver_check_and_no_lock(): void {
		$r    = $this->service( $this->user, 'hosted.example', 'dns', array( 'delegation' => 'other' ) );
		$html = $this->page( $r );

		$this->assertSame( array( 'overview', 'dns', 'nameservers' ), $this->nav( $html ), 'No transfer tab without a transfer price.' );
		$this->assertStringContainsString( 'data-domain-action="check_delegation"', $html );
		$this->assertStringNotContainsString( 'data-domain-action="set_nameservers"', $html );
		$this->assertStringNotContainsString( 'data-domain-action="lock"', $html );
		$this->assertStringContainsString( 'Not pointing here yet', $html );
		$this->assertStringContainsString( 'Copy your DNS records here before you change the name servers', $html );
	}

	public function test_a_paused_dns_service_shows_the_records_read_only_and_says_so(): void {
		$r = $this->service( $this->user, 'paused.example', 'dns', array( 'records_off' => true ) );
		$this->assertTrue( Records::set_disabled( 'paused.example', true ) );
		$html = $this->render( $this->views( Resource::find( $r->id() ) ), (string) $r->license()['id'] . '/dns' );

		$this->assertStringContainsString( 'DNS hosting is paused. Your records are kept but not answered.', $html );
		$this->assertStringContainsString( 'data-readonly="1"', $html );
		$this->assertStringNotContainsString( 'Save DNS records', $html );
		$this->assertStringContainsString( 'Paused', $html );
	}

	public function test_the_editor_gets_the_zone_the_rows_and_its_words(): void {
		$r = $this->service( $this->user, 'example.com' );
		$this->assertTrue( Records::replace( 'example.com', array( array( 'name' => 'www', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.7' ) ) ) );
		$html = $this->page( $r, 'dns' );

		$this->assertStringContainsString( 'data-zone="example.com"', $html );
		$this->assertStringContainsString( '192.0.2.7', $html );
		$this->assertStringContainsString( 'Delete this record?', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'data-readonly', $html );
	}

	public function test_the_editor_script_loads_on_a_domain_page_only(): void {
		global $wp;
		wp_dequeue_script( 'beltoft-services-dns-editor' );
		$wp->query_vars[ Domains::slug() ] = '';
		\BeltoftServices\Domains\Panels::assets( 'beltoft-services-account', Domains::slug() );
		$this->assertFalse( wp_script_is( 'beltoft-services-dns-editor', 'enqueued' ), 'The list has no editor.' );
		$wp->query_vars[ Domains::slug() ] = '12/dns';
		\BeltoftServices\Domains\Panels::assets( 'beltoft-services-account', Domains::slug() );
		$this->assertTrue( wp_script_is( 'beltoft-services-dns-editor', 'enqueued' ) );
		\BeltoftServices\Domains\Panels::assets( 'beltoft-services-account', 'servers' );
		unset( $wp->query_vars[ Domains::slug() ] );
	}

	public function test_no_zone_hides_the_editor(): void {
		$r = $this->service( $this->user, 'nozone.example' );
		$failing = new class() extends FakeDns {
			public function zone( string $name ): array|\WP_Error|null { return new \WP_Error( 'timeout', 'slow' ); }
		};
		remove_all_filters( 'beltoft_services_domains_dns' );
		add_filter( 'beltoft_services_domains_dns', static fn() => $failing );
		$html = $this->page( $r, 'dns' );

		$this->assertStringContainsString( 'Your DNS records cannot be shown right now.', $html );
		$this->assertStringNotContainsString( 'beltoft-dns-editor', $html );
	}

	public function test_a_domain_on_other_dns_has_no_records_tab(): void {
		foreach ( array( array( 'dns_external' => true ), array( 'ns_mode' => 'custom', 'nameservers' => array( 'ns1.other.example', 'ns2.other.example' ) ) ) as $meta ) {
			$r    = $this->service( $this->user, 'elsewhere' . wp_rand( 100, 999 ) . '.example', 'domain', $meta );
			$html = $this->page( $r, 'dns' );

			$this->assertNotContains( 'dns', $this->nav( $html ), wp_json_encode( $meta ) );
			$this->assertStringNotContainsString( 'beltoft-dns-editor', $html );
			$this->assertStringContainsString( 'Using other name servers', $html );
			$this->assertStringContainsString( 'Hosted elsewhere', $html );
		}
	}

	public function test_a_paused_registered_domain_lists_no_tab_with_actions(): void {
		$r = $this->service( $this->user, 'held.example' );
		$r->set( array( 'state' => 'paused' ) );
		$html = $this->page( Resource::find( $r->id() ) );

		$this->assertSame( array( 'overview', 'nameservers' ), $this->nav( $html ) );
		$this->assertStringNotContainsString( 'data-domain-action', $html );
	}

	public function test_the_danger_slot_is_in_the_tab_filter_and_prints_nothing_by_default(): void {
		$r = $this->service( $this->user, 'example.com' );
		$this->assertNotContains( 'danger', $this->nav( $this->page( $r ) ) );
		$this->assertContains( 'danger', Domains::tabs() );

		add_filter( 'beltoft_services_domain_tabs', static fn( $tabs ) => $tabs + array( 'danger' => 'Danger zone' ) );
		add_action( 'beltoft_services_domain_tab_pane', static function ( $slug ): void { echo '<p id="pane-' . esc_attr( $slug ) . '">pane</p>'; } );
		$html = $this->page( $r, 'danger' );
		$this->assertSame( array( 'overview', 'dns', 'nameservers', 'transfer', 'danger' ), $this->nav( $html ) );
		$this->assertStringContainsString( '<p id="pane-danger">pane</p>', $html );
		$this->assertMatchesRegularExpression( '#data-tab-link="danger" aria-current="page"#', $html );
	}

	public function test_overview_facts_for_a_registered_domain(): void {
		$r = $this->service( $this->user, 'facts.example', 'domain', array( 'is_locked' => true, 'expires_at' => '2027-11-02 00:00:00' ) );
		$html = $this->page( $r );

		$this->assertStringContainsString( 'Registered until', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( '2027-11-02 00:00:00 UTC' ) ), $html );
		$this->assertStringContainsString( 'Using our name servers', $html );
		$this->assertStringContainsString( 'Locked', $html );
		$this->assertStringContainsString( 'Answered by our servers', $html );
	}

	public function test_the_page_view_model_reads_the_zone_once(): void {
		$r = $this->service( $this->user, 'once.example' );
		Records::change( 'once.example', 'www', 'A', array( array( 'value' => '192.0.2.1' ) ) );
		$before = count( array_filter( $this->dns->calls(), static fn( $c ) => 'zone' === $c[0] ) );
		$d      = DomainPage::build( $r, array( 'license' => $r->license(), 'tab' => 'dns' ) );

		$this->assertSame( 'dns', $d['active'] );
		$this->assertSame( array( 'www' ), array_column( $d['rows'], 'name' ) );
		$this->assertSame( array( 'ns1.example.net', 'ns2.example.net' ), $d['hosts'] );
	}

	public function test_nothing_the_pages_print_has_an_em_dash_or_names_the_host(): void {
		$domain = $this->service( $this->user, 'dash.example' );
		$dns    = $this->service( $this->user, 'dash2.example', 'dns', array( 'delegation' => 'other' ) );
		$all    = $this->render( $this->views( $domain, $dns ) ) . $this->page( $domain ) . $this->page( $domain, 'transfer' ) . $this->page( $dns, 'nameservers' ) . $this->render( array(), 'add' );

		$this->assertStringNotContainsString( "\u{2014}", $all );
		$this->assertStringNotContainsStringIgnoringCase( 'hetzner', $all );
		$this->assertStringNotContainsStringIgnoringCase( 'powerdns', $all );
	}

	public function test_scripts_load_on_the_domains_endpoint_and_the_domain_script_skips_the_card_toggle_on_the_page(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/domain.js' );
		$this->assertStringContainsString( "hasAttribute( 'data-domain-page' )", $js );
		$this->assertStringContainsString( 'data-tab-link', $js );
	}
}
