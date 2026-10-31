<?php
/**
 * The New database page, and the action bars of the Servers and Databases lists.
 * FakeClient and the capacity cache only: nothing reaches a network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\Databases;
use BeltoftServices\Account\DbPicker;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Servers;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DbEngines;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\BackupTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/BackupTestCase.php';

class DbPickerTest extends BackupTestCase {

	/**
	 * Plan variations: small and big.
	 *
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();

		$product = new \WC_Product_Variable();
		$product->set_name( 'Managed database' );
		$product->set_status( 'publish' );
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Plan' );
		$attr->set_options( array( 'Small', 'Big' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product->set_attributes( array( $attr ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'major' => '18', 'cores' => '1', 'memory_mb' => '2048', 'disk_gb' => '20', 'backup_days' => '0' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		foreach ( array( 'Small' => '25', 'Big' => '45' ) as $name => $price ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'plan' => $name ) );
			$v->set_regular_price( $price );
			$v->set_status( 'publish' );

			if ( 'Big' === $name ) {
				$v->update_meta_data( Product::META_PLAN . 'cores', '4' );
				$v->update_meta_data( Product::META_PLAN . 'memory_mb', '8192' );
				$v->update_meta_data( Product::META_PLAN . 'disk_gb', '120' );
			}

			$v->save();
			$this->v[ $name ] = $v;
		}

		$this->user = self::factory()->user->create();
		wp_set_current_user( $this->user );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		remove_all_filters( 'beltoft_services_db_needs_server_url' );
		remove_all_filters( 'beltoft_services_db_picker_products' );
		remove_all_filters( 'beltoft_services_picker_rate_limit' );
		unset( $GLOBALS['wp']->query_vars['databases'], $GLOBALS['wp']->query_vars['vps'], $_GET['plan'], $_GET['engine'] );
		wp_dequeue_style( ServerPicker::HANDLE );
		wp_dequeue_script( ServerPicker::HANDLE );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function page(): string {
		$GLOBALS['wp']->query_vars['databases'] = 'new';
		ob_start();
		ServiceArea::get( Databases::class )->render( array() );

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, string> $over Fields.
	 * @return array<string, string>
	 */
	private function post( array $over = array() ): array {
		return array_merge(
			array(
				DbPicker::FIELD     => wp_create_nonce( DbPicker::NONCE ),
				ServerPicker::PLAN  => (string) $this->v['Small']->get_id(),
			),
			$over
		);
	}

	public function test_the_address_is_the_new_page_and_the_list_is_unchanged(): void {
		$area = ServiceArea::get( Databases::class );
		$this->assertMatchesRegularExpression( '#databases(/|=)new/?$#', rawurldecode( $area->url_new() ) );

		foreach ( array( 'new' => true, 'new/x' => true, '' => false, '12' => false, 'newer' => false, '12/backups' => false ) as $value => $new ) {
			$GLOBALS['wp']->query_vars['databases'] = (string) $value;
			$this->assertSame( $new, $area->is_new(), (string) $value );
		}

		$GLOBALS['wp']->query_vars['databases'] = '';
		ob_start();
		$area->render( array() );
		$list = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'beltoft-services-picker', $list );
		$this->assertStringContainsString( 'You have no databases yet.', $list );
	}

	public function test_the_plan_tiles_come_from_the_variations(): void {
		$html = $this->page();

		$this->assertSame( 2, substr_count( $html, 'name="' . ServerPicker::PLAN . '"' ), 'One radio per plan.' );
		$this->assertStringContainsString( 'value="' . $this->v['Small']->get_id() . '"', $html );
		$this->assertStringContainsString( 'value="' . $this->v['Big']->get_id() . '"', $html );
		$this->assertStringContainsString( 'Small', $html );
		$this->assertStringContainsString( '1 vCPU', $html );
		$this->assertStringContainsString( '4 vCPU', $html );
		$this->assertStringContainsString( '8 GB RAM', $html );
		$this->assertStringContainsString( '120 GB data disk', $html );
		$this->assertStringContainsString( 'PostgreSQL 18', $html );
		$this->assertStringContainsString( 'Daily backups, kept 7 days', $html );
		$this->assertStringContainsString( 'excluding VAT', $html );
		$this->assertStringContainsString( 'name="' . DbPicker::FIELD . '"', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( 'data-tiles', $html, 'No system or app tiles.' );
	}

	public function test_the_plan_days_come_from_the_plan_when_it_names_them(): void {
		$this->v['Big']->update_meta_data( Product::META_PLAN . 'backup_days', '14' );
		$this->v['Big']->save();

		$this->assertStringContainsString( 'Daily backups, kept 14 days', $this->page() );
	}

	public function test_only_published_products_of_the_driver_are_offered(): void {
		$other = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$this->assertArrayNotHasKey( $other->get_id(), DbPicker::plans() );
		$this->assertCount( 2, DbPicker::plans() );

		$parent = wc_get_product( $this->v['Small']->get_parent_id() );
		$parent->set_status( 'private' );
		$parent->save();
		$this->assertSame( array(), DbPicker::plans() );
		$this->assertStringContainsString( 'No database can be ordered right now.', $this->page() );
	}

	public function test_the_notice_shows_for_a_customer_without_a_server_and_not_with_one(): void {
		$this->assertStringContainsString( 'public IP address', $this->page() );
		$this->assertStringContainsString( 'coming soon', $this->page(), 'No public IP plan for sale here: not promised.' );
		$this->assertStringNotContainsString( "\u{2014}", $this->page() );

		$vm       = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $vm->get_id() ), array() );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		$this->assertStringNotContainsString( 'public IP address', $this->page() );
	}

	public function test_a_good_post_fills_the_cart_with_one_database_and_goes_to_checkout(): void {
		$result = DbPicker::process( $this->post(), $this->user );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$item = array_values( WC()->cart->get_cart() )[0];
		$this->assertSame( $this->v['Small']->get_id(), (int) $item['variation_id'] );

		// Another plan replaces the earlier try.
		$result = DbPicker::process( $this->post( array( ServerPicker::PLAN => (string) $this->v['Big']->get_id() ) ), $this->user );
		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( $this->v['Big']->get_id(), (int) array_values( WC()->cart->get_cart() )[0]['variation_id'] );
	}

	public function test_a_bad_nonce_an_unknown_plan_and_a_stranger_are_refused(): void {
		$this->assertArrayHasKey( 'form', DbPicker::process( $this->post( array( DbPicker::FIELD => 'nope' ) ), $this->user )['errors'] );
		$this->assertArrayHasKey( 'form', DbPicker::process( $this->post(), 0 )['errors'] );
		$this->assertArrayHasKey( 'plan', DbPicker::process( $this->post( array( ServerPicker::PLAN => '999999' ) ), $this->user )['errors'] );
		$this->assertArrayHasKey( 'plan', DbPicker::process( $this->post( array( ServerPicker::PLAN => '' ) ), $this->user )['errors'] );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_an_out_of_stock_plan_is_refused(): void {
		$this->v['Small']->set_stock_status( 'outofstock' );
		$this->v['Small']->save();

		$this->assertStringContainsString( 'is-disabled', $this->page() );
		$this->assertArrayHasKey( 'plan', DbPicker::process( $this->post(), $this->user )['errors'] );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_visitor_who_is_not_logged_in_goes_to_the_login_and_back(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp']->query_vars['databases'] = 'new';
		$_SERVER['REQUEST_METHOD']              = 'GET';
		$sent                                   = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$sent ) {
				$sent = (string) $location;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			DbPicker::submit();
			$this->fail( 'A visitor is redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			remove_all_filters( 'wp_redirect' );
		}

		$this->assertStringContainsString( 'redirect_to=', $sent );
		$this->assertMatchesRegularExpression( '#databases(/|=)new#', rawurldecode( $sent ) );
	}

	public function test_the_style_and_script_load_only_on_the_new_page(): void {
		$GLOBALS['wp']->query_vars['databases'] = '';
		DbPicker::assets( 'beltoft-services-account', 'databases' );
		$this->assertFalse( wp_style_is( ServerPicker::HANDLE, 'enqueued' ) );

		$GLOBALS['wp']->query_vars['databases'] = 'new';
		DbPicker::assets( 'beltoft-services-account', 'vps' );
		$this->assertFalse( wp_style_is( ServerPicker::HANDLE, 'enqueued' ), 'Not for another endpoint.' );
	}

	// -- The action bars ---------------------------------------------------------------------------

	public function test_the_databases_list_has_the_action_bar_empty_and_with_rows(): void {
		$GLOBALS['wp']->query_vars['databases'] = '';
		ob_start();
		ServiceArea::get( Databases::class )->render( array() );
		$empty = (string) ob_get_clean();

		$this->assertStringContainsString( 'You have no databases yet.', $empty );
		$this->assertStringContainsString( 'beltoft-services-list-actions is-start', $empty );
		$this->assertStringContainsString( 'beltoft-services-list-btn', $empty );
		$this->assertStringContainsString( 'Create a database', $empty );
		$this->assertStringContainsString( esc_url( DbPicker::url() ), $empty );
		$this->assertSame( 1, substr_count( $empty, 'beltoft-services-list-btn' ) );

		// With a row the bar sits over the list, not at the start.
		$db       = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'major' => 18, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'dbx-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $db, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $db->get_id() ), array() );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$view = array( 'id' => (int) $license['id'], 'license' => $license, 'product' => 'Test db' );

		ob_start();
		ServiceArea::get( Databases::class )->render( array( $view ) );
		$rows = (string) ob_get_clean();

		$this->assertStringContainsString( 'Create a database', $rows );
		$this->assertStringNotContainsString( 'is-start', $rows );
		$this->assertStringContainsString( 'beltoft-services-srv', $rows );
		$this->assertLessThan( strpos( $rows, 'beltoft-services-srv' ), strpos( $rows, 'beltoft-services-list-actions' ), 'The bar is over the rows.' );
	}

	public function test_the_servers_list_has_the_action_bar_empty_and_with_rows(): void {
		$GLOBALS['wp']->query_vars['vps'] = '';
		ob_start();
		Servers::render( array() );
		$empty = (string) ob_get_clean();

		$this->assertStringContainsString( 'You have no servers yet.', $empty );
		$this->assertStringContainsString( 'beltoft-services-list-actions is-start', $empty );
		$this->assertStringContainsString( 'Create a server', $empty );
		$this->assertStringContainsString( esc_url( Servers::url_new() ), $empty );
		$this->assertSame( 1, substr_count( $empty, 'beltoft-services-list-btn' ) );

		$vm       = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vmx-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $this->user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $vm->get_id() ), array() );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$view = array( 'id' => (int) $license['id'], 'license' => $license, 'product' => 'Test server' );

		ob_start();
		Servers::render( array( $view ) );
		$rows = (string) ob_get_clean();

		$this->assertStringContainsString( 'Create a server', $rows );
		$this->assertStringNotContainsString( 'is-start', $rows );
		$this->assertLessThan( strpos( $rows, 'beltoft-services-srv' ), strpos( $rows, 'beltoft-services-list-actions' ), 'The bar is over the rows.' );
	}

	public function test_the_apps_list_has_no_action_bar(): void {
		$GLOBALS['wp']->query_vars['apps'] = '';
		ob_start();
		ServiceArea::get( \BeltoftServices\Account\Apps::class )->render( array() );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'beltoft-services-list-actions', $html );
		unset( $GLOBALS['wp']->query_vars['apps'] );
	}

	/**
	 * A second managed database product of an engine, one plan.
	 *
	 * @param string $engine Engine id.
	 * @param string $status Product status.
	 * @return \WC_Product_Variation
	 */
	private function engine_product( string $engine, string $status = 'publish' ): \WC_Product_Variation {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Zz engine ' . $engine );
		$product->set_status( $status );
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Plan' );
		$attr->set_options( array( 'Only' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product->set_attributes( array( $attr ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9200', 'engine' => $engine, 'cores' => '1', 'memory_mb' => '4096', 'disk_gb' => '20' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$v = new \WC_Product_Variation();
		$v->set_parent_id( $product->get_id() );
		$v->set_attributes( array( 'plan' => 'Only' ) );
		$v->set_regular_price( '30' );
		$v->set_status( 'publish' );
		$v->save();

		return $v;
	}

	/**
	 * @param string[] $list List.
	 * @return string[]
	 */
	private function sorted( array $list ): array {
		sort( $list );

		return $list;
	}

	public function test_one_engine_shows_no_engine_row(): void {
		$html = $this->page();

		$this->assertStringNotContainsString( 'data-picker-engines', $html );
		$this->assertStringNotContainsString( 'beltoft_services_db_engine', $html );
		$this->assertCount( 1, DbPicker::engines( DbPicker::plans() ) );
	}

	public function test_two_engines_show_a_tile_each_and_only_the_chosen_engines_plans(): void {
		$valkey = $this->engine_product( 'valkey' );
		$html   = $this->page();

		$this->assertStringContainsString( 'data-picker-engines', $html );
		$this->assertSame( 2, substr_count( $html, 'name="beltoft_services_db_engine"' ) );
		$this->assertStringContainsString( 'Relational database', $html );
		$this->assertStringContainsString( 'In-memory key-value store, Redis compatible', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		// PostgreSQL starts chosen: its two plans show, the Valkey plan is hidden but present.
		$this->assertSame( 2, substr_count( $html, 'data-engine="postgres"' ) );
		$this->assertSame( 1, preg_match( '#<label class="beltoft-services-plan" hidden><input[^>]*value="' . $valkey->get_id() . '"[^>]*data-engine="valkey"#', $html ) );
		$this->assertSame( 0, preg_match( '#<label class="beltoft-services-plan" hidden><input[^>]*value="' . $this->v['Small']->get_id() . '"#', $html ) );
		$this->assertSame( 1, preg_match( '#value="postgres"[^>]* checked#', $html ) );
		$this->assertSame( 1, preg_match( '#value="' . $this->v['Small']->get_id() . '"[^>]* checked#', $html ) );
	}

	public function test_the_engine_in_the_address_preselects_it_and_its_first_plan(): void {
		$valkey          = $this->engine_product( 'valkey' );
		$_GET['engine'] = 'valkey';
		$html            = $this->page();

		$this->assertSame( 1, preg_match( '#value="valkey"[^>]* checked#', $html ) );
		$this->assertSame( 1, preg_match( '#<label class="beltoft-services-plan"><input[^>]*value="' . $valkey->get_id() . '"[^>]* checked#', $html ) );
		$this->assertSame( 2, preg_match_all( '#<label class="beltoft-services-plan" hidden>#', $html ), 'The two PostgreSQL plans are hidden.' );

		// An engine that is not offered is ignored.
		$_GET['engine'] = 'mssql';
		$this->assertSame( 1, preg_match( '#value="postgres"[^>]* checked#', $this->page() ) );
	}

	public function test_an_engine_whose_product_is_private_is_never_shown(): void {
		$this->engine_product( 'valkey', 'private' );
		$_GET['engine'] = 'valkey';
		$html           = $this->page();

		$this->assertSame( array( 'postgres' ), array_keys( DbPicker::engines( DbPicker::plans() ) ) );
		$this->assertStringNotContainsString( 'data-picker-engines', $html );
		$this->assertStringNotContainsString( 'Valkey', $html );
		$this->assertStringNotContainsString( 'In-memory', $html );
	}

	public function test_engines_come_in_registry_order_whatever_order_the_plans_arrive_in(): void {
		$plans = array( array( 'engine' => 'mssql' ), array( 'engine' => 'valkey' ), array( 'engine' => 'postgres' ) );

		$this->assertSame( array( 'postgres', 'valkey', 'mssql' ), \BeltoftServices\Proxmox\DbEngines::ids() );
		$this->assertSame( array( 'postgres', 'valkey', 'mssql' ), array_keys( DbPicker::engines( $plans ) ) );
		$this->assertSame( array( 'postgres', 'valkey', 'mssql' ), array_keys( DbPicker::engines( array_reverse( $plans ) ) ) );
	}

	public function test_a_third_engine_joins_without_code_and_a_plan_of_any_engine_can_be_posted(): void {
		$valkey = $this->engine_product( 'valkey' );
		$mssql  = $this->engine_product( 'mssql' );

		$this->assertSame( array( 'mssql', 'postgres', 'valkey' ), $this->sorted( array_keys( DbPicker::engines( DbPicker::plans() ) ) ) );
		$this->assertStringContainsString( 'Microsoft SQL Server, Express edition', $this->page() );

		$result = DbPicker::process( $this->post( array( ServerPicker::PLAN => (string) $valkey->get_id() ) ), $this->user );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( $valkey->get_id(), (int) array_values( WC()->cart->get_cart() )[0]['variation_id'] );
		$this->assertSame( 'Valkey', DbEngines::name( 'valkey' ) );
		$this->assertSame( 'SQL Server 2025 Express', DbEngines::name( 'mssql' ) );
		$this->assertSame( 'PostgreSQL', DbEngines::name( 'postgres' ) );
		unset( $mssql );
	}
}
