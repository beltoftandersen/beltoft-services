<?php
/**
 * Opening a pay as you go product and saving it without a change writes nothing.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Product;
use BeltoftServices\Usage\Admin\PaygVariation;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * The form is read back from what the editor prints, as the browser would post it.
 */
class PaygUntouchedSaveTest extends PaygTestCase {

	/**
	 * What a form posts: every input with a name and value, and the selected option of every select.
	 *
	 * @param string $html Markup.
	 * @return array<string, string>
	 */
	private function form( string $html ): array {
		$out = array();

		preg_match_all( '/<input\b[^>]*>/i', $html, $inputs );

		foreach ( $inputs[0] as $tag ) {
			if ( preg_match( '/\bname=["\']([^"\']+)["\']/', $tag, $n ) && 1 !== preg_match( '/type=["\'](submit|button|checkbox|radio)["\']/', $tag ) ) {
				$out[ html_entity_decode( $n[1] ) ] = preg_match( '/\bvalue=["\']([^"\']*)["\']/', $tag, $v ) ? html_entity_decode( $v[1] ) : '';
			}
		}

		preg_match_all( '/<select\b[^>]*name=["\']([^"\']+)["\'][^>]*>(.*?)<\/select>/is', $html, $selects, PREG_SET_ORDER );

		foreach ( $selects as $s ) {
			$out[ $s[1] ] = preg_match( '/<option\s+value=["\']([^"\']*)["\'][^>]*selected/i', $s[2], $o ) ? $o[1] : '';
		}

		return $out;
	}

	/**
	 * A flat name list ("a[0][EUR]") as the nested array PHP would make of it.
	 *
	 * @param array<string, string> $flat Names and values.
	 * @return array<string, mixed>
	 */
	private function nested( array $flat ): array {
		parse_str( http_build_query( $flat ), $post );

		return $post;
	}

	private function metas( int $id ): array {
		$all = get_post_meta( $id );

		return array_filter( $all, static fn( $key ) => 1 === preg_match( '/billing|usage|unit_/', (string) $key ), ARRAY_FILTER_USE_KEY );
	}

	public function test_a_product_shaped_like_the_script_made_ones_is_saved_untouched(): void {
		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( $codes, array( 'DKK' ) ) );
		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

		$product = $this->payg_product( 'proxmox', 'usage', array( 'Small' => '15.50', 'Large' => '30' ) );
		$small   = $this->variation_with( $product, 'plan', 'Small' );
		$small->update_meta_data( Rates::META_MONTHLY . '_DKK', '115.00' );
		$small->save();
		$product->update_meta_data( Rates::META_PREMIUM, '10' );
		$product->save();

		$product = wc_get_product( $product->get_id() );
		$ids     = array( $product->get_id() );

		foreach ( PaygProductAccess::variations( $product ) as $v ) {
			$ids[] = $v->get_id();
		}

		$before = array_map( fn( $id ) => $this->metas( $id ), $ids );

		// The Service panel and the variation rows as printed.
		$GLOBALS['product_object'] = $product;
		ob_start();
		Product::panel();
		$panel = (string) ob_get_clean();
		$post  = $this->form( $panel );

		foreach ( PaygProductAccess::variations( $product ) as $i => $v ) {
			ob_start();
			PaygVariation::fields( $i, array(), get_post( $v->get_id() ) );
			$post += $this->form( (string) ob_get_clean() );
		}

		$this->assertArrayHasKey( Rates::META_BILLING, $post );
		$this->assertSame( 'usage', $post[ Rates::META_BILLING ] );
		$this->assertSame( '115.00', $post[ PaygVariation::FIELD_MONTHLY . '[0][DKK]' ] ?? $post[ PaygVariation::FIELD_MONTHLY . '[1][DKK]' ] ?? '', 'the variation rows are in the posted form' );

		$_POST = $this->nested( $post );

		Product::save( $product );
		$product->save();

		foreach ( PaygProductAccess::variations( $product ) as $i => $v ) {
			PaygVariation::save( $v, $i );
			$v->save();
		}

		$this->assertSame( array(), $this->refusals() );
		$this->assertSame( $before, array_map( fn( $id ) => $this->metas( $id ), $ids ), 'no pay as you go meta was written, changed or removed' );
	}
}

/**
 * Test helper: the editor's own variation list.
 */
final class PaygProductAccess {

	/**
	 * Variations of a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return \WC_Product_Variation[]
	 */
	public static function variations( \WC_Product $product ): array {
		return \BeltoftServices\Usage\Admin\PaygProduct::variations( wc_get_product( $product->get_id() ) );
	}
}
