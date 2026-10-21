<?php
/**
 * Service settings on WooCommerce products.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Which licensed products are services: the driver, the connection, retention, the trial
 * rule and the driver's plan fields, with a "Service" tab beside the License tab. Plan
 * fields marked as variation fields can be overridden per variation.
 */
final class Product {

	public const META_DRIVER     = '_beltoft_services_driver';
	public const META_CONNECTION = '_beltoft_services_connection';
	public const META_RETENTION  = '_beltoft_services_retention';
	public const META_TRIAL_PAID = '_beltoft_services_trial_requires_payment';
	public const META_PLAN       = '_beltoft_services_plan_';
	public const FIELD_MARKER    = '_beltoft_services_plan_driver';
	public const FIELD_RELEASE   = '_beltoft_services_no_longer_service';
	public const OPTION_SLUGS    = 'beltoft_services_service_slugs';

	/**
	 * Hooks for the product editor and the beltoft-licenses filters.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );

		add_action( 'added_post_meta', array( __CLASS__, 'driver_meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'driver_meta_changed' ), 10, 4 );

		add_filter( 'beltoft_licenses_api_license', array( __CLASS__, 'hide_from_api' ) );
		add_filter( 'beltoft_licenses_resource_label', array( __CLASS__, 'resource_label' ), 10, 4 );
		add_filter( 'beltoft_licenses_account_show_key', array( __CLASS__, 'show_key' ), 10, 2 );
	}

	/**
	 * The parent product id of a variation, or the id itself.
	 *
	 * @param int $product_id Product or variation id.
	 */
	private static function parent_id( int $product_id ): int {
		$parent = wp_get_post_parent_id( $product_id );

		return 'product_variation' === get_post_type( $product_id ) && $parent ? (int) $parent : $product_id;
	}

