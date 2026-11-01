<?php
/**
 * Bricks element: the mail setup guide.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Bricks;

use BeltoftServices\Mail\MailSetup;

defined( 'ABSPATH' ) || exit;

/**
 * The same markup as the shortcode [beltoft_services_mail_setup] (MailSetup::html), with controls for the headings and
 * the intro and for the looks. Looks map to the --bsms-* variables of assets/mail-setup.css where one exists; the
 * layout stays in that stylesheet. Loaded only when Bricks is active (see DocsElements).
 */
class MailSetupElement extends \Bricks\Element {

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
	public $name = 'beltoft-mail-setup';

	/**
	 * Icon.
	 *
	 * @var string
	 */
	public $icon = 'ti-email';

	/**
	 * Root selector.
	 *
	 * @var string
	 */
	public $css_selector = '.beltoft-services-mailsetup';

	/**
	 * Label.
	 */
	public function get_label() {
		return esc_html__( 'Email setup guide', 'beltoft-services' );
	}

	/**
	 * Keywords of the element search.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords() {
		return array( 'mail', 'email', 'imap', 'smtp', 'setup' );
	}

	/**
	 * Control groups.
	 */
	public function set_control_groups() {
		$groups = array(
			'texts'    => array( esc_html__( 'Texts', 'beltoft-services' ), 'content' ),
			'layout'   => array( esc_html__( 'Layout', 'beltoft-services' ), 'style' ),
			'headings' => array( esc_html__( 'Headings', 'beltoft-services' ), 'style' ),
			'body'     => array( esc_html__( 'Text and lists', 'beltoft-services' ), 'style' ),
			'tables'   => array( esc_html__( 'Tables', 'beltoft-services' ), 'style' ),
			'code'     => array( esc_html__( 'Settings values', 'beltoft-services' ), 'style' ),
			'links'    => array( esc_html__( 'Links', 'beltoft-services' ), 'style' ),
		);

		foreach ( $groups as $key => $group ) {
			$this->control_groups[ $key ] = array(
				'title' => $group[0],
				'tab'   => $group[1],
			);
		}
	}

