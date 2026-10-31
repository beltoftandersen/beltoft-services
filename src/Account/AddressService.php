<?php
/**
 * Saving a customer's address, as WooCommerce's My Account address form does.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Form_Handler::save_address reads $_POST and ends in a redirect, so it cannot be called from anywhere else. This
 * does the same checks on a list of values: only the fields of the customer's country are accepted, required ones may not be
 * emptied, postcode and phone are validated, the same filters and actions run (woocommerce_process_myaccount_field_*,
 * woocommerce_after_save_address_validation, woocommerce_customer_save_address) and an error stops the save. Errors are
 * WooCommerce notices of type error, as in the form, so a plugin that adds one through its hook is heard.
 */
final class AddressService {

	/**
	 * Saves some fields of an address. Nothing is saved when anything is wrong.
	 *
	 * @param int                  $user_id Customer.
	 * @param string               $type    billing or shipping.
	 * @param array<string, string> $values  Field (without the billing_ prefix) => new value. Fields not named stay as they are.
	 * @return bool Whether it was saved. The reasons are error notices.
	 */
	public static function save( int $user_id, string $type, array $values ): bool {
		if ( $user_id <= 0 || ! in_array( $type, array( 'billing', 'shipping' ), true ) ) {
			return false;
		}

		$customer = new \WC_Customer( $user_id );
		$country  = (string) $customer->{"get_{$type}_country"}();
		$fields   = (array) WC()->countries->get_address_fields( $country, $type . '_' );
		$address  = array();

		foreach ( $values as $short => $raw ) {
			$key = $type . '_' . $short;

			if ( ! isset( $fields[ $key ] ) ) {
				/* translators: %s: field name */
				wc_add_notice( sprintf( __( 'The field %s is not used for this country.', 'beltoft-services' ), $short ), 'error' );
				continue;
			}

			$field = $fields[ $key ];
			$value = apply_filters( 'woocommerce_process_myaccount_field_' . $key, wc_clean( $raw ) );

			if ( ! empty( $field['required'] ) && true !== ( $field['hidden'] ?? false ) && '' === (string) $value ) {
				/* translators: %s: field name */
				wc_add_notice( sprintf( __( '%s is a required field.', 'beltoft-services' ), (string) ( $field['label'] ?? $short ) ), 'error' );
				continue;
			}

			if ( '' !== (string) $value && ! empty( $field['validate'] ) && is_array( $field['validate'] ) ) {
				foreach ( $field['validate'] as $rule ) {
					if ( 'postcode' === $rule ) {
						$value = wc_format_postcode( (string) $value, $country );

						if ( '' !== $value && ! \WC_Validation::is_postcode( $value, $country ) ) {
							wc_add_notice( __( 'Please enter a valid postcode / ZIP.', 'beltoft-services' ), 'error' );
						}
					} elseif ( 'phone' === $rule ) {
						$value = wc_remove_non_displayable_chars( (string) $value );

						if ( '' === $value || ! \WC_Validation::is_phone( $value, $country ) ) {
							wc_add_notice( __( 'That is not a valid phone number.', 'beltoft-services' ), 'error' );
						}
					}
				}
			}

			$address[ $key ] = $value;

			try {
				if ( is_callable( array( $customer, 'set_' . $key ) ) ) {
					$customer->{'set_' . $key}( $value );
				} else {
					$customer->update_meta_data( $key, $value );
				}
			} catch ( \WC_Data_Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
		}

		if ( 0 < wc_notice_count( 'error' ) ) {
			return false;
		}

		do_action( 'woocommerce_after_save_address_validation', $user_id, $type, $address, $customer );

		if ( 0 < wc_notice_count( 'error' ) ) {
			return false;
		}

		$customer->save();

		do_action( 'woocommerce_customer_save_address', $user_id, $type, $address, $customer );

		return 0 === wc_notice_count( 'error' );
	}
}
