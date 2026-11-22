<?php
/** Domain CLI tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainsCommand;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class CliTest extends TestCase {
	public function test_cli_domain_prices_reports_count(): void {
		$fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/openprovider/tlds-page.json' ), true );
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', $fixture['data'] );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		\WP_CLI::reset();
		( new DomainsCommand() )->domain_prices( array(), array() );
		$this->assertStringContainsString( '7', \WP_CLI::text() );
	}

	private function link( string $domain, string $id, FakeClient $fake ): ?\Throwable {
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'link-domain-test' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		\WP_CLI::reset();
		try {
			( new DomainsCommand() )->link_domain( array( (string) $license['id'], $domain ), array( 'registrar-id' => $id ) );
		} catch ( \RuntimeException $e ) {
			$this->assertNull( \BeltoftServices\Resource::for_license( (int) $license['id'] ), 'Nothing was linked.' );
			return $e;
		}
		$this->assertNotNull( \BeltoftServices\Resource::for_license( (int) $license['id'] ) );
		return null;
	}

	public function test_link_domain_checks_that_the_registrar_id_is_the_domain(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/444', array( 'id' => 444, 'full_name' => 'someone-else.com' ) );
		$error = $this->link( 'linked-example.com', '444', $fake );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'different domain', $error->getMessage() );
	}

	public function test_link_domain_links_when_the_registrar_id_matches_in_either_answer_shape(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/445', array( 'id' => 445, 'domain' => array( 'name' => 'linked-shape', 'extension' => 'com' ) ) );
		$this->assertNull( $this->link( 'Linked-Shape.com', '445', $fake ) );
		$fake2 = new FakeClient();
		$fake2->reply( 'GET', '/domains/446', array( 'id' => 446, 'full_name' => 'linked-name.com' ) );
		$this->assertNull( $this->link( 'linked-name.com', '446', $fake2 ) );
	}

	public function test_link_domain_dry_run_checks_everything_and_links_nothing(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/448', array( 'id' => 448, 'full_name' => 'linked-dry.com' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'link-domain-dry' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		\WP_CLI::reset();

		( new DomainsCommand() )->link_domain( array( (string) $license['id'], 'linked-dry.com' ), array( 'registrar-id' => '448', 'dry-run' => true ) );

		$this->assertStringContainsString( 'Dry run', \WP_CLI::text() );
		$this->assertNull( \BeltoftServices\Resource::for_license( (int) $license['id'] ), 'Nothing was linked.' );

		// The checks still run: a wrong id is refused in a dry run too.
		$wrong = new FakeClient();
		$wrong->reply( 'GET', '/domains/449', array( 'id' => 449, 'full_name' => 'someone-else.com' ) );
		remove_all_filters( 'beltoft_services_domains_client' );
		add_filter( 'beltoft_services_domains_client', static fn() => $wrong );
		$this->expectException( \RuntimeException::class );
		( new DomainsCommand() )->link_domain( array( (string) $license['id'], 'linked-dry.com' ), array( 'registrar-id' => '449', 'dry-run' => true ) );
	}

	public function test_link_domain_stops_when_the_registrar_cannot_confirm(): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/domains/447', new \WP_Error( 'transport', 'down' ) );
		$error = $this->link( 'linked-down.com', '447', $fake );
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'could not confirm', $error->getMessage() );
	}
}
