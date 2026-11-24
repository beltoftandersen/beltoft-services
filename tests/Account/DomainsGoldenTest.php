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

require_once dirname( __DIR__ ) . '/Support/DomainsGolden.php';

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
use BeltoftServices\Tests\Support\DomainsGolden;
use BeltoftServices\Tests\TestCase;

final class DomainsGoldenTest extends TestCase {
	use DomainsGolden;

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
		// The hosting area's two pages are pinned by the hosting tests.
		$this->compare( 'domains', $this->render_all( array( 'databases', 'email', 'ssl-certificates', 'apps', 'licences' ) ) );
	}

	public function test_every_address_form_and_link_answers_as_before(): void {
		$now = $this->table();

		// The order lists name the areas of an optional feature (hosting); its position is pinned by that feature's own tests.
		foreach ( array( 'groups|order', 'menu|always' ) as $key ) {
			$now[ $key ] = implode( ',', array_diff( explode( ',', $now[ $key ] ), $this->optional( 'area_slugs' ) ) );
		}

		$this->compare( 'domains-table', $now );
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
