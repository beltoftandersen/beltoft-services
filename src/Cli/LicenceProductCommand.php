<?php
/**
 * WP-CLI: creates the licence key products (standalone Plesk licence, CloudLinux, Plesk add-ons).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Licences\KeyCatalog;
use BeltoftServices\Licences\KeyDriver;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services licence-product --family=plesk|cloudlinux|addons --dry-run|--create [--prices=pro:30,pro-physical:35]`.
 *
 * One variable subscription product per family (driver licence-key, software slug plesk-licence, cloudlinux or
 * plesk-addons) with an attribute "Licence" and one monthly variation per catalogue key (plan fields lic_kind, lic_tier,
 * lic_server, lic_label). The product is a draft: the owner publishes it. Without --prices no price is set; with it each
 * key gets its monthly price (the shop's price as entered, EUR). It refuses to run when a product with the slug exists,
 * so it is safe to run twice.
 */
final class LicenceProductCommand {

	/**
	 * The name of each family's product.
	 *
	 * @param string $family Family.
	 */
	public static function product_name( string $family ): string {
		return array(
			KeyCatalog::FAMILY_PLESK      => 'Plesk licence',
			KeyCatalog::FAMILY_CLOUDLINUX => 'CloudLinux',
			KeyCatalog::FAMILY_ADDONS     => 'Plesk add-ons',
		)[ $family ] ?? '';
	}

	/**
	 * The id of a family's product in any status, 0 when there is none.
	 *
	 * @param string $family Family.
	 */
	public static function existing( string $family ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => \BeltoftLicenses\Software::META_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => KeyCatalog::SLUGS[ $family ] ?? '-', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Reads "pro:30,pro-physical:35" into key => price. Every key named must belong to the family and every price must be
	 * a positive number with at most two decimals.
	 *
	 * @param string $raw    The option value.
	 * @param string $family Family.
	 * @return array<string, string>|\WP_Error
	 */
	public static function parse_prices( string $raw, string $family ) {
		$out  = array();
		$keys = KeyCatalog::keys_of( $family );

		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			$key   = strtolower( trim( $parts[0] ) );
			$price = trim( (string) ( $parts[1] ?? '' ) );

			if ( ! isset( $keys[ $key ] ) ) {
				return new \WP_Error( 'key', sprintf( 'Unknown licence "%s" for this family. The keys are: %s.', $key, implode( ', ', array_keys( $keys ) ) ) );
			}

			if ( 1 !== preg_match( '/^\d{1,6}(\.\d{1,2})?$/D', $price ) || (float) $price <= 0 ) {
				return new \WP_Error( 'price', sprintf( 'The price of "%s" must be a positive number such as 17 or 14.50.', $key ) );
			}

			$out[ $key ] = (string) (float) $price;
		}

		return $out;
	}

