<?php
/** Pages below an app's page in My Account: path parts, the body and redirect hooks, the inside Open button, the tab strip. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Apps;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Http\Connections;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class AppPagesTest extends TestCase {
	private int $user  = 0;
	private int $other = 0;

	public function set_up(): void {
		parent::set_up();
		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', static fn() => $key );
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['apps'] );
		remove_all_filters( 'beltoft_services_account_page_body' );
		remove_all_filters( 'beltoft_services_account_page_redirect' );
		remove_all_filters( 'beltoft_services_account_app_url' );
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
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function app( int $user, string $name = 'OdooWoo' ): Resource {
		Connections::save(
			'svc',
			array(
				'label'      => 'Service',
				'base_url'   => 'https://api.example.com/v1',
				'auth'       => 'hmac',
				'secret'     => 'secret-secret-secret-1',
				'endpoints'  => array( 'provision' => array( 'method' => 'POST', 'path' => '/tenants' ) ),
				'open_url'   => 'https://app.example.com/open?t={token}',
				'open_label' => 'Open app',
			)
		);
		$product = $this->service_product( 'http', array( 'limit' => 3 ), array( 'connection' => 'svc', 'max' => 3, 'slug' => 'app-' . wp_generate_password( 5, false ) ) );
		$product->set_name( $name );
		$product->save();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'http' ), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function area(): Apps {
		return ServiceArea::get( Apps::class );
	}

	private function go( string $value ): void {
		$GLOBALS['wp']->query_vars['apps'] = $value;
	}

	private function render( Resource ...$resources ): string {
		ob_start();
		$this->area()->render( array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources ) );

		return (string) ob_get_clean();
	}

	private function redirect_target(): string {
		$method = new \ReflectionMethod( ServiceArea::class, 'page_redirect' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	public function test_the_parts_after_the_service_are_cleaned_and_kept(): void {
		$this->assertSame( array( 'my-shop', 'mappings', 'a.b_c', 'b' ), Slugs::segments( array( 'My-Shop', '', 'mappings', '..', 'a.b_c', '<b>' ) ) );

		$this->go( 'odoowoo/my-shop/mappings' );
		$asked = Slugs::parse( 'apps', 'apps' );
		$this->assertSame( 'odoowoo', $asked['first'] );
		$this->assertSame( array( 'my-shop', 'mappings' ), $asked['rest'] );
	}

	public function test_a_page_below_the_service_asks_the_body_hook_for_the_resource_path_and_base(): void {
		$app  = $this->app( $this->user );
		$seen = array();
		add_filter(
			'beltoft_services_account_page_body',
			static function ( $handled, $page ) use ( &$seen ) {
				$seen = $page;
				echo '<p class="drawn-by-other">store page</p>';

				return true;
			},
			10,
			2
		);

		Slugs::for_license( $app->license() );
		$this->go( 'odoowoo/my-shop/mappings' );
		$html = $this->render( $app );

		$this->assertStringContainsString( 'drawn-by-other', $html );
		$this->assertStringContainsString( 'All apps', $html );
		$this->assertStringNotContainsString( 'beltoft-services-http', $html, 'The driver part gave way.' );
		$this->assertSame( $app->id(), $seen['resource']->id() );
		$this->assertSame( array( 'my-shop', 'mappings' ), $seen['path'] );
		$this->assertSame( $this->area()->url( $app->license() ), $seen['base'] );
		$this->assertStringContainsString( 'odoowoo', $seen['base'] );
		$this->assertSame( (int) $app->license()['id'], (int) $seen['license']['id'] );
		$this->assertInstanceOf( Apps::class, $seen['area'] );
		$this->assertArrayHasKey( 'view', $seen );
	}

	public function test_without_a_listener_the_service_page_is_what_it_was(): void {
		$app = $this->app( $this->user );
		Slugs::for_license( $app->license() );
		$this->go( 'odoowoo/whatever' );
		$html = $this->render( $app );

		$this->assertStringContainsString( 'beltoft-services-http', $html );
	}

	public function test_another_customers_service_never_reaches_the_hook_and_is_not_found_like_an_unknown_one(): void {
		$theirs = $this->app( $this->other, 'Theirs' );
		$mine   = $this->app( $this->user, 'Mine' );
		$calls  = 0;
		add_filter(
			'beltoft_services_account_page_body',
			static function ( $handled ) use ( &$calls ) {
				++$calls;

				return $handled;
			}
		);
		$slug = Slugs::for_license( $theirs->license() );
		Slugs::for_license( $mine->license() );

		$this->go( $slug . '/shop' );
		$foreign = $this->render( $mine );
		$this->go( 'nothing-here/shop' );
		$unknown = $this->render( $mine );

		$this->assertSame( $unknown, $foreign );
		$this->assertStringContainsString( 'That app was not found', $foreign );
		$this->assertSame( 0, $calls );

		$this->go( (string) $theirs->license()['id'] . '/shop' );
		$this->assertSame( $unknown, $this->render( $mine ) );
		$this->assertSame( '', $this->redirect_target() );
	}

	public function test_the_redirect_hook_runs_for_the_owner_only_and_may_send_a_301_target(): void {
		$app    = $this->app( $this->user );
		$theirs = $this->app( $this->other, 'Theirs' );
		$given  = array();
		add_filter(
			'beltoft_services_account_page_redirect',
			static function ( $target, $page ) use ( &$given ) {
				$given = $page;

				return 'https://example.org/new/';
			},
			10,
			2
		);
		Slugs::for_license( $app->license() );

		$this->go( 'odoowoo' );
		$this->assertSame( '', $this->redirect_target(), 'The service page itself is not a sub page.' );

		$this->go( 'odoowoo/12' );
		$this->assertSame( 'https://example.org/new/', $this->redirect_target() );
		$this->assertSame( array( '12' ), $given['path'] );
		$this->assertSame( $app->id(), $given['resource']->id() );

		$given = array();
		$this->go( (string) $theirs->license()['id'] . '/12' );
		$this->assertSame( '', $this->redirect_target() );
		$this->assertSame( array(), $given, 'The hook never hears about someone else\'s service.' );

		wp_set_current_user( 0 );
		$this->go( 'odoowoo/12' );
		$this->assertSame( '', $this->redirect_target() );
	}

	public function test_a_license_id_with_parts_redirects_to_the_slug_address_with_the_parts(): void {
		$app = $this->app( $this->user );
		$this->go( (string) $app->license()['id'] . '/my-shop/mappings' );

		$area   = $this->area();
		$target = Slugs::redirect_target( 'apps', 'apps', static fn( array $l, string $t, array $rest = array() ): string => $area->tabs() ? $area->url( $l, $t ) : $area->service_url( $l, $rest ) );

		$this->assertSame( $area->service_url( $app->license(), array( 'my-shop', 'mappings' ) ), $target );
		$this->assertStringContainsString( 'odoowoo', $target );
		$this->assertStringContainsString( 'my-shop', $target );
	}

	public function test_service_urls_add_the_parts_and_the_license_helper_picks_the_area(): void {
		$app = $this->app( $this->user );
		$one = $this->area()->url( $app->license() );

		$this->assertSame( $one, $this->area()->service_url( $app->license() ) );
		$this->set_permalink_structure( '/%postname%/' );
		$one = $this->area()->url( $app->license() );
		$this->assertStringEndsWith( '/apps/odoowoo/', $one );
		$this->assertSame( $one . 'a-b/mappings/', $this->area()->service_url( $app->license(), array( 'a-b', 'mappings' ) ) );
		$this->assertSame( $one . 'a-b/', ServiceArea::url_for_license( $app->license(), array( 'a-b' ) ) );
		$this->assertSame( $one . 'a-b/', $this->area()->service_url( $app->license(), array( 'A-B', '', '..' ) ), 'Parts are cleaned.' );
		$this->set_permalink_structure( '' );

		$plain = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );
		$this->assertSame( '', ServiceArea::url_for_license( $plain, array( 'x' ) ) );
	}

	public function test_open_goes_inside_my_account_when_a_plugin_gives_the_address(): void {
		$app  = $this->app( $this->user );
		$view = View::license( $app->license() );

		$outside = $this->area()->row( $view, $app );
		$this->assertStringContainsString( '%2Fopen', $outside['action']['url'] );
		$this->assertArrayNotHasKey( 'internal', $outside['action'] );

		add_filter( 'beltoft_services_account_app_url', static fn( $url, $resource ) => $resource instanceof Resource ? 'https://example.org/my-account/apps/odoowoo/' : $url, 10, 2 );
		$inside = $this->area()->row( $view, $app );
		$this->assertSame( 'https://example.org/my-account/apps/odoowoo/', $inside['action']['url'] );
		$this->assertTrue( $inside['action']['internal'] );
		$this->assertSame( 'Open app', $inside['action']['label'] );

		$this->go( '' );
		$html = $this->render( $app );
		$this->assertStringContainsString( 'href="https://example.org/my-account/apps/odoowoo/"', $html );
		$this->assertStringNotContainsString( 'data-beltoft-services-open', $html, 'Same window, no new tab.' );
		$this->assertStringNotContainsString( 'target="_blank"', $html );

		$app->set( array( 'state' => 'provisioning' ) );
		$this->assertArrayNotHasKey( 'internal', $this->area()->row( $view, Resource::find( $app->id() ) )['action'] ?? array(), 'Not while it is being set up.' );
	}

	public function test_the_tab_strip_marks_the_active_tab_and_prints_nothing_without_tabs(): void {
		ob_start();
		ServiceArea::tab_nav( array(), 'a', 'Sections' );
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		ServiceArea::tab_nav(
			array(
				'a' => array( 'title' => 'One', 'url' => 'https://example.org/one/' ),
				'b' => array( 'title' => 'Two <b>', 'url' => 'https://example.org/two/' ),
			),
			'b',
			'Store sections'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<nav class="beltoft-services-tabs" aria-label="Store sections">', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( 'aria-current="page">Two &lt;b&gt;</a>', $html );
		$this->assertStringContainsString( 'href="https://example.org/one/">One</a>', $html );
	}

	public function test_an_app_page_shows_no_driver_name(): void {
		$app = $this->app( $this->user );
		Slugs::for_license( $app->license() );
		$this->go( 'odoowoo' );
		$html = $this->render( $app );

		$this->assertStringContainsString( 'beltoft-services-page__name', $html );
		$this->assertStringNotContainsString( 'beltoft-services-page__plan', $html );
		$this->assertStringNotContainsString( Registry::get( 'http' )->label(), $html );
		$this->assertSame( '', $this->area()->label_of( $app ) );
	}
}
