<?php
/**
 * Domain names with national letters: typed as Unicode, stored and sent as punycode, shown as Unicode.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Cart;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\HostName;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\Search;
use BeltoftServices\Tests\TestCase;

final class HostNameTest extends TestCase {

	private const UNICODE = "\u{e6}blegr\u{f8}d-\u{f8}ster\u{e5}.dk";
	private const ASCII   = 'xn--blegrd-ster-78ab1xc.dk';

	public function test_unicode_input_becomes_punycode(): void {
		$this->assertSame( self::ASCII, HostName::normalise( self::UNICODE ) );
		$this->assertSame( self::ASCII, HostName::normalise( ' ' . mb_strtoupper( self::UNICODE ) . '. ' ) );
		$this->assertSame( 'www.' . self::ASCII, HostName::normalise( 'www.' . self::UNICODE ) );
		$this->assertSame( 'example.com', HostName::normalise( 'Example.COM' ) );
	}

	public function test_invalid_unicode_is_refused(): void {
		foreach ( array( "a b\u{e6}.dk", "\u{e6}-.dk", "\u{e6}blegr\u{f8}d", "\u{200b}.dk", "\u{e6}.localhost" ) as $bad ) {
			$this->assertNull( HostName::normalise( $bad ), $bad );
		}
		$this->assertNull( HostName::normalise( str_repeat( "\u{e6}", 64 ) . '.dk' ) );
	}

	public function test_ascii_checks_still_apply(): void {
		foreach ( array( 'not a host', 'a_b.com', '-a.com', 'a.localhost', 'single', '' ) as $bad ) {
			$this->assertNull( HostName::normalise( $bad ), $bad );
		}
		$this->assertNull( HostName::normalise( array() ) );
	}

	public function test_display_round_trips_and_falls_back(): void {
		$this->assertSame( self::UNICODE, HostName::display( self::ASCII ) );
		$this->assertSame( 'www.' . self::UNICODE, HostName::display( 'www.' . self::ASCII ) );
		$this->assertSame( '*.' . self::UNICODE, HostName::display( '*.' . self::ASCII ) );
		$this->assertSame( 'me@' . self::UNICODE, HostName::display( 'me@' . self::ASCII ) );
		$this->assertSame( 'example.com', HostName::display( 'example.com' ) );
		$this->assertSame( 'xn--bad', HostName::display( 'xn--bad' ) );
		$this->assertSame( '', HostName::display( null ) );
		$this->assertSame( self::ASCII, HostName::normalise( HostName::display( self::ASCII ) ) );
		$this->assertSame( 'https://' . self::UNICODE . '/shop', HostName::display_url( 'https://' . self::ASCII . '/shop' ) );
	}

	public function test_the_cart_line_and_the_order_meta_show_the_unicode_name(): void {
		$data = Cart::item_data( array(), array( 'beltoft_services_domain' => array( 'domain' => self::ASCII, 'mode' => 'register' ) ) );
		$this->assertSame( self::ASCII, $data[0]['value'], 'The stored value stays punycode.' );
		$this->assertSame( self::UNICODE, $data[0]['display'] );

		$meta = (object) array( 'key' => __( 'Domain', 'beltoft-services' ) );
		$this->assertStringContainsString( self::UNICODE, Cart::meta_display( '<p>' . self::ASCII . "</p>\n", $meta ) );
		// A key stored in another language is still recognised.
		$this->assertStringContainsString( self::UNICODE, Cart::meta_display( '<p>' . self::ASCII . '</p>', (object) array( 'key' => 'Dom\u{e6}ne', 'value' => self::ASCII ) ) );
		// Other lines are left alone.
		$this->assertSame( '<p>' . self::ASCII . ' and text</p>', Cart::meta_display( '<p>' . self::ASCII . ' and text</p>', (object) array( 'key' => 'Other', 'value' => self::ASCII . ' and text' ) ) );
	}

	public function test_search_results_carry_a_display_form_next_to_the_stored_one(): void {
		update_option( Prices::OPTION, array( 'dk' => array( 'price' => 8, 'restricted' => false ) ) );
		$fake = new FakeClient();
		$fake->reply( 'POST', '/domains/check', array( 'results' => array( array( 'domain' => self::ASCII, 'status' => 'free', 'is_premium' => false ) ) ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $fake );

		$found = Search::find( self::UNICODE );
		$this->assertIsArray( $found );
		$row = $found['results'][0];
		$this->assertSame( self::ASCII, $row['domain'] );
		$this->assertSame( self::UNICODE, $row['display'] );
	}
}
