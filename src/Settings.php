<?php
/**
 * Settings → Services.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * Every site-specific value lives here, with a default that works on a fresh install.
 *
 * Other parts add to the page with two filters:
 * - `beltoft_services_settings_tabs` adds a tab (slug => label);
 * - `beltoft_services_settings_fields` adds fields. A field sets `tab` (a tab slug), `section`
 *   (a section slug, titled by `beltoft_services_settings_sections`), `type` (text, textarea,
 *   checkbox, number, select, email or secret), `default` and `label`.
 * A `secret` field is a password field whose value is encrypted with Crypto before it is saved.
 */
final class Settings {

	public const OPTION = 'beltoft_services_settings';
	public const PAGE   = 'beltoft-services';

	/**
	 * Field definitions in page order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		$fields = array(
			'pause_all'     => array(
				'tab'     => 'general',
				'section' => 'general',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Pause all driver activity', 'beltoft-services' ),
				'desc'    => __( 'Jobs keep their place and run when this is turned off.', 'beltoft-services' ),
			),
			'deletion_mode' => array(
				'tab'     => 'general',
				'section' => 'general',
				'type'    => 'select',
				'default' => 'off',
				'options' => array(
					'off'    => __( 'Off: never delete', 'beltoft-services' ),
					'report' => __( 'Report only: log what would be deleted', 'beltoft-services' ),
					'on'     => __( 'On: delete', 'beltoft-services' ),
				),
				'label'   => __( 'Delete paused services after their retention period', 'beltoft-services' ),
			),
			'warning_days'  => array(
				'tab'     => 'general',
				'section' => 'general',
				'type'    => 'number',
				'default' => 1,
				'min'     => 0,
				'label'   => __( 'Warn customers this many days before deletion', 'beltoft-services' ),
			),
			'admin_email'   => array(
				'tab'     => 'general',
				'section' => 'general',
				'type'    => 'email',
				'default' => (string) get_option( 'admin_email' ),
				'label'   => __( 'Send failure alerts to', 'beltoft-services' ),
			),
			'delete_data'   => array(
				'tab'     => 'general',
				'section' => 'general',
				'type'    => 'checkbox',
				'default' => 0,
				'label'   => __( 'Delete the settings when the plugin is deleted', 'beltoft-services' ),
			),
		);

		/**
		 * Filters the settings fields. Add fields for a tab here.
		 *
		 * @param array<string, array<string, mixed>> $fields Field definitions by key.
		 */
		$filtered = apply_filters( 'beltoft_services_settings_fields', $fields );