	/**
	 * Controls.
	 */
	public function set_controls() {
		$root = '.beltoft-services-mailsetup';

		foreach ( self::text_fields() as $key => $label ) {
			$this->controls[ $key ] = array(
				'group'          => 'texts',
				'label'          => $label,
				'type'           => 'intro' === $key ? 'textarea' : 'text',
				'placeholder'    => esc_html__( 'Default text', 'beltoft-services' ),
				'hasDynamicData' => false,
			);
		}
		$this->controls['textsInfo'] = array(
			'group'   => 'texts',
			'type'    => 'info',
			'content' => esc_html__( 'Leave a field empty to keep the default text. The mail server values come from the Services settings.', 'beltoft-services' ),
		);

		$c = DocsControls::class;

		$this->controls['width']   = array(
			'group' => 'layout',
			'label' => esc_html__( 'Maximum width', 'beltoft-services' ),
			'type'  => 'number',
			'units' => true,
			'css'   => $c::rules( array( 'max-width' ), $root ),
		);
		$this->controls['gap']     = $c::size_var( 'layout', esc_html__( 'Space above each section', 'beltoft-services' ), array( '--bsms-gap' ) );
		$this->controls['jumpGap'] = $c::property( 'layout', esc_html__( 'Gap between the jump links', 'beltoft-services' ), 'number', 'gap', array( $root . ' .beltoft-services-mailsetup__jump' ) );
		$this->controls['jumpGap']['units'] = true;

		$this->controls['h2Typography'] = $c::typography( 'headings', esc_html__( 'Section headings', 'beltoft-services' ), array( $root . ' h2' ) );
		$this->controls['h3Typography'] = $c::typography( 'headings', esc_html__( 'Sub headings', 'beltoft-services' ), array( $root . ' h3' ) );
		$this->controls['headingColor'] = $c::color_var( 'headings', esc_html__( 'Heading colour', 'beltoft-services' ), array( '--bsms-heading' ) );

		$this->controls['bodyTypography'] = $c::typography( 'body', esc_html__( 'Body text', 'beltoft-services' ), array( $root ) );
		$this->controls['textColor']      = $c::color_var( 'body', esc_html__( 'Text colour', 'beltoft-services' ), array( '--bsms-text' ) );
		$this->controls['softColor']      = $c::color_var( 'body', esc_html__( 'Muted text colour', 'beltoft-services' ), array( '--bsms-soft-color' ) );
		$this->controls['markerColor']    = $c::color_var( 'body', esc_html__( 'Step number colour', 'beltoft-services' ), array( '--bsms-marker' ) );
		$this->controls['markerTypography'] = $c::typography( 'body', esc_html__( 'Step numbers', 'beltoft-services' ), array( $root . ' li::marker' ) );

		$this->controls['thTypography'] = $c::typography( 'tables', esc_html__( 'Header cells', 'beltoft-services' ), array( $root . ' th' ) );
		$this->controls['thColor']      = $c::color_var( 'tables', esc_html__( 'Header text colour', 'beltoft-services' ), array( '--bsms-th' ) );
		$this->controls['thBg']         = $c::color_var( 'tables', esc_html__( 'Header background', 'beltoft-services' ), array( '--bsms-th-bg' ) );
		$this->controls['tableBg']      = $c::color_var( 'tables', esc_html__( 'Table background', 'beltoft-services' ), array( '--bsms-card-color' ) );
		$this->controls['ruleColor']    = $c::color_var( 'tables', esc_html__( 'Row line colour', 'beltoft-services' ), array( '--bsms-rule-color' ) );
		$this->controls['ruleWidth']    = $c::size_var( 'tables', esc_html__( 'Row line width', 'beltoft-services' ), array( '--bsms-rule-width' ) );
		$this->controls['cellPadding']  = $c::property( 'tables', esc_html__( 'Cell padding', 'beltoft-services' ), 'spacing', 'padding', array( $root . ' th', $root . ' td' ) );
		$this->controls['cellTypography'] = $c::typography( 'tables', esc_html__( 'Body cells', 'beltoft-services' ), array( $root . ' td' ) );

		$this->controls['valueTypography'] = $c::typography( 'code', esc_html__( 'Server names and values (bold)', 'beltoft-services' ), array( $root . ' strong' ) );
		$this->controls['codeTypography']  = $c::typography( 'code', esc_html__( 'Code in tables', 'beltoft-services' ), array( $root . ' code' ) );
		$this->controls['codeBg']          = $c::property( 'code', esc_html__( 'Code background', 'beltoft-services' ), 'color', 'background-color', array( $root . ' code' ) );
		$this->controls['codePadding']     = $c::property( 'code', esc_html__( 'Code padding', 'beltoft-services' ), 'spacing', 'padding', array( $root . ' code' ) );

		$this->controls['linkColor']      = $c::color_var( 'links', esc_html__( 'Link colour', 'beltoft-services' ), array( '--bsms-link' ) );
		$this->controls['linkTypography'] = $c::typography( 'links', esc_html__( 'Links', 'beltoft-services' ), array( $root . ' a' ) );
	}

	/**
	 * The text controls: key => label. Keys are the keys of MailSetup::html().
	 *
	 * @return array<string, string>
	 */
	public static function text_fields(): array {
		return array(
			'settings_title' => esc_html__( 'Heading of the settings table', 'beltoft-services' ),
			'intro'          => esc_html__( 'Text under the table', 'beltoft-services' ),
			'jump_label'     => esc_html__( 'Text before the app links', 'beltoft-services' ),
			'dav_title'      => esc_html__( 'Heading, calendar and contacts', 'beltoft-services' ),
			'faq_title'      => esc_html__( 'Heading, questions', 'beltoft-services' ),
		);
	}

	/**
	 * The custom texts of this instance.
	 *
	 * @return array<string, string>
	 */
	public function texts(): array {
		$texts = array();

		foreach ( array_keys( self::text_fields() ) as $key ) {
			if ( isset( $this->settings[ $key ] ) && is_string( $this->settings[ $key ] ) ) {
				$texts[ $key ] = $this->settings[ $key ];
			}
		}

		return $texts;
	}

	/**
	 * Output: the guide inside the Bricks root.
	 */
	public function render() {
		$root = $this->render_attributes( '_root' );

		// The markup is built and escaped by the template; the root attributes are made by Bricks.
		echo '<div ' . $root . '>' . MailSetup::html( $this->texts() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
