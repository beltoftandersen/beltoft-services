<?php
/** The registrar's renewal date and restore facts, and the price table's restore columns. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\RestoreFacts;
use BeltoftServices\Domains\Renewals;
use BeltoftServices\Domains\Sync;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class RestoreFactsTest extends TestCase {
	private function eur( float $price ): array {
		return array( 'reseller' => array( 'price' => $price, 'currency' => 'EUR' ) );
	}

	/** The live shape of an extension (plan section 2): prices and quarantine days. */
	private function tld( string $name, array $extra = array(), array $prices = array() ): array {
		return array_merge(
			array(
				'name'   => $name,
				'status' => 'ACT',
				'prices' => $prices + array( 'create_price' => $this->eur( 8 ), 'renew_price' => $this->eur( 8 ), 'transfer_price' => $this->eur( 8 ) ),
			),
			$extra
		);
	}

	private function sync_tlds( array $entries ): void {
		$fake = new FakeClient();
		$fake->reply( 'GET', '/tlds', array( 'results' => $entries ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );
		$this->assertIsInt( Prices::sync() );
	}

	public function test_the_renewal_date_wins_over_the_expiration_date(): void {
		$this->assertSame( '2027-10-24 00:00:00', RestoreFacts::expiry_of( array( 'renewal_date' => '2027-10-24 00:00:00', 'expiration_date' => '2027-10-26 00:00:00' ) ) );
		$this->assertSame( '2027-10-26 00:00:00', RestoreFacts::expiry_of( array( 'expiration_date' => '2027-10-26 00:00:00' ) ), 'An answer without a renewal date falls back.' );
		$this->assertSame( '2027-10-26 00:00:00', RestoreFacts::expiry_of( array( 'renewal_date' => '', 'expiration_date' => '2027-10-26 00:00:00' ) ) );
		$this->assertSame( '', RestoreFacts::expiry_of( array() ) );
	}

	public function test_the_registrar_fields_are_kept_as_given_and_only_when_present(): void {
		$meta = RestoreFacts::registrar_meta(
			array(
				'renewal_date'                => '2027-10-24 00:00:00',
				'expiration_date'             => '2027-10-26 00:00:00',
				'registry_expiration_date'    => '2027-10-26 00:00:00',
				'soft_quarantine_expiry_date' => '2027-12-05 00:00:00',
				'hard_quarantine_expiry_date' => '',
				'restorable_until'            => '2027-12-05 00:00:00',
				'delete_status'               => 'none',
				'autorenew'                   => 'off',
				'is_deleted'                  => false,
				'status'                      => 'ACT',
			)
		);
		$this->assertSame( '2027-10-24 00:00:00', $meta['registrar_renewal_date'] );
		$this->assertSame( '2027-10-26 00:00:00', $meta['registrar_expiration_date'] );
		$this->assertSame( '2027-12-05 00:00:00', $meta['soft_quarantine_until'] );
		$this->assertSame( '', $meta['hard_quarantine_until'], 'An empty field is stored empty, not interpreted.' );
		$this->assertSame( '2027-12-05 00:00:00', $meta['restorable_until'] );
		$this->assertSame( 'off', $meta['registrar_autorenew'] );
		$this->assertFalse( $meta['registrar_deleted'] );
		$this->assertArrayNotHasKey( 'quarantine_until', $meta, 'A field the answer lacks is not invented.' );
		$this->assertArrayNotHasKey( 'status', $meta );
		$this->assertSame( array(), RestoreFacts::registrar_meta( array( 'status' => 'ACT' ) ) );
	}

	public function test_the_price_refresh_stores_the_restore_prices_and_the_quarantine_days(): void {
		$this->sync_tlds(
			array(
				$this->tld( 'com', array( 'soft_quarantine_period' => 40, 'quarantine_period' => 0 ), array( 'restore_price' => $this->eur( 43.08 ), 'soft_restore_price' => $this->eur( 0 ) ) ),
				$this->tld( 'dk', array( 'soft_quarantine_period' => 0, 'quarantine_period' => 0 ), array( 'restore_price' => $this->eur( 17.56 ) ) ),
				$this->tld( 'nofacts' ),
			)
		);
		$table = get_option( Prices::OPTION );
		$this->assertSame( 43.08, $table['com']['restore'] );
		$this->assertSame( 0.0, $table['com']['soft_restore'] );
		$this->assertSame( 40, $table['com']['soft_quarantine_days'] );
		$this->assertSame( 0, $table['com']['quarantine_days'] );
		$this->assertSame( 17.56, $table['dk']['restore'] );
		$this->assertArrayNotHasKey( 'soft_restore', $table['dk'], 'Not stated is not stored as 0.' );
		$this->assertArrayNotHasKey( 'restore', $table['nofacts'] );
		$this->assertSame( 8.0, $table['com']['create'], 'The existing columns are unchanged.' );
	}

	public function test_a_restore_price_in_another_currency_is_not_stored(): void {
		$this->sync_tlds( array( $this->tld( 'com', array(), array( 'restore_price' => array( 'reseller' => array( 'price' => 9, 'currency' => 'USD' ) ) ) ) ) );
		$this->assertArrayNotHasKey( 'restore', get_option( Prices::OPTION )['com'] );
	}

	public function test_the_restore_window_per_extension(): void {
		update_option(
			Prices::OPTION,
			array(
				'com'    => array( 'soft_quarantine_days' => 40, 'quarantine_days' => 0 ),
				'eu'     => array( 'soft_quarantine_days' => 0, 'quarantine_days' => 38 ),
				'de'     => array( 'soft_quarantine_days' => 0, 'quarantine_days' => 29 ),
				'io'     => array( 'soft_quarantine_days' => 44, 'quarantine_days' => 28 ),
				'dk'     => array( 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
				'no'     => array( 'soft_quarantine_days' => 0, 'quarantine_days' => 0 ),
				'co.uk'  => array( 'soft_quarantine_days' => 88, 'quarantine_days' => 0 ),
				'uk'     => array( 'soft_quarantine_days' => 1, 'quarantine_days' => 0 ),
			)
		);
		$this->assertSame( 40, RestoreFacts::window_days( 'example.com' ) );
		$this->assertSame( 38, RestoreFacts::window_days( 'example.eu' ) );
		$this->assertSame( 29, RestoreFacts::window_days( 'example.de' ) );
		$this->assertSame( 72, RestoreFacts::window_days( 'example.io' ), 'Soft quarantine and quarantine add up.' );
		$this->assertSame( 30, RestoreFacts::window_days( 'example.dk' ), '.dk is the registry suspension of 30 days, whatever the API says.' );
		$this->assertNull( RestoreFacts::window_days( 'example.no' ), 'No days known: no window.' );
		$this->assertSame( 88, RestoreFacts::window_days( 'example.co.uk' ), 'The longest matching extension.' );
		$this->assertNull( RestoreFacts::window_days( 'example.unknown' ) );
	}

	private function domain( array $meta ): Resource {
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, new DomainDriver(), array(), $meta + array( 'state' => 'ready', 'domain' => 'sync-facts.com', 'remote_id' => '902', 'expires_at' => '2027-10-26 00:00:00' ) );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	public function test_the_daily_sync_uses_the_renewal_date_and_keeps_the_expiration_date_too(): void {
		$r  = $this->domain( array() );
		$op = new FakeClient();
		$op->reply(
			'GET',
			'/domains/902',
			array(
				'status'                      => 'ACT',
				'renewal_date'                => '2027-10-24 00:00:00',
				'expiration_date'             => '2027-10-26 00:00:00',
				'soft_quarantine_expiry_date' => '2027-12-03 00:00:00',
				'autorenew'                   => 'off',
				'is_locked'                   => true,
			)
		);
		$op->reply( 'GET', '/resellers', array( 'balance' => 100, 'currency' => 'EUR' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		Sync::run();
		$r = Resource::find( $r->id() );
		$this->assertSame( '2027-10-24 00:00:00', $r->get( 'expires_at' ) );
		$this->assertSame( '2027-10-24 00:00:00', $r->get( 'registrar_renewal_date' ) );
		$this->assertSame( '2027-10-26 00:00:00', $r->get( 'registrar_expiration_date' ), 'expiration_date stays stored.' );
		$this->assertSame( '2027-12-03 00:00:00', $r->get( 'soft_quarantine_until' ) );
		$this->assertSame( 'off', $r->get( 'registrar_autorenew' ) );
	}

	public function test_a_sync_answer_without_a_renewal_date_still_stores_the_expiration_date(): void {
		$r  = $this->domain( array() );
		$op = new FakeClient();
		$op->reply( 'GET', '/domains/902', array( 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00' ) );
		$op->reply( 'GET', '/resellers', array( 'balance' => 100, 'currency' => 'EUR' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		Sync::run();
		$this->assertSame( '2027-10-26 00:00:00', Resource::find( $r->id() )->get( 'expires_at' ) );
	}

	public function test_a_registrar_autorenew_that_is_not_off_alerts_staff_once(): void {
		$mails = array();
		add_filter( 'pre_wp_mail', static function ( $pre, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$r  = $this->domain( array() );
		$op = new FakeClient();
		foreach ( array( 1, 2 ) as $round ) {
			$op->reply( 'GET', '/domains/902', array( 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00', 'autorenew' => 'on' ) );
			$op->reply( 'GET', '/resellers', array( 'balance' => 100, 'currency' => 'EUR' ) );
		}
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		Sync::run();
		Sync::run();
		$found = array_filter( $mails, static fn( $m ) => str_contains( (string) $m['subject'], 'autorenew on' ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'christian.beltoft@gmail.com', (string) reset( $found )['to'] );
	}

	public function test_renewal_progress_is_read_from_the_renewal_date(): void {
		// An offset extension: renewal_date two days before expiration_date. A baseline from the renewal date must not look renewed
		// because the other field is later.
		$this->assertFalse( Renewals::later( RestoreFacts::expiry_of( array( 'renewal_date' => '2027-10-24 00:00:00', 'expiration_date' => '2027-10-26 00:00:00' ) ), '2027-10-24 00:00:00' ) );
		$this->assertTrue( Renewals::later( RestoreFacts::expiry_of( array( 'renewal_date' => '2028-10-24 00:00:00', 'expiration_date' => '2028-10-26 00:00:00' ) ), '2027-10-24 00:00:00' ) );
	}
}
