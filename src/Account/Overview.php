<?php
/**
 * The Overview of My Account: what needs the customer, servers, domains, licenses, the next payment,
 * recent activity and a welcome for a customer with nothing yet.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

defined( 'ABSPATH' ) || exit;

/**
 * One plain array of values for the logged-in customer, built once per request (see data()), and one
 * shortcode per section that prints a part of it. A section prints nothing when the customer has none
 * of that kind. Nothing here calls Proxmox or a registrar: it reads the license rows, the resource meta,
 * the status the power job already cached, the subscriptions and the license history.
 *
 * Shortcodes: beltoft_services_attention, _servers, _databases, _domains, _licenses, _apps, _mail, _next_payment, _activity, _welcome,
 * and _overview (all of them). The templates are in templates/account/overview/ and can be copied to
 * yourtheme/beltoft-services/account/overview/. On the WooCommerce Dashboard endpoint the whole overview is
 * printed by woocommerce_account_dashboard, unless the beltoft_services_overview_auto filter says false (a site
 * that places the shortcodes itself).
 */
final class Overview {

	/**
	 * Section => shortcode tag.
	 */
	public const SECTIONS = array(
		'attention'    => 'beltoft_services_attention',
		'servers'      => 'beltoft_services_servers',
		'databases'    => 'beltoft_services_databases',
		'domains'      => 'beltoft_services_domains',
		'apps'         => 'beltoft_services_apps',
		'mail'         => 'beltoft_services_mail',
		'licenses'     => 'beltoft_services_licenses',
		'next_payment' => 'beltoft_services_next_payment',
		'activity'     => 'beltoft_services_activity',
		'welcome'      => 'beltoft_services_welcome',
	);

	/**
	 * Section => the area (ServiceArea) that draws its rows.
	 */
	public const AREAS = array(
		'databases' => Databases::class,
		'apps'      => Apps::class,
		'mail'      => Email::class,
	);

	/**
	 * Most items of a kind that are printed, before the "see all" link takes over.
	 */
	public const LIMIT = 10;

	/**
	 * Most items in the activity feed.
	 */
	public const FEED = 5;

	/**
	 * Whether the Dashboard already printed the overview in this request.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_fields', array( __CLASS__, 'settings_fields' ), 8 );
		foreach ( self::SECTIONS as $section => $tag ) {
			add_shortcode(
				$tag,
				static function () use ( $section ): string {
					return self::section( $section );
				}
			);
		}

		add_shortcode( 'beltoft_services_overview', array( __CLASS__, 'all' ) );
		// WooCommerce fires the first on its own Dashboard; a Bricks Dashboard state replaces that and only fires the second.
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'dashboard' ), 20 );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'dashboard' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * The three welcome links, as settings on the General tab.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions by key.
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_fields( array $fields ): array {
		return array_merge(
			$fields,
			array(
				'welcome_servers_url' => array(
					'tab'     => 'general',
					'section' => 'account',
					'type'    => 'url',
					'default' => '',
					'label'   => __( 'Welcome block: servers link', 'beltoft-services' ),
					'desc'    => __( 'Address of the page where customers buy a server. A customer with no services sees three starting points in My Account; one without an address is left out.', 'beltoft-services' ),
				),
				'welcome_domains_url' => array(
					'tab'     => 'general',
					'section' => 'account',
					'type'    => 'url',
					'default' => '',
					'label'   => __( 'Welcome block: domains link', 'beltoft-services' ),
				),
				'welcome_plugins_url' => array(
					'tab'     => 'general',
					'section' => 'account',
					'type'    => 'url',
					'default' => '',
					'label'   => __( 'Welcome block: plugins link', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Forgets what was built (tests; a request that changes the customer's services).
	 */
	public static function reset(): void {
		OverviewData::reset();
		OverviewActivity::reset();
		self::$printed = false;
	}

