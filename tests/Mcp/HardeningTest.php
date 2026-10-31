<?php
/**
 * The hardening of the confirmation: the wait, the e-mail about what was done, the REST blocklist, wide rule sets, the
 * limits of the log, the facts in the summaries and the limit of waiting confirmations.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Audit;
use BeltoftServices\Mcp\Confirmations;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Oauth\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Hardening tests.
 */
final class HardeningTest extends McpTestCase {

	/**
	 * @var FakeDriver
	 */
	private $driver;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	/**
	 * A fake-driver server that offers the given actions, with the customer's own e-mail on its license.
	 *
	 * @param string[] $actions Action names.
	 */
	private function offering( array $actions, string $email = 'christian.beltoft@gmail.com' ): Resource {
		$this->driver                   = $this->fake_driver();
		$this->driver->customer_actions = array_combine( $actions, $actions );
		$row                            = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'email' => $email ) );

		return Resource::create( $row, $this->driver, array(), array( 'state' => 'ready' ) );
	}

	/**
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * @param array<string, mixed> $set Columns.
	 */
	private function update_rows( array $set, string $where = '1 = 1' ): void {
		global $wpdb;

		foreach ( $set as $column => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Test fixture.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %d WHERE ' . $where, Schema::tables()['confirms'], $column, $value ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snap( Resource $r, string $id = 'abcd1234' ): array {
		return array( 'id' => $r->id(), 'snapshot' => $id );
	}

	// -- 1a: the wait --------------------------------------------------------------------------

	public function test_a_confirmation_that_comes_too_soon_is_refused_and_keeps_the_token(): void {
		remove_all_filters( 'beltoft_services_mcp_confirm_delay' );
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) );

		$soon = $this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $soon['isError'] );
		$this->assertStringContainsString( 'Too soon', $this->text( $soon ) );
		$this->assertStringContainsString( 'Show the summary to the customer, ask', $this->text( $soon ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );
		$this->assertContains( 'Claude: delete snapshot refused (the confirmation came before the customer could answer)', $this->history( $r ) );

		// Not spent: after the wait it works.
		$this->update_rows( array( 'created_at' => time() - Confirmations::MIN_DELAY - 1 ) );
		$this->assertFalse( $this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => $first['confirm'] ), $token )['isError'] );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		$this->assertSame( 5, Confirmations::MIN_DELAY );
	}

	// -- 1b and 4: the e-mail --------------------------------------------------------------------

	public function test_every_confirmed_change_is_told_to_the_customer_by_e_mail(): void {
		$r     = $this->offering( array( 'snapshot_delete', 'reinstall' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );

		// A summary alone tells nobody.
		$first = $this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) );
		$this->assertSame( array(), $this->tool_mails );

		// The shop's own switch for this e-mail does not silence it.
		update_option( 'woocommerce_beltoft_services_notice_settings', array( 'enabled' => 'no' ) );
		$this->assertFalse( $this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => $first['confirm'] ), $token )['isError'] );

		$this->assertCount( 1, $this->tool_mails );
		$mail = $this->tool_mails[0];
		$this->assertSame( 'christian.beltoft@gmail.com', $mail['to'] );
		$this->assertSame( 'A connected app changed one of your services', $mail['subject'] );
		$body = (string) preg_replace( '/\s+/', ' ', (string) $mail['message'] );
		$this->assertStringContainsString( 'it deleted a snapshot', $body );
		$this->assertStringContainsString( 'If this was not you, disconnect the app', $body );
		$this->assertStringNotContainsString( 'abcd1234', $body, 'Nothing but what was done.' );

		// A refused confirmation and a reinstall that only says where to buy a licence tell nobody.
		$this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => 'bsc_wrong' ), $token );
		add_filter( 'beltoft_services_action_redirect', static fn( $u, $x, $action ) => 'reinstall' === $action ? 'https://shop.example/c' : $u, 10, 3 );
		$re = $this->json( $this->tool( 'reinstall_server', array( 'id' => $r->id() ), $token ) );
		$this->tool( 'reinstall_server', array( 'id' => $r->id(), 'confirm' => $re['confirm'] ), $token );
		$this->assertCount( 1, $this->tool_mails );
	}

	public function test_the_mail_goes_to_the_customer_when_the_license_has_no_address_and_a_card_change_is_told_too(): void {
		$r    = $this->offering( array( 'snapshot_delete' ), '' );
		$user = $this->owner( $r );
		wp_update_user( array( 'ID' => $user, 'user_email' => 'christian.beltoft@gmail.com' ) );
		$this->tool_mails = array(); // WordPress mails the old address about the change.
		$token = $this->oauth( $user, Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) );
		$this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => $first['confirm'] ), $token );

		$this->assertCount( 1, $this->tool_mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $this->tool_mails[0]['to'] );

		// A change that belongs to no service goes to the customer's own address through the shop's mailer.
		$one = new \WC_Payment_Token_CC();
		$two = new \WC_Payment_Token_CC();

		foreach ( array( array( $one, '1111', true ), array( $two, '4242', false ) ) as $c ) {
			$c[0]->set_token( 'tok_' . $c[1] );
			$c[0]->set_gateway_id( 'bacs' );
			$c[0]->set_card_type( 'visa' );
			$c[0]->set_last4( $c[1] );
			$c[0]->set_expiry_month( '12' );
			$c[0]->set_expiry_year( '2040' );
			$c[0]->set_user_id( $user );
			$c[0]->set_default( $c[2] );
			$c[0]->save();
		}

		$args  = array( 'token_id' => $two->get_id() );
		$first = $this->json( $this->tool( 'set_default_payment_method', $args, $token ) );
		$this->assertFalse( $this->tool( 'set_default_payment_method', $args + array( 'confirm' => $first['confirm'] ), $token )['isError'] );
		$this->assertCount( 2, $this->tool_mails );
		$this->assertSame( 'A connected app changed your account', $this->tool_mails[1]['subject'] );
		$this->assertStringContainsString( 'made a saved card your default payment method', (string) $this->tool_mails[1]['message'] );
		$this->assertStringNotContainsString( 'tok_4242', wp_json_encode( $this->tool_mails ) );
	}

	public function test_a_call_with_no_address_to_tell_is_refused_before_it_is_confirmed(): void {
		global $wpdb;

		$r    = $this->offering( array( 'snapshot_delete' ), '' );
		$user = $this->owner( $r );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $user ) );
		clean_user_cache( $user );
		$token = $this->oauth( $user, Tokens::FULL );

		$result = $this->tool( 'delete_snapshot', $this->snap( $r ), $token );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'no e-mail address', $this->text( $result ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::tables()['confirms'] ) ), 'No token was made.' );
		$this->assertFalse( Jobs::acting( $r->id() ) );
	}

	public function test_a_mail_that_could_not_be_sent_is_said_in_the_history(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$first = $this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) );

		add_filter( 'pre_wp_mail', '__return_false', 20 );
		$done = $this->tool( 'delete_snapshot', $this->snap( $r ) + array( 'confirm' => $first['confirm'] ), $token );
		remove_filter( 'pre_wp_mail', '__return_false', 20 );

		$this->assertFalse( $done['isError'], 'What was asked is done; the history says the customer was not told.' );
		$this->assertContains( 'Claude: notice e-mail not sent', $this->history( $r ) );
	}

	// -- 2: the REST blocklist -------------------------------------------------------------------

	public function test_every_action_of_a_full_tool_is_blocked_for_a_token_on_the_rest_routes(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		Tokens::use_grant( array( 'user' => $user, 'scope' => Tokens::ACTIONS, 'family' => 'rest1', 'client_id' => 'c' ) );

		$full_only = array();

		foreach ( ActionMap::ACTIONS as $action => $tool ) {
			if ( Tokens::FULL === Tools::definitions()[ $tool ]['level'] ) {
				$full_only[] = $action;
			}
		}

		$this->assertEqualsCanonicalizing( $full_only, Tokens::full_only_actions() );

		foreach ( array( 'fw_reset', 'db_set_policy', 'reset_password', 'snapshot_delete', 'snapshot_rollback', 'restore', 'reinstall', 'replace_ssh_key' ) as $must ) {
			$this->assertContains( $must, $full_only, $must );
		}

		foreach ( $full_only as $action ) {
			$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/1/actions/' . $action );
			$result  = Tokens::gate( null, rest_get_server(), $request );

			$this->assertInstanceOf( \WP_Error::class, $result, $action );
			$this->assertSame( 'beltoft_services_token_action', $result->get_error_code(), $action );
		}

		// An everyday action still passes the gate.
		$this->assertNull( Tokens::gate( null, rest_get_server(), new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/1/actions/reboot' ) ) );
		Tokens::reset( false );
	}

	// -- 3: wide rule sets -----------------------------------------------------------------------

	public function test_rules_that_together_open_many_ports_to_anyone_are_wide(): void {
		$rules = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$rules[] = array( 'proto' => 'tcp', 'from' => 1000 + $i * 100, 'to' => 1099 + $i * 100 );
		}

		$level = static fn( array $r ) => Tools::required_level( 'set_firewall', array( 'id' => 1, 'on' => true, 'rules' => $r ) );

		$this->assertSame( Tokens::FULL, $level( $rules ), '100 rules of 100 ports each.' );
		$this->assertSame( Tokens::FULL, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 65535, 'sources' => array( '1.0.0.0/1' ) ) ) ), 'A source of half the internet is anyone.' );
		$this->assertSame( Tokens::FULL, $level( array( array( 'proto' => 'udp', 'from' => 1, 'to' => 500, 'sources' => array( '203.0.113.7', '0.0.0.0/0' ) ) ) ), 'One broad source in the list is enough.' );
		$this->assertSame( Tokens::FULL, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 500, 'sources' => array( '2000::/16' ) ) ) ), 'IPv6 broader than /32.' );
		$this->assertSame( Tokens::FULL, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 60 ), array( 'proto' => 'tcp', 'from' => 100, 'to' => 160, 'sources' => array() ) ) ), '61 and 61 ports.' );

		$this->assertSame( Tokens::ACTIONS, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 65535, 'sources' => array( '10.0.0.0/8' ) ) ) ), '/8 is the widest that is a range.' );
		$this->assertSame( Tokens::ACTIONS, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 65535, 'sources' => array( '2001:db8::/32' ) ) ) ) );
		$this->assertSame( Tokens::ACTIONS, $level( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 100 ), array( 'proto' => 'tcp', 'from' => 1, 'to' => 100 ) ) ), 'The same ports twice are counted once.' );
		$this->assertSame( Tokens::ACTIONS, $level( array( array( 'proto' => 'tcp', 'from' => 22 ), array( 'proto' => 'icmp', 'from' => 1, 'to' => 65535 ) ) ), 'icmp has no ports.' );
		$this->assertSame( 101, Tools::open_ports( array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 100 ), array( 'proto' => 'tcp', 'from' => 101 ) ) )['total'] );

		// And the call through the tool: refused at the actions level.
		$r     = $this->offering( array( 'fw_apply' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::ACTIONS );
		$this->assertStringContainsString( 'Full access', $this->text( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $rules ), $token ) ) );
	}

	// -- 5: the log ------------------------------------------------------------------------------

	public function test_summaries_do_not_flood_the_history_and_the_asked_lines_cannot_push_out_what_was_done(): void {
		$r     = $this->offering( array( 'snapshot_delete' ) );
		$user  = $this->owner( $r );
		$token = $this->oauth( $user, Tokens::FULL );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) );
		}

		$asked = array_filter( $this->history( $r ), static fn( $m ) => str_contains( $m, 'asked to' ) );
		$this->assertCount( 1, $asked, 'The same summary again within a minute leaves no new line.' );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->update_rows( array( 'used_at' => time() ), 'used_at IS NULL' ); // So the limit of waiting confirmations is not what stops it.
			$this->json( $this->tool( 'delete_snapshot', $this->snap( $r, sprintf( 'abcd%04d', $i ) ), $token ) );
		}

		$asked = array_filter( $this->history( $r ), static fn( $m ) => str_contains( $m, 'asked to' ) );
		$this->assertCount( 10, $asked, 'At most ten such lines an hour.' );

		// The log of the customer: the asked lines have a quota of their own.
		Audit::add( $user, 'billing address changed (city)' );

		for ( $i = 0; $i < 3 * Audit::KEEP_ASKED; $i++ ) {
			Audit::add( $user, 'asked ' . $i, true );
		}

		$texts = array_column( Audit::lines( $user ), 'text' );
		$this->assertContains( 'Claude: billing address changed (city)', $texts );
		$this->assertCount( 1 + Audit::KEEP_ASKED, $texts );
		$this->assertContains( 'Claude: asked ' . ( 3 * Audit::KEEP_ASKED - 1 ), $texts );
		$this->assertNotContains( 'Claude: asked 0', $texts );
	}

	// -- 6: the summaries --------------------------------------------------------------------------

	public function test_summaries_name_the_service_with_its_number_and_show_what_matters(): void {
		$r     = $this->offering( array( 'snapshot_delete', 'reinstall', 'replace_ssh_key', 'fw_reset' ) );
		$token = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$tag   = '(service #' . $r->id() . ')';

		$this->assertStringContainsString( $tag, $this->json( $this->tool( 'delete_snapshot', $this->snap( $r ), $token ) )['summary'] );
		$this->assertStringContainsString( $tag, $this->json( $this->tool( 'reset_firewall', array( 'id' => $r->id() ), $token ) )['summary'] );

		$re = $this->json( $this->tool( 'reinstall_server', array( 'id' => $r->id(), 'app' => 'none', 'domain_mode' => 'change', 'domain_zone' => 'example.com', 'domain_sub' => 'shop' ), $token ) )['summary'];
		$this->assertStringContainsString( 'Domain for the app: change, shop.example.com', $re );
		$own = $this->json( $this->tool( 'reinstall_server', array( 'id' => $r->id(), 'domain_mode' => 'own', 'domain_own' => 'my.example.org' ), $token ) )['summary'];
		$this->assertStringContainsString( 'Domain for the app: own, my.example.org', $own );

		$key  = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDqjJLYRRTB9Z55AmrMBnnZqXMgIfW4QWnlAurKuKSeI laptop@home';
		$blob = base64_decode( explode( ' ', $key )[1] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test.
		$fp   = 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test.
		$sum  = $this->json( $this->tool( 'replace_ssh_key', array( 'id' => $r->id(), 'key' => $key ), $token ) )['summary'];
		$this->assertStringContainsString( $fp, $sum );
		$this->assertStringContainsString( 'comment "laptop@home"', $sum );
	}

	public function test_nameservers_must_be_host_names_and_are_shown_as_a_list(): void {
		$user   = self::factory()->user->create();
		$domain = $this->domain( $user );
		$this->email_to( $domain );
		$token = $this->oauth( $user, Tokens::FULL );
		$base  = array( 'id' => $domain->id(), 'action' => 'set_nameservers', 'mode' => 'custom' );

		foreach ( array(
			array( 'ns1.ok.example', 'not a host' ),
			array( 'ns1.ok.example', 'ns2.ok.example; drop' ),
			array( 'ns1.ok.example', '-bad.example' ),
			array( 'ns1.ok.example', 'localhost' ),
			array( 'ns1.ok.example', 'ns1.ok.example' ),
			array( 'ns1.ok.example' ),
		) as $hosts ) {
			$result = $this->tool( 'domain_settings', $base + array( 'hosts' => $hosts ), $token );
			$this->assertTrue( $result['isError'], wp_json_encode( $hosts ) );
			$this->assertStringNotContainsString( 'bsc_', $this->text( $result ) );
		}

		$ok = $this->json( $this->tool( 'domain_settings', $base + array( 'hosts' => array( 'NS1.ok.example.', 'ns2.ok.example' ) ), $token ) );
		$this->assertStringContainsString( "\n- ns1.ok.example\n- ns2.ok.example\n", $ok['summary'] );
	}

	public function test_the_services_a_cancellation_covers_are_part_of_what_was_confirmed(): void {
		$user = self::factory()->user->create();
		$sub  = $this->subscription( $user, '12.00', true );
		$row  = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id(), 'email' => 'christian.beltoft@gmail.com' ) );
		Resource::create( $row, $this->fake_driver(), array(), array( 'state' => 'ready' ) );
		$token = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'subscription_id' => $sub->get_id() );
		$first = $this->json( $this->tool( 'cancel_subscription', $args, $token ) );

		// A second service is put on the same subscription after the summary: what the customer said yes to is not what would be cancelled.
		$row2 = $this->service_license( $this->service_product( 'fake', array(), array( 'max' => 3 ) ), array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $sub->get_id() ) );
		Resource::create( $row2, $this->fake_driver(), array(), array( 'state' => 'ready' ) );

		$result = $this->tool( 'cancel_subscription', $args + array( 'confirm' => $first['confirm'] ), $token );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'Things changed', $this->text( $result ) );
		$this->assertTrue( wcs_get_subscription( $sub->get_id() )->has_status( 'active' ) );
	}

	// -- 7: waiting confirmations per connection --------------------------------------------------

	public function test_the_limit_of_waiting_confirmations_is_per_connection_and_leaves_no_row_over_it(): void {
		global $wpdb;

		$r     = $this->offering( array( 'snapshot_delete' ) );
		$user  = $this->owner( $r );
		$one   = $this->oauth( $user, Tokens::FULL );
		$two   = $this->oauth( $user, Tokens::FULL );

		for ( $i = 0; $i < Confirmations::MAX_OPEN; $i++ ) {
			$this->json( $this->tool( 'delete_snapshot', $this->snap( $r, sprintf( 'abcd%04d', $i ) ), $one ) );
		}

		$over = $this->tool( 'delete_snapshot', $this->snap( $r, 'abcd9999' ), $one );
		$this->assertTrue( $over['isError'] );
		$this->assertStringContainsString( 'too many confirmations', $this->text( $over ) );
		$this->assertSame( Confirmations::MAX_OPEN, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::tables()['confirms'] ) ), 'The row of the refused call was taken back.' );

		// Another connection of the same customer is not held up by the first.
		$this->assertTrue( $this->json( $this->tool( 'delete_snapshot', $this->snap( $r, 'abcd9999' ), $two ) )['requires_confirmation'] );
	}
}
