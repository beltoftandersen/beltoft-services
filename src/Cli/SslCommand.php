<?php
/**
 * WP-CLI: the SSL certificate product, the cost read and the state of one certificate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Product;
use BeltoftServices\RenewalReminder;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslDriver;

defined( 'ABSPATH' ) || exit;

/**
 * `wp beltoft-services ssl product --dry-run|--create [--prices=dv:19,dv_wildcard:99,ov:55,ev:129]`: ONE variable subscription
 * product (software slug `ssl-certificate`, driver ssl), "Sold individually", virtual, hidden from the shop catalog and a
 * draft, with one yearly variation per certificate type of the launch catalog (the plan fields are the registrar product,
 * the validation level and wildcard). The product's renewal reminder is 30 days. It refuses to run when a product with the
 * slug exists. The variations are the filter `beltoft_services_ssl_launch_products` (key => registrar product id).
 */
final class SslCommand {

	public const SLUG = 'ssl-certificate';

	/** Reminder, in days before the renewal is charged. */
	public const REMINDER_DAYS = 30;

	/**
	 * The variations: key => [name, validation level, wildcard].
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function types(): array {
		$known = array(
			'dv'          => array( __( 'Domain validated', 'beltoft-services' ), 'dv', false ),
			'dv_wildcard' => array( __( 'Domain validated wildcard', 'beltoft-services' ), 'dv', true ),
			'ov'          => array( __( 'Organization validated', 'beltoft-services' ), 'ov', false ),
			'ev'          => array( __( 'Extended validation', 'beltoft-services' ), 'ev', false ),
		);
		$out   = array();

		foreach ( SslCatalog::launch() as $key => $id ) {
			if ( isset( $known[ $key ] ) && $id > 0 ) {
				$out[ $key ] = $known[ $key ];
			}
		}

		return $out;
	}

	/**
	 * The id of the certificate product in any status, 0 when there is none.
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
	 * Reads "dv:19,ov:55" into key => price. Every key must be a type of the catalog and every price a positive number.
	 *
	 * @param string $raw The option value.
	 * @return array<string, string>|\WP_Error
	 */
	public static function parse_prices( string $raw ) {
		$out   = array();
		$types = self::types();

		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			$key   = strtolower( trim( $parts[0] ) );
			$price = trim( (string) ( $parts[1] ?? '' ) );

			if ( ! isset( $types[ $key ] ) ) {
				return new \WP_Error( 'type', sprintf( 'Unknown type "%s". The types are: %s.', $key, implode( ', ', array_keys( $types ) ) ) );
			}

			if ( 1 !== preg_match( '/^\d{1,6}(\.\d{1,2})?$/D', $price ) || (float) $price <= 0 ) {
				return new \WP_Error( 'price', sprintf( 'The price of "%s" must be a positive number such as 19 or 14.50.', $key ) );
			}

			$out[ $key ] = (string) (float) $price;
		}

