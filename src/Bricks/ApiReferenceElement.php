<?php
/**
 * Bricks element: the API reference.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

use BeltoftServices\Api\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * The same output as the shortcode [beltoft_services_api_reference] (Reference::html), in either layout. Looks map to
 * the --apiref-* (page column layout) and --bsdocs-* (full width docs app) custom properties of the two stylesheets;
 * layout and script behaviour stay in those files. Loaded only when Bricks is active (see DocsElements).
 */
class ApiReferenceElement extends \Bricks\Element {

	/**
	 * Panel category.
	 *
	 * @var string
	 */
	public $category = 'beltoft services';

	/**
	 * Element name.
	 *
	 * @var string
	 */
	public $name = 'beltoft-api-reference';

	/**
	 * Icon.
	 *
	 * @var string
	 */
	public $icon = 'ti-book';

	/**
	 * Label.
	 */
	public function get_label() {
		return esc_html__( 'API reference', 'beltoft-services' );
	}

	/**
	 * Keywords of the element search.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return array( 'api', 'docs', 'documentation', 'reference', 'rest' );
	}

	/**
	 * Control groups.
	 */
	public function set_control_groups() {
		$groups = array(
			'layout'  => array( esc_html__( 'Layout', 'beltoft-services' ), 'content' ),
			'sidebar' => array( esc_html__( 'Sidebar', 'beltoft-services' ), 'style' ),
			'search'  => array( esc_html__( 'Search', 'beltoft-services' ), 'style' ),
			'content' => array( esc_html__( 'Content', 'beltoft-services' ), 'style' ),
			'band'    => array( esc_html__( 'Code band', 'beltoft-services' ), 'style' ),
			'tabs'    => array( esc_html__( 'Language tabs', 'beltoft-services' ), 'style' ),
			'methods' => array( esc_html__( 'Methods', 'beltoft-services' ), 'style' ),
		);

		foreach ( $groups as $key => $group ) {
			$this->control_groups[ $key ] = array(
				'title' => $group[0],
				'tab'   => $group[1],
			);
		}
	}

	/**
	 * Both stylesheets use their own prefix: the arguments map to (apiref, bsdocs).
	 *
	 * @param string $suffix Variable name after the prefix, e.g. 'text'.
	 * @return array<int, string>
	 */
	private static function vars( string $suffix ): array {
		return array( '--apiref-' . $suffix, '--bsdocs-' . $suffix );
	}

	/**
	 * Selectors for both layouts.
	 *
	 * @param string $apiref Part after .beltoft-services-apiref (a space and a selector, or '' for the root).
	 * @param string $bsdocs Part after .bsdocs.
	 * @return array<int, string>
	 */
	private static function both( string $apiref, string $bsdocs ): array {
		return array( '.beltoft-services-apiref' . $apiref, '.bsdocs' . $bsdocs );
	}

