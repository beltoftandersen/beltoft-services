<?php
/**
 * WP-CLI: creates the Business email product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Product;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services mail-products --dry-run|--create`: ONE variable subscription product, "Business email", with a
 * "Plan" attribute and three monthly variations (no trial, no sign-up fee, standard tax class, visible in the
 * catalogue). It refuses to run when a product with the mail driver exists, so it is safe to run twice. Nothing else
 * is touched. Prices and limits are the defaults below and can be changed with the filter
 * `beltoft_services_mail_plans`.
 */
final class MailProductsCommand {

	public const SLUG = 'business-email';

	/**
	 * The plans: name => price (per month, excluding VAT), mailboxes, size of each mailbox in MB, aliases, domains.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	public static function plans(): array {
		$plans = array(
			'Personal' => array( 'price' => '5', 'mailboxes' => 1, 'quota_mb' => 5120, 'aliases' => 20, 'domains' => 1 ),
			'Team'     => array( 'price' => '25', 'mailboxes' => 5, 'quota_mb' => 10240, 'aliases' => 50, 'domains' => 3 ),
			'Business' => array( 'price' => '60', 'mailboxes' => 10, 'quota_mb' => 20480, 'aliases' => 200, 'domains' => 10 ),
		);

		/**
		 * The plans of the Business email product the CLI creates.
		 *
		 * @param array<string, array<string, int|string>> $plans Name => price, mailboxes, quota_mb, aliases, domains.
		 */
		$filtered = apply_filters( 'beltoft_services_mail_plans', $plans );

		return is_array( $filtered ) && array() !== $filtered ? $filtered : $plans;
	}

	/**
	 * The id of a product with the mail driver, 0 when there is none.
	 */
	public static function existing(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => Product::META_DRIVER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => MailDriver::ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Creates the product and its variations.
	 *
	 * @return int Product id.
	 */
	public static function create(): int {
		$plans   = self::plans();
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Business email' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_virtual( true );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Plan' );
		$attribute->set_options( array_map( 'strval', array_keys( $plans ) ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$id = $product->save();

		Product::remember_slug( self::SLUG );

		foreach ( array(
			'_beltoft_licenses_licensed'        => 'yes',
			'_beltoft_licenses_software_slug'   => self::SLUG,
			'_beltoft_licenses_max_activations' => '1',
			Product::META_DRIVER                => MailDriver::ID,
			'_subscription_period'              => 'month',
			'_subscription_period_interval'     => '1',
			'_subscription_length'              => '0',
			'_subscription_trial_length'        => '0',
			'_subscription_sign_up_fee'         => '0',
		) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		foreach ( $plans as $name => $plan ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $id );
			$variation->set_attributes( array( 'plan' => (string) $name ) );
			$variation->set_regular_price( (string) $plan['price'] );
			$variation->set_virtual( true );
			$variation->set_status( 'publish' );
			$variation->set_tax_class( 'parent' );
			$variation->set_description( sprintf( '%d mailbox(es) of %d GB, %d aliases, %d domain(s)', (int) $plan['mailboxes'], (int) $plan['quota_mb'] / 1024, (int) $plan['aliases'], (int) $plan['domains'] ) );
			$vid = $variation->save();

			$meta = array(
				'_subscription_price'           => (string) $plan['price'],
				'_subscription_period'          => 'month',
				'_subscription_period_interval' => '1',
				'_subscription_length'          => '0',
				'_subscription_trial_length'    => '0',
				'_subscription_sign_up_fee'     => '0',
			);

			foreach ( MailDriver::limits_from( $plan ) as $key => $value ) {
				$meta[ Product::META_PLAN . $key ] = (string) $value;
			}

			foreach ( $meta as $key => $value ) {
				update_post_meta( $vid, $key, $value );
			}
		}

		\WC_Product_Variable::sync( $id );
		wc_delete_product_transients( $id );

		return $id;
	}

	/**
	 * Creates the Business email product, or shows what would be created.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the product and its plans. Nothing is written.
	 *
	 * [--create]
	 * : Create the product. Refused when a product with the mail driver exists.
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
			\WP_CLI::error( 'A product with the mail driver exists already: ' . $found . '. Nothing was changed.' );
		}

		foreach ( self::plans() as $name => $plan ) {
			\WP_CLI::log( sprintf( '%s: %s EUR per month excluding VAT, %d mailbox(es), %d MB each, %d aliases, %d domain(s)', $name, $plan['price'], $plan['mailboxes'], $plan['quota_mb'], $plan['aliases'], $plan['domains'] ) );
		}

		if ( $dry ) {
			\WP_CLI::log( 'Dry run: would create one variable subscription product "Business email" with these plans (monthly, no trial, no sign-up fee, standard tax class, visible). Nothing was written.' );
			return;
		}

		\WP_CLI::success( 'Created product ' . self::create() . ' with ' . count( self::plans() ) . ' plans.' );
	}
}
