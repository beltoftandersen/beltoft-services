<?php
/**
 * The values of the account Overview: what needs the customer, the services, the next payment and the welcome.
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
 * Builds the one plain array the Overview sections print, once per request and customer. Nothing here calls
 * Proxmox or a registrar: it reads the license rows, the resource meta, the status the power job already cached
 * and the subscriptions. The history is read by OverviewActivity. Overview::data() delegates here.
 */
final class OverviewData {

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
	 * Forgets what was built.
	 */
	public static function reset(): void {
		self::$memo          = array();
		self::$subscriptions = array();
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
			'databases'    => array(),
			'databases_url' => '',
			'apps'         => array(),
			'apps_url'     => '',
			'mail'         => array(),
			'mail_url'     => '',
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
		$tab     = array_slice( (array) ( $groups[ ServerLinks::slug() ] ?? array() ), 0, 50 );
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

		$area_views = array( 'databases' => array(), 'apps' => array(), 'mail' => array() );

		foreach ( Overview::AREAS as $key => $class ) {
			foreach ( array_slice( (array) ( $groups[ ServiceArea::get( $class )->slug() ] ?? array() ), 0, 50 ) as $license ) {
				$view                          = View::license( $license );
				$area_views[ $key ][]          = $view;
				$names[ (int) $license['id'] ] = (string) $view['product'];
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
				$data['domains'][]                     = DomainRows::row( $view, $resource );
			}
		}

		$area_resources = array();

		foreach ( Overview::AREAS as $key => $class ) {
			$area = ServiceArea::get( $class );

			foreach ( $area_views[ $key ] as $view ) {
				$resource = Resource::for_license( (int) $view['license']['id'] );

				if ( Resource::gone( $resource ) ) {
					continue;
				}

				$area_resources[ (int) $view['id'] ] = $resource;
				$row                                 = $area->row( $view, $resource );
				$sub                                 = array_filter( array( $row['details'], $row['extra'] ), 'strlen' );
				$data[ $key ][]                      = array(
					'url'    => $row['url'],
					'name'   => $row['name'],
					'sub'    => implode( ', ', $sub ),
					'state'  => $row['state'],
					'tone'   => $row['tone'],
					'renews' => '',
				);
			}

			$data[ $key . '_url' ] = $data[ $key ] ? $area->url_list() : '';
		}

		foreach ( $license_views as $view ) {
			$data['licenses'][] = self::license_row( $view );
		}

		$data['domains_url']  = $domains ? Domains::url_list() : '';
		$data['licenses_url'] = $plain ? Endpoint::url_for_license( $plain[0] ) : '';

		$subscriptions = self::subscriptions( $user_id );
		$by_subscription = array();

		foreach ( $licenses as $license ) {
			$sub = (int) ( $license['subscription_id'] ?? 0 );

			if ( $sub > 0 && isset( $names[ (int) $license['id'] ] ) ) {
				$by_subscription[ $sub ][ $names[ (int) $license['id'] ] ] = true;
			}
		}

		$all_resources = $resources + $domain_resources + $area_resources;

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
		$data['activity']     = OverviewActivity::activity( $licenses, $names, $subscriptions, $resources );

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
	 * A plugin license row.
	 *
	 * @param array<string, mixed> $view License view.
	 * @return array<string, string>
	 */
	private static function license_row( array $view ): array {
		$renews = ServerLinks::renewal( $view['license'] );

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
				'sep'   => ':',
				'state' => __( 'Working', 'beltoft-services' ),
				'name'  => $names[ $id ] ?? View::product_name( $license ),
				'text'  => 'app' === $progress['kind'] ? \BeltoftServices\Proxmox\AppProgress::line( $resource ) : (string) $progress['title'],
				'link'  => self::follow_url( $license ),
				'label' => __( 'Follow it', 'beltoft-services' ),
			);
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || ! $subscription->has_status( 'on-hold' ) ) {
				continue;
			}

			$when = (int) $subscription->get_time( 'last_order_date_created', 'gmt' );
			$text = $when > 0
				/* translators: %s: date */
				? sprintf( __( 'could not be renewed on %s.', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $when ) )
				: __( 'could not be renewed.', 'beltoft-services' );

			$items[] = array(
				'tone'  => 'bad',
				'sep'   => '',
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
			return ServerLinks::url( $license );
		}

		if ( Domains::is_domain( $license ) ) {
			return Domains::url( $license );
		}

		foreach ( ServiceArea::all() as $area ) {
			if ( $area->is_member( $license ) ) {
				return $area->url( $license );
			}
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

		return ! empty( $tab[ ServerLinks::slug() ] );
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

			$next = (int) $subscription->get_time( 'next_payment', 'gmt' );

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

		$card = self::card( $user_id, $first['subs'][0] );

		return array(
			'date'   => wp_date( (string) get_option( 'date_format' ), (int) $first['time'] ),
			'time'   => gmdate( 'c', (int) $first['time'] ),
			'total'  => (float) $first['total'],
			'amount' => wc_price( (float) $first['total'], array( 'currency' => (string) $first['currency'] ) ),
			'covers' => implode( ', ', $names ),
			'card'   => $card['text'],
			'label'  => $card['is_card'] ? __( 'Card', 'beltoft-services' ) : __( 'Payment method', 'beltoft-services' ),
			/**
			 * Where "Go to Billing" leads.
			 *
			 * @param string $url Address, default the subscriptions page of My Account.
			 */
			'url'    => (string) apply_filters( 'beltoft_services_overview_billing_url', $billing ),
		);
	}

	/**
	 * How the subscription is paid, as text. A card ("Visa ending 4242") only when it renews by itself: the
	 * subscription's own token, else the customer's default token if it belongs to the same gateway. Otherwise
	 * the payment method's title; a manual renewal with no title is "Paid by invoice".
	 *
	 * @param int               $user_id      Customer.
	 * @param \WC_Subscription  $subscription A subscription due.
	 * @return array{text: string, is_card: bool} The text, and whether it names a card.
	 */
	private static function card( int $user_id, \WC_Subscription $subscription ): array {
		$title = trim( wp_strip_all_tags( (string) $subscription->get_payment_method_title() ) );

		if ( $subscription->is_manual() ) {
			return array(
				'text'    => '' !== $title ? $title : __( 'Paid by invoice', 'beltoft-services' ),
				'is_card' => false,
			);
		}

		if ( class_exists( '\WC_Payment_Tokens' ) ) {
			$token = null;

			foreach ( $subscription->get_payment_tokens() as $token_id ) {
				$found = \WC_Payment_Tokens::get( (int) $token_id );

				if ( Cards::is_card( $found ) && (int) $found->get_user_id() === $user_id ) {
					$token = $found;
					break;
				}
			}

			if ( ! $token ) {
				$default = \WC_Payment_Tokens::get_customer_default_token( $user_id );

				if ( Cards::is_card( $default ) && $default->get_gateway_id() === $subscription->get_payment_method() ) {
					$token = $default;
				}
			}

			if ( Cards::is_card( $token ) ) {
				return array(
					'text'    => Cards::label( $token ),
					'is_card' => true,
				);
			}
		}

		return array(
			'text'    => $title,
			'is_card' => false,
		);
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
