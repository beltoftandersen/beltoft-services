<?php
/**
 * The backups block of the account and admin panels, and the switch links. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Panel;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * Panels and switch URLs.
 */
class BackupsSwitchTest extends BackupTestCase {

	/**
	 * A server on a variation of a product with a Backups attribute, with a WooCommerce Subscriptions
	 * subscription whose line item is that variation. Returns the variations, the resource and its owner.
	 *
	 * @param string $own  Which variation the server is on: a-None or a-Daily.
	 * @param bool   $subscription Whether a subscription exists.
	 * @return array{0: array<string, \WC_Product_Variation>, 1: Resource, 2: int, 3: \WC_Subscription|null}
	 */
	private function on_variation( string $own = 'a-None', bool $subscription = true ): array {
		$v       = $this->variable_product();
		$owner   = self::factory()->user->create();
		$parent  = wc_get_product( $v[ $own ]->get_parent_id() );
		$sub     = null;
		$license = array(
			'product_id'   => $parent->get_id(),
			'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
			'variation_id' => $v[ $own ]->get_id(),
			'user_id'      => $owner,
			'fire'         => false,
		);

		if ( $subscription ) {
			$sub = wcs_create_subscription( array( 'customer_id' => $owner, 'billing_period' => 'month', 'billing_interval' => 1 ) );
			$this->assertInstanceOf( \WC_Subscription::class, $sub );
			$sub->add_product( $v[ $own ], 1 );
			$sub->save();
			$license['subscription_id'] = $sub->get_id();
		}

		$row      = $this->service_license( $parent, $license );
		$resource = Resource::create( $row, Registry::get( 'proxmox' ), Product::plan( $parent->get_id(), $v[ $own ]->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		wp_set_current_user( $owner );

		return array( $v, Resource::find( $resource->id() ), $owner, $sub );
	}

	private function switchable( bool $yes = true ): void {
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', static fn() => $yes );
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function panel( Resource $r ): string {
		ob_start();
		Panel::render( Resource::find( $r->id() )->license() );

		return (string) ob_get_clean();
	}

	/**
	 * The href of the first link with this text.
	 *
	 * @param string $html HTML.
	 * @param string $text Link text.
	 */
	private function href( string $html, string $text ): string {
		$this->assertSame( 1, preg_match( '#<a [^>]*href="([^"]+)"[^>]*>\s*' . preg_quote( $text, '#' ) . '#', $html, $m ), 'A link "' . $text . '"' );

		return html_entity_decode( $m[1] );
	}

	// -- Customer panel ----------------------------------------------------------------------------

	public function test_a_server_without_backups_offers_to_add_them_through_a_switch(): void {
		list( $v, $r, , $sub ) = $this->on_variation( 'a-None' );
		$this->switchable();

		$html = $this->panel( $r );
		$url  = $this->href( $html, 'Add daily backups' );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		$this->assertSame( wc_get_checkout_url(), strtok( $url, '?' ) );
		$this->assertSame( (string) $v['a-None']->get_parent_id(), (string) $args['add-to-cart'] );
		$this->assertSame( (string) $v['a-Daily']->get_id(), (string) $args['variation_id'] );
		$this->assertSame( 'a', $args['attribute_tier'] );
		$this->assertSame( 'Daily', $args['attribute_backups'] );
		$this->assertSame( (string) $sub->get_id(), (string) $args['switch-subscription'] );
		$this->assertNotEmpty( $args['item'] );
		$this->assertNotEmpty( $args['_wcsnonce'] );
		$this->assertNotFalse( wp_verify_nonce( $args['_wcsnonce'], 'wcs_switch_request' ) );

		$this->assertMatchesRegularExpression( '#Add daily backups \(\+[^)]*2\.00#', $html, 'The price difference is on the link.' );
		$this->assertStringNotContainsString( 'Remove backups', $html );
		$this->assertStringNotContainsString( 'data-action="restore"', $html );
	}

	public function test_a_server_with_backups_lists_them_and_offers_restore_and_removal(): void {
		list( $v, $r ) = $this->on_variation( 'a-Daily' );
		$this->switchable();

		ProxmoxJobs::backup();
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		$r      = Resource::find( $r->id() );
		$stored = $r->get( 'backup_list' );
		$this->assertCount( 1, $stored );
		$seen = count( $this->fake->calls() );

		$html = $this->panel( $r );

		$this->assertCount( $seen, $this->fake->calls(), 'The panel makes no Proxmox call.' );
		$this->assertStringContainsString( 'data-action="restore"', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( $stored[0]['volid'] ) . '"', $html );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stored[0]['ctime'] ), $html );
		$this->assertStringContainsString( 'Type RESTORE to confirm', $html );
		$this->assertStringContainsString( 'password and the SSH key go back', $html );
		$this->assertStringNotContainsString( 'Add daily backups', $html );

		$remove = $this->href( $html, 'Remove backups' );
		$args   = array();
		wp_parse_str( (string) wp_parse_url( $remove, PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $v['a-None']->get_id(), (string) $args['variation_id'], 'Back to the variation without backups.' );
		$this->assertSame( 'None', $args['attribute_backups'] );
		$this->assertStringContainsString( 'all backups of this server are deleted', $html );
	}

	public function test_a_server_with_backups_but_no_run_yet_says_so(): void {
		list( , $r ) = $this->on_variation( 'a-Daily' );
		$this->switchable();

		$html = $this->panel( $r );

		$this->assertStringContainsString( 'will appear here after tonight', $html );
		$this->assertStringNotContainsString( 'data-action="restore"', $html );
	}

	public function test_no_links_when_the_item_cannot_be_switched_or_there_is_no_subscription(): void {
		list( , $r ) = $this->on_variation( 'a-None' );
		$this->switchable( false );

		$this->assertSame( '', Backups::switch_url( $r, true ) );
		$this->assertStringNotContainsString( 'Add daily backups', $this->panel( $r ) );
		$this->assertStringNotContainsString( 'beltoft-services-vm__backups', $this->panel( $r ) );

		list( , $none ) = $this->on_variation( 'a-None', false );
		$this->switchable();
		$this->assertSame( '', Backups::switch_url( $none, true ), 'No subscription.' );
		$this->assertStringNotContainsString( 'beltoft-services-vm__backups', $this->panel( $none ) );
	}

	public function test_no_block_without_a_sibling_variation(): void {
		$r = $this->ready_vm( array( 'backup_days' => 0 ) );
		$this->switchable();

		$this->assertSame( '', Backups::switch_url( $r, true ) );
		$this->assertNull( Backups::panel( $r ) );
	}

	public function test_a_linked_vm_gets_no_backups_block(): void {
		list( , $r ) = $this->on_variation( 'a-Daily' );
		$this->switchable();
		$r->set( array( 'linked' => true ) );

		$this->assertNull( Backups::panel( Resource::find( $r->id() ) ) );
		$this->assertStringNotContainsString( 'beltoft-services-vm__backups', $this->panel( $r ) );
	}

	public function test_everything_printed_is_escaped(): void {
		list( , $r ) = $this->on_variation( 'a-Daily' );
		$this->switchable();
		$r->set(
			array(
				'backup_ok_at' => time(),
				'backup_list'  => array(
					array(
						'volid' => 'backup-store:backup/vm/1/"><script>alert(1)</script>',
						'ctime' => time(),
						'size'  => 1024,
					),
				),
			)
		);

		$html = $this->panel( $r );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '"><script>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}

	// -- Admin panel -------------------------------------------------------------------------------

	private function admin_html( Resource $r ): string {
		ob_start();
		( new ProxmoxDriver() )->render_admin( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	public function test_the_admin_sees_the_backup_state(): void {
		$r = $this->ready_vm();
		ProxmoxJobs::backup();
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );

		$html = $this->admin_html( $r );
		$this->assertStringContainsString( 'daily, kept 7 days', $html );
		$this->assertStringContainsString( 'last OK', $html );
		$this->assertStringContainsString( '1 kept', $html );
		$this->assertStringNotContainsString( 'last error', $html );

		Resource::find( $r->id() )->set( array( 'backup_error' => 'storage <b>offline</b>' ) );
		$html = $this->admin_html( $r );
		$this->assertStringContainsString( 'last error: storage &lt;b&gt;offline&lt;/b&gt;', $html );
	}

	public function test_the_admin_row_is_empty_for_a_server_that_never_had_backups(): void {
		$r    = $this->ready_vm( array( 'backup_days' => 0 ) );
		$html = $this->admin_html( $r );

		$this->assertMatchesRegularExpression( '#<th scope="row">Backups</th><td>—</td>#', $html );
	}
}