		return is_array( $filtered ) ? $filtered : $fields;
	}

	/**
	 * Tabs of the page, slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		$tabs = array( 'general' => __( 'General', 'beltoft-services' ) );

		/**
		 * Filters the tabs of Settings → Services.
		 *
		 * @param array<string, string> $tabs Tab labels by slug.
		 */
		$filtered = apply_filters( 'beltoft_services_settings_tabs', $tabs );

		return is_array( $filtered ) && isset( $filtered['general'] ) ? $filtered : $tabs;
	}

	/**
	 * Section titles by slug.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		$sections = array( 'general' => __( 'General', 'beltoft-services' ) );

		/**
		 * Filters the section titles of Settings → Services.
		 *
		 * @param array<string, string> $sections Titles by section slug.
		 */
		$filtered = apply_filters( 'beltoft_services_settings_sections', $sections );

		return is_array( $filtered ) ? $filtered : $sections;
	}

	/**
	 * One setting, falling back to its default.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}

		return self::fields()[ $key ]['default'] ?? null;
	}

	/**
	 * Hooks the page into the admin.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BELTOFT_SERVICES_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * The tab being shown.
	 */
	public static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Settings API registration.
	 */
	public static function register_setting(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		$fields   = self::fields();
		$sections = self::sections();
		$added    = array();

		foreach ( $fields as $field ) {
			$tab = $field['tab'] ?? 'general';
			$id  = $field['section'] ?? 'general';

			if ( isset( $added[ $tab . '_' . $id ] ) ) {
				continue;
			}

			$added[ $tab . '_' . $id ] = true;
			add_settings_section( 'beltoft_services_' . $tab . '_' . $id, $sections[ $id ] ?? '', '__return_false', self::PAGE . '-' . $tab );
		}

		foreach ( $fields as $key => $field ) {
			$tab = $field['tab'] ?? 'general';

			add_settings_field(
				'beltoft_services_' . $key,
				$field['label'],
				array( __CLASS__, 'render_field' ),
				self::PAGE . '-' . $tab,
				'beltoft_services_' . $tab . '_' . ( $field['section'] ?? 'general' ),
				array(
					'key'       => $key,
					'label_for' => 'beltoft_services_' . $key,
				)
			);
		}
	}

	/**
	 * Settings → Services.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Services', 'beltoft-services' ),
			__( 'Services', 'beltoft-services' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'beltoft-services' ) )
		);

		return $links;
	}

	/**
	 * The page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = self::current_tab();

		echo '<div class="wrap"><h1>' . esc_html__( 'Services', 'beltoft-services' ) . '</h1>';

		if ( ! Crypto::available() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s</p><p><code>%2$s</code></p></div>',
				esc_html__( 'Secrets cannot be saved until an encryption key is set. Add this line to wp-config.php:', 'beltoft-services' ),
				esc_html( "define( 'BELTOFT_SERVICES_KEY', '" . Crypto::suggested_key() . "' );" )
			);
		}

		settings_errors( self::OPTION );

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => $slug ), admin_url( 'options-general.php' ) ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		/**
		 * Renders a tab that draws its own content instead of the generic fields. When something
		 * is hooked to `beltoft_services_settings_tab_{slug}`, the Settings API form is skipped.
		 */
		if ( has_action( 'beltoft_services_settings_tab_' . $current ) ) {
			do_action( 'beltoft_services_settings_tab_' . $current );
			echo '</div>';
			return;
		}

		echo '<form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE . '-' . $current );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * One field.
	 *
	 * @param array{key:string} $args Field key.
	 */
	public static function render_field( array $args ): void {
		$key   = $args['key'];
		$field = self::fields()[ $key ];
		$id    = 'beltoft_services_' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::get( $key );

		switch ( $field['type'] ) {
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text code">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
				break;
			case 'checkbox':
				printf( '<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s>', esc_attr( $name ), esc_attr( $id ), checked( (int) $value, 1, false ) );
				break;
			case 'number':
				printf( '<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), esc_attr( (string) ( $field['min'] ?? 0 ) ) );
				break;
			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $option => $label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			case 'secret':
				// A saved secret is never printed back into the page.
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( '' !== (string) $value ? __( 'Saved', 'beltoft-services' ) : '' )
				);
				break;
			case 'email':
				printf( '<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			default:
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
		}

		if ( ! empty( $field['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
		}
	}

	/**
	 * Sanitises the whole option. Unknown keys are dropped. A field that was not submitted
	 * (it sits on another tab) keeps its saved value.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( self::fields() as $key => $field ) {
			$type = $field['type'];

			if ( ! array_key_exists( $key, $input ) ) {
				$clean[ $key ] = self::get( $key );
				continue;
			}

			$raw = $input[ $key ];

			switch ( $type ) {
				case 'checkbox':
					$clean[ $key ] = empty( $raw ) ? 0 : 1;
					break;
				case 'number':
					$clean[ $key ] = max( (int) ( $field['min'] ?? 0 ), (int) $raw );
					break;
				case 'select':
					$clean[ $key ] = array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : $field['default'];
					break;
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'email':
					$email         = sanitize_email( (string) $raw );
					$clean[ $key ] = is_email( $email ) ? $email : $field['default'];
					break;
				case 'secret':
					$raw           = trim( (string) $raw );
					$clean[ $key ] = (string) self::get( $key );

					if ( '' === $raw ) {
						break;
					}

					// update_option() may sanitise twice (add_option on a new option): keep a value that is already ours.
					if ( 0 === strpos( $raw, 'bs1:' ) && null !== Crypto::decrypt( $raw ) ) {
						$clean[ $key ] = $raw;
						break;
					}

					if ( ! Crypto::available() ) {
						add_settings_error(
							self::OPTION,
							'beltoft_services_no_key',
							__( 'Add BELTOFT_SERVICES_KEY to wp-config.php before saving secrets.', 'beltoft-services' )
						);
						break;
					}

					$clean[ $key ] = Crypto::encrypt( $raw );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		return $clean;
	}
}