	/**
	 * Reads product meta without loading the product object.
	 *
	 * @param int    $product_id Product id.
	 * @param string $key        Meta key.
	 * @return string
	 */
	private static function meta( int $product_id, string $key ): string {
		$value = get_post_meta( $product_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Is this product a service: it has a driver chosen?
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function is_service( int $product_id ): bool {
		return $product_id > 0 && '' !== self::meta( self::parent_id( $product_id ), self::META_DRIVER );
	}

	/**
	 * Software slugs that were ever saved as services. Licenses with these slugs stay out of
	 * the public license API even if their product is deleted.
	 *
	 * @return string[]
	 */
	public static function service_slugs(): array {
		$slugs = get_option( self::OPTION_SLUGS, array() );

		return is_array( $slugs ) ? array_values( array_filter( array_map( 'strval', $slugs ) ) ) : array();
	}

	/**
	 * Remembers a software slug as a service slug.
	 *
	 * @param string $slug Software slug.
	 */
	public static function remember_slug( string $slug ): void {
		$slug  = sanitize_title( $slug );
		$slugs = self::service_slugs();

		if ( '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
			$slugs[] = $slug;
			update_option( self::OPTION_SLUGS, $slugs, false );
		}
	}

	/**
	 * Remembers the software slug whenever a product's driver meta is added or updated with
	 * a value, by any route (editor, CLI, import, code), not only the editor.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $object_id  Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function driver_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( self::META_DRIVER !== $meta_key || ! is_scalar( $meta_value ) || '' === (string) $meta_value ) {
			return;
		}

		$slug = (string) get_post_meta( (int) $object_id, \BeltoftLicenses\Software::META_SLUG, true );

		if ( '' !== $slug ) {
			self::remember_slug( $slug );
		}
	}

	/**
	 * Forgets a service slug. Only an explicit admin action does this.
	 *
	 * @param string $slug Software slug.
	 */
	public static function forget_slug( string $slug ): void {
		$slug = sanitize_title( $slug );

		update_option( self::OPTION_SLUGS, array_values( array_diff( self::service_slugs(), array( $slug ) ) ), false );
	}

	/**
	 * Is this license a service license: its product is a service, or its software slug was
	 * saved as a service (which survives the product being deleted)?
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function is_service_license( array $license ): bool {
		if ( self::is_service( (int) ( $license['product_id'] ?? 0 ) ) ) {
			return true;
		}

		$slug = sanitize_title( (string) ( $license['product_slug'] ?? '' ) );

		return '' !== $slug && in_array( $slug, self::service_slugs(), true );
	}

	/**
	 * The product's driver, or null when it is not a service or the driver is not registered.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function driver_for( int $product_id ): ?Driver {
		if ( $product_id <= 0 ) {
			return null;
		}

		$id = self::meta( self::parent_id( $product_id ), self::META_DRIVER );

		return '' === $id ? null : Registry::get( $id );
	}

	/**
	 * The connection id (HTTP driver).
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function connection( int $product_id ): string {
		return self::meta( self::parent_id( $product_id ), self::META_CONNECTION );
	}

	/**
	 * Days to keep a resource after its license ends. 0 means never delete.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function retention_days( int $product_id ): int {
		$value = self::meta( self::parent_id( $product_id ), self::META_RETENTION );

		return '' === $value ? 0 : max( 0, (int) $value );
	}

	/**
	 * Does a trial need a payment method? Defaults to yes.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public static function trial_requires_payment( int $product_id ): bool {
		return 'no' !== self::meta( self::parent_id( $product_id ), self::META_TRIAL_PAID );
	}

	/**
	 * The plan: the driver's defaults, then the product's values, then the variation's (only
	 * for fields the driver allows to vary). An empty stored value means "not set".
	 *
	 * @param int      $product_id   Product id (or a variation id).
	 * @param int|null $variation_id Variation id, if any.
	 * @return array<string, mixed>
	 */
	public static function plan( int $product_id, ?int $variation_id = null ): array {
		if ( null === $variation_id && 'product_variation' === get_post_type( $product_id ) ) {
			$variation_id = $product_id;
		}

		$product_id = self::parent_id( $product_id );
		$driver     = self::driver_for( $product_id );
		$plan       = array();

		if ( ! $driver ) {
			return $plan;
		}

		foreach ( $driver->plan_fields() as $key => $field ) {
			$type  = (string) ( $field[0] ?? 'text' );
			$value = $field[3] ?? '';

			$stored = self::meta( $product_id, self::META_PLAN . $key );
			if ( '' !== $stored ) {
				$value = $stored;
			}

			if ( ! empty( $field[4] ) && $variation_id ) {
				$override = self::meta( $variation_id, self::META_PLAN . $key );
				if ( '' !== $override ) {
					$value = $override;
				}
			}

			$plan[ $key ] = self::cast( $type, $value );
		}

		return $plan;
	}

	/**
	 * Types a stored plan value.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function cast( string $type, $value ) {
		if ( 'checkbox' === $type ) {
			return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
		}

		if ( 'number' === $type ) {
			return is_numeric( $value ) ? $value + 0 : 0;
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Hides service licenses from the public license API: a service key never validates,
	 * activates or downloads there.
	 *
	 * @param array<string, mixed>|null $license License row or null.
	 * @return array<string, mixed>|null
	 */
	public static function hide_from_api( $license ) {
		if ( is_array( $license ) && self::is_service_license( $license ) ) {
			return null;
		}

		return $license;
	}

	/**
	 * Uses the driver's unit label for service licenses.
	 *
	 * @param string               $label   Default label.
	 * @param array<string, mixed> $license License row.
	 * @param int                  $used    Used.
	 * @param int                  $max     Allowed.
	 */
	public static function resource_label( $label, $license, $used, $max ): string {
		$driver = is_array( $license ) && self::is_service_license( $license ) ? self::driver_for( (int) ( $license['product_id'] ?? 0 ) ) : null;

		return $driver ? $driver->unit_label( (int) $used, (int) $max ) : (string) $label;
	}

	/**
	 * Service licenses have no key to show in My Account.
	 *
	 * @param bool                 $show    Default.
	 * @param array<string, mixed> $license License row.
	 */
	public static function show_key( $show, $license ): bool {
		if ( is_array( $license ) && self::is_service_license( $license ) ) {
			return false;
		}

		return (bool) $show;
	}

	/**
	 * The "Service" tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function tab( array $tabs ): array {
		$tabs['beltoft_services'] = array(
			'label'    => __( 'Service', 'beltoft-services' ),
			'target'   => 'beltoft_services_product_data',
			'class'    => array(),
			'priority' => 66,
		);

		return $tabs;
	}

	/**
	 * The tab's fields.
	 */
	public static function panel(): void {
		global $product_object;

		$product   = $product_object instanceof \WC_Product ? $product_object : null;
		$driver_id = $product ? (string) $product->get_meta( self::META_DRIVER ) : '';
		$options   = array( '' => __( 'Not a service', 'beltoft-services' ) );

		foreach ( Registry::all() as $id => $driver ) {
			$options[ $id ] = $driver->label();
		}

		if ( '' !== $driver_id && ! isset( $options[ $driver_id ] ) ) {
			/* translators: %s: driver id. */
			$options[ $driver_id ] = sprintf( __( '%s (unavailable)', 'beltoft-services' ), $driver_id );
		}

		echo '<div id="beltoft_services_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => self::META_DRIVER,
				'label'       => __( 'Service driver', 'beltoft-services' ),
				'description' => __( 'The product must also be a licensed product. Save to show the driver\'s plan fields.', 'beltoft-services' ),
				'desc_tip'    => true,
				'options'     => $options,
				'value'       => $driver_id,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_CONNECTION,
				'label'       => __( 'Connection', 'beltoft-services' ),
				'description' => __( 'The connection id (HTTP driver only).', 'beltoft-services' ),
				'desc_tip'    => true,
				'value'       => $product ? $product->get_meta( self::META_CONNECTION ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_RETENTION,
				'label'             => __( 'Retention (days)', 'beltoft-services' ),
				'description'       => __( 'Days to keep the service after its license ends. 0 never deletes.', 'beltoft-services' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'placeholder'       => '0',
				'value'             => $product ? $product->get_meta( self::META_RETENTION ) : '',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_TRIAL_PAID,
				'label'       => __( 'Trial needs payment', 'beltoft-services' ),
				'description' => __( 'Ask for a payment method at checkout when the order is a free trial.', 'beltoft-services' ),
				'value'       => $product && 'no' === $product->get_meta( self::META_TRIAL_PAID ) ? 'no' : 'yes',
			)
		);

		$driver = '' === $driver_id ? null : Registry::get( $driver_id );
		if ( $driver ) {
			// Tells save() which driver's fields were on the form.
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::FIELD_MARKER ), esc_attr( $driver_id ) );

			foreach ( $driver->plan_fields() as $key => $field ) {
				self::product_field( (string) $key, $field, $product );
			}
		}

		if ( '' !== $driver_id ) {
			woocommerce_wp_checkbox(
				array(
					'id'          => self::FIELD_RELEASE,
					'label'       => __( 'No longer a service', 'beltoft-services' ),
					'description' => __( 'Warning: its licenses will start working in the public license API.', 'beltoft-services' ),
					'value'       => 'no',
				)
			);
		}

		echo '</div></div>';
	}

