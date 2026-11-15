<?php
/**
 * Fixes found by a click-through of the customer dashboard (0.31.2): the step-up URL of the page scripts, the hosting
 * watcher, the claim dates and the words of the mail state.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Api\Routes;
use BeltoftServices\Domains\DnsClaimRoutes;
use BeltoftServices\Mail\MailDns;

/**
 * Click-through fixes tests.
 */
final class ClickthroughFixesTest extends TestCase {

	private function js( string $name ): string {
		return (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/' . $name );
	}

	public function test_the_step_up_route_is_not_below_resources_and_the_scripts_use_the_full_address(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Routes::NAMESPACE . '/step-up/send', $routes );
		$this->assertStringContainsString( "rest_url( Routes::NAMESPACE . '/step-up/send' )", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Account/Panel.php' ) );

		$seen = 0;

		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/*.js' ) as $file ) {
			$code = (string) file_get_contents( $file );

			if ( false === strpos( $code, 'step-up' ) && false === strpos( $code, 'stepUpUrl' ) ) {
				continue;
			}

			++$seen;
			$this->assertDoesNotMatchRegularExpression( '/root\s*\+\s*[\'"]step-up/', $code, basename( $file ) . ' joins step-up to the resources root.' );
		}

		$this->assertGreaterThanOrEqual( 2, $seen );
		$this->assertStringContainsString( 'account.stepUpUrl', $this->js( 'hosting.js' ) );
		$this->assertStringContainsString( 'config.stepUpUrl', $this->js( 'domain.js' ) );
	}

	public function test_the_hosting_watcher_polls_slowly_backs_off_and_clears_the_rows_when_the_task_is_gone(): void {
		$js = $this->js( 'hosting.js' );

		$api = $this->js( 'api.js' );

		// The pace and the back-off live in the shared follow() (until: 'idle'), which the watcher uses.
		$this->assertStringContainsString( "until: 'idle'", $js );
		$this->assertStringContainsString( '5000', $api );
		$this->assertStringContainsString( '429 === reply.status', $api );
		$this->assertStringContainsString( 'Retry-After', $api );
		$this->assertStringContainsString( 'data-idle-text', $js, 'A row goes back to what it said before the task.' );
		$this->assertStringContainsString( 'cacheFacts();', $js );
	}

	public function test_a_claim_carries_its_dates_in_the_site_format(): void {
		update_option( 'date_format', 'd/m/Y' );
		update_option( 'time_format', 'H:i' );

		$view = DnsClaimRoutes::view(
			array(
				'id'            => 1,
				'user_id'       => 1,
				'domain'        => 'example.com',
				'status'        => 'pending',
				'last_result'   => array(),
				'expires_at'    => '2026-11-17 16:04:17',
				'checked_at'    => '2026-11-16 09:05:00',
				'ds_present'    => null,
				'draft_records' => array(),
			)
		);

		$this->assertSame( wp_date( 'd/m/Y H:i', strtotime( '2026-11-17 16:04:17 UTC' ) ), $view['expires_label'] );
		$this->assertSame( wp_date( 'H:i', strtotime( '2026-11-16 09:05:00 UTC' ) ), $view['checked_label'] );
		$this->assertStringContainsString( 'claim.expires_label', $this->js( 'dns-claim.js' ) );
	}

	public function test_a_dns_look_ends_with_a_sentence(): void {
		$all = array();

		foreach ( MailDns::NEEDED as $key ) {
			$all[] = array( 'key' => $key, 'status' => 'ok' );
		}

		$this->assertStringContainsString( 'All the needed DNS records were found', MailDns::check_text( $all ) );

		$all[0]['status'] = 'missing';
		$this->assertStringContainsString( '1 needed DNS record is not found yet', MailDns::check_text( $all ) );

		$all[1]['status'] = 'missing';
		$this->assertStringContainsString( '2 needed DNS records are not found yet', MailDns::check_text( $all ) );

		$js = $this->js( 'mail.js' );
		$this->assertStringContainsString( 'outcome.text || strings.checked', $js );
		$this->assertStringContainsString( 'strings.proofNot', $js );
	}
}