	/**
	 * Controls.
	 */
	public function set_controls() {
		$c = DocsControls::class;

		$this->controls['layout'] = array(
			'group'       => 'layout',
			'label'       => esc_html__( 'Layout', 'beltoft-services' ),
			'type'        => 'select',
			'options'     => array(
				'full'    => esc_html__( 'Full width docs app', 'beltoft-services' ),
				'default' => esc_html__( 'Page column', 'beltoft-services' ),
			),
			'default'     => 'full',
			'inline'      => true,
			'placeholder' => esc_html__( 'Full width docs app', 'beltoft-services' ),
		);

		// Sidebar.
		$this->controls['sideWidth']  = $c::size_var( 'sidebar', esc_html__( 'Width (desktop, full layout)', 'beltoft-services' ), array( '--bsdocs-side-width' ) );
		$this->controls['sideBg']     = $c::color_var( 'sidebar', esc_html__( 'Background', 'beltoft-services' ), array( '--apiref-nav-bg', '--bsdocs-side-bg' ) );
		$this->controls['navLinks']   = $c::typography( 'sidebar', esc_html__( 'Link typography', 'beltoft-services' ), self::both( ' .beltoft-services-apiref__nav a', ' .bsdocs__nav a' ) );
		$this->controls['navActive']  = $c::color_var( 'sidebar', esc_html__( 'Active link colour', 'beltoft-services' ), self::vars( 'nav-active' ) );
		$this->controls['navLine']    = $c::color_var( 'sidebar', esc_html__( 'Divider colour', 'beltoft-services' ), self::vars( 'rule-color' ) );

		// Search (full layout).
		$this->controls['searchBg']   = $c::color_var( 'search', esc_html__( 'Input background', 'beltoft-services' ), array( '--bsdocs-search-bg' ) );
		$this->controls['searchFg']   = $c::color_var( 'search', esc_html__( 'Input text colour', 'beltoft-services' ), array( '--bsdocs-search-fg' ) );
		$this->controls['searchLine'] = $c::color_var( 'search', esc_html__( 'Input border colour', 'beltoft-services' ), array( '--bsdocs-search-line' ) );
		$this->controls['searchType'] = $c::typography( 'search', esc_html__( 'Input typography', 'beltoft-services' ), array( '.bsdocs .bsdocs__search' ) );

		// Content.
		$this->controls['h2Typography']   = $c::typography( 'content', esc_html__( 'Section headings', 'beltoft-services' ), self::both( ' h2', ' h2' ) );
		$this->controls['h3Typography']   = $c::typography( 'content', esc_html__( 'Endpoint headings', 'beltoft-services' ), self::both( ' h3', ' h3' ) );
		$this->controls['bodyTypography'] = $c::typography( 'content', esc_html__( 'Body text', 'beltoft-services' ), array_merge( self::both( ' p', ' p' ), self::both( ' li', ' li' ), self::both( ' td', ' td' ) ) );
		$this->controls['textColor']      = $c::color_var( 'content', esc_html__( 'Text colour', 'beltoft-services' ), self::vars( 'text' ) );
		$this->controls['softColor']      = $c::color_var( 'content', esc_html__( 'Muted text colour', 'beltoft-services' ), array( '--apiref-soft-color', '--bsdocs-soft-color' ) );
		$this->controls['accent']         = $c::color_var( 'content', esc_html__( 'Accent colour (parameters, focus)', 'beltoft-services' ), self::vars( 'accent' ) );
		$this->controls['linkTypography'] = $c::typography( 'content', esc_html__( 'Links in the text', 'beltoft-services' ), self::both( ' p a', ' p a' ) );
		$this->controls['cardBg']         = $c::color_var( 'content', esc_html__( 'Address box background', 'beltoft-services' ), array( '--bsdocs-card-color' ) );

		// Code band.
		$this->controls['bandBg']      = $c::color_var( 'band', esc_html__( 'Band background', 'beltoft-services' ), array( '--bsdocs-band-bg' ) );
		$this->controls['codeBg']      = $c::color_var( 'band', esc_html__( 'Code block background', 'beltoft-services' ), array( '--apiref-code-bg', '--bsdocs-code-bg' ) );
		$this->controls['codeFg']      = $c::color_var( 'band', esc_html__( 'Code text colour', 'beltoft-services' ), array( '--apiref-code-fg', '--bsdocs-code-fg' ) );
		$this->controls['codeSoft']    = $c::color_var( 'band', esc_html__( 'Code caption colour', 'beltoft-services' ), array( '--apiref-code-soft', '--bsdocs-code-soft-color' ) );
		$this->controls['codeSize']    = $c::size_var( 'band', esc_html__( 'Code font size', 'beltoft-services' ), self::vars( 'code-size' ) );
		$this->controls['copyBg']      = $c::color_var( 'band', esc_html__( 'Copy button background', 'beltoft-services' ), array( '--apiref-copy-bg', '--bsdocs-copy-bg' ) );
		$this->controls['copyHover']   = $c::color_var( 'band', esc_html__( 'Copy button hover background', 'beltoft-services' ), array( '--apiref-copy-hover', '--bsdocs-copy-hover' ) );

		// Language tabs (full layout).
		$this->controls['tabType']       = $c::typography( 'tabs', esc_html__( 'Tab typography', 'beltoft-services' ), array( '.bsdocs .bsdocs__tab' ) );
		$this->controls['tabActiveBg']   = $c::color_var( 'tabs', esc_html__( 'Active tab background', 'beltoft-services' ), array( '--bsdocs-tab-active-bg' ) );
		$this->controls['tabActiveFg']   = $c::color_var( 'tabs', esc_html__( 'Active tab text colour', 'beltoft-services' ), array( '--bsdocs-tab-active-fg' ) );
		$this->controls['tabActiveLine'] = $c::color_var( 'tabs', esc_html__( 'Active tab underline colour', 'beltoft-services' ), array( '--bsdocs-tab-active-line' ) );

		// Method labels.
		$this->controls['methodType'] = $c::typography( 'methods', esc_html__( 'Method label typography', 'beltoft-services' ), self::both( ' .beltoft-services-apiref__method', ' .bsdocs__method' ) );
		$this->controls['getColor']   = $c::color_var( 'methods', esc_html__( 'GET colour', 'beltoft-services' ), self::vars( 'get' ) );
		$this->controls['postColor']  = $c::color_var( 'methods', esc_html__( 'POST colour', 'beltoft-services' ), self::vars( 'post' ) );
		$this->controls['putColor']   = $c::color_var( 'methods', esc_html__( 'PUT colour', 'beltoft-services' ), self::vars( 'put' ) );
		$this->controls['deleteColor'] = $c::color_var( 'methods', esc_html__( 'DELETE colour', 'beltoft-services' ), self::vars( 'delete' ) );
	}

	/**
	 * The layout of this instance: full or default.
	 */
	public function layout(): string {
		return isset( $this->settings['layout'] ) && 'default' === $this->settings['layout'] ? 'default' : 'full';
	}

	/**
	 * Output: the reference inside the Bricks root.
	 */
	public function render() {
		$root = $this->render_attributes( '_root' );

		// The markup is built and escaped by the template; the root attributes are made by Bricks.
		echo '<div ' . $root . '>' . Reference::html( $this->layout() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
