<?php
/** Domain account panel rendering. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsDriver;
use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\Panels;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class PanelsTest extends TestCase {
	private function render( $driver, array $meta, bool $ready = false ): string {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$product = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, $driver, array(), $meta );
		$this->assertInstanceOf( Resource::class, $r );
		if ( $ready ) { $r->set( array( 'state' => 'ready' ) ); }
		ob_start();
		Panels::account( $r );
		return (string) ob_get_clean();
	}

	public function test_domain_panel_renders_with_our_name_servers(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'panel-test.com', 'mode' => 'register' ) );
		$this->assertStringNotContainsString( '<details', $html, 'DNS settings are not folded away.' );
		$this->assertStringContainsString( '<li>ns1.example.net</li>', $html );
		$this->assertStringContainsString( '<li>ns2.example.net</li>', $html );
	}

	public function test_domain_name_is_not_repeated_in_the_panel(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'only-in-heading.com', 'mode' => 'register' ), true );
		$this->assertStringNotContainsString( 'only-in-heading.com', $html, 'The card heading shows the name.' );
	}

	public function test_card_heading_is_the_domain_name_for_domains_and_dns_hosting_only(): void {
		$product  = $this->service_product( 'domain', array(), array( 'slug' => 'domain-test' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, new DomainDriver(), array(), array( 'domain' => 'heading-test.com', 'mode' => 'register' ) );
		$this->assertInstanceOf( Resource::class, $resource );

		$this->assertSame( 'heading-test.com', apply_filters( 'beltoft_licenses_account_title', 'Domain name', $license ) );

		$this->fake_driver();
		$other = $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) );
		$this->assertSame( 'Product name', apply_filters( 'beltoft_licenses_account_title', 'Product name', $other ) );
	}

	public function test_lock_and_name_server_actions_live_in_the_panel_not_below_it(): void {
		$r = $this->render( new DomainDriver(), array( 'domain' => 'lock-test.com', 'mode' => 'register', 'is_locked' => false ), true );
		$this->assertStringContainsString( 'data-domain-action="lock"', $r );
		$this->assertStringNotContainsString( 'data-domain-action="unlock"', $r, 'Only the action that applies is offered.' );

		$this->assertStringContainsString( 'beltoft-services-domain__copy', $r, 'The transfer code comes with a Copy button.' );
		$this->assertMatchesRegularExpression( '/<input[^>]*readonly[^>]*beltoft-services-domain-code|<input[^>]*beltoft-services-domain-code[^>]*readonly/', $r );
		$locked = $this->render( new DomainDriver(), array( 'domain' => 'lock-test-2.com', 'mode' => 'register', 'is_locked' => true ), true );
		$this->assertStringContainsString( 'data-domain-action="unlock"', $locked );
		$this->assertStringNotContainsString( 'data-domain-action="lock"', $locked );

		$resource = Resource::create( $this->service_license( $this->service_product( 'domain' ), array( 'fire' => false ) ), new DomainDriver(), array(), array( 'domain' => 'btn-test.com', 'mode' => 'register' ) );
		$buttons  = Panels::buttons( array( 'lock' => 'Lock', 'unlock' => 'Unlock', 'set_nameservers' => 'NS', 'set_records' => 'R', 'check_delegation' => 'C', 'other' => 'Other' ), $resource );
		$this->assertSame( array( 'other' ), array_keys( $buttons ), 'The generic button row keeps nothing the panel shows itself.' );
	}

	public function test_a_domain_whose_dns_is_hosted_elsewhere_shows_its_name_servers_and_no_records_editor(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'external-card.com', 'mode' => 'transfer', 'dns_external' => true, 'transfer_ns' => array( 'ns1.old-host.example', 'ns2.old-host.example' ) ), true );
		$this->assertStringContainsString( '<li>ns1.example.net</li>', $this->render( new DomainDriver(), array( 'domain' => 'ours-card.com', 'mode' => 'register' ), true ), 'A domain with our DNS is unchanged.' );
		$this->assertStringContainsString( '<li>ns1.old-host.example</li>', $html );
		$this->assertStringContainsString( '<li>ns2.old-host.example</li>', $html );
		$this->assertStringNotContainsString( 'ns1.example.net', $html );
		$this->assertStringContainsString( '(DNS is hosted elsewhere)', $html );
		$this->assertStringNotContainsString( 'beltoft-dns-editor', $html );
		$this->assertStringNotContainsString( 'Save DNS records', $html );
		$this->assertStringNotContainsString( 'value="ours"', $html, 'Moving the DNS here is not offered.' );
		$this->assertStringContainsString( 'value="custom"', $html );
		$this->assertStringNotContainsString( 'Your DNS records here stay as they are', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_a_domain_with_our_dns_keeps_its_records_editor_and_ours_choice(): void {
		$html = $this->render( new DomainDriver(), array( 'domain' => 'ours-card-2.com', 'mode' => 'transfer' ), true );
		$this->assertStringContainsString( 'beltoft-dns-editor', $html );
		$this->assertStringContainsString( 'value="ours"', $html );
		$this->assertStringNotContainsString( '(DNS is hosted elsewhere)', $html );
	}

	public function test_the_dns_only_card_keeps_the_transfer_note_about_its_records(): void {
		update_option( \BeltoftServices\Domains\Prices::OPTION, array( 'com' => array( 'price' => 22, 'restricted' => false ) ) );
		$html = $this->render( new DnsDriver(), array( 'domain' => 'note-here.com', 'mode' => 'dns' ), true );
		$this->assertStringContainsString( 'Your DNS records here stay as they are.', $html );
	}

	public function test_dns_hosting_panel_renders_the_records_section(): void {
		$html = $this->render( new DnsDriver(), array( 'domain' => 'dns-panel-test.com', 'mode' => 'dns' ), true );
		$this->assertStringContainsString( '<li>ns1.example.net</li>', $html );
		$this->assertStringContainsString( '<li>ns2.example.net</li>', $html );
		$this->assertStringContainsString( 'beltoft-dns-editor', $html, 'Records are edited row by row, not as JSON.' );
		$this->assertStringContainsString( 'data-rows="[', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_active_dns_card_offers_to_move_the_registration_here(): void {
		update_option( \BeltoftServices\Domains\Prices::OPTION, array( 'com' => array( 'price' => 22, 'restricted' => false ) ) );
		$html = $this->render( new DnsDriver(), array( 'domain' => 'move-here.com', 'mode' => 'dns' ), true );
		$this->assertStringContainsString( 'Move the registration here', $html );
		$this->assertStringContainsString( 'data-domain-transfer', $html );
		$this->assertStringContainsString( 'data-domain="move-here.com"', $html );
		$this->assertStringContainsString( 'name="auth_code"', $html );
		$this->assertStringContainsString( '22', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		// Not offered while the service is not active, nor for an extension that is not sold here.
		$this->assertStringNotContainsString( 'data-domain-transfer', $this->render( new DnsDriver(), array( 'domain' => 'paused-here.com', 'mode' => 'dns' ) ) );
		$this->assertStringNotContainsString( 'data-domain-transfer', $this->render( new DnsDriver(), array( 'domain' => 'not-sold.xyz', 'mode' => 'dns' ), true ) );
		// Nor on a registered domain's own card.
		$this->assertStringNotContainsString( 'data-domain-transfer', $this->render( new DomainDriver(), array( 'domain' => 'mine.com', 'mode' => 'register' ), true ) );
	}

	public function test_dns_card_status_lines_and_notes(): void {
		$ours = $this->render( new DnsDriver(), array( 'domain' => 'a.example.com', 'mode' => 'dns', 'delegation' => 'ours', 'parent_ds' => true ), true );
		$this->assertStringContainsString( 'Pointing at our name servers', $ours );
		$this->assertStringNotContainsString( 'DNSSEC', $ours, 'The DS note is only for a domain not pointing here.' );
		$this->assertStringContainsString( 'data-domain-action="check_delegation"', $ours );
		$this->assertStringContainsString( 'Save DNS records', $ours );

		$other = $this->render( new DnsDriver(), array( 'domain' => 'b.example.com', 'mode' => 'dns', 'delegation' => 'other', 'parent_ds' => true ), true );
		$this->assertStringContainsString( 'Not pointing here yet', $other );
		$this->assertStringContainsString( 'This domain uses DNSSEC at your registrar. Remove the DS record there before you change the name servers, then wait a day.', $other );

		$none = $this->render( new DnsDriver(), array( 'domain' => 'c.example.com', 'mode' => 'dns', 'parent_ds' => false ), true );
		$this->assertStringContainsString( 'Unable to check right now', $none );
		$this->assertStringNotContainsString( 'DNSSEC', $none );
		$this->assertStringNotContainsString( 'paused', $none );

		$error = $this->render( new DnsDriver(), array( 'domain' => 'd.example.com', 'mode' => 'dns', 'import_error' => 'MX was invalid <b>' ), true );
		$this->assertStringContainsString( 'Some imported records could not be added: MX was invalid &lt;b&gt;. Add them below.', $error );
	}

	public function test_dns_card_is_read_only_while_records_are_off(): void {
		$off = $this->render( new DnsDriver(), array( 'domain' => 'e.example.com', 'mode' => 'dns', 'records_off' => true, 'state' => 'paused' ) );
		$this->assertStringContainsString( 'DNS hosting is paused. Your records are kept but not answered.', $off );
		$this->assertStringContainsString( 'beltoft-dns-editor', $off );
		$this->assertStringContainsString( 'data-readonly="1"', $off );
		$this->assertStringNotContainsString( 'Save DNS records', $off );
		$this->assertStringNotContainsString( 'beltoft-dns-add', $off );
	}

	public function test_dns_admin_rows(): void {
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$user = self::factory()->user->create();
		$claim = \BeltoftServices\Domains\DnsClaims::create( $user, 'admin.example.com' );
		\BeltoftServices\Domains\DnsClaims::set_status( $claim['id'], 'verified', array( 'verified_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		\BeltoftServices\Domains\DnsAssignments::reserve( $user, $claim['id'], 'admin.example.com' );
		\BeltoftServices\Domains\DnsAssignments::bind_order( 'admin.example.com', $user, 4321, 1 );
		$license = $this->service_license( $this->service_product( 'dns' ), array( 'fire' => false, 'user_id' => $user ) );
		$r = Resource::create( $license, new DnsDriver(), array(), array( 'domain' => 'admin.example.com', 'mode' => 'dns', 'claim_id' => $claim['id'], 'parent_ds' => true, 'records_off' => true, 'import_error' => 'bad MX' ) );
		ob_start();
		Panels::admin( $r );
		$html = (string) ob_get_clean();
		foreach ( array( 'Claim:', 'verified', 'Assignment status:', 'reserved', 'Order:', '#4321', 'Parent DS:', 'Records off:', 'Import error:', 'bad MX' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
		$this->assertStringNotContainsString( $claim['token'], $html );
		\BeltoftServices\Domains\DnsAssignments::release_pending( 'admin.example.com' );
		\BeltoftServices\Domains\DnsAssignments::activate( 'admin.example.com', $user, (int) $license['id'], $r->id() );
		\BeltoftServices\Domains\DnsAssignments::release_pending( 'admin.example.com' );
		\BeltoftServices\Domains\DnsAssignments::mark_clear( 'admin.example.com', '2026-11-01 10:00:00' );
		ob_start();
		Panels::admin( $r );
		$this->assertStringContainsString( 'First clear check:', (string) ob_get_clean() );
	}

	public function test_the_account_page_does_not_offer_the_editor_when_the_zone_cannot_be_read(): void {
		$down = new class() extends \BeltoftServices\Domains\FakeDns {
			public function zone( string $name ): array|\WP_Error|null { return new \WP_Error( 'transport', 'DNS request could not be completed.' ); }
		};
		add_filter( 'beltoft_services_domains_dns', static fn() => $down );
		$html = $this->render( new DomainDriver(), array( 'domain' => 'down-test.com', 'mode' => 'register' ), true );
		$this->assertStringContainsString( 'cannot be shown right now', $html );
		$this->assertStringNotContainsString( 'beltoft-dns-editor', $html, 'An empty editor could be saved over the real records.' );
		$up = new \BeltoftServices\Domains\FakeDns();
		$up->create_zone( 'up-test.com', array( 'ns1.example.net', 'ns2.example.net' ) );
		remove_all_filters( 'beltoft_services_domains_dns' );
		add_filter( 'beltoft_services_domains_dns', static fn() => $up );
		$html = $this->render( new DomainDriver(), array( 'domain' => 'up-test.com', 'mode' => 'register' ), true );
		$this->assertStringContainsString( 'beltoft-dns-editor', $html );
	}
}