	/**
	 * Prints one plan field on the product tab.
	 *
	 * @param string                $key     Field key.
	 * @param array<int, mixed>     $field   [type, label, desc, default, variation].
	 * @param \WC_Product|null      $product Product.
	 */
	private static function product_field( string $key, array $field, ?\WC_Product $product ): void {
		$args = array(
			'id'          => self::META_PLAN . $key,
			'label'       => (string) ( $field[1] ?? $key ),
			'description' => (string) ( $field[2] ?? '' ),
			'desc_tip'    => true,
			'value'       => $product ? $product->get_meta( self::META_PLAN . $key ) : '',
		);

		switch ( (string) ( $field[0] ?? 'text' ) ) {
			case 'checkbox':
				$args['value'] = $product && '' !== $product->get_meta( self::META_PLAN . $key )
					? $product->get_meta( self::META_PLAN . $key )
					: ( self::cast( 'checkbox', $field[3] ?? false ) ? 'yes' : 'no' );
				woocommerce_wp_checkbox( $args );
				break;
			case 'textarea':
				woocommerce_wp_textarea_input( $args );
				break;
			case 'number':
				$args['type']              = 'number';
				$args['custom_attributes'] = array( 'step' => 'any' );
				$args['placeholder']       = (string) ( $field[3] ?? '' );
				woocommerce_wp_text_input( $args );
				break;
			default:
				$args['placeholder'] = (string) ( $field[3] ?? '' );
				woocommerce_wp_text_input( $args );
		}
	}

