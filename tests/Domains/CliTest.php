<?php
/** Domain CLI tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Cli\Commands;
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
		( new Commands() )->domain_prices( array(), array() );
		$this->assertStringContainsString( '2', \WP_CLI::text() );
	}
}
