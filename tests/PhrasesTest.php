<?php
/** The words typed to confirm a step: shown in the page language, sent in English. @package BeltoftServices */
namespace BeltoftServices\Tests;

use BeltoftServices\Phrases;

class PhrasesTest extends TestCase {
	private const DANISH = array(
		'RESTORE'   => 'GENDAN',
		'DELETE'    => 'SLET',
		'REINSTALL' => 'GENINSTALLER',
		'ROLLBACK'  => 'TILBAGE',
		'CANCEL'    => 'ANNULLER',
	);

	private function danish(): callable {
		$filter = static fn( $translated, $text, $domain ) => 'beltoft-services' === $domain && isset( self::DANISH[ $text ] ) ? self::DANISH[ $text ] : $translated;
		add_filter( 'gettext', $filter, 10, 3 );
		return $filter;
	}

	public function test_every_word_is_english_without_a_translation(): void {
		foreach ( array_keys( self::DANISH ) as $word ) {
			$this->assertSame( $word, Phrases::shown( $word ) );
		}

		$this->assertSame( 'my-server', Phrases::shown( 'my-server' ), 'A name that is not a fixed word is shown as it is.' );
	}

	public function test_the_shown_word_is_translated_and_the_english_word_is_what_is_sent(): void {
		$filter = $this->danish();

		foreach ( self::DANISH as $english => $shown ) {
			$this->assertSame( $shown, Phrases::shown( $english ) );
			$this->assertSame( $english, Phrases::sent( $shown, $english ), 'Typing the shown word sends the English one.' );
			$this->assertSame( $english, Phrases::sent( ' ' . $shown . ' ', $english ) );
			$this->assertSame( $english, Phrases::sent( $english, $english ), 'The English word still goes through.' );
			$this->assertSame( 'x', Phrases::sent( 'x', $english ), 'Anything else is sent as typed and refused by the route.' );
		}

		remove_filter( 'gettext', $filter, 10 );
	}

	public function test_the_input_attributes_carry_both_words(): void {
		$filter = $this->danish();
		$attrs  = Phrases::attrs( 'RESTORE' );
		remove_filter( 'gettext', $filter, 10 );

		$this->assertSame( ' data-phrase-send="RESTORE" data-phrase-shown="GENDAN"', $attrs );
	}

	public function test_the_database_restore_page_asks_for_the_shown_word_and_keeps_the_english_constant(): void {
		$filter = $this->danish();
		$source = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/db.php' );
		remove_filter( 'gettext', $filter, 10 );

		$this->assertStringContainsString( "Phrases::shown( (string) \$beltoft_services_backups['confirm'] )", $source );
		$this->assertStringContainsString( "Phrases::attrs( (string) \$beltoft_services_backups['confirm'] )", $source );
		$this->assertSame( 'RESTORE', \BeltoftServices\Proxmox\Vm\Actions::CONFIRM_RESTORE );
	}

	public function test_every_confirm_input_sends_through_the_phrase_helper(): void {
		$js = static fn( string $file ): string => (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/' . $file );

		$this->assertStringContainsString( 'function phrase( input )', $js( 'status.js' ) );
		$this->assertStringContainsString( 'phrase: phrase', $js( 'status.js' ) );
		$this->assertStringContainsString( 'confirm: S.phrase( input )', $js( 'db.js' ) );
		$this->assertStringContainsString( 'args[ field.name ] = bs.phrase( field )', $js( 'vm.js' ) );
		$this->assertStringContainsString( 'S.phrase( input ).trim()', $js( 'mail.js' ) );
		$this->assertStringContainsString( 'S.phrase( input )', $js( 'ssl.js' ) );
		$this->assertStringContainsString( 'window.beltoftStatus.phrase( input )', $js( 'delete.js' ) );

		$vm = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/vm.php' );

		foreach ( array( 'RESTORE', 'REINSTALL', 'ROLLBACK' ) as $word ) {
			$this->assertStringContainsString( "Phrases::shown( '" . $word . "' )", $vm );
			$this->assertStringContainsString( "Phrases::attrs( '" . $word . "' )", $vm );
		}
	}

	public function test_the_delete_block_shows_the_translated_word_only_for_the_fixed_word(): void {
		$template = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'templates/account/delete.php' );

		$this->assertStringContainsString( 'data-delete-shown=', $template );
		$this->assertStringContainsString( 'CustomerDelete::CONFIRM === $beltoft_services_delete_word', $template );
		$this->assertSame( 'DELETE', \BeltoftServices\Usage\CustomerDelete::CONFIRM );
	}
}
