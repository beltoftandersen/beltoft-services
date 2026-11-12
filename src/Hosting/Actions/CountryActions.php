<?php
/**
 * The country block of a website.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting\Actions;

use BeltoftServices\Hosting\CountryBlock;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the country block of a website of the account. The work is the node agent's `site.country_block`
 * (nginx in the container, see CountryBlock); the state is kept in the website's flags. Reached through the
 * HostingActions facade.
 */
final class CountryActions {

	use Base;

	/**
	 * The block of a website, the ready lists and the country of the person asking when it is known.
	 *
	 * @param string $slug Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function country_block( string $slug ) {
		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		return CountryBlock::view( $site ) + array( 'lists' => CountryBlock::lists(), 'own_country' => CountryBlock::visitor_country() );
	}

	/**
	 * Sets the block of a website; an empty list turns it off. When the country of the person asking is on the list, nothing
	 * is saved until they have confirmed it.
	 *
	 * @param string   $slug      Site.
	 * @param string[] $countries Country codes.
	 * @param string   $scope     site or login.
	 * @param bool     $confirmed The customer saw that their own country is on the list and wants it anyway.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_country_block( string $slug, array $countries, string $scope, bool $confirmed = false ) {
		return $this->locked( fn() => $this->set_country_block_locked( $slug, $countries, $scope, $confirmed ) );
	}

	/**
	 * set_country_block with the site lock held.
	 *
	 * @param string   $slug      Site.
	 * @param string[] $countries Codes.
	 * @param string   $scope     Scope.
	 * @param bool     $confirmed Confirmed.
	 * @return mixed
	 */
	private function set_country_block_locked( string $slug, array $countries, string $scope, bool $confirmed ) {
		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site ) {
			return $this->error( 'not_found', __( 'That website does not exist.', 'beltoft-services' ) );
		}

		$codes = CountryBlock::clean( $countries );

		if ( null === $codes ) {
			return $this->error( 'invalid', __( 'Choose countries from the list.', 'beltoft-services' ) );
		}

		if ( ! in_array( $scope, CountryBlock::SCOPES, true ) ) {
			return $this->error( 'invalid', __( 'Choose where the block applies.', 'beltoft-services' ) );
		}

		$own = CountryBlock::visitor_country();

		if ( ! $confirmed && '' !== $own && in_array( $own, $codes, true ) ) {
			/* translators: %s: the country of the person asking. */
			$whole = __( 'Your own country, %s, is on this list. You would be blocked from the whole website too, and the buttons on this page that open WordPress, the file manager and the database tool would stop working for you. Save anyway?', 'beltoft-services' );
			/* translators: %s: the country of the person asking. */
			$login = __( 'Your own country, %s, is on this list. You would be blocked from the WordPress login and admin area too, and the buttons on this page that open WordPress, the file manager and the database tool would stop working for you. Save anyway?', 'beltoft-services' );

			return new \WP_Error( 'country_confirm', sprintf( 'site' === $scope ? $whole : $login, CountryBlock::name( $own ) ), array( 'country' => $own ) );
		}

		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_country_' . $slug, 'site.country_block', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'countries' => $codes, 'scope' => $scope ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$active = array() === $codes ? null : ! empty( $done['active'] );

		Sites::update(
			$this->r->id(),
			$slug,
			array(
				'flags' => array(
					'country_block' => array(
						'countries' => $codes,
						'scope'     => $scope,
						'active'    => $active,
						'at'        => \BeltoftServices\Jobs::now(),
					),
				),
			)
		);

		return CountryBlock::view( (array) Sites::find( $this->r->id(), $slug ) ) + array( 'slug' => $slug );
	}

	/**
	 * Sends the stored block of a website to the node again (after a restore, or any path that renders the website afresh
	 * and so loses the node's copy). Nothing is changed in the shop: the stored setting is the truth. A website that never
	 * had a block set is left alone; one whose block was turned off is sent as off, so a restored older copy does not bring
	 * a removed block back.
	 *
	 * @param string $slug Site.
	 * @return true|\WP_Error True when sent or when there is nothing stored.
	 */
	public function resend( string $slug ) {
		return $this->locked( fn() => $this->resend_locked( $slug ) );
	}

	/**
	 * resend with the site lock held.
	 *
	 * @param string $slug Site.
	 * @return true|\WP_Error
	 */
	private function resend_locked( string $slug ) {
		$site = Sites::find( $this->r->id(), $slug );

		if ( null === $site || ! isset( $site['flags']['country_block'] ) ) {
			return true;
		}

		$ready = $this->ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$stored = CountryBlock::stored( $site );
		$record = (array) Records::for_resource( $this->r->id() );
		$done   = $this->call( 'site_country_' . $slug, 'site.country_block', array( 'vmid' => (int) $record['vmid'], 'slug' => $slug, 'countries' => $stored['countries'], 'scope' => $stored['scope'] ) );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$block           = (array) $site['flags']['country_block'];
		$block['active'] = array() === $stored['countries'] ? null : ! empty( $done['active'] );
		Sites::update( $this->r->id(), $slug, array( 'flags' => array( 'country_block' => $block ) ) );

		return true;
	}
}
