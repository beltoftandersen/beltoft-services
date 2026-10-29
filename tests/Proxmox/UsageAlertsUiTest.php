<?php
/**
 * The per-server switch for usage emails, the upgrade link, and the usage emails themselves. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Panel;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Emails\Emails;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\MetricsRoute;
use BeltoftServices\Proxmox\Monitor;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\Upgrades;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class UsageAlertsUiTest extends BackupTestCase {
	private const OWNER = 'christian.beltoft+usage-ui@gmail.com';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'monitor_alerts' => 'on', 'pause_all' => 0 ) );
		Offers::reset();
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		wp_set_current_user( 0 );
		Offers::reset();
		parent::tear_down();
	}

	/**
	 * A server on a variation. Tier b is larger (4 cores, 8192 MB, 60 GB) and dearer than tier a.
	 *
	 * @return array{0: array<string, \WC_Product_Variation>, 1: Resource, 2: int}
	 */
	private function on_variation( string $own = 'a-None' ): array {
		$v = $this->variable_product();
		foreach ( array( 'b-None', 'b-Daily' ) as $key ) {
			$v[ $key ]->update_meta_data( Product::META_PLAN . 'cores', '4' );
			$v[ $key ]->update_meta_data( Product::META_PLAN . 'memory_mb', '8192' );
			$v[ $key ]->update_meta_data( Product::META_PLAN . 'disk_gb', '60' );
			$v[ $key ]->set_regular_price( 'b-None' === $key ? '20' : '22' );
			$v[ $key ]->save();
		}
		Offers::reset();
		$owner  = self::factory()->user->create( array( 'user_email' => self::OWNER ) );
		$parent = wc_get_product( $v[ $own ]->get_parent_id() );
		$sub    = wcs_create_subscription( array( 'customer_id' => $owner, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$sub->add_product( $v[ $own ], 1 );
		$sub->save();
		$row = $this->service_license( $parent, array(
			'product_id'      => $parent->get_id(),
			'product_slug'    => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
			'variation_id'    => $v[ $own ]->get_id(),
			'user_id'         => $owner,
			'email'           => self::OWNER,
			'subscription_id' => $sub->get_id(),
			'fire'            => false,
		) );
		$r = Resource::create( $row, Registry::get( 'proxmox' ), Product::plan( $parent->get_id(), $v[ $own ]->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $r );
		Jobs::run( $r->id() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		wp_set_current_user( $owner );
		$this->mails = array();

		return array( $v, Resource::find( $r->id() ), $owner );
	}

	private function post( int $id, bool $on ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/usage-alerts' );
		$request->set_param( 'id', $id );
		$request->set_param( 'on', $on );
		return MetricsRoute::switch_alerts( $request );
	}

	// -- The switch ---------------------------------------------------------------------------------

	public function test_owner_switches_usage_emails_off_and_on_and_it_is_written_to_the_history(): void {
		list( , $r ) = $this->on_variation();
		$this->assertTrue( Monitor::usage_wanted( $r ) );
		$off = $this->post( $r->id(), false );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( $off->get_data()['on'] );
		$this->assertFalse( Monitor::usage_wanted( Resource::find( $r->id() ) ) );
		$this->assertSame( 'off', Resource::find( $r->id() )->get( 'usage_alerts' ) );
		$this->assertTrue( $this->post( $r->id(), true )->get_data()['on'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'usage_alerts' ) );
	}

	public function test_off_switch_blocks_the_usage_email(): void {
		list( , $r ) = $this->on_variation();
		$this->post( $r->id(), false );
		Monitor::disk( Resource::find( $r->id() ), array( 'used' => 99, 'total' => 100 ), 1000 );
		$this->assertCount( 0, $this->mails );
		$this->post( $r->id(), true );
		Monitor::disk( Resource::find( $r->id() ), array( 'used' => 99, 'total' => 100 ), 2000 );
		$this->assertCount( 1, $this->mails );
	}

	public function test_other_user_is_refused_and_logged_out_is_unauthorised(): void {
		list( , $r ) = $this->on_variation();
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->post( $r->id(), false )->get_status() );
		$this->assertNull( Resource::find( $r->id() )->get( 'usage_alerts' ) );
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/usage-alerts' );
		$request->set_param( 'on', false );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
	}

	public function test_switch_is_rate_limited(): void {
		list( , $r ) = $this->on_variation();
		$last = 0;
		for ( $i = 0; $i < 12; ++$i ) {
			$last = $this->post( $r->id(), 0 === $i % 2 )->get_status();
		}
		$this->assertSame( 429, $last );
	}

	public function test_panel_shows_the_switch_and_the_state_line(): void {
		list( , $r ) = $this->on_variation();
		$r->set( array( 'disk_used' => 91, 'disk_total' => 100, 'disk_read_at' => time(), 'monitor_disk_notified' => time() ) );
		ob_start();
		Panel::render( Resource::find( $r->id() )->license() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-usage-alerts', $html );
		$this->assertMatchesRegularExpression( '#data-usage-alerts checked#', $html );
		$this->assertStringContainsString( 'Disk 91 percent full since', $html );
		$this->assertStringContainsString( 'Email me when this server is short of disk or CPU', $html );
		$r->set( array( 'usage_alerts' => 'off' ) );
		ob_start();
		Panel::render( Resource::find( $r->id() )->license() );
		$this->assertDoesNotMatchRegularExpression( '#data-usage-alerts checked#', (string) ob_get_clean() );
		$this->settings( array( 'monitor_alerts' => 'off' ) );
		ob_start();
		Panel::render( Resource::find( $r->id() )->license() );
		$this->assertStringNotContainsString( 'data-usage-alerts', (string) ob_get_clean(), 'No switch when the site has the emails off.' );
	}

	// -- The upgrade link ----------------------------------------------------------------------------

	public function test_target_is_the_next_larger_in_stock_plan_for_what_ran_short(): void {
		list( $v, $r ) = $this->on_variation( 'a-None' );
		$this->assertSame( $v['b-None']->get_id(), Upgrades::target( $r, 'disk' )->get_id() );
		$this->assertSame( $v['b-None']->get_id(), Upgrades::target( $r, 'cpu' )->get_id() );
		$this->assertNull( Upgrades::target( $r, 'nonsense' ) );
	}

	public function test_target_keeps_the_backups_state_and_is_empty_for_the_largest_plan(): void {
		list( $v, $r ) = $this->on_variation( 'a-Daily' );
		$this->assertSame( $v['b-Daily']->get_id(), Upgrades::target( $r, 'disk' )->get_id() );
	}

	public function test_the_largest_plan_has_no_upgrade(): void {
		list( , $big ) = $this->on_variation( 'b-None' );
		$this->assertNull( Upgrades::target( $big, 'disk' ) );
		$this->assertSame( '', Upgrades::link( $big, 'disk' ) );
	}

	public function test_target_skips_a_plan_that_is_out_of_stock(): void {
		list( $v, $r ) = $this->on_variation( 'a-None' );
		$v['b-None']->set_stock_status( 'outofstock' );
		$v['b-None']->save();
		Offers::reset();
		$this->assertNull( Upgrades::target( $r, 'disk' ) );
	}

	public function test_link_goes_to_the_server_page_and_the_owner_is_sent_on_with_a_valid_switch_link(): void {
		list( $v, $r ) = $this->on_variation( 'a-None' );
		$link = Upgrades::link( $r, 'disk' );
		$this->assertStringContainsString( 'beltoft_upgrade=disk', $link );
		$this->assertStringNotContainsString( '_wcsnonce', $link, 'No nonce is written into an email.' );
		$url  = Upgrades::url( $r, 'disk' );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $v['b-None']->get_id(), (string) $args['variation_id'] );
		$this->assertNotFalse( wp_verify_nonce( $args['_wcsnonce'], 'wcs_switch_request' ) );
	}

	public function test_no_link_when_the_item_cannot_be_switched_or_wcs_does_not_allow_it(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_false' );
		$this->assertSame( '', Upgrades::url( $r, 'disk' ) );
	}

	/** Runs the handler with the server page asked for; returns the address it redirected to, or ''. */
	private function follow( Resource $r, string $need ): string {
		global $wp;
		add_filter( 'woocommerce_is_account_page', '__return_true' );
		$wp->query_vars[ \BeltoftServices\Account\Servers::slug() ] = (string) (int) $r->license()['id'];
		$_GET[ Upgrades::QUERY ] = $need;
		$sent = '';
		add_filter( 'wp_redirect', static function ( $location ) use ( &$sent ) { $sent = (string) $location; throw new \RuntimeException( 'redirected' ); } );
		try {
			Upgrades::redirect();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_all_filters( 'wp_redirect' );
			remove_all_filters( 'woocommerce_is_account_page' );
			unset( $_GET[ Upgrades::QUERY ], $wp->query_vars[ \BeltoftServices\Account\Servers::slug() ] );
		}
		return $sent;
	}

	public function test_the_owner_who_opens_the_email_link_is_sent_to_the_switch_and_others_are_not(): void {
		list( $v, $r ) = $this->on_variation( 'a-None' );
		$sent = $this->follow( $r, 'disk' );
		$this->assertStringContainsString( 'switch-subscription=', $sent );
		$this->assertStringContainsString( 'variation_id=' . $v['b-None']->get_id(), $sent );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', $this->follow( $r, 'disk' ), 'Someone else is not redirected.' );
		wp_set_current_user( 0 );
		$this->assertSame( '', $this->follow( $r, 'disk' ), 'Logged out: the account page asks for a login.' );
	}

	public function test_a_switch_that_is_not_possible_sends_the_owner_to_the_server_page(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_false' );
		$sent = $this->follow( $r, 'disk' );
		$this->assertStringNotContainsString( 'switch-subscription', $sent );
		$this->assertStringContainsString( (string) (int) $r->license()['id'], $sent );
	}

	// -- The emails ------------------------------------------------------------------------------------

	private function mail( Resource $r, string $reason, array $data = array() ): array {
		$this->mails = array();
		$this->assertTrue( Emails::send( Monitor::EMAIL_ID, $r, array( 'reason' => $reason ) + $data ) );
		return array( $this->mails[0], $r );
	}

	public function test_disk_email_has_the_numbers_the_links_and_the_footer_line(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		list( $mail ) = $this->mail( $r, 'disk', array( 'disk_used' => 27 * 1073741824, 'disk_total' => 30 * 1073741824 ) );
		$this->assertSame( self::OWNER, $mail['to'] );
		$this->assertSame( 'Your server disk is 90 percent full', $mail['subject'] );
		$this->assertStringContainsString( '27.0 of 30.0 GB is used (90 percent)', $mail['message'] );
		$this->assertStringContainsString( 'larger disk', $mail['message'] );
		$this->assertStringContainsString( 'beltoft_upgrade=disk', html_entity_decode( $mail['message'] ) );
		$this->assertStringContainsString( '/disks', html_entity_decode( $mail['message'] ) );
		$this->assertStringContainsString( 'turn these emails off for this server in your account, under Monitoring', preg_replace( '/\s+/', ' ', $mail['message'] ) );
		$this->assertStringNotContainsString( 'snapshot', strtolower( $mail['message'] ) );
		$this->assertMailIsPlain( $mail );
	}

	public function test_urgent_disk_and_cpu_emails_have_their_own_text(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		list( $urgent ) = $this->mail( $r, 'disk_urgent', array( 'disk_used' => 98, 'disk_total' => 100 ) );
		$this->assertSame( 'Your server disk is almost completely full', $urgent['subject'] );
		$this->assertStringContainsString( 'Please act today', $urgent['message'] );
		$this->assertMailIsPlain( $urgent );
		list( $cpu ) = $this->mail( $r, 'cpu', array( 'cpu_percent' => 90, 'cpu_hours' => 3, 'cpu_since' => 1700000000 ) );
		$this->assertSame( 'Your server has used almost all of its CPU for 3 hours', $cpu['subject'] );
		$this->assertStringContainsString( '90 percent or more of its CPU since', $cpu['message'] );
		$this->assertStringContainsString( 'more cores', $cpu['message'] );
		$this->assertStringContainsString( 'top or htop', $cpu['message'] );
		$this->assertMailIsPlain( $cpu );
	}

	public function test_upgrade_line_is_left_out_when_no_larger_plan_exists(): void {
		list( , $r ) = $this->on_variation( 'b-None' );
		$this->mails = array();
		$this->assertTrue( Emails::send( Monitor::EMAIL_ID, $r, array( 'reason' => 'disk', 'disk_used' => 95, 'disk_total' => 100 ) ) );
		$this->assertStringNotContainsString( 'larger disk', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'beltoft_upgrade', $this->mails[0]['message'] );
	}

	public function test_down_email_is_unchanged_and_has_no_footer_line(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		list( $mail ) = $this->mail( $r, 'down' );
		$this->assertSame( 'Check your server', $mail['subject'] );
		$this->assertStringNotContainsString( 'turn these emails off', $mail['message'] );
	}

	public function test_templates_have_no_provider_name_and_no_em_dash(): void {
		foreach ( array( 'templates/emails/beltoft-services-monitor-alert.php', 'templates/emails/plain/beltoft-services-monitor-alert.php' ) as $file ) {
			$text = (string) file_get_contents( BELTOFT_SERVICES_PATH . $file );
			$this->assertStringNotContainsString( '—', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $text ), $file );
			$this->assertDoesNotMatchRegularExpression( '#hetzner|proxmox#i', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $text ), $file );
		}
	}

	private function assertMailIsPlain( array $mail ): void {
		$this->assertStringNotContainsString( '—', $mail['subject'] . $mail['message'] );
		$this->assertDoesNotMatchRegularExpression( '#hetzner|proxmox#i', $mail['subject'] . $mail['message'] );
	}
}