		return $out;
	}

	/**
	 * Creates the product and its variations.
	 *
	 * @param array<string, string> $prices Type => yearly price (as entered in the shop); a type without one has no price.
	 * @return int Product id.
	 */
	public static function create( array $prices = array() ): int {
		$types   = self::types();
		$ids     = SslCatalog::launch();
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( __( 'SSL certificate', 'beltoft-services' ) );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Type' );
		$attribute->set_options( array_map( static fn( $t ) => (string) $t[0], array_values( $types ) ) );
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
			Product::META_DRIVER                     => SslDriver::ID,
			RenewalReminder::META                    => (string) self::REMINDER_DAYS,
			'_subscription_period'                   => 'year',
			'_subscription_period_interval'          => '1',
			'_subscription_length'                   => '0',
			'_subscription_trial_length'             => '0',
			'_subscription_sign_up_fee'              => '0',
		) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		foreach ( $types as $key => $type ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $id );
			$variation->set_attributes( array( 'type' => (string) $type[0] ) );
			$variation->set_virtual( true );
			$variation->set_status( 'publish' );
			$variation->set_tax_class( 'parent' );
			$vid = $variation->save();

			if ( isset( $prices[ $key ] ) ) {
				$variation = wc_get_product( $vid );
				$variation->set_regular_price( $prices[ $key ] );
				$variation->save();
				update_post_meta( $vid, '_subscription_price', $prices[ $key ] );
			}

			foreach ( array(
				'_subscription_period'               => 'year',
				'_subscription_period_interval'      => '1',
				'_subscription_length'               => '0',
				'_subscription_trial_length'         => '0',
				'_subscription_sign_up_fee'          => '0',
				Product::META_PLAN . 'ssl_product'   => (string) (int) $ids[ $key ],
				Product::META_PLAN . 'ssl_level'     => (string) $type[1],
				Product::META_PLAN . 'ssl_wildcard'  => $type[2] ? 'yes' : 'no',
			) as $meta_key => $value ) {
				update_post_meta( $vid, $meta_key, $value );
			}
		}

		\WC_Product_Variable::sync( $id );
		// The sync derives this from the variations; a certificate is never shipped.
		update_post_meta( $id, '_virtual', 'yes' );
		wc_delete_product_transients( $id );

		return $id;
	}

	/**
	 * Creates the certificate product, or shows what would be created.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the product and its variations. Nothing is written.
	 *
	 * [--create]
	 * : Create the product (a draft). Refused when a product with the slug ssl-certificate exists.
	 *
	 * [--prices=<prices>]
	 * : Yearly price of each type, for example dv:19,dv_wildcard:99,ov:55,ev:129 (the shop's price as entered). Without it the variations have no price.
	 *
	 * @param array<int, string>   $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function product( array $args, array $assoc_args ): void {
		unset( $args );

		$dry    = ! empty( $assoc_args['dry-run'] );
		$create = ! empty( $assoc_args['create'] );

		if ( $dry === $create ) {
			\WP_CLI::error( 'Give exactly one of --dry-run and --create.' );
		}

		$prices = array();

		if ( isset( $assoc_args['prices'] ) ) {
			$prices = self::parse_prices( (string) $assoc_args['prices'] );

			if ( is_wp_error( $prices ) ) {
				\WP_CLI::error( $prices->get_error_message() );
			}
		}

		$found = self::existing();

		if ( $found > 0 ) {
			\WP_CLI::error( 'A product with the software slug ' . self::SLUG . ' exists already: ' . $found . '. Nothing was changed.' );
		}

		$ids = SslCatalog::launch();

		foreach ( self::types() as $key => $type ) {
			$cost = SslCatalog::cost( (int) $ids[ $key ] );
			\WP_CLI::log( sprintf( '%s (%s): registrar product %d, cost %s, %s', $type[0], $key, $ids[ $key ], null === $cost ? 'not read yet' : number_format( $cost, 2 ) . ' EUR', isset( $prices[ $key ] ) ? 'price ' . $prices[ $key ] . ' per year' : 'no price' ) );
		}

		if ( $dry ) {
			\WP_CLI::log( 'Dry run: would create one draft variable yearly subscription product "SSL certificate", sold individually. Nothing was written.' );
			return;
		}

		$id = self::create( $prices );

		\WP_CLI::success( sprintf( 'Created draft product %d with %d variations. Read the costs (wp beltoft-services ssl sync), turn on SSL certificates in the settings and publish it.', $id, count( self::types() ) ) );
	}

	/**
	 * Reads the registrar's certificate products and costs (read only; nothing is ordered).
	 *
	 * @param array<int, string>   $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function sync( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$count = SslCatalog::sync();

		if ( is_wp_error( $count ) ) {
			\WP_CLI::error( 'The registrar list could not be read: ' . $count->get_error_code() );
		}

		$rows = array();

		foreach ( SslCatalog::all() as $row ) {
			$rows[] = array( 'id' => $row['id'], 'name' => $row['name'], 'brand' => $row['brand'], 'level' => $row['level'], 'wildcard' => $row['wildcard'] ? 'yes' : 'no', 'delivery' => $row['delivery'], 'cost_1y_eur' => number_format( (float) $row['cost_1y'], 2 ) );
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'brand', 'level', 'wildcard', 'delivery', 'cost_1y_eur' ) );
		\WP_CLI::success( $count . ' certificate products stored.' );
	}

	/**
	 * Shows the state of one certificate service. Never prints a key or a certificate text.
	 *
	 * ## OPTIONS
	 *
	 * <resource-id>
	 * : The resource (activation) id.
	 *
	 * @param array<int, string>   $args       Resource id.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$r = Resource::find( (int) ( $args[0] ?? 0 ) );

		if ( ! $r || SslDriver::ID !== $r->get( 'driver' ) ) {
			\WP_CLI::error( 'That is not a certificate service.' );
		}

		foreach ( array( 'state', 'ssl_name', 'remote_id', 'ssl_status', 'ssl_validation', 'ssl_key_mode', 'steps', 'ssl_serial', 'ssl_valid_from', 'ssl_valid_to', 'ssl_order_expires', 'ssl_reissue_pending', 'ssl_cancelled', 'last_error' ) as $key ) {
			$value = $r->get( $key );
			\WP_CLI::log( sprintf( '%-20s %s', $key, is_array( $value ) ? implode( ',', $value ) : (string) ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ) ) );
		}
	}
}
