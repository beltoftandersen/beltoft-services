<?php
/**
 * Moving account credit to gift cards (wp beltoft-services credit move-to-cards).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use Bgcw\GiftCard\Repository;
use Bgcw\GiftCard\Source;
use BeltoftServices\Cli\CreditCommand;
use BeltoftServices\Credit\Balance;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';
require_once __DIR__ . '/GiftCreditTestCase.php';

/**
 * The move.
 */
class MoveToCardsTest extends GiftCreditTestCase {

	/**
	 * The customer's account cards.
	 *
	 * @param int $user User.
	 * @return array<int, object>
	 */
	private function cards_of( int $user ): array {
		return array_values( array_filter( Repository::get_by_customer( $user ), fn( $c ) => 'active' === $c->status && ! in_array( (int) $c->id, $this->before, true ) ) );
	}

	/**
	 * Card ids that were there before the test (the gift card tables outlive a test's rollback).
	 *
	 * @var int[]
	 */
	private $before = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->before = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bgcw_gift_cards" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
	}

	public function test_free_credit_becomes_one_free_card_and_a_second_run_makes_none(): void {
		$user = $this->new_user();
		$this->assertTrue( Balance::credit( $user, 86920, Balance::PROMO, 'test', 'grant-' . $user, 'Welcome', 'DKK' ) );

		\WP_CLI::reset();
		( new CreditCommand() )->move_to_cards( array(), array() );
		$this->assertSame( array(), $this->cards_of( $user ), 'A dry run makes nothing.' );
		$this->assertSame( 86920, Balance::get( $user ) );

		( new CreditCommand() )->move_to_cards( array(), array( 'apply' => true ) );
		$cards = $this->cards_of( $user );
		$this->assertCount( 1, $cards );
		$this->assertSame( Source::PROMOTION, $cards[0]->source );
		$this->assertSame( 'DKK', $cards[0]->currency );
		$this->assertEqualsWithDelta( 869.20, (float) $cards[0]->balance, 0.001 );
		$this->assertNull( $cards[0]->expires_at, 'Account cards never expire.' );
		$this->assertSame( 0, Balance::get( $user ), 'The ledger is closed.' );

		( new CreditCommand() )->move_to_cards( array(), array( 'apply' => true ) );
		$this->assertCount( 1, $this->cards_of( $user ), 'A second run moves nothing.' );
	}

	public function test_paid_credit_becomes_a_paid_card(): void {
		$user = $this->new_user();
		$this->assertTrue( Balance::credit( $user, 2500, Balance::TOPUP, 'order_item', 'seed-' . $user, '', 'EUR' ) );

		\WP_CLI::reset();
		( new CreditCommand() )->move_to_cards( array(), array( 'apply' => true ) );

		$cards = $this->cards_of( $user );
		$this->assertCount( 1, $cards );
		$this->assertSame( Source::PAID_OFFLINE, $cards[0]->source );
		$this->assertEqualsWithDelta( 25.0, (float) $cards[0]->balance, 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
	}
}