	/**
	 * Creates the product and its variations.
	 *
	 * @param string                $family Family.
	 * @param array<string, string> $prices Key => monthly price; a key without one has no price.
	 * @return int Product id.
	 */
	public static function create( string $family, array $prices = array() ): int {
		$keys    = KeyCatalog::keys_of( $family );
		$slug    = KeyCatalog::SLUGS[ $family ];
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( self::product_name( $family ) );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Licence' );
		$attribute->set_options( array_map( 'strval', array_values( $keys ) ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$id = $product->save();

		Product::remember_slug( $slug );

		foreach ( array(
			\BeltoftLicenses\Software::META_LICENSED => 'yes',
			\BeltoftLicenses\Software::META_SLUG     => $slug,
			'_beltoft_licenses_max_activations'      => '1',
			Product::META_DRIVER                     => KeyDriver::ID,
			'_subscription_period'                   => 'month',
			'_subscription_period_interval'          => '1',
			'_subscription_length'                   => '0',
			'_subscription_trial_length'             => '0',
			'_subscription_sign_up_fee'              => '0',
		) as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
		}

		foreach ( $keys as $key => $name ) {
			$edition   = KeyCatalog::is_edition( $key );
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $id );
			$variation->set_attributes( array( 'licence' => (string) $name ) );
			$variation->set_virtual( true );
			$variation->set_status( 'publish' );
			$variation->set_tax_class( 'parent' );
			$vid = $variation->save();

			// A price only when one was given; otherwise the owner sets it.
			if ( isset( $prices[ $key ] ) ) {
				$variation = wc_get_product( $vid );
				$variation->set_regular_price( $prices[ $key ] );
				$variation->save();
				update_post_meta( $vid, '_subscription_price', $prices[ $key ] );
			}

			foreach ( array(
				'_subscription_period'            => 'month',
				'_subscription_period_interval'   => '1',
				'_subscription_length'            => '0',
				'_subscription_trial_length'      => '0',
				'_subscription_sign_up_fee'       => '0',
				Product::META_PLAN . 'lic_kind'   => $edition ? 'edition' : 'addon',
				Product::META_PLAN . 'lic_tier'   => $edition ? (string) preg_replace( '/-physical$/D', '', $key ) : (string) $key,
				Product::META_PLAN . 'lic_server' => $edition ? ( KeyCatalog::is_physical( $key ) ? 'physical' : 'vps' ) : '',
				Product::META_PLAN . 'lic_label'  => $edition ? 'Plesk ' . $name : (string) $name,
			) as $meta_key => $value ) {
				update_post_meta( $vid, $meta_key, $value );
			}
		}

		\WC_Product_Variable::sync( $id );
		wc_delete_product_transients( $id );

		return $id;
	}

	/**
	 * Creates a licence key product, or shows what would be created.
	 *
	 * ## OPTIONS
	 *
	 * --family=<family>
	 * : plesk (the three editions for a virtual and for a physical server), cloudlinux (the four editions) or addons (Imunify360, ImunifyAV+, KernelCare, SpamExperts).
	 *
	 * [--dry-run]
	 * : Print the product and its licences. Nothing is written.
	 *
	 * [--create]
	 * : Create the product (a draft). Refused when a product with the family's slug exists, so it is safe to run twice.
	 *
	 * [--prices=<prices>]
	 * : Monthly price of each licence, for example pro:30,pro-physical:35 (the shop's price as entered). Without it the licences have no price.
	 *
	 * @param array<int, string>   $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$family = strtolower( (string) ( $assoc_args['family'] ?? '' ) );
		$dry    = ! empty( $assoc_args['dry-run'] );
		$create = ! empty( $assoc_args['create'] );

		if ( ! isset( KeyCatalog::SLUGS[ $family ] ) ) {
			\WP_CLI::error( 'Give --family=plesk, --family=cloudlinux or --family=addons.' );
		}

		if ( $dry === $create ) {
			\WP_CLI::error( 'Give exactly one of --dry-run and --create.' );
		}

		$prices = array();

		if ( isset( $assoc_args['prices'] ) ) {
			$prices = self::parse_prices( (string) $assoc_args['prices'], $family );

			if ( is_wp_error( $prices ) ) {
				\WP_CLI::error( $prices->get_error_message() );
			}
		}

		$found = self::existing( $family );

		if ( $found > 0 ) {
			\WP_CLI::error( 'A product with the software slug ' . KeyCatalog::SLUGS[ $family ] . ' exists already: ' . $found . '. Nothing was changed.' );
		}

		$items = KeyCatalog::items();
		$costs = KeyCatalog::costs();

		foreach ( KeyCatalog::keys_of( $family ) as $key => $name ) {
			\WP_CLI::log( sprintf( '%s (%s): Openprovider item %s, cost %s, %s', $name, $key, $items[ $key ] ?? 'NOT SET (set it in Settings, Services, Domains, Licence key items before the first order)', isset( $costs[ $key ] ) ? (string) $costs[ $key ] : 'NOT SET', isset( $prices[ $key ] ) ? 'price ' . $prices[ $key ] . ' per month' : 'no price' ) );
		}

		if ( $dry ) {
			\WP_CLI::log( 'Dry run: would create one draft variable subscription product "' . self::product_name( $family ) . '" with these licences (monthly). Nothing was written.' );
			return;
		}

		$id = self::create( $family, $prices );

		\WP_CLI::success( sprintf( 'Created draft product %1$d with %2$d licences%3$s. Set the licence key items and costs (Settings, Services, Domains) and publish it.', $id, count( KeyCatalog::keys_of( $family ) ), array() === $prices ? ' and no prices' : ' and the given prices' ) );
	}
}
