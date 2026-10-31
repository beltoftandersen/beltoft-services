<?php
/**
 * Fixes after the security review of the first phase: parent orders, auto-renew without a card, notices, audit, the
 * consent version of Full grants, schema bounds.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Account\ApiTokens;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Audit;
use BeltoftServices\Mcp\Notices;
use BeltoftServices\Oauth\Grants;
use BeltoftServices\Oauth\Schema;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Review fix tests.
 */
final class ReviewFixesTest extends McpTestCase {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				unset( $pre );
				$this->mails[] = $atts;

				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	public function test_an_order_that_started_a_subscription_is_not_cancelled_but_a_renewal_order_is(): void {
		$user  = self::factory()->user->create();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$parent = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending' ) );
		$sub    = wcs_create_subscription( array( 'customer_id' => $user, 'order_id' => $parent->get_id(), 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertTrue( wcs_order_contains_subscription( $parent, 'parent' ) );

		$refused = $this->tool( 'cancel_unpaid_order', array( 'order_id' => $parent->get_id() ), $token );
		$this->assertTrue( $refused['isError'] );
		$this->assertStringContainsString( 'subscription', $this->text( $refused ) );
		$this->assertSame( 'pending', wc_get_order( $parent->get_id() )->get_status() );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );

		// A subscription that has ended no longer stands in the way.
		$ended = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending' ) );
		$gone  = wcs_create_subscription( array( 'customer_id' => $user, 'order_id' => $ended->get_id(), 'billing_period' => 'year', 'billing_interval' => 1, 'status' => 'cancelled' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $gone );
		$this->assertFalse( $this->tool( 'cancel_unpaid_order', array( 'order_id' => $ended->get_id() ), $token )['isError'] );

		// A renewal order is only an invoice.
		$renewal = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending' ) );
		$renewal->update_meta_data( '_subscription_renewal', $sub->get_id() );
		$renewal->save();
		$this->assertFalse( wcs_order_contains_subscription( $renewal, 'parent' ) );
		$this->assertFalse( $this->tool( 'cancel_unpaid_order', array( 'order_id' => $renewal->get_id() ), $token )['isError'] );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_auto_renew_on_needs_a_default_card_that_can_be_charged(): void {
		update_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle', 'yes' );
		$user  = self::factory()->user->create();
		$sub   = $this->subscription( $user, '12.00', true );
		$token = $this->oauth( $user, Tokens::ACTIONS );
		$args  = array( 'subscription_id' => $sub->get_id(), 'on' => true );

		$none = $this->tool( 'set_auto_renew', $args, $token );
		$this->assertTrue( $none['isError'] );
		$this->assertStringContainsString( 'default saved payment method', $this->text( $none ) );
		$this->assertTrue( (bool) wcs_get_subscription( $sub->get_id() )->is_manual() );

		$card = function ( int $year ) use ( $user ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_token( 'pm_' . wp_generate_password( 8, false ) );
			$token->set_gateway_id( 'bacs' );
			$token->set_user_id( $user );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( (string) $year );
			$token->set_default( true );
			$token->save();

			return $token;
		};

		$expired = $card( 2020 );
		$this->assertTrue( $this->tool( 'set_auto_renew', $args, $token )['isError'], 'An expired default card does not count.' );
		$expired->delete();

		$card( (int) gmdate( 'Y' ) + 3 );
		$sub->set_payment_method( 'bacs' );
		$sub->set_requires_manual_renewal( true );
		$sub->save();
		$ok = $this->tool( 'set_auto_renew', $args, $token );
		$this->assertFalse( $ok['isError'], $this->text( $ok ) );

		// Off never needs a card.
		$this->assertFalse( $this->tool( 'set_auto_renew', array( 'subscription_id' => $sub->get_id(), 'on' => false ), $token )['isError'] );
		delete_option( 'woocommerce_subscriptions_enable_auto_renewal_toggle' );
	}

	public function test_notices_run_nothing_when_there_is_no_session(): void {
		$this->setExpectedIncorrectUsage( 'wc_load_cart' );
		$ran     = false;
		$session = WC()->session;
		$out     = null;

		WC()->session = null;
		add_action(
			'before_woocommerce_init',
			static function () use ( &$ran, &$out ) {
				// wc_load_cart() refuses inside this action and leaves the session unloaded.
				$out = Notices::capture(
					static function () use ( &$ran ) {
						$ran = true;

						return true;
					}
				);
			},
			99
		);
		do_action( 'before_woocommerce_init' );
		WC()->session = $session;

		$this->assertFalse( $ran, 'Nothing was run without notices to read.' );
		$this->assertFalse( $out['ok'] );
		$this->assertFalse( $out['result'] );
		$this->assertNotEmpty( $out['errors'] );
	}

	public function test_a_billing_address_change_keeps_old_and_new_values_tells_the_customer_and_shows_in_security(): void {
		$user     = self::factory()->user->create( array( 'user_email' => 'christian.beltoft@gmail.com' ) );
		$customer = new \WC_Customer( $user );
		$customer->set_billing_country( 'DK' );
		$customer->set_billing_city( 'Odense' );
		$customer->save();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$done = $this->tool( 'update_billing_address', array( 'city' => 'Aarhus' ), $token );
		$this->assertFalse( $done['isError'], $this->text( $done ) );

		$lines = array_column( Audit::lines( $user ), 'text' );
		$this->assertSame( array( 'Claude: billing address changed (city: "Odense" to "Aarhus")' ), $lines );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'christian.beltoft@gmail.com' ), (array) $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Town or city: from "Odense" to "Aarhus"', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'changed your billing address at your request', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '(Claude)', $this->mails[0]['message'], 'No hard-coded app name.' );
		$this->assertStringContainsString( 'disconnect the app', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '—', $this->mails[0]['message'] . $this->mails[0]['subject'] );

		// The same value again is not a change: no line, no mail.
		$this->tool( 'update_billing_address', array( 'city' => 'Aarhus' ), $token );
		$this->assertCount( 1, Audit::lines( $user ) );
		$this->assertCount( 1, $this->mails );

		// The customer sees it in the Security section.
		wp_set_current_user( $user );
		ob_start();
		ApiTokens::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Recent actions by connected apps', $html );
		$this->assertStringContainsString( 'Claude: billing address changed (city: &quot;Odense&quot; to &quot;Aarhus&quot;)', $html );
	}

	public function test_the_audit_keeps_every_line_and_the_last_hundred(): void {
		$user = self::factory()->user->create();

		for ( $i = 1; $i <= 105; $i++ ) {
			Audit::add( $user, 'line ' . $i );
		}

		$lines = Audit::lines( $user );
		$this->assertCount( Audit::KEEP, $lines );
		$this->assertSame( 'Claude: line 105', end( $lines )['text'] );
		$this->assertSame( 'Claude: line 6', $lines[0]['text'] );

		// A line added next to another is its own row: nothing is overwritten.
		$other = self::factory()->user->create();
		add_user_meta( $other, Audit::META, array( 'at' => 1, 'text' => 'Claude: a' ) );
		Audit::add( $other, 'b' );
		$this->assertCount( 2, Audit::lines( $other ) );
	}

	public function test_dns_numbers_have_bounds(): void {
		$domain = $this->domain( self::factory()->user->create() );
		$token  = $this->oauth( (int) $domain->license()['user_id'], Tokens::ACTIONS );
		$base   = array( 'id' => $domain->id(), 'type' => 'MX', 'name' => '@' );
		$record = static fn( array $extra ) => array( 'records' => array( array( 'value' => 'mail.example.net.' ) + $extra ) );

		foreach ( array(
			array( 'ttl' => 59 ) + $record( array() ),
			array( 'ttl' => 86401 ) + $record( array() ),
			$record( array( 'priority' => 65536 ) ),
			$record( array( 'priority' => -1 ) ),
			$record( array( 'weight' => 65536 ) ),
			$record( array( 'port' => 70000 ) ),
			$record( array( 'flag' => 256 ) ),
		) as $extra ) {
			$this->assertTrue( $this->tool( 'dns_upsert_record', $base + $extra, $token )['isError'], wp_json_encode( $extra ) );
		}

		$ok = $this->tool( 'dns_upsert_record', $base + array( 'ttl' => 3600 ) + $record( array( 'priority' => 10 ) ), $token );
		$this->assertFalse( $ok['isError'], $this->text( $ok ) );
	}

	/**
	 * Gives the grants of a customer a stored scope, as an older consent would have left it.
	 */
	private function restore_scope( int $user, string $scope ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->update( Schema::tables()['grants'], array( 'scope' => $scope ), array( 'user_id' => $user ) );
	}

	public function test_a_full_grant_from_an_older_consent_works_as_actions_until_the_customer_reconnects(): void {
		$server = $this->server();
		$user   = $this->owner( $server );
		$fresh  = $this->oauth( $user, Tokens::FULL );

		$this->assertSame( Tokens::FULL, Grants::authenticate( $fresh )['scope'] );
		$this->assertSame( 'full:' . Tokens::FULL_CONSENT, $this->stored( $user ) );

		// The set of Full-only tools changed since: the stored version is an older one.
		$user2 = self::factory()->user->create();
		$token   = $this->oauth( $user2, Tokens::FULL );
		$refresh = $this->last_refresh;
		$client  = $this->last_client;
		$this->restore_scope( $user2, 'full:' . ( Tokens::FULL_CONSENT - 1 ) );

		$grant = Grants::authenticate( $token );
		$this->assertSame( Tokens::ACTIONS, $grant['scope'] );
		$this->assertTrue( $grant['stale_full'] );
		$this->assertTrue( Tokens::stale( 'full' ), 'A full from before versions is stale.' );
		$this->assertFalse( Tokens::stale( 'full:' . Tokens::FULL_CONSENT ) );

		// The connection list says so, and the level it works at is not full.
		$apps = Grants::connections( $user2 );
		$this->assertSame( Tokens::FULL, $apps[0]['scope'] );
		$this->assertTrue( $apps[0]['stale'] );
		$instructions = (string) $this->rpc( 'initialize', array( 'protocolVersion' => '2025-06-18' ), $token )['result']['instructions'];
		$this->assertStringContainsString( 'everyday actions', $instructions );
		$this->assertStringNotContainsString( 'This connection has Full access', $instructions );

		wp_set_current_user( $user2 );
		ob_start();
		ApiTokens::render();
		$this->assertStringContainsString( 'reconnect to use full access', (string) ob_get_clean() );

		// A refresh does not upgrade it.
		$data = Grants::refresh( $refresh, $client );
		$this->assertIsArray( $data );
		$this->assertSame( Tokens::ACTIONS, Grants::authenticate( $data['access_token'] )['scope'] );
	}

	private function stored( int $user ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT scope FROM %i WHERE user_id = %d AND type = %s LIMIT 1', Schema::tables()['grants'], $user, 'refresh' ) );
	}
}
