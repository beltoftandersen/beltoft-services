<?php
/** The fixture paid app and its licence product, shared by the paid app tests. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Product;

/**
 * The fixture paid app is the shipped docker app with a `licence` key added by the filter beltoft_services_apps
 * (the same filter a site can use): nothing is added to any real app.json.
 */
trait PaidAppFixtures {

	protected static string $slug = 'paid-docker-licence';

	/**
	 * Makes docker a paid app. Hooked to the definition filter.
	 *
	 * @param mixed $data Definition.
	 * @param mixed $id   App id.
	 * @return mixed
	 */
	public function paid_docker( $data, $id ) {
		if ( 'docker' === $id && is_array( $data ) ) {
			$data['licence'] = array( 'product_slug' => self::$slug );
		}

		return $data;
	}

	/**
	 * The licence product: a variable subscription product, attribute Tier, one variation per tier.
	 *
	 * @param array<string, string> $tiers Tier key => monthly price.
	 * @param array<string, array<string, string>> $extra Plan fields by tier key (limits).
	 * @return array<string, \WC_Product_Variation> By tier key.
	 */
	protected function tier_product( array $tiers = array( 'basic' => '5', 'pro' => '12' ), array $extra = array() ): array {
		$product   = new \WC_Product_Variable_Subscription();
		$attribute = new \WC_Product_Attribute();
		$names     = array_map( 'ucfirst', array_keys( $tiers ) );

		$product->set_name( 'Docker licence ' . wp_generate_password( 4, false ) );
		$product->set_status( 'publish' );
		$attribute->set_name( 'Tier' );
		$attribute->set_options( $names );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-app' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, self::$slug );
		$product->update_meta_data( Product::META_PLAN . 'app', 'docker' );
		$product->save();
		Product::remember_slug( self::$slug );

		$out = array();

		foreach ( $tiers as $key => $price ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'tier' => ucfirst( $key ) ) );
			$variation->set_regular_price( $price );
			$variation->update_meta_data( '_subscription_price', $price );
			$variation->update_meta_data( '_subscription_period', 'month' );
			$variation->update_meta_data( '_subscription_period_interval', '1' );
			$variation->update_meta_data( '_subscription_length', '0' );
			$variation->set_status( 'publish' );
			$variation->update_meta_data( Product::META_PLAN . 'tier', $key );

			foreach ( $extra[ $key ] ?? array() as $field => $value ) {
				$variation->update_meta_data( Product::META_PLAN . $field, $value );
			}

			$variation->save();
			$out[ $key ] = $variation;
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return array_map( static fn( $v ) => wc_get_product( $v->get_id() ), $out );
	}

	/**
	 * The pay-as-you-go siblings of tiers: same parent, same tier key, Billing "Pay as you go", price 0, meta billing = usage
	 * (the way the shop sells a pay-as-you-go plan). A tier left out of $keys has none.
	 *
	 * @param array<string, \WC_Product_Variation> $tiers From tier_product().
	 * @param string[]                              $keys  Tier keys that get one (all when empty).
	 * @return array<string, \WC_Product_Variation> By tier key.
	 */
	protected function usage_siblings( array $tiers, array $keys = array() ): array {
		$out = array();

		foreach ( $tiers as $key => $monthly ) {
			if ( array() !== $keys && ! in_array( $key, $keys, true ) ) {
				continue;
			}

			$v = new \WC_Product_Variation();
			$v->set_parent_id( $monthly->get_parent_id() );
			$v->set_attributes( array( 'tier' => ucfirst( $key ), 'billing' => 'Pay as you go' ) );
			$v->set_regular_price( '0' );
			$v->set_status( 'publish' );
			$v->update_meta_data( \BeltoftServices\Usage\Rates::META_BILLING, 'usage' );
			$v->update_meta_data( Product::META_PLAN . 'tier', $key );
			$v->save();
			$out[ $key ] = wc_get_product( $v->get_id() );
		}

		\WC_Product_Variable::sync( (int) reset( $tiers )->get_parent_id() );

		return $out;
	}
}
