<?php
/** The seams between the My Account platform code and the features: area list, panel words, slug names, activity sentences. @package BeltoftServices */
namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\ActivityMap;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Account\Slugs;
use BeltoftServices\Tests\TestCase;

class AreaSeamsTest extends TestCase {

	public function test_the_areas_come_from_the_filter_in_tab_order(): void {
		$keys = array_map( static fn( ServiceArea $a ): string => $a->key_name(), ServiceArea::all() );

		// The area of an optional feature (hosting) is placed by that feature's own tests.
		$this->assertSame( array( 'domains', 'databases', 'apps', 'email', 'ssl', 'licences', 'storage', 'dedicated' ), array_values( array_diff( $keys, $this->optional( 'area_slugs' ) ) ) );
	}

	public function test_an_area_offered_with_a_lower_priority_comes_first_and_a_bad_name_is_ignored(): void {
		add_filter(
			'beltoft_services_account_areas',
			static function ( $classes ) {
				$classes[] = 'NoSuchArea';
				$classes[] = \stdClass::class;

				return $classes;
			},
			5
		);

		$keys = array_map( static fn( ServiceArea $a ): string => $a->key_name(), ServiceArea::all() );

		$this->assertSame( 'domains', $keys[0] );
		$this->assertCount( 8 + count( $this->optional( 'area_slugs' ) ), $keys );
	}

	public function test_by_key_finds_an_area_and_an_unknown_key_throws(): void {
		$this->assertSame( 'databases', ServiceArea::by_key( 'databases' )->key_name() );
		$this->assertSame( 'email', ServiceArea::by_key( 'email' )->key_name() );

		$this->expectException( \OutOfBoundsException::class );
		ServiceArea::by_key( 'nothing' );
	}

	public function test_only_the_domains_area_leads_and_the_markers_are_new_and_add(): void {
		$leading = array_values( array_filter( ServiceArea::all(), static fn( ServiceArea $a ): bool => $a->leads() ) );

		$this->assertCount( 1, $leading );
		$this->assertSame( 'domains', $leading[0]->key_name() );
		$this->assertEqualsCanonicalizing( array( 'add', 'new' ), ServiceArea::markers() );
	}

	public function test_a_feature_words_a_state_through_the_panel_filters(): void {
		add_filter( 'beltoft_services_panel_label', static fn( $label ) => '' === $label ? 'Own label' : $label );
		add_filter( 'beltoft_services_panel_message', static fn( $message ) => '' === $message ? 'Own message' : $message, 30 );

		$this->assertSame( 'Own label', Panel::label( 'ready' ) );
		$this->assertSame( 'Own message', Panel::message( 'ready' ) );
		$this->assertSame( 'ready', Panel::group( 'ready' ), 'No resource, no app: the group is the plain one.' );
	}

	public function test_the_group_filter_can_turn_a_ready_service_into_one_getting_ready(): void {
		add_filter( 'beltoft_services_panel_group', static fn( $group ) => 'ready' === $group ? 'getting-ready' : $group );

		$this->assertSame( 'getting-ready', Panel::group( 'ready' ) );
		$this->assertSame( 'failed', Panel::group( 'failed' ) );
	}

	public function test_a_server_and_a_licence_name_their_own_address_and_a_host_keeps_its_dots(): void {
		$this->assertSame( 'a.example.com', Slugs::slugify( 'A.Example.com', 'domains' ) );
		$this->assertSame( 'a-example-com', Slugs::slugify( 'A.Example.com', 'servers' ) );
		$this->assertSame( 'Own name', (string) apply_filters( 'beltoft_services_slug_name', 'Own name', 'unknown', null, array() ) );
	}
}
