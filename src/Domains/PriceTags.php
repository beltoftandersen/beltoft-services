<?php
/**
 * Bricks tags for the price of an extension.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic data tags that print a domain extension's yearly price, so a page does not type it:
 *
 *   {beltoft_domain_register:com}  the price to register ".com" for a year
 *   {beltoft_domain_renew:com}     the price to renew it for a year
 *   {beltoft_domain_transfer:com}  the price to transfer it (one year is added)
 *
 * The figure is in the visitor's currency and the shop's tax display, whole amounts without decimals. It is empty when
 * the extension is not sold or has no price in that currency, so a text element can hide itself with the condition
 * "dynamic data is not empty". The html passes the filter beltoft_services_price_html (context 'domain-tag'), so a site
 * can show both tax displays.
 */
final class PriceTags {

	/** Tag name => translated label. */
	private static function labels(): array {
		return array(
			'beltoft_domain_register' => __( 'Domain: price to register for a year', 'beltoft-services' ),
			'beltoft_domain_renew'    => __( 'Domain: price to renew for a year', 'beltoft-services' ),
			'beltoft_domain_transfer' => __( 'Domain: price to transfer', 'beltoft-services' ),
		);
	}

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render' ), 20, 2 );
	}

	/**
	 * The tags in the Bricks list.
	 *
	 * @param mixed $tags Tags.
	 * @return mixed
	 */
	public static function tags_list( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( self::labels() as $name => $label ) {
			$tags[] = array(
				'name'  => '{' . $name . ':com}',
				'label' => $label,
				'group' => Brand::name(),
			);
		}

		return $tags;
	}

	/**
	 * Replaces the tags in a piece of content.
	 *
	 * @param mixed $content Content.
	 * @return mixed
	 */
	public static function render( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{beltoft_domain_' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/\{beltoft_domain_(?:register|renew|transfer):([a-z0-9][a-z0-9.-]*)\}/iD',
			static fn( $m ) => self::value( (string) $m[1] ),
			$content
		);
	}

	/**
	 * The price of an extension as HTML for a page ('' when it has none in the current currency). Registering, renewing and transferring
	 * share one yearly price today; the three tags keep their own names so they can differ later.
	 *
	 * @param string $tld Extension without the dot.
	 */
	public static function value( string $tld ): string {
		$price = Prices::price( $tld );

		return null === $price ? '' : Search::price_html( $price, 'domain-tag', true );
	}
}
