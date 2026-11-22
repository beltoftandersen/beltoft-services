<?php
/**
 * The seams between the platform's API code and the features: the filters the features answer so that Api\ names none of them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Api\OpenApi;
use BeltoftServices\Api\Reference;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Proxmox\Disk\UsageDisk;
use BeltoftServices\Proxmox\Network\PowerWatch;

/**
 * Tests.
 */
final class ApiSeamsTest extends TestCase {

	/**
	 * The expression Tokens used before the features added their own parts (a literal, kept to prove nothing changed).
	 */
	private const OLD_ALLOWED = '#^/beltoft-services/v1/(resources(/\d+(/(metrics|db-ca|dns-records(/[A-Za-z]+/[^/]+)?|actions/[a-z0-9_-]+|ssl-certificate|mail(/(mailboxes|aliases(/[^/]+)?|dns/check))?))?)?|usage|activity|domains/check|openapi\.json)$#D';

	private function allowed(): string {
		$method = new \ReflectionMethod( Tokens::class, 'allowed' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	public function test_the_token_expression_accepts_and_refuses_what_it_always_did(): void {
		$base   = '/beltoft-services/v1';
		$routes = array(
			'/resources', '/resources/12', '/resources/12/metrics', '/resources/12/db-ca', '/resources/12/dns-records', '/resources/12/dns-records/A/www',
			'/resources/12/dns-records/A', '/resources/12/actions/start', '/resources/12/actions/Start', '/resources/12/ssl-certificate', '/resources/12/mail',
			'/resources/12/mail/mailboxes', '/resources/12/mail/mailboxes/a@b.c', '/resources/12/mail/mailboxes/a@b.c/password', '/resources/12/mail/aliases',
			'/resources/12/mail/aliases/x', '/resources/12/mail/aliases/x/y', '/resources/12/mail/dns/check', '/resources/12/mail/dns', '/resources/12/mail-reveal',
			'/resources/12/grow', '/resources/12/open', '/resources/12/delete', '/resources/12/firewall', '/resources/12/db-access', '/resources/12/storage',
			'/resources/x', '/usage', '/activity', '/domains/check', '/domains/cart', '/openapi.json', '/openapi', '/step-up/code', '/my', '',
		);

		foreach ( $routes as $route ) {
			$this->assertSame(
				preg_match( self::OLD_ALLOWED, $base . $route ),
				preg_match( $this->allowed(), $base . $route ),
				'Route ' . $route
			);
		}
	}

	public function test_the_features_add_their_parts_to_the_token_expression(): void {
		$parts = apply_filters( 'beltoft_services_token_path_fragments', array( 'resource' => array(), 'root' => array() ) );

		foreach ( array( 'db-ca', 'dns-records(/[A-Za-z]+/[^/]+)?', 'ssl-certificate', 'mail(/(mailboxes|aliases(/[^/]+)?|dns/check))?' ) as $part ) {
			$this->assertContains( $part, $parts['resource'] );
		}

		$this->assertSame( array( 'domains/check' ), $parts['root'] );
	}

	public function test_the_openapi_table_holds_the_same_operations_as_before(): void {
		$keys = array_keys( OpenApi::operations() );
		sort( $keys );

		$this->assertSame(
			array(
				'DELETE /resources/{id}/dns-records/{type}/{name}',
				'DELETE /resources/{id}/mail/aliases/{address}',
				'GET /activity',
				'GET /domains/check',
				'GET /openapi.json',
				'GET /resources',
				'GET /resources/{id}',
				'GET /resources/{id}/dns-records',
				'GET /resources/{id}/mail',
				'GET /resources/{id}/metrics',
				'GET /resources/{id}/ssl-certificate',
				'GET /usage',
				'POST /resources/{id}/actions/{action}',
				'POST /resources/{id}/mail/aliases',
				'POST /resources/{id}/mail/dns/check',
				'POST /resources/{id}/mail/mailboxes',
				'PUT /resources/{id}/dns-records/{type}/{name}',
			),
			$keys
		);
	}

	public function test_the_reference_groups_come_in_the_old_order(): void {
		$method = new \ReflectionMethod( Reference::class, 'group_texts' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'services', 'servers', 'databases', 'dns', 'email', 'ssl', 'storage', 'domains', 'usage', 'reference' ),
			array_keys( $method->invoke( null ) )
		);
	}

	public function test_a_feature_group_takes_its_paths_and_a_stranger_goes_to_services(): void {
		$method = new \ReflectionMethod( Reference::class, 'group_of' );
		$method->setAccessible( true );

		foreach ( array(
			'/resources/{id}/metrics'            => 'servers',
			'/resources/{id}/firewall'           => 'servers',
			'/resources/{id}/db-access'          => 'databases',
			'/resources/{id}/dns-records'        => 'dns',
			'/resources/{id}/mail/mailboxes'     => 'email',
			'/resources/{id}/ssl-certificate'    => 'ssl',
			'/resources/{id}/storage-rollback'   => 'storage',
			'/resources/{id}/usage-alerts'       => 'usage',
			'/resources/{id}/actions/{action}'   => 'services',
			'/resources/{id}'                    => 'services',
			'/domains/check'                     => 'domains',
			'/usage'                             => 'usage',
			'/openapi.json'                      => 'reference',
		) as $path => $group ) {
			$this->assertSame( $group, $method->invoke( null, $path ), $path );
		}
	}

	public function test_the_status_reply_listens_for_the_power_filter(): void {
		$this->assertSame( 10, has_filter( 'beltoft_services_status_extra', array( PowerWatch::class, 'status_extra' ) ) );
	}

	public function test_the_grow_route_is_registered_by_the_disk_with_the_same_path(): void {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$key    = '/beltoft-services/v1/resources/(?P<id>\d+)/grow';

		$this->assertArrayHasKey( $key, $routes );
		$this->assertSame( array( UsageDisk::class, 'rest_grow' ), $routes[ $key ][0]['callback'] );
		$this->assertArrayHasKey( 'POST', $routes[ $key ][0]['methods'] );
		$this->assertFalse( method_exists( \BeltoftServices\Api\Routes::class, 'grow' ), 'The platform no longer holds the disk route.' );
	}
}