	/**
	 * Saves the tab. WooCommerce has verified the product form's nonce and the user's
	 * capability before this action runs.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		// A save path that does not post the Service panel changes nothing.
		if ( ! isset( $_POST[ self::META_DRIVER ] ) ) {
			return;
		}

		$saved  = (string) $product->get_meta( self::META_DRIVER );
		$posted = sanitize_key( wp_unslash( $_POST[ self::META_DRIVER ] ) );
		$slug   = isset( $_POST[ \BeltoftLicenses\Software::META_SLUG ] ) ? sanitize_title( wp_unslash( $_POST[ \BeltoftLicenses\Software::META_SLUG ] ) ) : sanitize_title( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		if ( '' !== $saved && isset( $_POST[ self::FIELD_RELEASE ] ) ) {
			// The one explicit way out: the licenses become ordinary software licenses.
			$product->update_meta_data( self::META_DRIVER, '' );
			self::forget_slug( $slug );
			return;
		}

		// A registered driver can be chosen; anything else (empty, unknown) keeps the saved one.
		$driver_id = Registry::get( $posted ) ? $posted : $saved;
		$driver    = '' === $driver_id ? null : Registry::get( $driver_id );

		$product->update_meta_data( self::META_DRIVER, $driver_id );
		if ( '' !== $driver_id ) {
			self::remember_slug( $slug );
		}
		$product->update_meta_data( self::META_CONNECTION, isset( $_POST[ self::META_CONNECTION ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_CONNECTION ] ) ) : '' );

		$retention = isset( $_POST[ self::META_RETENTION ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::META_RETENTION ] ) ) ) : '';
		$product->update_meta_data( self::META_RETENTION, '' === $retention ? '' : (string) absint( $retention ) );
		$product->update_meta_data( self::META_TRIAL_PAID, isset( $_POST[ self::META_TRIAL_PAID ] ) ? 'yes' : 'no' );

		$rendered = isset( $_POST[ self::FIELD_MARKER ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD_MARKER ] ) ) : '';
		if ( $driver && $rendered === $driver_id ) {
			foreach ( $driver->plan_fields() as $key => $field ) {
				$name = self::META_PLAN . $key;
				$type = (string) ( $field[0] ?? 'text' );

				if ( 'checkbox' === $type ) {
					$product->update_meta_data( $name, isset( $_POST[ $name ] ) ? 'yes' : 'no' );
				} elseif ( isset( $_POST[ $name ] ) ) {
					$product->update_meta_data( $name, self::clean( $type, wp_unslash( $_POST[ $name ] ) ) );
				}
			}
		}
		// phpcs:enable
	}

	/**
	 * Sanitises a submitted plan value.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw (unslashed) value.
	 */
	private static function clean( string $type, $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( $value );
		}

		$value = trim( sanitize_text_field( $value ) );

		return 'number' === $type && '' !== $value && ! is_numeric( $value ) ? '' : $value;
	}

	/**
	 * Plan fields that may vary, on each variation.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Unused.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ): void {
		$driver = self::driver_for( (int) $variation->post_parent );
		if ( ! $driver ) {
			return;
		}

		foreach ( $driver->plan_fields() as $key => $field ) {
			if ( empty( $field[4] ) ) {
				continue;
			}

			$type  = (string) ( $field[0] ?? 'text' );
			$value = get_post_meta( $variation->ID, self::META_PLAN . $key, true );
			$args  = array(
				'id'            => self::META_PLAN . $key . '_' . $loop,
				'name'          => self::META_PLAN . $key . '[' . $loop . ']',
				'label'         => (string) ( $field[1] ?? $key ),
				'description'   => __( 'Empty uses the product\'s setting.', 'beltoft-services' ),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-first',
				'value'         => $value,
			);

			if ( 'checkbox' === $type ) {
				$args['options'] = array(
					''    => __( 'Product setting', 'beltoft-services' ),
					'yes' => __( 'Yes', 'beltoft-services' ),
					'no'  => __( 'No', 'beltoft-services' ),
				);
				woocommerce_wp_select( $args );
			} elseif ( 'textarea' === $type ) {
				woocommerce_wp_textarea_input( $args );
			} else {
				if ( 'number' === $type ) {
					$args['type']              = 'number';
					$args['custom_attributes'] = array( 'step' => 'any' );
				}
				woocommerce_wp_text_input( $args );
			}
		}
	}

	/**
	 * Saves a variation's plan overrides. WooCommerce has verified the nonce
	 * (save-variations) before this action runs.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $i         Index.
	 */
	public static function save_variation( $variation, $i ): void {
		$driver = self::driver_for( (int) $variation->get_parent_id() );
		if ( ! $driver ) {
			return;
		}

		foreach ( $driver->plan_fields() as $key => $field ) {
			if ( empty( $field[4] ) ) {
				continue;
			}

			$name = self::META_PLAN . $key;
			$type = (string) ( $field[0] ?? 'text' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations).
			$raw = isset( $_POST[ $name ][ $i ] ) ? wp_unslash( $_POST[ $name ][ $i ] ) : '';

			if ( 'checkbox' === $type ) {
				$raw = in_array( $raw, array( 'yes', 'no' ), true ) ? $raw : '';
			} else {
				$raw = self::clean( $type, $raw );
			}

			$variation->update_meta_data( $name, $raw );
		}
	}
}
