<?php
/**
 * The pay as you go lines of a variation row in the product editor.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage\Admin;

use BeltoftServices\Currency;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Only a variation of a product billed by use gets any of it. An hourly plan takes the monthly price its rate is taken from, per
 * currency; a per unit plan takes a unit price and price steps per currency. Saving checks every currency first and writes only
 * what changed, so opening and saving a variation leaves its meta as it was.
 */
final class PaygVariation {

	public const FIELD_MONTHLY = '_beltoft_services_payg_monthly';
	public const FIELD_ROWS    = '_beltoft_services_payg_rows';
	public const FIELD_TEXT    = '_beltoft_services_payg_steps_text';
	public const FIELD_PRICE   = '_beltoft_services_payg_unit_price';

	/**
	 * Every refusal of this request (tests read it).
	 *
	 * @var string[]
	 */
	public static array $log = array();

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'fields' ), 5, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save' ), 20, 2 );
	}

	/**
	 * The kind of the product a variation belongs to, '' when it is billed monthly or its driver cannot be billed by use.
	 *
	 * @param int $parent_id Product id.
	 */
	private static function kind( int $parent_id ): string {
		$parent = wc_get_product( $parent_id );

		return $parent instanceof \WC_Product && PaygProduct::offers( $parent ) ? PaygProduct::kind( $parent ) : '';
	}

	/**
	 * The words for the live rate next to a monthly price (the screen script fills them in while the price is typed).
	 *
	 * @param string $hourly Rate per hour.
	 * @param string $cap    Cap per month.
	 */
	public static function live_text( string $hourly, string $cap ): string {
		/* translators: 1: rate per hour, 2: most per month */
		return sprintf( __( '%1$s per hour, max %2$s per month', 'beltoft-services' ), $hourly, $cap );
	}

	/**
	 * Prints the lines.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Unused.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function fields( $loop, $variation_data, $variation ): void {
		$kind = self::kind( (int) $variation->post_parent );
		$v    = '' !== $kind ? wc_get_product( $variation->ID ) : null;

		if ( ! $v instanceof \WC_Product_Variation ) {
			return;
		}

		printf(
			'<div class="bs-payg-variation" style="clear:both" data-bs-variation="%1$d" data-bs-plan="%2$s"><p><strong>%3$s</strong></p>',
			(int) $v->get_id(),
			esc_attr( PaygProduct::label( $v ) ),
			esc_html( Rates::KIND_COUNTED === $kind ? __( 'Pay per unit', 'beltoft-services' ) : __( 'Pay as you go by the hour', 'beltoft-services' ) )
		);

		// What the last save of this plan refused, shown once under its own fields (the screen reloads the rows after the ajax save).
		foreach ( self::take_refusals( $v->get_id() ) as $message ) {
			echo '<p class="bs-payg-refusal" role="alert" style="color:#d63638;font-weight:600;clear:both"><span class="screen-reader-text">' . esc_html__( 'Problem:', 'beltoft-services' ) . ' </span>' . esc_html( $message ) . '</p>';
		}

		if ( Rates::KIND_COUNTED === $kind ) {
			self::counted_fields( (int) $loop, $v );
		} else {
			self::hourly_fields( (int) $loop, $v );
		}

		echo '</div>';
	}

	/**
	 * An hourly variation: the monthly price its rate is taken from, per currency, with the rate and cap next to it.
	 *
	 * @param int                   $loop Index.
	 * @param \WC_Product_Variation $v    Variation.
	 */
	private static function hourly_fields( int $loop, \WC_Product_Variation $v ): void {
		$premium = Rates::premium( $v->get_parent_id() );

		foreach ( Currency::known_codes() as $code ) {
			$id      = self::FIELD_MONTHLY . '_' . $loop . '_' . $code;
			$monthly = PaygProduct::monthly( $v, $code );

			printf(
				'<p class="form-field form-row form-row-full bs-payg-rate"><label for="%1$s">%2$s</label><input type="number" min="0" step="any" class="short" id="%1$s" name="%3$s[%4$d][%5$s]" value="%6$s" data-bs-code="%5$s" /> <span class="bs-payg-live" data-bs-for="%1$s">%7$s</span></p>',
				esc_attr( $id ),
				/* translators: %s: currency code */
				esc_html( sprintf( __( 'Monthly price this rate is taken from, excl. VAT (%s)', 'beltoft-services' ), $code ) ),
				esc_attr( self::FIELD_MONTHLY ),
				(int) $loop,
				esc_attr( $code ),
				esc_attr( (string) $v->get_meta( PaygProduct::key( Rates::META_MONTHLY, $code ) ) ),
				esc_html( null === $monthly ? '' : self::live_text( number_format( Rates::hourly( $monthly, $premium ), 4, '.', '' ), number_format( Rates::cap( $monthly, $premium ), 2, '.', '' ) ) )
			);
		}

		echo '<p class="description bs-payg-help" style="clear:both">' . esc_html__( 'The hourly rate and the monthly cap are worked out from this price and the premium of the product. A currency left empty has no rate, so the plan is not sold in it.', 'beltoft-services' ) . '</p>';
	}

	/**
	 * A per unit variation: the unit price and the price steps per currency, and a worked example.
	 *
	 * @param int                   $loop Index.
	 * @param \WC_Product_Variation $v    Variation.
	 */
	private static function counted_fields( int $loop, \WC_Product_Variation $v ): void {
		foreach ( Currency::known_codes() as $code ) {
			$text = (string) $v->get_meta( PaygProduct::key( Rates::META_UNIT_STEPS, $code ) );
			$rows = PaygSteps::parse( $text );

			echo '<div class="bs-payg-currency" style="clear:both" data-bs-code="' . esc_attr( $code ) . '"><p><em>' . esc_html( $code ) . '</em></p>';

			woocommerce_wp_text_input(
				array(
					'id'                => self::FIELD_PRICE . '_' . $loop . '_' . $code,
					'name'              => self::FIELD_PRICE . '[' . $loop . '][' . $code . ']',
					'label'             => __( 'Unit price', 'beltoft-services' ),
					'description'       => __( 'One unit, excl. VAT. Used when there are no price steps.', 'beltoft-services' ),
					'desc_tip'          => false,
					'wrapper_class'     => 'form-row form-row-first',
					'type'              => 'number',
					'custom_attributes' => array( 'min' => '0', 'step' => 'any' ),
					'value'             => (string) $v->get_meta( PaygProduct::key( Rates::META_UNIT_PRICE, $code ) ),
				)
			);

			if ( null === $rows ) {
				// A text that is not a list of steps stays as text, so it can be corrected (the save refuses it until it is).
				woocommerce_wp_text_input(
					array(
						'id'            => self::FIELD_TEXT . '_' . $loop . '_' . $code,
						'name'          => self::FIELD_TEXT . '[' . $loop . '][' . $code . ']',
						'label'         => __( 'Price steps', 'beltoft-services' ),
						'description'   => __( 'This text cannot be read. Write it as up to units:price, separated by commas, the last step with 0 units.', 'beltoft-services' ),
						'desc_tip'      => false,
						'wrapper_class' => 'form-row form-row-last',
						'value'         => $text,
					)
				);
			} else {
				echo '<div class="form-field form-row form-row-last bs-payg-steps"><label>' . esc_html__( 'Price steps', 'beltoft-services' ) . '</label>';

				for ( $i = 0, $n = count( $rows ) + 2; $i < $n; $i++ ) {
					printf(
						'<span style="display:block;margin-bottom:4px"><input type="number" min="0" step="1" style="width:9em" name="%1$s[%2$d][%3$s][%4$d][upto]" value="%5$s" placeholder="%6$s" /> <input type="number" min="0" step="any" style="width:9em" name="%1$s[%2$d][%3$s][%4$d][price]" value="%7$s" placeholder="%8$s" /></span>',
						esc_attr( self::FIELD_ROWS ),
						(int) $loop,
						esc_attr( $code ),
						(int) $i,
						esc_attr( $rows[ $i ][0] ?? '' ),
						esc_attr__( 'Up to units', 'beltoft-services' ),
						esc_attr( $rows[ $i ][1] ?? '' ),
						esc_attr__( 'Price', 'beltoft-services' )
					);
				}

				echo '<span class="description">' . esc_html__( 'Up to this many units in a month, each unit costs the price. The last step has 0 units: every unit above.', 'beltoft-services' ) . '</span></div>';
			}

			$steps = Rates::unit_steps( $v, $code );

			if ( $steps ) {
				printf(
					'<p class="description" style="clear:both">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: units, 2: cost, 3: currency code */
							__( '%1$s units in a month = %2$s %3$s, excl. VAT.', 'beltoft-services' ),
							number_format_i18n( PaygPreview::EXAMPLE_UNITS ),
							number_format( PaygSteps::cost( $steps, PaygPreview::EXAMPLE_UNITS ), 2, '.', '' ),
							$code
						)
					)
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Saves the lines of a variation. WooCommerce has verified the nonce (save-variations) before this action runs. Nothing of the
	 * pay as you go part is written when any currency of it is refused.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $i         Index.
	 */
	public static function save( $variation, $i ): void {
		$kind = self::kind( (int) $variation->get_parent_id() );

		if ( '' === $kind ) {
			return;
		}

		$label  = PaygProduct::label( $variation );
		$writes = Rates::KIND_COUNTED === $kind ? self::counted_writes( $variation, $i, $label ) : self::hourly_writes( $variation, $i, $label );

		if ( null === $writes ) {
			return;
		}

		foreach ( $writes as $key => $value ) {
			if ( null === $value ) {
				$variation->delete_meta_data( $key );
			} else {
				$variation->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * The monthly prices to write for an hourly variation, every currency checked first. Null (and a refusal) when one is not valid.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $i         Index.
	 * @param string                $label     The plan in words.
	 * @return array<string, ?string>|null Meta key => value of what changed (null deletes).
	 */
	private static function hourly_writes( \WC_Product_Variation $variation, $i, string $label ): ?array {
		$writes = array();

		foreach ( Currency::known_codes() as $code ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations).
			$posted = isset( $_POST[ self::FIELD_MONTHLY ][ $i ][ $code ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_MONTHLY ][ $i ][ $code ] ) ) ) : null;
			$key    = PaygProduct::key( Rates::META_MONTHLY, $code );
			$had    = (string) $variation->get_meta( $key );

			if ( null === $posted || self::same_number( $posted, $had ) ) {
				continue;
			}

			if ( '' === $posted && Currency::base() !== $code ) {
				$writes[ $key ] = null;
				continue;
			}

			if ( ! is_numeric( $posted ) || (float) $posted <= 0 || ! is_finite( (float) $posted ) ) {
				/* translators: 1: the plan, 2: currency code */
				self::refuse( sprintf( __( '%1$s, %2$s: the monthly price must be a number above 0. Nothing in this plan was saved.', 'beltoft-services' ), $label, $code ), $variation->get_id() );

				return null;
			}

			$writes[ $key ] = $posted;
		}

		return $writes;
	}

	/**
	 * The unit prices and price steps to write for a per unit variation, per currency, every currency checked first. Null (and a
	 * refusal) when any of them is not valid: then none is written.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $i         Index.
	 * @param string                $label     The plan in words.
	 * @return array<string, ?string>|null Meta key => value of what changed.
	 */
	private static function counted_writes( \WC_Product_Variation $variation, $i, string $label ): ?array {
		$writes = array();
		$id     = $variation->get_id();

		foreach ( Currency::known_codes() as $code ) {
			$price_key = PaygProduct::key( Rates::META_UNIT_PRICE, $code );
			$steps_key = PaygProduct::key( Rates::META_UNIT_STEPS, $code );
			$had_price = (string) $variation->get_meta( $price_key );
			$had_steps = (string) $variation->get_meta( $steps_key );
			$price     = self::posted_price( $i, $code ) ?? $had_price;
			$steps     = self::posted_steps( $i, $code ) ?? $had_steps;

			if ( '' !== $price && ( ! is_numeric( $price ) || (float) $price <= 0 ) ) {
				/* translators: 1: the plan, 2: currency code */
				self::refuse( sprintf( __( '%1$s, %2$s unit price: a number above 0. Nothing in this plan was saved.', 'beltoft-services' ), $label, $code ), $id );

				return null;
			}

			if ( null === PaygSteps::parse( $steps ) ) {
				self::refuse( self::steps_message( $label, $code ), $id );

				return null;
			}

			if ( Currency::base() === $code && '' === $price && '' === $steps ) {
				/* translators: 1: the plan, 2: currency code */
				self::refuse( sprintf( __( '%1$s, %2$s: a per unit plan needs a unit price or price steps. Nothing in this plan was saved.', 'beltoft-services' ), $label, $code ), $id );

				return null;
			}

			if ( ! self::same_number( $price, $had_price ) ) {
				$writes[ $price_key ] = $price;
			}

			if ( ! PaygSteps::same( $steps, $had_steps ) ) {
				$writes[ $steps_key ] = $steps;
			}
		}

		return $writes;
	}

	/**
	 * Do two typed numbers mean the same ("12.50" and "12.5")? Two empty ones do.
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 */
	private static function same_number( string $a, string $b ): bool {
		return $a === $b || ( is_numeric( $a ) && is_numeric( $b ) && (float) $a === (float) $b );
	}

	/**
	 * What is said when price steps are not valid (the screen script says the same before the save).
	 *
	 * @param string $label The plan in words.
	 * @param string $code  Currency code.
	 */
	public static function steps_message( string $label, string $code ): string {
		/* translators: 1: the plan, 2: currency code */
		return sprintf( __( '%1$s, %2$s price steps: each step needs more units than the one before, and the last step 0 units (every unit above). Nothing in this plan was saved.', 'beltoft-services' ), $label, $code );
	}

	/**
	 * The unit price posted for a currency, or null when the form did not carry it.
	 *
	 * @param int    $i    Index.
	 * @param string $code Currency code.
	 */
	private static function posted_price( $i, string $code ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations).
		return isset( $_POST[ self::FIELD_PRICE ][ $i ][ $code ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_PRICE ][ $i ][ $code ] ) ) ) : null;
	}

	/**
	 * The steps posted for a currency as the stored text, or null when the form did not carry them.
	 *
	 * @param int    $i    Index.
	 * @param string $code Currency code.
	 */
	private static function posted_steps( $i, string $code ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by WooCommerce (save-variations); every value is sanitized below.
		if ( isset( $_POST[ self::FIELD_TEXT ][ $i ][ $code ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_TEXT ][ $i ][ $code ] ) ) );
		}

		if ( ! isset( $_POST[ self::FIELD_ROWS ][ $i ][ $code ] ) || ! is_array( $_POST[ self::FIELD_ROWS ][ $i ][ $code ] ) ) {
			return null;
		}

		$rows = array();

		foreach ( wp_unslash( $_POST[ self::FIELD_ROWS ][ $i ][ $code ] ) as $row ) {
			$upto  = is_array( $row ) ? trim( sanitize_text_field( (string) ( $row['upto'] ?? '' ) ) ) : '';
			$price = is_array( $row ) ? trim( sanitize_text_field( (string) ( $row['price'] ?? '' ) ) ) : '';

			// An empty row is no step; a row with only a price is the open last step.
			if ( '' !== $price || '' !== $upto ) {
				$rows[] = array( $upto, $price );
			}
		}
		// phpcs:enable

		return PaygSteps::compose( $rows );
	}

	/**
	 * Tells the admin a change was refused. A variation's refusal is kept for its own row (shown under its fields when the rows are
	 * loaded again after the ajax save); a product-level one is a WooCommerce notice on the next screen.
	 *
	 * @param string $message      Message.
	 * @param int    $variation_id The variation it is about, 0 for the product.
	 */
	public static function refuse( string $message, int $variation_id = 0 ): void {
		self::$log[] = $message;

		if ( $variation_id > 0 ) {
			$key                    = 'beltoft_services_payg_refusals_' . get_current_user_id();
			$all                    = get_transient( $key );
			$all                    = is_array( $all ) ? $all : array();
			$all[ $variation_id ][] = $message;
			set_transient( $key, $all, 10 * MINUTE_IN_SECONDS );

			return;
		}

		if ( class_exists( \WC_Admin_Meta_Boxes::class ) ) {
			\WC_Admin_Meta_Boxes::add_error( $message );
		}
	}

	/**
	 * The refusals kept for a variation, once.
	 *
	 * @param int $variation_id Variation id.
	 * @return string[]
	 */
	public static function take_refusals( int $variation_id ): array {
		$key = 'beltoft_services_payg_refusals_' . get_current_user_id();
		$all = get_transient( $key );

		if ( ! is_array( $all ) || empty( $all[ $variation_id ] ) ) {
			return array();
		}

		$out = array_map( 'strval', $all[ $variation_id ] );
		unset( $all[ $variation_id ] );

		if ( $all ) {
			set_transient( $key, $all, 10 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( $key );
		}

		return $out;
	}
}
