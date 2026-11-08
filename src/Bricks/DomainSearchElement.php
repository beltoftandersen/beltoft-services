<?php
/**
 * The public domain search as a native Bricks element: the same form and script as the shortcode,
 * with the texts as content controls and the look as style controls.
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
class DomainSearchElement extends \Bricks\Element {

	public $category = 'beltoft services';
	public $name     = 'beltoft-domain-search';
	public $icon     = 'ti-search';
	public $scripts  = array( 'beltoftDomainSearchInit' );

	public function get_label() {
		return esc_html__( 'Domain search', 'beltoft-services' );
	}

	public function get_keywords() {
		return array( 'domain', 'search', 'whois', 'register' );
	}

	public function enqueue_scripts() {
		Search::enqueue_assets();
	}

	public function set_control_groups() {
		$this->control_groups['texts'] = array( 'title' => esc_html__( 'Texts', 'beltoft-services' ), 'tab' => 'content' );
		$groups = array(
			'form'    => esc_html__( 'Layout', 'beltoft-services' ),
			'input'   => esc_html__( 'Input', 'beltoft-services' ),
			'buttons' => esc_html__( 'Buttons', 'beltoft-services' ),
			'results' => esc_html__( 'Results', 'beltoft-services' ),
			'text'    => esc_html__( 'Result text', 'beltoft-services' ),
			'transfer' => esc_html__( 'Transfer form', 'beltoft-services' ),
			'loading' => esc_html__( 'Loading', 'beltoft-services' ),
		);
		foreach ( $groups as $key => $title ) {
			$this->control_groups[ $key ] = array( 'title' => $title, 'tab' => 'style' );
		}
	}

	/** One CSS rule for a control. */
	private static function rule( string $property, string $selector = '' ): array {
		return array( 'property' => $property, 'selector' => $selector );
	}

	/** Adds a style control with one or more CSS rules. */
	private function style( string $key, string $group, string $label, string $type, array $rules, array $extra = array() ): void {
		$this->controls[ $key ] = array_merge( array( 'group' => $group, 'label' => $label, 'type' => $type, 'css' => $rules ), $extra );
	}

	public function set_controls() {
		$text = static function ( string $label, string $placeholder ): array {
			return array( 'group' => 'texts', 'label' => $label, 'type' => 'text', 'placeholder' => $placeholder, 'hasDynamicData' => false );
		};
		$defaults = Search::default_texts();
		$this->controls['labelText']   = $text( esc_html__( 'Label', 'beltoft-services' ), $defaults['label'] );
		$this->controls['placeholder'] = $text( esc_html__( 'Input placeholder', 'beltoft-services' ), 'example.com' );
		$this->controls['buttonText']  = $text( esc_html__( 'Search button', 'beltoft-services' ), $defaults['button'] );
		// The transfer link: under the form and on a taken domain. Empty address: no link anywhere.
		$this->controls['transferUrl']      = $text( esc_html__( 'Transfer section address', 'beltoft-services' ), '#transfer' );
		$this->controls['transferLinkText'] = $text( esc_html__( 'Transfer link', 'beltoft-services' ), $defaults['transfer'] );
		// Another page that does the search (for example /domains/): this form sends the term there and that page starts the search.
		$this->controls['searchUrl'] = $text( esc_html__( 'Search page address (empty: results on this page)', 'beltoft-services' ), '/domains/' );

		// Form layout. Columns are a grid template (for example "minmax(0,1fr) auto"); set it per breakpoint for a stacked form on a phone.
		$this->style( 'formColumns', 'form', esc_html__( 'Columns (grid template)', 'beltoft-services' ), 'text', array( self::rule( 'grid-template-columns' ) ), array( 'placeholder' => 'minmax(0,1fr) auto', 'hasDynamicData' => false ) );
		$this->style( 'formGap', 'form', esc_html__( 'Gap', 'beltoft-services' ), 'number', array( self::rule( 'gap' ) ), array( 'units' => true, 'placeholder' => '.75rem' ) );
		$this->style( 'formAlign', 'form', esc_html__( 'Align items', 'beltoft-services' ), 'align-items', array( self::rule( 'align-items' ) ) );
		$this->style( 'labelTypography', 'form', esc_html__( 'Label typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', 'label' ) ) );
		$this->style( 'labelMargin', 'form', esc_html__( 'Label margin', 'beltoft-services' ), 'dimensions', array( self::rule( 'margin', 'label' ) ) );

		// Input.
		$this->style( 'inputTypography', 'input', esc_html__( 'Typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', '> input' ) ) );
		$this->style( 'inputBackground', 'input', esc_html__( 'Background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', '> input' ) ) );
		$this->style( 'inputBorder', 'input', esc_html__( 'Border', 'beltoft-services' ), 'border', array( self::rule( 'border', '> input' ) ) );
		$this->style( 'inputHeight', 'input', esc_html__( 'Height', 'beltoft-services' ), 'number', array( self::rule( 'height', '> input' ) ), array( 'units' => true ) );
		$this->style( 'inputPadding', 'input', esc_html__( 'Padding', 'beltoft-services' ), 'dimensions', array( self::rule( 'padding', '> input' ) ) );

		// Buttons: the search button and the buttons in the results share one set of controls each, with a hover colour pair.
		$buttons = array(
			'search' => array( esc_html__( 'Search button', 'beltoft-services' ), '> button' ),
			'result' => array( esc_html__( 'Result buttons', 'beltoft-services' ), '.beltoft-domain-action' ),
		);
		foreach ( $buttons as $id => list( $title, $selector ) ) {
			$this->controls[ $id . 'ButtonsInfo' ] = array( 'group' => 'buttons', 'type' => 'separator', 'label' => $title );
			$this->style( $id . 'ButtonTypography', 'buttons', esc_html__( 'Typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', $selector ) ) );
			$this->style( $id . 'ButtonBackground', 'buttons', esc_html__( 'Background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', $selector ) ) );
			$this->style( $id . 'ButtonBorder', 'buttons', esc_html__( 'Border', 'beltoft-services' ), 'border', array( self::rule( 'border', $selector ) ) );
			$this->style( $id . 'ButtonPadding', 'buttons', esc_html__( 'Padding', 'beltoft-services' ), 'dimensions', array( self::rule( 'padding', $selector ) ) );
			$this->style( $id . 'ButtonMinHeight', 'buttons', esc_html__( 'Minimum height', 'beltoft-services' ), 'number', array( self::rule( 'min-height', $selector ) ), array( 'units' => true ) );
			$this->style( $id . 'ButtonMinWidth', 'buttons', esc_html__( 'Minimum width', 'beltoft-services' ), 'number', array( self::rule( 'min-width', $selector ) ), array( 'units' => true ) );
			$this->style( $id . 'ButtonWidth', 'buttons', esc_html__( 'Width', 'beltoft-services' ), 'number', array( self::rule( 'width', $selector ) ), array( 'units' => true ) );
			$this->style( $id . 'ButtonHoverBackground', 'buttons', esc_html__( 'Hover background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', $selector . ':hover' ) ) );
			$this->style( $id . 'ButtonHoverColor', 'buttons', esc_html__( 'Hover text colour', 'beltoft-services' ), 'color', array( self::rule( 'color', $selector . ':hover' ) ) );
		}

		// Results.
		$row = '.beltoft-domain-result';
		$this->style( 'resultsGap', 'results', esc_html__( 'Gap between results', 'beltoft-services' ), 'number', array( self::rule( 'gap', '.beltoft-domain-results' ) ), array( 'units' => true ) );
		$this->style( 'resultsTypography', 'results', esc_html__( 'Results typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', '.beltoft-domain-results' ) ) );
		$this->style( 'resultsMargin', 'results', esc_html__( 'Results margin', 'beltoft-services' ), 'dimensions', array( self::rule( 'margin', '.beltoft-domain-results' ) ) );
		$this->style( 'rowColumns', 'results', esc_html__( 'Row columns (grid template)', 'beltoft-services' ), 'text', array( self::rule( 'grid-template-columns', $row ) ), array( 'placeholder' => 'minmax(0,1fr) auto auto', 'hasDynamicData' => false ) );
		$this->style( 'rowGap', 'results', esc_html__( 'Row gap', 'beltoft-services' ), 'number', array( self::rule( 'gap', $row ) ), array( 'units' => true ) );
		$this->style( 'rowBackground', 'results', esc_html__( 'Row background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', $row ) ) );
		$this->style( 'rowBorder', 'results', esc_html__( 'Row border', 'beltoft-services' ), 'border', array( self::rule( 'border', $row ) ) );
		$this->style( 'rowPadding', 'results', esc_html__( 'Row padding', 'beltoft-services' ), 'dimensions', array( self::rule( 'padding', $row ) ) );
		$this->style( 'takenBackground', 'results', esc_html__( 'Taken row background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', '.beltoft-domain-result--taken' ) ) );
		$this->style( 'takenOpacity', 'results', esc_html__( 'Taken row text opacity', 'beltoft-services' ), 'number', array( self::rule( '--bds-taken-opacity' ) ), array( 'min' => 0, 'max' => 1, 'step' => 0.05, 'placeholder' => '0.55' ) );
		$this->style( 'unavailableBackground', 'results', esc_html__( 'Unavailable and premium row background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', '.beltoft-domain-result--not_sold, .beltoft-domain-result--premium' ) ) );
		$this->style( 'primaryBackground', 'results', esc_html__( 'Main result background', 'beltoft-services' ), 'color', array( self::rule( 'background-color', '.beltoft-domain-result--primary' ) ) );
		$this->style( 'primaryBorderColor', 'results', esc_html__( 'Main result border colour', 'beltoft-services' ), 'color', array( self::rule( 'border-color', '.beltoft-domain-result--primary' ) ) );
		$this->style( 'actionsDirection', 'results', esc_html__( 'Buttons direction', 'beltoft-services' ), 'direction', array( self::rule( 'flex-direction', '.beltoft-domain-result__actions' ) ) );
		$this->style( 'primaryPadding', 'results', esc_html__( 'Main result padding', 'beltoft-services' ), 'dimensions', array( self::rule( 'padding', '.beltoft-domain-result--primary' ) ) );

		// Result text.
		$texts = array(
			'name'   => array( esc_html__( 'Domain name', 'beltoft-services' ), '.beltoft-domain-result__name' ),
			'price'  => array( esc_html__( 'Price', 'beltoft-services' ), '.beltoft-domain-result__price' ),
			'status' => array( esc_html__( 'Status', 'beltoft-services' ), '.beltoft-domain-result__status' ),
			'detail' => array( esc_html__( 'Details and renewal', 'beltoft-services' ), '.beltoft-domain-result__note, .beltoft-domain-result__renewal' ),
			'note'   => array( esc_html__( 'Price note above the results', 'beltoft-services' ), '.beltoft-domain-note' ),
			'error'  => array( esc_html__( 'Error message', 'beltoft-services' ), '.beltoft-domain-error' ),
			'link'   => array( esc_html__( 'Transfer links', 'beltoft-services' ), '.beltoft-domain-search__transfer a, .beltoft-domain-result__transfer' ),
		);
		foreach ( $texts as $id => list( $title, $selector ) ) {
			$this->style( $id . 'Typography', 'text', $title, 'typography', array( self::rule( 'font', $selector ) ) );
		}
		// The link in a taken result row sits on the row's background, not the form's: it gets its own colour (later rule, so it wins).
		$this->style( 'resultLinkTypography', 'text', esc_html__( 'Transfer link in a result', 'beltoft-services' ), 'typography', array( self::rule( 'font', '.beltoft-domain-results .beltoft-domain-result__transfer' ) ) );

		// Transfer code form, shown by the transfer element once the domain is checked.
		$this->style( 'transferColumns', 'transfer', esc_html__( 'Columns (grid template)', 'beltoft-services' ), 'text', array( self::rule( 'grid-template-columns', '.beltoft-domain-transfer' ) ), array( 'placeholder' => 'minmax(12rem,1fr) auto', 'hasDynamicData' => false ) );
		$this->style( 'transferMaxWidth', 'transfer', esc_html__( 'Maximum width', 'beltoft-services' ), 'number', array( self::rule( 'max-width', '.beltoft-domain-transfer' ) ), array( 'units' => true ) );
		$this->style( 'transferHelpTypography', 'transfer', esc_html__( 'Help text typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', '.beltoft-domain-transfer__help' ) ) );
		$this->style( 'transferInputTypography', 'transfer', esc_html__( 'Code input typography', 'beltoft-services' ), 'typography', array( self::rule( 'font', '.beltoft-domain-transfer input' ) ) );
		$this->style( 'transferInputBorder', 'transfer', esc_html__( 'Code input border', 'beltoft-services' ), 'border', array( self::rule( 'border', '.beltoft-domain-transfer input' ) ) );
		$this->style( 'transferInputHeight', 'transfer', esc_html__( 'Code input height', 'beltoft-services' ), 'number', array( self::rule( 'height', '.beltoft-domain-transfer input' ) ), array( 'units' => true ) );
		$this->style( 'transferInputPadding', 'transfer', esc_html__( 'Code input padding', 'beltoft-services' ), 'dimensions', array( self::rule( 'padding', '.beltoft-domain-transfer input' ) ) );

		// Loading: these set variables the stylesheet reads, so they reach the animated bar.
		$this->style( 'loadingBar', 'loading', esc_html__( 'Bar colour', 'beltoft-services' ), 'color', array( self::rule( '--bds-bar' ) ) );
		$this->style( 'loadingTrack', 'loading', esc_html__( 'Track colour', 'beltoft-services' ), 'color', array( self::rule( '--bds-track' ) ) );
		$this->style( 'accent', 'loading', esc_html__( 'Accent colour (working button)', 'beltoft-services' ), 'color', array( self::rule( '--bds-accent' ) ) );
	}

	public function render() {
		$settings = $this->settings;
		$this->set_attribute( '_root', 'class', 'beltoft-domain-search' );
		foreach ( Search::root_data() as $name => $value ) {
			$this->set_attribute( '_root', $name, $value );
		}
		$this->set_attribute( '_root', 'data-param', DomainQuery::param() );
		$search_url = trim( (string) ( $settings['searchUrl'] ?? '' ) );
		if ( '' !== $search_url ) {
			$this->set_attribute( '_root', 'data-search-url', esc_url( $search_url ) );
		}
		$texts = array(
			'label'       => (string) ( $settings['labelText'] ?? '' ),
			'placeholder' => (string) ( $settings['placeholder'] ?? '' ),
			'button'      => (string) ( $settings['buttonText'] ?? '' ),
			'transfer'    => (string) ( $settings['transferLinkText'] ?? '' ),
		);
		// Inner markup is escaped by the builder.
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . Search::inner_markup( $texts, (string) ( $settings['transferUrl'] ?? '' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
