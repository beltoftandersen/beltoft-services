<?php
/**
 * WP-CLI: creates the Plesk product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppLicenceDriver;
use BeltoftServices\Proxmox\PleskLicence;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services plesk-product --dry-run|--create`: ONE variable subscription product, "Plesk" (software slug
 * `plesk`, driver proxmox-app, plan field app=plesk), with a "Tier" attribute and three monthly variations (plan fields
 * `tier` and `tier_label`). NO price is set and the product is a draft: the owner sets the prices and publishes it.
 * It refuses to run when a product with the slug exists, so it is safe to run twice. The tiers can be changed with the
 * filter `beltoft_services_plesk_tiers`.
 */
final class PleskProductCommand {

	public const SLUG = 'plesk';

	/**
	 * The tiers: tier key => name.
	 *
	 * @return array<string, string>
	 */
	public static function tiers(): array {
		$tiers = array(
			'admin' => 'Web Admin',
			'pro'   => 'Web Pro',
			'host'  => 'Web Host',
		);

		/**
		 * The tiers of the Plesk product the CLI creates.
		 *
		 * @param array<string, string> $tiers Tier key => name.
		 */
		$filtered = apply_filters( 'beltoft_services_plesk_tiers', $tiers );

		return is_array( $filtered ) && array() !== $filtered ? $filtered : $tiers;
	}

	/**
	 * The id of the Plesk product in any status, 0 when there is none.
	 */
	public static function existing(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => \BeltoftLicenses\Software::META_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => self::SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Creates the product and its variations, or returns the one that exists.
	 *
	 * @return int Product id.
	 */
	public static function ensure(): int {
		$found = self::existing();

		return $found > 0 ? $found : self::create();
	}

	/**
	 * Creates the product and its variations.
	 *
	 * @return int Product id.
	 */
	public static function create(): int {
		$tiers   = self::tiers();
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Plesk' );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_virtual( true );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Tier' );
		$attribute->set_options( array_map( 'strval', array_values( $tiers ) ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$id = $product->save();

		Product::remember_slug( self::SLUG );

		foreach ( array(
			\BeltoftLicenses\Software::META_LICENSED => 'yes',
			\BeltoftLicenses\Software::META_SLUG     => self::SLUG,
			'_beltoft_licenses_max_activations'      => '1',
			Product::META_DRIVER                     => AppLicenceDriver::ID,
			Product::META_PLAN . 'app'               => PleskLicence::APP,
			'_subscription_period'                   => 'month',
			'_subscription_period_interval'          => '1',
			'_subscription_length'                   => '0',
			'_subscription_trial_length'             => '0',
			'_subscription_sign_up_fee'              => '0',
		) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		foreach ( $tiers as $key => $name ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $id );
			$variation->set_attributes( array( 'tier' => (string) $name ) );
			$variation->set_virtual( true );
			$variation->set_status( 'publish' );
			$variation->set_tax_class( 'parent' );
			$vid = $variation->save();

			// No price: the owner sets it.
			foreach ( array(
				'_subscription_period'          => 'month',
				'_subscription_period_interval' => '1',
				'_subscription_length'          => '0',
				'_subscription_trial_length'    => '0',
				'_subscription_sign_up_fee'     => '0',
				Product::META_PLAN . 'tier'     => (string) $key,
				Product::META_PLAN . 'tier_label' => (string) $name,
			) as $meta_key => $value ) {
				update_post_meta( $vid, $meta_key, $value );
			}
		}

		\WC_Product_Variable::sync( $id );
		wc_delete_product_transients( $id );

		return $id;
	}

	/**
	 * Creates the Plesk product, or shows what would be created.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the product and its tiers. Nothing is written.
	 *
	 * [--create]
	 * : Create the product (a draft, no prices). Refused when a product with the slug plesk exists.
	 *
	 * @param array<int, string>   $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry    = ! empty( $assoc_args['dry-run'] );
		$create = ! empty( $assoc_args['create'] );

		if ( $dry === $create ) {
			\WP_CLI::error( 'Give exactly one of --dry-run and --create.' );
		}

		$found = self::existing();

		if ( $found > 0 ) {
			\WP_CLI::error( 'A product with the software slug plesk exists already: ' . $found . '. Nothing was changed.' );
		}

		$items = PleskLicence::items();

		foreach ( self::tiers() as $key => $name ) {
			\WP_CLI::log( sprintf( '%s (tier %s): Openprovider item %s, no price', $name, $key, $items[ $key ] ?? 'NOT SET' ) );
		}

		if ( $dry ) {
			\WP_CLI::log( 'Dry run: would create one draft variable subscription product "Plesk" with these tiers (monthly, no price). Nothing was written.' );
			return;
		}

		\WP_CLI::success( 'Created draft product ' . self::create() . ' with ' . count( self::tiers() ) . ' tiers and no prices. Set the prices, the costs (Settings, Domains) and publish it.' );
	}
}
