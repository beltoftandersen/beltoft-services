<?php
/**
 * The Overview of My Account: what needs the customer, servers, domains, licenses, the next payment,
 * recent activity and a welcome for a customer with nothing yet.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Progress;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One plain array of values for the logged-in customer, built once per request (see data()), and one
 * shortcode per section that prints a part of it. A section prints nothing when the customer has none
 * of that kind. Nothing here calls Proxmox or a registrar: it reads the license rows, the resource meta,
 * the status the power job already cached, the subscriptions and the license history.
 *
 * Shortcodes: beltoft_services_attention, _servers, _domains, _licenses, _next_payment, _activity, _welcome,
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
		'domains'      => 'beltoft_services_domains',
		'licenses'     => 'beltoft_services_licenses',
		'next_payment' => 'beltoft_services_next_payment',
		'activity'     => 'beltoft_services_activity',
		'welcome'      => 'beltoft_services_welcome',
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
	 * How many of the newest history rows are read to find the feed's items.
	 */
	private const HISTORY_ROWS = 100;

	/**
	 * The values for one user, by user id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $memo = array();

	/**
	 * The user's subscriptions, by user id.
	 *
	 * @var array<int, array<int, \WC_Subscription>>
	 */
	private static $subscriptions = array();

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
	 * Forgets what was built (tests; a request that changes the customer's services).
	 */
	public static function reset(): void {
		self::$memo          = array();
		self::$subscriptions = array();
		self::$printed       = false;
	}

	/**
	 * The stylesheet on the account page.
	 */
	public static function assets(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
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
				return $data['servers'] ? self::template(
					'overview/servers.php',
					array(
						'rows'  => array_slice( $data['servers'], 0, self::limit() ),
						'total' => count( $data['servers'] ),
						'url'   => Servers::url_list(),
					)
				) : '';
			case 'domains':
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
				return $data['next_payment'] ? self::template( 'overview/next-payment.php', array( 'payment' => $data['next_payment'] ) ) : '';
			case 'activity':
				return $data['activity'] ? self::template( 'overview/activity.php', array( 'items' => $data['activity'] ) ) : '';
			default:
				return $data['welcome'] ? self::template( 'overview/welcome.php', $data['welcome'] ) : '';
		}
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
	 * Everything the sections print, for the current customer. Built once per request.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || ! class_exists( Endpoint::class ) ) {
			return self::blank();
		}

		if ( ! isset( self::$memo[ $user_id ] ) ) {
			self::$memo[ $user_id ] = self::build( $user_id );
		}

		return self::$memo[ $user_id ];
	}

	/**
	 * The values of a customer with nothing.
	 *
	 * @return array<string, mixed>
	 */
	private static function blank(): array {
		return array(
			'attention'    => array(),
			'servers'      => array(),
			'domains'      => array(),
			'domains_url'  => '',
			'licenses'     => array(),
			'licenses_url' => '',
			'next_payment' => null,
			'activity'     => array(),
			'welcome'      => null,
		);
	}

	/**
	 * Builds the values. One pass: the licenses, their resources, the subscriptions and one history query.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	private static function build( int $user_id ): array {
		$data     = self::blank();
		$licenses = Endpoint::licenses_for_user( $user_id );

		if ( ! $licenses ) {
			// Subscriptions without a service (for example a renewal that failed) still need the customer.
			$subscriptions = self::subscriptions( $user_id );

			if ( ! $subscriptions ) {
				$data['welcome'] = self::welcome( $user_id );

				return $data;
			}

			$data['attention']    = self::attention( array(), array(), array(), $subscriptions, array() );
			$data['next_payment'] = self::next_payment( $user_id, $subscriptions, array() );

			return $data;
		}

		$groups  = Endpoint::split( $licenses );
		$domains = array_slice( (array) ( $groups['domains'] ?? array() ), 0, 50 );
		$tab     = array_slice( (array) ( $groups[ Servers::slug() ] ?? array() ), 0, 50 );
		$plain   = array_slice( (array) ( $groups[''] ?? array() ), 0, 50 );
		$names   = array();
		$servers = array();
		$addons  = array();
		$domain_views  = array();
		$license_views = array();

		foreach ( $tab as $license ) {
			if ( Servers::is_server( $license ) ) {
				$view                       = View::license( $license );
				$servers[ (int) $view['id'] ] = $view;
				$names[ (int) $license['id'] ] = (string) $view['product'];
			} else {
				$addons[]                      = $license;
				$names[ (int) $license['id'] ] = View::product_name( $license );
			}
		}

		foreach ( $domains as $license ) {
			$view                          = View::license( $license );
			$domain_views[]                = $view;
			$names[ (int) $license['id'] ] = (string) $view['product'];
		}

		foreach ( $plain as $license ) {
			$view                          = View::license( $license );
			$license_views[]               = $view;
			$names[ (int) $license['id'] ] = (string) $view['product'];
		}

		$resources = array();

		foreach ( $servers as $id => $view ) {
			$resource = Resource::for_license( (int) $view['license']['id'] );

			if ( $resource ) {
				$resources[ $id ] = $resource;
			}
		}

		// The same row code as the Servers list.
		$data['servers'] = Servers::rows( array_values( $servers ), $resources );

		$domain_resources = array();

		foreach ( $domain_views as $view ) {
			$resource = Resource::for_license( (int) $view['license']['id'] );

			if ( $resource ) {
				$domain_resources[ (int) $view['id'] ] = $resource;
				$data['domains'][]                     = self::domain_row( $view, $resource );
			}
		}

		foreach ( $license_views as $view ) {
			$data['licenses'][] = self::license_row( $view );
		}

		$data['domains_url']  = $domains ? Endpoint::url_for_license( $domains[0] ) : '';
		$data['licenses_url'] = $plain ? Endpoint::url_for_license( $plain[0] ) : '';

		$subscriptions = self::subscriptions( $user_id );
		$by_subscription = array();

		foreach ( $licenses as $license ) {
			$sub = (int) ( $license['subscription_id'] ?? 0 );

			if ( $sub > 0 && isset( $names[ (int) $license['id'] ] ) ) {
				$by_subscription[ $sub ][ $names[ (int) $license['id'] ] ] = true;
			}
		}

		$all_resources = $resources + $domain_resources;

		foreach ( $addons as $license ) {
			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource ) {
				$all_resources[ (int) $license['id'] ] = $resource;
			}
		}

		$by_id = array();

		foreach ( $licenses as $license ) {
			$by_id[ (int) $license['id'] ] = $license;
		}

		$data['attention']    = self::attention( $all_resources, $by_id, $names, $subscriptions, $by_subscription );
		$data['next_payment'] = self::next_payment( $user_id, $subscriptions, $by_subscription );
		$data['activity']     = self::activity( $licenses, $names, $subscriptions, $resources );

		return $data;
	}

	/**
	 * The customer's subscriptions, loaded once.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, \WC_Subscription>
	 */
	private static function subscriptions( int $user_id ): array {
		if ( ! isset( self::$subscriptions[ $user_id ] ) ) {
			$list = function_exists( 'wcs_get_users_subscriptions' ) ? wcs_get_users_subscriptions( $user_id ) : array();

			self::$subscriptions[ $user_id ] = array_slice( is_array( $list ) ? $list : array(), 0, 50, true );
		}

		return self::$subscriptions[ $user_id ];
	}

	/**
	 * A domain or DNS hosting row.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 * @return array<string, string>
	 */
	private static function domain_row( array $view, Resource $resource ): array {
		$dns_only = 'dns' === $resource->get( 'driver' );
		$external = ! $dns_only && (bool) $resource->get( 'dns_external' );
		$ours     = ! $external && ( $dns_only || 'custom' !== $resource->get( 'ns_mode' ) );

		if ( $dns_only ) {
			$sub = __( 'DNS with us', 'beltoft-services' );
		} else {
			$sub = $ours ? __( 'Registered and DNS with us', 'beltoft-services' ) : __( 'Registered with us', 'beltoft-services' );
		}

		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state );

		if ( 'ready' === $group && ! $resource->on_hold() ) {
			$tone = 'ok';
		} elseif ( 'getting-ready' === $group ) {
			$tone = 'busy';
		} else {
			$tone = 'bad';
		}

		$renews = Servers::renewal( $view['license'] );

		if ( '' === $renews && 'ready' === $group ) {
			$expires = (string) $resource->get( 'expires_at' );
			$time    = '' !== $expires ? strtotime( $expires . ' UTC' ) : false;

			if ( $time ) {
				/* translators: %s: date the registration ends */
				$renews = sprintf( __( 'Expires %s', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $time ) );
			}
		}

		return array(
			'url'    => Endpoint::url_for_license( $view['license'] ) . '#beltoft-license-' . (int) $view['id'],
			'name'   => (string) $view['product'],
			'sub'    => $sub,
			'state'  => \BeltoftServices\Account\Panel::label( $state, $resource ),
			'tone'   => $tone,
			'renews' => $renews,
		);
	}

	/**
	 * A plugin license row.
	 *
	 * @param array<string, mixed> $view License view.
	 * @return array<string, string>
	 */
	private static function license_row( array $view ): array {
		$renews = Servers::renewal( $view['license'] );

		if ( '' === $renews ) {
			$renews = (string) $view['expiry'];
		}

		return array(
			'url'    => Endpoint::url_for_license( $view['license'] ) . '#beltoft-license-' . (int) $view['id'],
			'name'   => (string) $view['product'],
			'sub'    => ! empty( $view['show_usage'] ) ? (string) $view['sites'] : '',
			'state'  => (string) $view['status_label'],
			'tone'   => ! empty( $view['active'] ) ? 'ok' : 'bad',
			'renews' => $renews,
		);
	}

	/**
	 * What needs the customer: work in progress, and subscriptions whose renewal failed.
	 *
	 * @param array<int, Resource>                  $resources       Resources of the customer's services, by license id.
	 * @param array<int, array<string, mixed>>      $licenses        License rows by id.
	 * @param array<int, string>                    $names           License id => name.
	 * @param array<int, \WC_Subscription>          $subscriptions   Subscriptions.
	 * @param array<int, array<string, bool>>       $by_subscription Subscription id => names of what it covers.
	 * @return array<int, array<string, string>>
	 */
	private static function attention( array $resources, array $licenses, array $names, array $subscriptions, array $by_subscription ): array {
		$items = array();

		foreach ( $resources as $id => $resource ) {
			$progress = Progress::build( $resource );
			$license  = $licenses[ $id ] ?? null;

			if ( empty( $progress['active'] ) || ! $license ) {
				continue;
			}

			$items[] = array(
				'tone'  => 'busy',
				'state' => __( 'Working', 'beltoft-services' ),
				'name'  => $names[ $id ] ?? View::product_name( $license ),
				'text'  => (string) $progress['title'],
				'link'  => self::follow_url( $license ),
				'label' => __( 'Follow it', 'beltoft-services' ),
			);
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || ! $subscription->has_status( 'on-hold' ) ) {
				continue;
			}

			$when = (int) $subscription->get_time( 'last_order_date_created', 'site' );
			$text = $when > 0
				/* translators: %s: date */
				? sprintf( __( 'could not be renewed on %s.', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $when ) )
				: __( 'could not be renewed.', 'beltoft-services' );

			$items[] = array(
				'tone'  => 'bad',
				'state' => __( 'Payment', 'beltoft-services' ),
				'name'  => self::covers( $subscription, $by_subscription ),
				'text'  => $text,
				'link'  => self::card_url(),
				'label' => __( 'Update card', 'beltoft-services' ),
			);
		}

		return $items;
	}

	/**
	 * Where a running action can be followed.
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function follow_url( array $license ): string {
		if ( Servers::is_server( $license ) || self::in_servers_tab( $license ) ) {
			return Servers::url( $license );
		}

		return Endpoint::url_for_license( $license ) . '#beltoft-license-' . (int) $license['id'];
	}

	/**
	 * Does the license live on the Servers tab (an extra disk or a snapshot pack)?
	 *
	 * @param array<string, mixed> $license License row.
	 */
	private static function in_servers_tab( array $license ): bool {
		$tab = Endpoint::split( array( $license ) );

		return ! empty( $tab[ Servers::slug() ] );
	}

	/**
	 * Where the customer updates the card.
	 */
	private static function card_url(): string {
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'payment-methods' ) : '';

		/**
		 * Where the overview sends a customer whose payment failed.
		 *
		 * @param string $url Address, default the payment methods page of My Account.
		 */
		return (string) apply_filters( 'beltoft_services_overview_card_url', $url );
	}

	/**
	 * What a subscription pays for, in words: the names of the services on it, else its items.
	 *
	 * @param \WC_Subscription                $subscription    Subscription.
	 * @param array<int, array<string, bool>> $by_subscription Subscription id => names.
	 */
	private static function covers( \WC_Subscription $subscription, array $by_subscription ): string {
		$names = array_keys( $by_subscription[ $subscription->get_id() ] ?? array() );

		if ( ! $names ) {
			foreach ( $subscription->get_items() as $item ) {
				$names[] = (string) $item->get_name();
			}
		}

		$names = array_values( array_unique( array_filter( $names ) ) );

		if ( ! $names ) {
			/* translators: %d: subscription number */
			return sprintf( __( 'Subscription #%d', 'beltoft-services' ), $subscription->get_id() );
		}

		if ( count( $names ) > 3 ) {
			$rest  = count( $names ) - 2;
			$names = array_slice( $names, 0, 2 );

			/* translators: %d: number of other items */
			$names[] = sprintf( _n( '%d more', '%d more', $rest, 'beltoft-services' ), $rest );
		}

		return implode( ', ', $names );
	}

	/**
	 * The next payment: the earliest date among the active subscriptions, the total of those due that day, what
	 * they cover and the default card.
	 *
	 * @param int                             $user_id         Customer.
	 * @param array<int, \WC_Subscription>    $subscriptions   Subscriptions.
	 * @param array<int, array<string, bool>> $by_subscription Subscription id => names.
	 * @return array<string, string>|null
	 */
	private static function next_payment( int $user_id, array $subscriptions, array $by_subscription ): ?array {
		$groups = array();

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || ! $subscription->has_status( 'active' ) ) {
				continue;
			}

			$next = (int) $subscription->get_time( 'next_payment', 'site' );

			if ( $next <= 0 ) {
				continue;
			}

			$key = wp_date( 'Y-m-d', $next ) . '|' . $subscription->get_currency();

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'time'     => $next,
					'currency' => $subscription->get_currency(),
					'total'    => 0.0,
					'subs'     => array(),
				);
			}

			$groups[ $key ]['total'] += (float) $subscription->get_total();
			$groups[ $key ]['subs'][] = $subscription;
		}

		if ( ! $groups ) {
			return null;
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				return $a['time'] <=> $b['time'];
			}
		);

		$first = reset( $groups );
		$names = array();

		usort(
			$first['subs'],
			static function ( \WC_Subscription $a, \WC_Subscription $b ): int {
				return $a->get_id() <=> $b->get_id();
			}
		);

		foreach ( $first['subs'] as $subscription ) {
			$names = array_merge( $names, array_map( 'trim', explode( ', ', self::covers( $subscription, $by_subscription ) ) ) );
		}

		$names = array_values( array_unique( array_filter( $names ) ) );

		if ( count( $names ) > 3 ) {
			$rest  = count( $names ) - 2;
			$names = array_slice( $names, 0, 2 );

			/* translators: %d: number of other items */
			$names[] = sprintf( _n( '%d more', '%d more', $rest, 'beltoft-services' ), $rest );
		}

		$billing = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'subscriptions' ) : '';

		return array(
			'date'   => wp_date( (string) get_option( 'date_format' ), (int) $first['time'] ),
			'time'   => gmdate( 'c', (int) $first['time'] ),
			'amount' => wc_price( (float) $first['total'], array( 'currency' => (string) $first['currency'] ) ),
			'covers' => implode( ', ', $names ),
			'card'   => self::card( $user_id, $first['subs'][0] ),
			/**
			 * Where "Go to Billing" leads.
			 *
			 * @param string $url Address, default the subscriptions page of My Account.
			 */
			'url'    => (string) apply_filters( 'beltoft_services_overview_billing_url', $billing ),
		);
	}

	/**
	 * The default card as "Visa ending 4242", else what the subscription says it is paid with, else ''.
	 *
	 * @param int               $user_id      Customer.
	 * @param \WC_Subscription  $subscription A subscription due.
	 */
	private static function card( int $user_id, \WC_Subscription $subscription ): string {
		if ( class_exists( '\WC_Payment_Tokens' ) ) {
			$token = \WC_Payment_Tokens::get_customer_default_token( $user_id );

			if ( $token instanceof \WC_Payment_Token_CC && '' !== (string) $token->get_last4() ) {
				$type = (string) $token->get_card_type();

				/* translators: 1: card brand, 2: last four digits */
				return sprintf( __( '%1$s ending %2$s', 'beltoft-services' ), function_exists( 'wc_get_credit_card_type_label' ) ? wc_get_credit_card_type_label( $type ) : ucfirst( $type ), $token->get_last4() );
			}
		}

		return wp_strip_all_tags( (string) $subscription->get_payment_method_title() );
	}

	/**
	 * The recent activity, newest first: only what the customer did or is told about, in plain words.
	 *
	 * The history of the services (the license events) is written for the admin; its texts are matched against
	 * a short list of known ones and everything else is left out, so nothing admin-only can show. A backup that
	 * finished comes from the server's own record.
	 *
	 * @param array<int, array<string, mixed>> $licenses      The customer's licenses.
	 * @param array<int, string>               $names         License id => name.
	 * @param array<int, \WC_Subscription>     $subscriptions Subscriptions.
	 * @param array<int, Resource>             $resources     Server resources.
	 * @return array<int, array<string, mixed>>
	 */
	private static function activity( array $licenses, array $names, array $subscriptions, array $resources ): array {
		global $wpdb;

		$ids           = array();
		$subscription_of = array();

		foreach ( $licenses as $license ) {
			if ( isset( $names[ (int) $license['id'] ] ) ) {
				$ids[]                                       = (int) $license['id'];
				$subscription_of[ (int) $license['id'] ] = (int) ( $license['subscription_id'] ?? 0 );
			}
		}

		$items = array();

		if ( $ids && class_exists( \BeltoftLicenses\Install::class ) ) {
			$table = \BeltoftLicenses\Install::tables()['events'];
			$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, one query per page; the placeholders are generated.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT license_id, message, created_at FROM %i WHERE type = 'service' AND license_id IN ($in) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					array_merge( array( $table ), $ids, array( self::HISTORY_ROWS ) )
				),
				ARRAY_A
			);
			// phpcs:enable

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$license_id = (int) $row['license_id'];
				$held       = false;
				$sub        = $subscription_of[ $license_id ] ?? 0;

				if ( $sub > 0 && isset( $subscriptions[ $sub ] ) && $subscriptions[ $sub ] instanceof \WC_Subscription ) {
					$held = $subscriptions[ $sub ]->has_status( 'on-hold' );
				}

				$text = self::describe( (string) $row['message'], (string) ( $names[ $license_id ] ?? '' ), $held );

				if ( '' !== $text ) {
					$items[] = array(
						'at'   => (int) strtotime( $row['created_at'] . ' UTC' ),
						'text' => $text,
					);
				}

				if ( count( $items ) >= self::FEED ) {
					break;
				}
			}
		}

		foreach ( $resources as $id => $resource ) {
			$done = (int) $resource->get( 'backup_ok_at' );

			if ( $done > 0 && isset( $names[ $id ] ) ) {
				$items[] = array(
					'at'   => $done,
					/* translators: %s: server name */
					'text' => sprintf( __( '%s: backup finished', 'beltoft-services' ), $names[ $id ] ),
				);
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);

		$items = array_slice( $items, 0, self::FEED );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['label'] = self::when( (int) $item['at'] );
			$items[ $i ]['time']  = gmdate( 'c', (int) $item['at'] );
		}

		return $items;
	}

	/**
	 * A history message as a customer sentence, or '' when it is not for customers.
	 *
	 * @param string $message History text.
	 * @param string $name    The service's name.
	 * @param bool   $held    Whether its subscription is on hold (a payment failed).
	 */
	public static function describe( string $message, string $name, bool $held ): string {
		if ( '' === $name ) {
			return '';
		}

		if ( 1 === preg_match( '/^Customer took the snapshot "(.*)"$/s', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: snapshot "%2$s" taken', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 1 === preg_match( '/^Snapshot "(.*)" deleted$/s', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: snapshot "%2$s" deleted', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 1 === preg_match( '/^Server rolled back to the snapshot "(.*)" of /s', $message, $m ) ) {
			/* translators: 1: server name, 2: snapshot name */
			return sprintf( __( '%1$s: went back to snapshot "%2$s"', 'beltoft-services' ), $name, $m[1] );
		}

		if ( 0 === strpos( $message, 'Server restored from a backup' ) || 0 === strpos( $message, 'Server restored from the backup' ) ) {
			/* translators: %s: server name */
			return sprintf( __( '%s: restored from a backup', 'beltoft-services' ), $name );
		}

		if ( 'Service provisioned' === $message ) {
			/* translators: %s: name of the server or service */
			return sprintf( __( '%s: ready', 'beltoft-services' ), $name );
		}

		if ( 'Service paused' === $message && $held ) {
			/* translators: %s: name of the server or service */
			return sprintf( __( '%s: payment failed, paused', 'beltoft-services' ), $name );
		}

		return '';
	}

	/**
	 * When, short: the time today, "Yesterday", else the date.
	 *
	 * @param int $time Unix time.
	 */
	private static function when( int $time ): string {
		$day = wp_date( 'Y-m-d', $time );

		if ( wp_date( 'Y-m-d' ) === $day ) {
			return wp_date( (string) get_option( 'time_format' ), $time );
		}

		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ) {
			return __( 'Yesterday', 'beltoft-services' );
		}

		return wp_date( (string) get_option( 'date_format' ), $time );
	}

	/**
	 * The welcome block of a customer with no services: three starting points. Their links come from the
	 * settings (or the filter below); a starting point without a link is not shown.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|null
	 */
	private static function welcome( int $user_id ): ?array {
		$cards = array(
			array(
				'key'    => 'servers',
				'label'  => __( 'Servers', 'beltoft-services' ),
				'title'  => __( 'A virtual server', 'beltoft-services' ),
				'text'   => __( 'Dedicated resources and root access, with snapshots and daily backups.', 'beltoft-services' ),
				'button' => __( 'See the plans', 'beltoft-services' ),
				'url'    => (string) Settings::get( 'welcome_servers_url' ),
			),
			array(
				'key'    => 'domains',
				'label'  => __( 'Domains', 'beltoft-services' ),
				'title'  => __( 'Register or move a domain', 'beltoft-services' ),
				'text'   => __( 'Register a new domain or move one you have, with DNS hosting included.', 'beltoft-services' ),
				'button' => __( 'Find a domain', 'beltoft-services' ),
				'url'    => (string) Settings::get( 'welcome_domains_url' ),
			),
			array(
				'key'    => 'plugins',
				'label'  => __( 'Plugins', 'beltoft-services' ),
				'title'  => __( 'Plugins', 'beltoft-services' ),
				'text'   => __( 'Plugins with updates and support, licensed to your sites.', 'beltoft-services' ),
				'button' => __( 'Browse plugins', 'beltoft-services' ),
				'url'    => (string) Settings::get( 'welcome_plugins_url' ),
			),
		);

		/**
		 * The starting points of the welcome block: key, label, title, text, button and url. An entry without a
		 * url is left out.
		 *
		 * @param array<int, array<string, string>> $cards   Cards.
		 * @param int                               $user_id Customer.
		 */
		$cards = apply_filters( 'beltoft_services_welcome_cards', $cards, $user_id );
		$cards = array_values(
			array_filter(
				is_array( $cards ) ? $cards : array(),
				static function ( $card ): bool {
					return is_array( $card ) && ! empty( $card['url'] );
				}
			)
		);

		if ( ! $cards ) {
			return null;
		}

		$user = get_userdata( $user_id );
		$name = $user ? ( '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->display_name ) : '';

		return array(
			'name'  => $name,
			'cards' => $cards,
		);
	}
}
