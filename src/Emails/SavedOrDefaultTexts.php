<?php
/** The subject and heading of an email whose default text follows its values. @package BeltoftServices */
namespace BeltoftServices\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * For a ServiceEmail with get_default_subject() and get_default_heading(): the subject and heading follow the values of
 * the send unless the shop owner saved their own (the form default is fixed at construct time).
 */
trait SavedOrDefaultTexts {

	/** The subject follows the values unless the shop owner saved their own. */
	public function get_subject() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['subject'] ?? '' ) ) ) {
			return parent::get_subject();
		}
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $this->get_default_subject() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}

	/** The heading follows the values unless the shop owner saved their own. */
	public function get_heading() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		if ( is_array( $saved ) && '' !== trim( (string) ( $saved['heading'] ?? '' ) ) ) {
			return parent::get_heading();
		}
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $this->get_default_heading() ), $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
	}
}
