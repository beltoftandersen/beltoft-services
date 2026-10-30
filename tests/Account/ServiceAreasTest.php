<?php
/** My Account → Databases and Apps: tabs, rows, pages, access and the Overview. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Apps;
use BeltoftServices\Account\Databases;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Http\Connections;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class ServiceAreasTest extends TestCase {
	private int $user = 0;

	public function set_up(): void {
		parent::set_up();
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', static fn() => $key );
		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		foreach ( array( 'databases', 'apps' ) as $slug ) {
			unset( $GLOBALS['wp']->query_vars[ $slug ] );
		}
		foreach ( wp_scripts()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'beltoft-services-' ) ) {
				wp_dequeue_script( $handle );
			}
		}
		foreach ( wp_styles()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'beltoft-services-' ) ) {
				wp_dequeue_style( $handle );
			}
		}
		remove_all_filters( 'beltoft_services_databases_slug' );
		remove_all_filters( 'beltoft_services_apps_slug' );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function database( int $user, array $meta = array() ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'db-' . wp_generate_password( 5, false ) ) );
		$product->set_name( 'Managed database' );
		$product->save();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array_merge( array( 'db_state' => 'ready', 'db_address' => '10.64.0.2' ), $meta ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function app( int $user, bool $openable = true ): Resource {
		$this->assertTrue(
			Connections::save(
				'svc',
				array(
					'label'            => 'Service',
					'base_url'         => 'https://api.example.com/v1',
					'auth'             => 'hmac',
					'secret'           => 'secret-secret-secret-1',
					'endpoints'        => array( 'provision' => array( 'method' => 'POST', 'path' => '/tenants' ) ),
					'open_url'         => $openable ? 'https://app.example.com/open?t={token}' : '',
					'open_label'       => 'Open app',
					'usage_used_field' => 'used',
				)
			)
		);
		$product = $this->service_product( 'http', array( 'limit' => 3 ), array( 'connection' => 'svc', 'max' => 3, 'slug' => 'app-' . wp_generate_password( 5, false ) ) );
		$product->set_name( 'Odoo app' );
		$product->save();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'http' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	private function render( ServiceArea $area, array $views, string $value = '' ): string {
		$GLOBALS['wp']->query_vars[ $area->slug() ] = $value;
		ob_start();
		$area->render( $views );

		return (string) ob_get_clean();
	}

	private function db_area(): Databases {
		return ServiceArea::get( Databases::class );
	}

	private function apps_area(): Apps {
		return ServiceArea::get( Apps::class );
	}

	// -- Tabs ----------------------------------------------------------------------------------

	public function test_databases_and_apps_get_their_own_tabs_and_leave_the_licenses_tab(): void {
		$db    = $this->database( $this->user );
		$app   = $this->app( $this->user );
		$other = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );
		$this->fake_driver();

		$groups = Endpoint::groups();

		$this->assertSame( 'Databases', $groups['databases']['label'] );
		$this->assertSame( 'You have no databases yet.', $groups['databases']['empty'] );
		$this->assertSame( 'Apps', $groups['apps']['label'] );
		$this->assertSame( 'You have no apps yet.', $groups['apps']['empty'] );

		$tabs = Endpoint::split( array( $db->license(), $app->license(), $other ) );

		$this->assertSame( array( $db->license()['id'] ), array_column( $tabs['databases'], 'id' ) );
		$this->assertSame( array( $app->license()['id'] ), array_column( $tabs['apps'], 'id' ) );
		$this->assertSame( array( $other['id'] ), array_column( $tabs[''], 'id' ), 'Neither is on the Licenses tab.' );
		$this->assertSame( wc_get_account_endpoint_url( 'databases' ), Endpoint::url_for_license( $db->license() ) );
		$this->assertContains( 'apps', Endpoint::slugs() );
	}

	public function test_the_slug_can_be_changed_with_a_filter(): void {
		add_filter( 'beltoft_services_databases_slug', static fn() => 'dbs' );

		$this->assertSame( 'dbs', $this->db_area()->slug() );
		$this->assertContains( 'dbs', Endpoint::slugs() );
	}

	// -- Databases -----------------------------------------------------------------------------

	public function test_a_database_row_has_name_engine_state_and_private_host_and_links_to_its_page(): void {
		$db = $this->database( $this->user );

		$rows = $this->db_area()->rows( array( (int) $db->license()['id'] => $this->views( $db )[0] ), array( (int) $db->license()['id'] => $db ) );

		$this->assertSame( 'Managed database', $rows[0]['name'] );
		$this->assertSame( 'PostgreSQL 18', $rows[0]['details'] );
		$this->assertSame( 'Ready', $rows[0]['state'] );
		$this->assertSame( 'ok', $rows[0]['tone'] );
		$this->assertSame( '10.64.0.2', $rows[0]['extra'] );
		$this->assertSame( $this->db_area()->url( $db->license() ), $rows[0]['url'] );

		$html = $this->render( $this->db_area(), $this->views( $db ) );

		$this->assertStringContainsString( 'beltoft-services-servers--area', $html );
		$this->assertStringContainsString( 'PostgreSQL 18', $html );
		$this->assertStringContainsString( '10.64.0.2', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->db_area()->url( $db->license() ) ) . '"', $html );
	}

	public function test_a_database_being_built_has_no_host_and_a_working_or_getting_ready_state(): void {
		$db = $this->database( $this->user, array( 'db_state' => 'installing', 'db_address' => '10.64.0.3' ) );
		$db->set( array( 'state' => 'provisioning' ) );

		$row = $this->db_area()->row( $this->views( Resource::find( $db->id() ) )[0], Resource::find( $db->id() ) );

		$this->assertSame( '', $row['extra'] );
		$this->assertSame( 'busy', $row['tone'] );
	}

	public function test_a_database_page_has_overview_and_new_password_tabs_with_real_links(): void {
		$db    = $this->database( $this->user );
		$views = $this->views( $db );
		$id    = (int) $db->license()['id'];

		$html = $this->render( $this->db_area(), $views, (string) $id );

		$this->assertStringContainsString( 'All databases', $html );
		$this->assertStringContainsString( 'beltoft-services-page__name', $html );
		$this->assertStringContainsString( 'Overview', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->db_area()->url( $db->license(), 'password' ) ) . '"', $html );
		$this->assertStringContainsString( 'Reachable from your servers on the private network.', $html );
		$this->assertStringContainsString( 'postgresql://', $html );
		$this->assertStringNotContainsString( 'data-db-confirm', $html, 'The reset form is on its own tab.' );

		$html = $this->render( $this->db_area(), $views, $id . '/password' );

		$this->assertStringContainsString( 'aria-current="page">New password', $html );
		$this->assertStringNotContainsString( 'Reachable from your servers', $html );
		$this->assertSame( array( 'overview', 'password' ), array_keys( $this->db_area()->nav() ) );
	}

	public function test_a_database_in_progress_shows_the_progress_list_on_its_page(): void {
		$db = $this->database( $this->user, array( 'db_state' => 'installing' ) );
		$db->set( array( 'state' => 'provisioning' ) );
		$db = Resource::find( $db->id() );

		$html = $this->render( $this->db_area(), $this->views( $db ), (string) $db->license()['id'] );

		$this->assertStringContainsString( 'beltoft-services-progress', $html );
		$this->assertStringContainsString( 'Your database is being set up', $html );
	}

	public function test_another_customer_gets_the_list_with_a_notice_never_the_page(): void {
		$db = $this->database( self::factory()->user->create() );

		// The tab lists only the viewer's licenses; a foreign id is not among them.
		$html = $this->render( $this->db_area(), array(), (string) $db->license()['id'] );

		$this->assertStringContainsString( 'That database was not found', $html );
		$this->assertStringContainsString( 'You have no databases yet.', $html );
		$this->assertStringNotContainsString( 'postgresql://', $html );

		// A license shown by email only prints the head, never the driver's part.
		wp_set_current_user( self::factory()->user->create() );
		$html = $this->render( $this->db_area(), $this->views( $db ), (string) $db->license()['id'] );

		$this->assertStringNotContainsString( 'postgresql://', $html );
		$this->assertStringNotContainsString( 'data-db-reveal', $html );
	}

	public function test_an_empty_list_says_so(): void {
		$html = $this->render( $this->db_area(), array() );

		$this->assertStringContainsString( 'You have no databases yet.', $html );
		$this->assertStringNotContainsString( 'beltoft-services-srv"', $html );
	}

	// -- Apps ----------------------------------------------------------------------------------

	public function test_an_app_row_has_name_state_usage_and_an_open_button(): void {
		$app = $this->app( $this->user );
		set_transient( 'beltoft_services_http_usage_' . $app->id(), array( 'used' => 1, 'limit' => 3 ) );

		$html = $this->render( $this->apps_area(), $this->views( $app ) );
		$row  = $this->apps_area()->row( $this->views( $app )[0], $app );

		$this->assertSame( 'Odoo app', $row['name'] );
		$this->assertSame( 'Ready', $row['state'] );
		$this->assertSame( '1 of 3', $row['details'] );
		$this->assertSame( 'Open app', $row['action']['label'] );
		$this->assertStringContainsString( 'data-beltoft-services-open', $html );
		$this->assertStringContainsString( '%2Fopen', $html );
		$this->assertStringContainsString( '<div class="beltoft-services-srv"', $html, 'A row with a button is not one link.' );
	}

	public function test_an_app_without_an_open_url_has_no_button_and_shows_the_limit(): void {
		$app = $this->app( $this->user, false );
		$row = $this->apps_area()->row( $this->views( $app )[0], $app );

		$this->assertSame( array(), $row['action'] );
		$this->assertSame( 'Limit 3', $row['details'] );
	}

	public function test_an_app_page_shows_what_the_driver_prints_and_has_no_tabs(): void {
		$app = $this->app( $this->user );

		$html = $this->render( $this->apps_area(), $this->views( $app ), (string) $app->license()['id'] );

		$this->assertStringContainsString( 'All apps', $html );
		$this->assertStringContainsString( 'beltoft-services-http', $html );
		$this->assertStringContainsString( 'Open app', $html );
		$this->assertStringNotContainsString( 'beltoft-services-tabs', $html );
		$this->assertSame( array(), $this->apps_area()->nav() );
	}

	public function test_an_unknown_app_id_shows_the_list_with_a_notice(): void {
		$app  = $this->app( $this->user );
		$html = $this->render( $this->apps_area(), $this->views( $app ), '999999' );

		$this->assertStringContainsString( 'That app was not found', $html );
		$this->assertStringContainsString( 'beltoft-services-srv', $html );
	}

	// -- Overview ------------------------------------------------------------------------------

	public function test_the_overview_lists_databases_after_servers_and_apps_in_the_reserved_slot(): void {
		$db  = $this->database( $this->user );
		$app = $this->app( $this->user );
		Overview::reset();

		$html = Overview::section( 'databases' );

		$this->assertStringContainsString( 'beltoft-services-ov__databases', $html );
		$this->assertStringContainsString( 'Managed database', $html );
		$this->assertStringContainsString( 'PostgreSQL 18 · 10.64.0.2', $html );
		$this->assertStringContainsString( 'All databases', $html );
		$this->assertStringContainsString( esc_url( $this->db_area()->url_list() ), $html );

		$html = Overview::section( 'apps' );

		$this->assertStringContainsString( 'beltoft-services-ov__apps', $html );
		$this->assertStringContainsString( 'All apps', $html );

		$all = Overview::all();

		$this->assertStringContainsString( 'beltoft-services-ov__cols', $all );
		$this->assertStringContainsString( 'order:4', $all, 'Apps keep the reserved slot.' );
		$this->assertStringContainsString( 'is-single', $all, 'Nothing on the right: one column, no empty half.' );
		$this->assertLessThan( strpos( $all, 'beltoft-services-ov__apps' ), strpos( $all, 'beltoft-services-ov__databases' ) );
		$this->assertSame( 'Managed database', Overview::data()['databases'][0]['name'] );
		$this->assertSame( $app->license()['id'], Overview::data()['apps'] ? $app->license()['id'] : 0 );
		$this->assertNotNull( $db );
	}

	public function test_the_overview_has_no_sections_without_databases_or_apps(): void {
		$this->assertSame( '', Overview::section( 'databases' ) );
		$this->assertSame( '', Overview::section( 'apps' ) );
	}

	public function test_a_working_database_is_followed_on_its_own_page(): void {
		$db = $this->database( $this->user, array( 'db_state' => 'installing' ) );
		$db->set( array( 'state' => 'provisioning' ) );
		Overview::reset();

		$attention = Overview::data()['attention'];

		$this->assertNotEmpty( $attention );
		$this->assertSame( $this->db_area()->url( $db->license() ), $attention[0]['link'] );
	}
}
