<?php
/** Readable My Account addresses: slugs per customer, numeric ids redirected, tabs, migration. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Databases;
use BeltoftServices\Account\DomainRows;
use BeltoftServices\Account\Domains;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Servers;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\ServiceEmail;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class SlugsTest extends TestCase {
	private int $user  = 0;
	private int $other = 0;

	public function set_up(): void {
		parent::set_up();
		$this->user  = self::factory()->user->create();
		$this->other = self::factory()->user->create();
		wp_set_current_user( $this->user );
		Overview::reset();
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'], $GLOBALS['wp']->query_vars['domains'], $GLOBALS['wp']->query_vars['databases'] );
		unset( $_SERVER['QUERY_STRING'] );
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
		remove_all_filters( 'beltoft_services_server_page_tabs' );
		delete_option( Slugs::OPTION );
		wp_set_current_user( 0 );
		Overview::reset();
		parent::tear_down();
	}

	private function server( int $user, string $name = '', string $product_name = 'Small VPS' ): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$product->set_name( $product_name );
		$product->save();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), '' !== $name ? array( ServerName::META => $name ) : array() );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function database( int $user, string $product_name = 'Managed database' ): Resource {
		$product = $this->service_product( 'proxmox-db', array( 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'db-' . wp_generate_password( 5, false ) ) );
		$product->set_name( $product_name );
		$product->save();
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array( 'db_state' => 'ready', 'db_address' => '10.64.0.2' ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function domain( int $user, string $name ): Resource {
		$product  = $this->service_product( 'domain', array(), array( 'slug' => 'domain-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$resource = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => $name, 'mode' => 'register' ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $resource->id() );
	}

	private function slug( Resource $r ): string {
		return Slugs::for_license( $r->license() );
	}

	/** @return array<int, array<string, mixed>> */
	private function views( Resource ...$resources ): array {
		return array_map( static fn( Resource $r ): array => View::license( $r->license() ), $resources );
	}

	private function go( string $endpoint, string $value ): void {
		$GLOBALS['wp']->query_vars[ $endpoint ] = $value;
	}

	// -- Slug creation -------------------------------------------------------------------------

	public function test_a_server_slug_is_its_name_and_a_nameless_one_uses_the_product_name(): void {
		$named = $this->server( $this->user, 'web1' );
		$bare  = $this->server( $this->user, '', 'Small VPS' );

		$this->assertSame( 'web1', $this->slug( $named ) );
		$this->assertSame( 'small-vps', $this->slug( $bare ) );
		$this->assertSame( 'web1', Resource::find( $named->id() )->get( Slugs::META ), 'Stored on the resource.' );
	}

	public function test_a_slug_never_changes_with_the_display_name(): void {
		$server = $this->server( $this->user, 'web1' );
		$this->assertSame( 'web1', $this->slug( $server ) );

		$server->set( array( ServerName::META => 'api' ) );
		$this->assertSame( 'web1', $this->slug( Resource::find( $server->id() ) ), 'A renamed server keeps its address.' );
		$this->assertMatchesRegularExpression( '#vps(/|=)web1/?$#', rawurldecode( Servers::url( $server->license() ) ) );
	}

	public function test_slugs_are_unique_per_customer_and_type(): void {
		$one   = $this->server( $this->user, 'web1' );
		$two   = $this->server( $this->user, 'web1' );
		$three = $this->server( $this->user, 'web1' );
		$theirs = $this->server( $this->other, 'web1' );

		$this->assertSame( 'web1', $this->slug( $one ) );
		$this->assertSame( 'web1-2', $this->slug( $two ) );
		$this->assertSame( 'web1-3', $this->slug( $three ) );
		$this->assertSame( 'web1', $this->slug( $theirs ), 'Another customer has their own web1.' );

		$db = $this->database( $this->user, 'web1' );
		$this->assertSame( 'web1', $this->slug( $db ), 'Another type has its own web1.' );
	}

	public function test_names_are_made_into_safe_slugs(): void {
		$this->assertSame( 'my-orders-db', Slugs::slugify( 'My Orders_DB!', 'databases' ) );
		$this->assertSame( 'example.dk', Slugs::slugify( 'Example.DK', 'domains' ) );
		$this->assertSame( 'sub.example.co.uk', Slugs::slugify( 'sub.example.co.uk', 'domains' ) );
		$this->assertSame( 'example-dk', Slugs::slugify( 'example.dk', 'servers' ), 'Only a domain keeps its dots.' );
		$this->assertSame( '', Slugs::slugify( '///', 'databases' ) );

		$odd = $this->database( $this->user, '!!!' );
		$this->assertSame( 'database', $this->slug( $odd ), 'A name with nothing usable falls back to the word for the type.' );
	}

	public function test_a_domain_keeps_its_dots(): void {
		$domain = $this->domain( $this->user, 'Example.dk' );

		$this->assertSame( 'example.dk', $this->slug( $domain ) );
		$this->assertMatchesRegularExpression( '#domains(/|=)example\.dk/?$#', rawurldecode( Domains::url( $domain->license() ) ) );
		$this->assertMatchesRegularExpression( '#domains(/|=)example\.dk(/|%2F)dns/?$#', Domains::url( $domain->license(), 'dns' ) );
	}

	public function test_reserved_words_and_numbers_are_skipped(): void {
		$this->assertTrue( Slugs::reserved( 'new', 'servers' ) );
		$this->assertTrue( Slugs::reserved( 'add', 'domains' ) );
		$this->assertTrue( Slugs::reserved( 'snapshots', 'servers' ) );
		$this->assertTrue( Slugs::reserved( 'overview', 'servers' ) );
		$this->assertTrue( Slugs::reserved( '123', 'servers' ) );
		$this->assertTrue( Slugs::reserved( 'backups', 'databases' ) );
		$this->assertFalse( Slugs::reserved( 'web1', 'servers' ) );

		$new   = $this->server( $this->user, 'new' );
		$disks = $this->server( $this->user, 'disks' );
		$num   = $this->server( $this->user, '2024' );

		$this->assertSame( 'new-2', $this->slug( $new ) );
		$this->assertSame( 'disks-2', $this->slug( $disks ) );
		$this->assertSame( '2024-2', $this->slug( $num ) );
		add_filter( 'beltoft_services_server_page_tabs', static fn( $tabs ) => array_merge( $tabs, array( 'extra' ) ) );
		$this->assertTrue( Slugs::reserved( 'extra', 'servers' ), 'A tab an extension adds is reserved too.' );
	}

	public function test_a_license_that_is_not_a_service_with_a_page_has_no_slug_and_uses_its_id(): void {
		$this->assertSame( '', Slugs::for_license( array( 'id' => 424242 ) ) );
		$this->assertMatchesRegularExpression( '#domains(/|=)42/?$#', rawurldecode( Domains::url( array( 'id' => 42 ) ) ) );
	}

	// -- Resolution ----------------------------------------------------------------------------

	public function test_the_address_resolves_a_slug_a_number_and_the_tab(): void {
		$server = $this->server( $this->user, 'web1' );
		$id     = (int) $server->license()['id'];
		$this->slug( $server );

		$this->go( 'vps', 'web1' );
		$this->assertSame( array( 'id' => $id, 'tab' => '' ), Servers::requested() );
		$this->go( 'vps', 'web1/snapshots/' );
		$this->assertSame( array( 'id' => $id, 'tab' => 'snapshots' ), Servers::requested() );
		$this->go( 'vps', 'WEB1/nonsense' );
		$this->assertSame( array( 'id' => $id, 'tab' => '' ), Servers::requested() );
		$this->go( 'vps', $id . '/disks' );
		$this->assertSame( array( 'id' => $id, 'tab' => 'disks' ), Servers::requested() );
		$this->go( 'vps', 'nobody' );
		$this->assertSame( array( 'id' => 0, 'tab' => '' ), Servers::requested() );
	}

	public function test_the_new_pages_still_work(): void {
		$this->server( $this->user, 'new' );
		$this->go( 'vps', 'new' );
		$this->assertTrue( Servers::is_new() );
		$this->assertSame( 0, Servers::requested()['id'] );
		$this->go( 'databases', 'new' );
		$this->assertTrue( ServiceArea::get( Databases::class )->is_new() );
		$this->go( 'domains', 'add' );
		$this->assertTrue( Domains::requested()['add'] );
	}

	public function test_a_slug_of_another_customers_service_is_not_found(): void {
		$mine   = $this->server( $this->user, 'mine' );
		$theirs = $this->server( $this->other, 'secret' );
		$this->slug( $mine );
		$this->slug( $theirs );

		$this->go( 'vps', 'secret' );
		$this->assertSame( 0, Servers::requested()['id'] );

		ob_start();
		Servers::render( $this->views( $mine ) );
		$other_html = (string) ob_get_clean();

		$this->go( 'vps', 'does-not-exist' );
		ob_start();
		Servers::render( $this->views( $mine ) );
		$missing_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'That server was not found', $other_html );
		$this->assertSame( $missing_html, $other_html, 'Exactly like a name that does not exist.' );
		$this->assertSame( '', Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ) );
	}

	public function test_another_customers_number_is_not_found_and_not_redirected(): void {
		$theirs = $this->server( $this->other, 'secret' );
		$this->slug( $theirs );
		$this->go( 'vps', (string) $theirs->license()['id'] );

		$this->assertSame( '', Servers::redirect_target() );
		$this->assertSame( '', Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ) );
		ob_start();
		Servers::render( array() );
		$this->assertStringContainsString( 'That server was not found', (string) ob_get_clean() );
	}

	public function test_the_pages_render_from_a_slug_for_each_type(): void {
		$db = $this->database( $this->user, 'Orders DB' );
		$this->slug( $db );
		$this->go( 'databases', 'orders-db' );
		ob_start();
		ServiceArea::get( Databases::class )->render( $this->views( $db ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'beltoft-services-area-page--databases', $html );

		$domain = $this->domain( $this->user, 'example.dk' );
		$this->slug( $domain );
		$this->go( 'domains', 'example.dk/dns' );
		$this->assertSame( array( (int) $domain->license()['id'], 'dns' ), array( Domains::requested()['id'], Domains::requested()['tab'] ) );

		$this->go( 'databases', 'nope' );
		ob_start();
		ServiceArea::get( Databases::class )->render( $this->views( $db ) );
		$this->assertStringContainsString( 'That database was not found', (string) ob_get_clean() );
	}

	// -- Redirect ------------------------------------------------------------------------------

	public function test_a_license_id_redirects_to_the_slug_address_keeping_the_tab(): void {
		$server = $this->server( $this->user, 'web1' );
		$id     = (string) $server->license()['id'];

		$this->go( 'vps', $id );
		$this->assertSame( Servers::url( $server->license() ), Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ) );

		$this->go( 'vps', $id . '/snapshots' );
		$this->assertSame( Servers::url( $server->license(), 'snapshots' ), Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ) );

		$_SERVER['QUERY_STRING'] = 'plan=7';
		$this->assertSame( add_query_arg( 'plan', '7', Servers::url( $server->license(), 'snapshots' ) ), Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ) );
		unset( $_SERVER['QUERY_STRING'] );

		$this->go( 'vps', 'web1' );
		$this->assertSame( '', Slugs::redirect_target( 'vps', 'servers', array( Servers::class, 'url' ) ), 'A slug address stays.' );
	}

	public function test_the_redirect_for_a_database_and_a_domain(): void {
		$db     = $this->database( $this->user, 'Orders DB' );
		$domain = $this->domain( $this->user, 'example.dk' );
		$area   = ServiceArea::get( Databases::class );

		$this->go( 'databases', (string) $db->license()['id'] . '/backups' );
		$this->assertSame( $area->url( $db->license(), 'backups' ), Slugs::redirect_target( 'databases', 'databases', fn( array $l, string $t ): string => $area->url( $l, $t ) ) );
		$this->assertStringContainsString( 'orders-db', $area->url( $db->license() ) );

		$this->go( 'domains', (string) $domain->license()['id'] );
		$this->assertSame( Domains::url( $domain->license() ), Slugs::redirect_target( 'domains', 'domains', array( Domains::class, 'url' ) ) );
	}

	// -- Links ---------------------------------------------------------------------------------

	public function test_rows_emails_and_follow_links_use_the_slug(): void {
		$server = $this->server( $this->user, 'web1' );
		$db     = $this->database( $this->user, 'Orders DB' );
		$domain = $this->domain( $this->user, 'example.dk' );
		$area   = ServiceArea::get( Databases::class );

		$this->assertSame( $area->url( $db->license() ), $area->row( $this->views( $db )[0], $db )['url'] );
		$this->assertSame( Domains::url( $domain->license() ), DomainRows::row( $this->views( $domain )[0], $domain )['url'] );

		$method = new \ReflectionMethod( ServiceEmail::class, 'account_url' );
		$method->setAccessible( true );
		$this->assertSame( Servers::url( $server->license() ), $method->invoke( null, $server, $server->license(), array() ) );
		$this->assertSame( $area->url( $db->license() ), $method->invoke( null, $db, $db->license(), array() ) );
		$this->assertSame( Domains::url( $domain->license() ), $method->invoke( null, $domain, $domain->license(), array() ) );
		$this->assertStringNotContainsString( '/' . $server->license()['id'] . '/', Servers::url( $server->license() ) );
	}

	// -- Migration -----------------------------------------------------------------------------

	public function test_the_migration_gives_every_service_a_slug_and_is_idempotent(): void {
		$one    = $this->server( $this->user, 'web1' );
		$two    = $this->server( $this->user, 'web1' );
		$db     = $this->database( $this->user );
		$domain = $this->domain( $this->other, 'example.dk' );

		$this->assertSame( '', Slugs::stored( $one ) );
		$this->assertSame( 4, Slugs::migrate() );

		$slugs = array( Slugs::stored( Resource::find( $one->id() ) ), Slugs::stored( Resource::find( $two->id() ) ) );
		sort( $slugs );
		$this->assertSame( array( 'web1', 'web1-2' ), $slugs );
		$this->assertSame( 'managed-database', Slugs::stored( Resource::find( $db->id() ) ) );
		$this->assertSame( 'example.dk', Slugs::stored( Resource::find( $domain->id() ) ) );

		$this->assertSame( 0, Slugs::migrate(), 'A second run changes nothing.' );
		$this->assertSame( $slugs, array( Slugs::stored( Resource::find( $one->id() ) ), Slugs::stored( Resource::find( $two->id() ) ) ) );
		$this->assertSame( Slugs::VERSION, (int) get_option( Slugs::OPTION ) );
	}

	public function test_the_migration_runs_once_on_an_upgrade(): void {
		delete_option( Slugs::OPTION );
		$server = $this->server( $this->user, 'web1' );
		Slugs::maybe_migrate();
		$this->assertSame( 'web1', Slugs::stored( Resource::find( $server->id() ) ) );

		$later = $this->server( $this->user, 'later' );
		Slugs::maybe_migrate();
		$this->assertSame( '', Slugs::stored( Resource::find( $later->id() ) ), 'Not again once done; a new one is slugged when its address is built.' );
		$this->assertSame( 'later', $this->slug( $later ) );
	}
}
