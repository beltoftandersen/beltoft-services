<?php
/**
 * Only the domain search form (label, input, button) as a GET form to the current page, with the term in the URL.
 * It is the front half of a native Bricks query loop of the type "Domain availability".
 *
 * Loaded only by Integration::register_elements(), when Bricks is active.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

use BeltoftServices\Domains\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks element.
 */
class DomainSearchFormElement extends DomainSearchElement {

	public $name    = 'beltoft-domain-search-form';
	public $icon    = 'ti-search';
	public $scripts = array();

	public function get_label() {
		return esc_html__( 'Domain search form', 'beltoft-services' );
	}

	public function enqueue_scripts() {
		Search::enqueue_style();
	}

	public function set_control_groups() {
		parent::set_control_groups();
		$this->control_groups = array_intersect_key( $this->control_groups, array_flip( array( 'texts', 'form', 'input', 'buttons' ) ) );
	}

	public function set_controls() {
		parent::set_controls();
		foreach ( $this->controls as $key => $control ) {
			// The form has no results, loading bar or transfer part, and one button.
			if ( ! isset( $this->control_groups[ $control['group'] ] ) || 0 === strpos( $key, 'result' ) || 0 === strpos( $key, 'transfer' ) ) {
				unset( $this->controls[ $key ] );
			}
		}
	}

	public function render() {
		$settings = $this->settings;
		$this->set_attribute( '_root', 'class', 'beltoft-domain-form' );
		$this->set_attribute( '_root', 'method', 'get' );
		$this->set_attribute( '_root', 'action', esc_url( (string) ( get_permalink( (int) get_queried_object_id() ) ?: '' ) ) );
		$defaults    = Search::default_texts();
		$label       = '' !== (string) ( $settings['labelText'] ?? '' ) ? (string) $settings['labelText'] : $defaults['label'];
		$button      = '' !== (string) ( $settings['buttonText'] ?? '' ) ? (string) $settings['buttonText'] : $defaults['button'];
		$placeholder = '' !== (string) ( $settings['placeholder'] ?? '' ) ? (string) $settings['placeholder'] : 'example.com';
		$param       = DomainQuery::param();
		echo '<form ' . $this->render_attributes( '_root' ) . '><label for="beltoft-domain-name-' . esc_attr( (string) $this->id ) . '">' . esc_html( $label ) . '</label><input id="beltoft-domain-name-' . esc_attr( (string) $this->id ) . '" type="search" name="' . esc_attr( $param ) . '" value="' . esc_attr( DomainQuery::term() ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"><button type="submit">' . esc_html( $button ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