	/**
	 * The stylesheet on the account page.
	 */
	public static function assets(): void {
		// Logged-in customers only, on the Overview and the billing pages (the sections and the tab bar print the
		// stylesheet themselves when they render); not on the login screen or Account details.
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! is_wc_endpoint_url() || '' !== Billing::current_tab() ) {
			Panel::style();
		}
	}

	/**
	 * Prints the whole overview on the Dashboard endpoint of My Account.
	 */
	public static function dashboard(): void {
		if ( self::$printed ) {
			return;
		}

		if ( 'woocommerce_account_content' === current_action() && ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_wc_endpoint_url() ) ) {
			return;
		}

		self::$printed = true;

		/**
		 * Whether the Dashboard endpoint prints the overview itself. A site that places the shortcodes in a
		 * page builder returns false.
		 *
		 * @param bool $auto Print it, default true.
		 */
		if ( apply_filters( 'beltoft_services_overview_auto', true ) ) {
			echo self::all(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the templates.
		}
	}

	/**
	 * Shortcode beltoft_services_overview: every section, in order, with the two-column rows.
	 */
	public static function all(): string {
		$parts = array();

		foreach ( array_keys( self::SECTIONS ) as $section ) {
			$parts[ $section ] = self::section( $section );
		}

		if ( '' === implode( '', $parts ) ) {
			return '';
		}

		return self::template( 'overview/overview.php', array( 'parts' => $parts ) );
	}

	/**
	 * One section's markup, or '' when there is nothing to show.
	 *
	 * @param string $section Key of SECTIONS.
	 */
	public static function section( string $section ): string {
		if ( ! isset( self::SECTIONS[ $section ] ) || ! is_user_logged_in() ) {
			return '';
		}

		Panel::style();

		$data = self::data();

		switch ( $section ) {
			case 'attention':
				return $data['attention'] ? self::template( 'overview/attention.php', array( 'items' => $data['attention'] ) ) : '';
			case 'servers':
				if ( $data['servers'] ) {
					\BeltoftServices\Proxmox\Network\LiveUsage::enqueue();
				}

				if ( ! $data['servers'] ) {
					return self::empty_section( 'servers', __( 'Servers', 'beltoft-services' ), __( 'You have no servers yet.', 'beltoft-services' ), Servers::buttons() );
				}

				return $data['servers'] ? self::template(
					'overview/servers.php',
					array(
						'rows'  => array_slice( $data['servers'], 0, self::limit() ),
						'total' => count( $data['servers'] ),
						'url'   => Servers::url_list(),
					)
				) : '';
			case 'domains':
				if ( ! $data['domains'] ) {
					return self::empty_section( 'domains', __( 'Domains & DNS', 'beltoft-services' ), __( 'You have no domains yet.', 'beltoft-services' ), Domains::buttons() );
				}

				return $data['domains'] ? self::template(
					'overview/rows.php',
					array(
						'class'   => 'domains',
						'heading' => __( 'Domains & DNS', 'beltoft-services' ),
						'more'    => __( 'Manage', 'beltoft-services' ),
						'url'     => $data['domains_url'],
						'rows'    => array_slice( $data['domains'], 0, self::limit() ),
					)
				) : '';
			case 'databases':
			case 'apps':
			case 'mail':
				// Every area shows, with its empty line and create button when the customer has nothing in it.
				if ( ! $data[ $section ] ) {
					$area = ServiceArea::get( self::AREAS[ $section ] );

					return self::empty_section( $section, $area->label(), $area->empty_line(), $area->buttons() );
				}

				return $data[ $section ] ? self::template(
					'overview/rows.php',
					array(
						'class'   => $section,
						'heading' => ServiceArea::get( self::AREAS[ $section ] )->label(),
						'more'    => ServiceArea::get( self::AREAS[ $section ] )->all_label(),
						'url'     => $data[ $section . '_url' ],
						'rows'    => array_slice( $data[ $section ], 0, self::limit() ),
					)
				) : '';
			case 'licenses':
				return $data['licenses'] ? self::template(
					'overview/rows.php',
					array(
						'class'   => 'licenses',
						'heading' => __( 'Licenses', 'beltoft-services' ),
						'more'    => __( 'Manage', 'beltoft-services' ),
						'url'     => $data['licenses_url'],
						'rows'    => array_slice( $data['licenses'], 0, self::limit() ),
					)
				) : '';
			case 'next_payment':
				// Nothing is due when the total is 0 (a free trial, a credit), so there is no card.
				// A pay-as-you-go customer's account is 0 a month, so the card also shows when there is usage to pay.
				$usage = \BeltoftServices\Usage\Estimate::for_user( get_current_user_id() );
				$due   = $data['next_payment'] && $data['next_payment']['total'] > 0;

				if ( ! $due && ( ! $usage || ! $usage['resources'] ) ) {
					return '';
				}

				return self::template(
					'overview/next-payment.php',
					array(
						'payment' => $data['next_payment'],
						'due'     => $due,
						'usage'   => $usage && $usage['resources'] ? \BeltoftServices\Usage\Estimate::so_far( get_current_user_id() ) : '',
						'next'    => $usage ? (int) $usage['next'] : 0,
					)
				);
			case 'activity':
				return $data['activity'] ? self::template( 'overview/activity.php', array( 'items' => $data['activity'] ) ) : '';
			default:
				return $data['welcome'] ? self::template( 'overview/welcome.php', $data['welcome'] ) : '';
		}
	}

	/**
	 * A section for a service area the customer has nothing in: the empty line and the create button.
	 *
	 * @param string                            $class   Section name.
	 * @param string                            $heading Heading.
	 * @param string                            $empty   Empty text.
	 * @param array<int, array<string, string>> $buttons Buttons (none without an address).
	 */
	private static function empty_section( string $class, string $heading, string $empty, array $buttons ): string {
		return self::template(
			'overview/empty.php',
			array(
				'class'   => $class,
				'heading' => $heading,
				'empty'   => $empty,
				'buttons' => $buttons,
			)
		);
	}

	/**
	 * How many rows of a kind are printed.
	 */
	private static function limit(): int {
		/**
		 * Most servers, domains and licenses the overview lists; the section's link shows the rest.
		 *
		 * @param int $limit Default 10.
		 */
		return max( 1, (int) apply_filters( 'beltoft_services_overview_limit', self::LIMIT ) );
	}

	/**
	 * A template of this plugin, or the theme's copy of it, printed into a string.
	 *
	 * @param string               $name Name under templates/account.
	 * @param array<string, mixed> $args Variables of the template.
	 */
	private static function template( string $name, array $args ): string {
		ob_start();
		wc_get_template( $name, $args, 'beltoft-services/account/', BELTOFT_SERVICES_PATH . 'templates/account/' );

		return (string) ob_get_clean();
	}

	/**
	 * Everything the sections print, for the current customer. Built once per request by OverviewData.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		return OverviewData::data();
	}

	/**
	 * A history message as a customer sentence, or '' when it is not for customers (see OverviewActivity).
	 *
	 * @param string $message History text.
	 * @param string $name    The service's name.
	 * @param bool   $held    Whether its subscription is on hold.
	 * @param bool   $failed  Whether a failed renewal payment came before the pause.
	 */
	public static function describe( string $message, string $name, bool $held, bool $failed = false ): string {
		return OverviewActivity::describe( $message, $name, $held, $failed );
	}
}
