<?php
/**
 * The rules of a mailbox password the customer types.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Mail\MailPassword;

require_once __DIR__ . '/MailTestCase.php';

final class MailPasswordTest extends MailTestCase {

	private const BOX = 'anna.larsen@example.com';

	public function tear_down(): void {
		remove_all_filters( MailPassword::MIN_FILTER );
		parent::tear_down();
	}

	public function test_a_good_password_passes(): void {
		$this->assertNull( MailPassword::check( 'Blue horses 42 run', self::BOX ) );
		$this->assertNull( MailPassword::check( str_repeat( 'a1', 64 ), self::BOX ), '128 characters.' );
		$this->assertNull( MailPassword::check( 'Spaces in 1 middle', self::BOX ) );
	}

	public function test_length_is_ten_to_128_and_counts_characters(): void {
		$short = MailPassword::check( 'abcd12345', self::BOX );
		$this->assertWPError( $short );
		$this->assertSame( 'invalid', $short->get_error_code() );
		$this->assertWPError( MailPassword::check( str_repeat( 'a1', 64 ) . 'a', self::BOX ), '129 characters.' );
		$this->assertNull( MailPassword::check( 'æøåæøåæø1x', self::BOX ), 'Ten characters, more bytes.' );
	}

	public function test_it_needs_a_letter_and_a_digit(): void {
		$this->assertWPError( MailPassword::check( 'onlyletterslong', self::BOX ) );
		$this->assertWPError( MailPassword::check( '12345678901234', self::BOX ) );
		$this->assertWPError( MailPassword::check( '!!!!!!!!!!!!!!', self::BOX ) );
	}

	public function test_no_leading_or_trailing_space_and_no_control_characters(): void {
		$this->assertWPError( MailPassword::check( ' goodpass1234', self::BOX ) );
		$this->assertWPError( MailPassword::check( 'goodpass1234 ', self::BOX ) );
		$this->assertWPError( MailPassword::check( "goodpass\t1234", self::BOX ) );
		$this->assertWPError( MailPassword::check( "goodpass\n1234", self::BOX ) );
		$this->assertWPError( MailPassword::check( "goodpass\x001234", self::BOX ) );
		$this->assertWPError( MailPassword::check( "goodpass\x7f1234", self::BOX ) );
	}

	public function test_it_cannot_contain_the_address_or_a_long_local_part(): void {
		$this->assertWPError( MailPassword::check( 'xx' . strtoupper( self::BOX ) . '1', self::BOX ), 'The address, any case.' );
		$this->assertWPError( MailPassword::check( 'My-ANNA.larsen-9', self::BOX ), 'The local part, any case.' );
		$this->assertNull( MailPassword::check( 'ann4 and larsen 9', self::BOX ), 'Parts of it are fine.' );
		// A local part under four characters is not matched.
		$this->assertNull( MailPassword::check( 'xxabcxx12345', 'abc@example.com' ) );
		$this->assertWPError( MailPassword::check( 'xxinfoxx12345', 'info@example.com' ) );
	}

	public function test_the_minimum_length_is_filterable_but_never_below_eight(): void {
		add_filter( MailPassword::MIN_FILTER, static fn() => 14 );
		$this->assertWPError( MailPassword::check( 'abcdefgh1234', self::BOX ) );
		$this->assertNull( MailPassword::check( 'abcdefghij1234', self::BOX ) );
		remove_all_filters( MailPassword::MIN_FILTER );
		add_filter( MailPassword::MIN_FILTER, static fn() => 2 );
		$this->assertSame( 8, MailPassword::min() );
	}

	public function test_an_error_never_holds_the_password(): void {
		$secret = 'Secret-pass-0';
		$cases  = array( 'short1', 'noDigitsHereAtAll', ' lead1234567890', 'xx' . self::BOX . '1' );

		foreach ( $cases as $case ) {
			$e = MailPassword::check( $case, self::BOX );
			$this->assertWPError( $e );
			$this->assertStringNotContainsString( trim( $case ), $e->get_error_message() );
		}

		$this->assertNull( MailPassword::check( $secret, self::BOX ) );
	}

	public function test_the_hint_and_sentences_have_no_em_dash_or_middle_dot(): void {
		$text = MailPassword::hint() . MailPassword::check( 'a', self::BOX )->get_error_message();
		$this->assertStringNotContainsString( "\u{2014}", $text );
		$this->assertStringNotContainsString( "\u{00B7}", $text );
	}
}
