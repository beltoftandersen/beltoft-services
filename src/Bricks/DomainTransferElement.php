<?php
/**
 * The domain transfer form as a native Bricks element: a section apart from the search, where a customer enters a
 * domain they own, sees the price and the lock state, and adds the transfer with its code to the order.
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
class DomainTransferElement extends DomainSearchElement {

	public $name     = 'beltoft-domain-transfer';
	public $icon     = 'ti-share';
	public $scripts  = array( 'beltoftDomainSearchInit' );

	public function get_label() {
		return esc_html__( 'Domain transfer', 'beltoft-services' );
	}

	public function get_keywords() {
		return array( 'domain', 'transfer', 'epp', 'auth code' );
	}

	public function set_controls() {
		parent::set_controls();
		$defaults = Search::transfer_texts();
		$this->controls['labelText']['placeholder']  = $defaults['label'];
		$this->controls['buttonText']['label']       = esc_html__( 'Button', 'beltoft-services' );
		$this->controls['buttonText']['placeholder'] = $defaults['button'];
		// No search results here: the link to a transfer section and the taken-row styles do not apply.
		foreach ( array( 'transferUrl', 'transferLinkText', 'takenBackground', 'takenOpacity', 'linkTypography', 'noteTypography' ) as $key ) {
			unset( $this->controls[ $key ] );
		}
	}

	public function render() {
		$settings = $this->settings;
		$this->set_attribute( '_root', 'class', 'beltoft-domain-transfer-box' );
		foreach ( Search::root_data() as $name => $value ) {
			$this->set_attribute( '_root', $name, $value );
		}
		$texts = array(
			'label'       => (string) ( $settings['labelText'] ?? '' ),
			'placeholder' => (string) ( $settings['placeholder'] ?? '' ),
			'button'      => (string) ( $settings['buttonText'] ?? '' ),
		);
		// Inner markup is escaped by the builder.
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . Search::transfer_markup( $texts ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
