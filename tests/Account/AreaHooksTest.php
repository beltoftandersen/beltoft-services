<?php
/** The overridable hooks of ServiceArea: defaults equal the old behaviour; each hook changes what it says. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Databases;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class AreaHooksTest extends TestCase {
	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_double_page_tabs' );
		remove_all_filters( 'beltoft_services_databases_page_tabs' );
		remove_all_filters( 'beltoft_services_account_page_chrome' );
		remove_all_filters( 'beltoft_services_account_page_body' );
		unset( $GLOBALS['wp']->query_vars['databases'] );
		parent::tear_down();
	}

	private function call( ServiceArea $area, string $method, ...$args ) {
		$m = new \ReflectionMethod( $area, $method );
		$m->setAccessible( true );

		return $m->invoke( $area, ...$args );
	}

	private function double(): ServiceArea {
		return new class() extends ServiceArea {
			protected function default_slug(): string { return 'double'; }
			protected function kinds(): array { return array( 'fake' ); }
			public function label(): string { return 'Doubles'; }
			protected function empty_text(): string { return 'No doubles.'; }
			public function all_label(): string { return 'All doubles'; }
			protected function not_found_text(): string { return 'Not found.'; }
			public function row( array $view, Resource $resource ): array { return array(); }
			protected function key(): string { return 'double'; }
			protected function page_tabs(): array { return array( 'one' => 'One' ); }
			protected function template_name(): string { return 'double.php'; }
			protected function list_name(): string { return 'double-list.php'; }
			protected function visibility( ?Resource $resource ): string { return self::HIDDEN; }
			protected function detail_classes( bool $chrome ): string { return 'double-box' . ( $chrome ? '' : ' bare' ); }
			protected function page_tabs_filter(): string { return 'beltoft_services_double_page_tabs'; }
			public function allows_plugin_pages(): bool { return false; }
			protected function head_vars( Resource $resource, array $vars ): array { return array( 'kind' => 'X' ) + $vars; }
		};
	}

	public function test_the_defaults_equal_the_old_behaviour_of_the_existing_areas(): void {
		foreach ( ServiceArea::all() as $area ) {
			$name = get_class( $area );
			$this->assertSame( 'area.php', $this->call( $area, 'template_name' ), $name );
			$this->assertSame( 'area-list.php', $this->call( $area, 'list_name' ), $name );
			$this->assertTrue( $area->allows_plugin_pages(), $name );
			$this->assertSame( 'beltoft_services_' . $area->key_name() . '_page_tabs', $this->call( $area, 'page_tabs_filter' ), $name );
			$this->assertSame( 'beltoft-services-server beltoft-services-area-page beltoft-services-area-page--' . $area->key_name(), $this->call( $area, 'detail_classes', true ), $name );
			$this->assertSame( 'beltoft-services-server beltoft-services-area-page beltoft-services-area-page--' . $area->key_name() . ' beltoft-services-area-page--bare', $this->call( $area, 'detail_classes', false ), $name );
			$this->assertSame( array( 'a' => 1 ), $this->call( $area, 'head_vars', $this->some_resource(), array( 'a' => 1 ) ), $name );
			$this->assertSame( ServiceArea::GONE, $this->call( $area, 'visibility', null ), $name );
		}
	}

	private function some_resource(): Resource {
		$this->fake_driver();
		$license = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );

		return Resource::create( $license, \BeltoftServices\Drivers\Registry::get( 'fake' ), array(), array( 'state' => 'ready' ) );
	}

	public function test_a_live_resource_is_shown_and_a_deleted_one_is_gone_by_default(): void {
		$area     = ServiceArea::get( Databases::class );
		$resource = $this->some_resource();

		$this->assertSame( ServiceArea::SHOWN, $this->call( $area, 'visibility', $resource ) );
		$resource->set( array( 'state' => 'deleted' ) );
		$this->assertSame( ServiceArea::GONE, $this->call( $area, 'visibility', Resource::find( $resource->id() ) ) );
	}

	public function test_each_hook_changes_what_it_says_on_a_double(): void {
		$area     = $this->double();
		$resource = $this->some_resource();

		$this->assertSame( 'double.php', $this->call( $area, 'template_name' ) );
		$this->assertSame( 'double-list.php', $this->call( $area, 'list_name' ) );
		$this->assertSame( ServiceArea::HIDDEN, $this->call( $area, 'visibility', $resource ) );
		$this->assertSame( 'double-box bare', $this->call( $area, 'detail_classes', false ) );
		$this->assertFalse( $area->allows_plugin_pages() );
		$this->assertSame( 'X', $this->call( $area, 'head_vars', $resource, array( 'kind' => 'Y' ) )['kind'] );

		// The tab filter has its own name.
		add_filter( 'beltoft_services_double_page_tabs', static fn( $t ) => array_merge( $t, array( 'two' ) ) );
		$this->assertSame( array( 'one', 'two' ), $area->tabs() );
	}
}
