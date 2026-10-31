<?php
/**
 * The read tools that are not a plain REST call: services, orders, subscriptions, cards, account, catalogue, licenses.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Account\Endpoint as LicenseAccount;
use BeltoftLicenses\Account\View as LicenseView;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\Cards;
use BeltoftServices\Account\DbPicker;
use BeltoftServices\Api\Services;
use BeltoftServices\Domains\DnsClaimRoutes;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\DbDriver;
use BeltoftServices\Proxmox\DiskField;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Proxmox\SnapshotCart;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Estimate;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;

defined( 'ABSPATH' ) || exit;

/**
 * Every function picks its values by name. Nothing here returns a link that carries a nonce or logs in, a card number or
 * payment token, an encrypted value or a secret. Everything reads data of the current customer only.
 */
final class Reads {

	/**
	 * Runs a read tool of this class.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'service_details':
				$r = Tools::owned( (int) $args['id'] );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				Tools::log( $r->id(), 'service details read', true );

				return Details::of( $r );

			case 'usage_summary':
				return self::usage( $user_id );

			case 'list_recovery_points':
				return self::recovery_points( (int) $args['id'] );

			case 'get_firewall':
				$view = Tools::route( 'GET', '/resources/' . (int) $args['id'] . '/firewall', array(), null, (int) $args['id'], 'firewall read' );

				if ( $view instanceof \WP_Error ) {
					return $view;
				}

				// The page's own address, button text and clock are not for a result.
				unset( $view['my_ip'], $view['now'] );

				return $view;

			case 'get_database_access':
				$id    = (int) $args['id'];
				$state = Tools::route( 'GET', '/resources/' . $id . '/db-access', array(), null, $id, 'database access list read' );
				$r     = $state instanceof \WP_Error ? null : Tools::owned( $id );

				if ( $state instanceof \WP_Error || $r instanceof \WP_Error ) {
					return $state instanceof \WP_Error ? $state : $r;
				}

				return $state + Details::access( $r );

			case 'list_orders':
				return self::orders( $user_id, (int) ( $args['limit'] ?? 10 ) );

			case 'get_order':
				return self::order( $user_id, (int) $args['order_id'] );

			case 'list_subscriptions':
				return self::subscriptions( $user_id, (string) ( $args['status'] ?? 'any' ) );

			case 'list_payment_methods':
				return self::payment_methods( $user_id );

			case 'get_account':
				return self::account( $user_id );

			case 'catalog':
				return self::catalog( (string) $args['kind'], (int) ( $args['variation_id'] ?? 0 ), (int) ( $args['id'] ?? 0 ) );

			case 'list_dns_claims':
				$list = Tools::route( 'GET', '/domains/dns-claims', array(), null, null );

				if ( $list instanceof \WP_Error ) {
					return $list;
				}

				$keep = array_flip( array( 'id', 'domain', 'status', 'expires_at', 'verified_at', 'checked_at', 'message' ) );

				return array( 'claims' => array_map( static fn( $c ) => array_intersect_key( (array) $c, $keep ), array_values( $list ) ) );

			case 'console_link':
				$r = Tools::owned( (int) $args['id'] );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				$driver = Registry::get( (string) $r->get( 'driver' ) );
				$url    = $driver && 'vm' === $driver->kind() ? Details::page( $r, 'vm', $r->license(), 'console' ) : '';

				Tools::log( $r->id(), 'console link read', '' !== $url );

				return '' === $url
					? new \WP_Error( 'beltoft_services_tool', __( 'This service has no console.', 'beltoft-services' ) )
					: array( 'console_page' => $url, 'note' => 'The customer opens this page in the browser and logs in. No console session is started here.' );

			case 'list_licenses':
				return self::licenses( $user_id );

			case 'get_license_key':
				return self::license_key( $user_id, (int) $args['license_id'] );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * The customer's services, with the ids of their license and subscription.
	 *
	 * @param int    $user_id Customer.
	 * @param string $type    Only this driver kind, or ''.
	 * @return array<int, array<string, mixed>>
	 */
	public static function services( int $user_id, string $type = '' ): array {
		$out = array();

		foreach ( Services::for_user( $user_id ) as $row ) {
			if ( '' !== $type && $type !== $row['type'] ) {
				continue;
			}

			$r       = Resource::find( (int) $row['id'] );
			$license = $r ? $r->license() : array();
			$sub     = (int) ( $license['subscription_id'] ?? 0 );

			$out[] = $row + array(
				'license_id'      => (int) ( $license['id'] ?? 0 ),
				'subscription_id' => $sub,
			);
		}

		return $out;
	}

	/**
	 * What a subscription is, in a few fields; null for none.
	 *
	 * @param int $id Subscription id.
	 * @return array<string, mixed>|null
	 */
	public static function subscription_summary( int $id ): ?array {
		$s = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		if ( ! $s instanceof \WC_Subscription || (int) $s->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return array(
			'id'           => $s->get_id(),
			'status'       => (string) $s->get_status(),
			'next_payment' => self::date( (string) $s->get_date( 'next_payment', 'site' ) ),
			'renews_by_itself' => ! $s->is_manual(),
		);
	}

	/**
	 * Live use of the servers and the pay-as-you-go estimate.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function usage( int $user_id ) {
		$live = Tools::route( 'GET', '/usage', array(), null, null );

		if ( $live instanceof \WP_Error ) {
			return $live;
		}

		$estimate = Estimate::for_user( $user_id );
		$out      = array(
			'servers'       => (array) ( $live['resources'] ?? array() ),
			'servers_note'  => 'Percent of CPU, memory and disk, for each running server by id.',
			'pay_as_you_go' => null,
		);

		if ( $estimate ) {
			$out['pay_as_you_go'] = array(
				'so_far'       => Estimate::money( (float) $estimate['total'], (string) $estimate['currency'] ),
				'note'         => 'An estimate of what the usage costs so far, charged at the next payment.',
				'next_payment' => $estimate['next'] > 0 ? wp_date( (string) get_option( 'date_format' ), (int) $estimate['next'] ) : '',
				'services'     => array_values(
					array_map(
						static fn( $id, $one ) => array(
							'id'    => (int) $id,
							'name'  => (string) $one['label'],
							'so_far' => Estimate::money( (float) $one['total'], (string) $estimate['currency'] ),
							'lines' => array_map( static fn( $l ) => array( 'item' => (string) $l['label'], 'hours' => (int) $l['hours'], 'charge' => Estimate::money( (float) $l['charge'], (string) $estimate['currency'] ), 'capped' => (bool) $l['capped'] ), (array) $one['lines'] ),
						),
						array_keys( $estimate['resources'] ),
						$estimate['resources']
					)
				),
			);
		}

		return $out;
	}

	/**
	 * Snapshots and backups of one server, or the backups of one database.
	 *
	 * @param int $id Service id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function recovery_points( int $id ) {
		$r = Tools::owned( $id );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$driver = (string) $r->get( 'driver' );

		if ( 'proxmox' !== $driver && DbDriver::ID !== $driver ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This service has no snapshots or backups.', 'beltoft-services' ) );
		}

		Tools::log( $id, 'recovery points read', true );

		$backups = Backups::listing( $r );
		$out     = array(
			'backups' => array(
				'enabled' => Backups::enabled( $r ),
				'days'    => Backups::days( $r ),
				'last_ok' => $backups['last_ok'],
				'list'    => array_map( static fn( $b ) => array( 'id' => (string) $b['volid'], 'when' => (string) $b['when'], 'size' => (string) $b['size'] ), $backups['list'] ),
			),
		);

		if ( 'proxmox' === $driver ) {
			$panel = Snapshots::panel( $r );

			$out['snapshots'] = null === $panel ? null : array(
				'enabled' => $panel['enabled'],
				'slots'   => $panel['slots'],
				'used'    => $panel['used'],
				'free'    => max( 0, (int) $panel['slots'] - (int) $panel['used'] ),
				'days'    => $panel['days'],
				'list'    => array_map(
					static fn( $e ) => array(
						'id'      => $e['id'],
						'label'   => $e['label'],
						'taken'   => $e['taken_at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $e['taken_at'] ) : '',
						'expires' => $e['expires_at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $e['expires_at'] ) : '',
						'state'   => $e['state'],
					),
					$panel['entries']
				),
			);
		}

		return $out;
	}

	/**
	 * The customer's recent orders.
	 *
	 * @param int $user_id Customer.
	 * @param int $limit   How many.
	 * @return array<string, mixed>
	 */
	private static function orders( int $user_id, int $limit ): array {
		$out = array();

		foreach ( wc_get_orders( array( 'customer_id' => $user_id, 'limit' => max( 1, min( 20, $limit ) ), 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) ) as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = self::order_row( $order );
			}
		}

		return array( 'orders' => $out );
	}

	/**
	 * One order.
	 *
	 * @param int $user_id  Customer.
	 * @param int $order_id Order id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function order( int $user_id, int $order_id ) {
		$order = wc_get_order( $order_id );

		// The same answer for someone else's order, a subscription, a refund and a missing id.
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || (int) $order->get_customer_id() !== $user_id || $user_id <= 0 ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This order is not available.', 'beltoft-services' ) );
		}

		$currency = (string) $order->get_currency();
		$items    = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => (string) $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => Rates::money( (float) $item->get_total(), 2, $currency ),
				'tax'      => Rates::money( (float) $item->get_total_tax(), 2, $currency ),
			);
		}

		$subs = function_exists( 'wcs_get_subscriptions_for_order' ) ? array_keys( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) ) : array();

		return self::order_row( $order ) + array(
			'items'          => $items,
			'tax'            => Rates::money( (float) $order->get_total_tax(), 2, $currency ),
			'payment_method' => wp_strip_all_tags( (string) $order->get_payment_method_title() ),
			'subscriptions'  => array_map( 'intval', $subs ),
		);
	}

	/**
	 * An order in a few fields.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function order_row( \WC_Order $order ): array {
		$created  = $order->get_date_created();
		$currency = (string) $order->get_currency();
		$pay      = $order->needs_payment();

		return array(
			'id'            => $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'status'        => (string) wc_get_order_status_name( $order->get_status() ),
			'date'          => $created ? $created->date( 'Y-m-d' ) : '',
			'total'         => Rates::money( (float) $order->get_total(), 2, $currency ),
			'currency'      => $currency,
			'items'         => array_values( array_map( static fn( $i ) => $i->get_name() . ' x ' . (int) $i->get_quantity(), $order->get_items() ) ),
			'needs_payment' => $pay,
			'pay_page'      => $pay ? $order->get_checkout_payment_url() : '',
		);
	}

	/**
	 * The customer's subscriptions.
	 *
	 * @param int    $user_id Customer.
	 * @param string $status  A status, or any.
	 * @return array<string, mixed>
	 */
	private static function subscriptions( int $user_id, string $status ): array {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return array( 'subscriptions' => array() );
		}

		// Which of the customer's services each subscription pays for.
		$services = array();

		foreach ( Services::for_user( $user_id ) as $row ) {
			$r   = Resource::find( (int) $row['id'] );
			$sub = (int) ( $r ? ( $r->license()['subscription_id'] ?? 0 ) : 0 );

			if ( $sub > 0 ) {
				$services[ $sub ][] = (int) $row['id'];
			}
		}

		$out = array();

		foreach ( wcs_get_users_subscriptions( $user_id ) as $s ) {
			if ( ! $s instanceof \WC_Subscription || ( 'any' !== $status && ! $s->has_status( $status ) ) ) {
				continue;
			}

			$currency = (string) $s->get_currency();

			$out[] = array(
				'id'               => $s->get_id(),
				'status'           => (string) $s->get_status(),
				'price'            => Rates::money( (float) $s->get_total(), 2, $currency ),
				'every'            => (int) $s->get_billing_interval() . ' ' . (string) $s->get_billing_period(),
				'next_payment'     => self::date( (string) $s->get_date( 'next_payment', 'site' ) ),
				'ends'             => self::date( (string) $s->get_date( 'end', 'site' ) ),
				'renews_by_itself' => ! $s->is_manual(),
				'items'            => array_values( array_map( static fn( $i ) => $i->get_name(), $s->get_items() ) ),
				'services'         => $services[ $s->get_id() ] ?? array(),
				'dashboard_offers' => array_keys( wcs_get_all_user_actions_for_subscription( $s, $user_id ) ),
				'can_change_auto_renew' => class_exists( 'WCS_My_Account_Auto_Renew_Toggle' ) && \WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $s, $user_id ),
			);
		}

		return array( 'subscriptions' => $out );
	}

	/**
	 * A date in the site's format, or ''.
	 *
	 * @param string $value A date as text, or '0'.
	 */
	private static function date( string $value ): string {
		$time = '' !== $value && '0' !== $value ? strtotime( $value ) : false;

		return $time ? wp_date( 'Y-m-d', $time ) : '';
	}

	/**
	 * The customer's saved payment methods: what a card looks like, never what it is.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	private static function payment_methods( int $user_id ): array {
		$out = array();
		$now = (int) gmdate( 'Ym' );

		foreach ( class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get_customer_tokens( $user_id ) : array() as $token ) {
			if ( (int) $token->get_user_id() !== $user_id ) {
				continue;
			}

			$card  = Cards::is_card( $token );
			$month = $card && method_exists( $token, 'get_expiry_month' ) ? (int) $token->get_expiry_month() : 0;
			$year  = $card && method_exists( $token, 'get_expiry_year' ) ? (int) $token->get_expiry_year() : 0;

			$out[] = array(
				'id'      => (int) $token->get_id(),
				'type'    => $card ? 'card' : 'other',
				'name'    => $card ? Cards::label( $token ) : (string) $token->get_type(),
				'expires' => $month > 0 && $year > 0 ? sprintf( '%02d/%04d', $month, $year ) : '',
				'expired' => $month > 0 && $year > 0 && $year * 100 + $month < $now,
				'default' => (bool) $token->is_default(),
			);
		}

		return array(
			'payment_methods' => $out,
			'add_card_page'   => wc_get_endpoint_url( 'add-payment-method', '', wc_get_page_permalink( 'myaccount' ) ),
		);
	}

	/**
	 * The customer's name, e-mail and addresses.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	private static function account( int $user_id ): array {
		$user     = get_userdata( $user_id );
		$customer = new \WC_Customer( $user_id );
		$out      = array(
			'name'  => $user ? (string) $user->display_name : '',
			'email' => $user ? (string) $user->user_email : '',
		);

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$country = (string) $customer->{"get_{$type}_country"}();
			$fields  = array();

			foreach ( array_keys( (array) WC()->countries->get_address_fields( $country, $type . '_' ) ) as $key ) {
				$value = is_callable( array( $customer, 'get_' . $key ) ) ? $customer->{'get_' . $key}() : $customer->get_meta( $key );

				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$fields[ preg_replace( '/^' . $type . '_/', '', $key ) ] = (string) $value;
				}
			}

			$out[ $type . '_address' ] = $fields;
		}

		return $out;
	}

	/**
	 * What can be bought.
	 *
	 * @param string $kind         server_plans, database_plans, os_and_apps, addons or domain_prices.
	 * @param int    $variation_id A server plan, for os_and_apps.
	 * @param int    $id           One of the customer's services, for addons.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function catalog( string $kind, int $variation_id, int $id ) {
		switch ( $kind ) {
			case 'server_plans':
				return array(
					'currency' => get_woocommerce_currency(),
					'note'     => 'Prices exclude tax.',
					'plans'    => array_values(
						array_map(
							static fn( $p ) => array(
								'variation_id'  => (int) $p['variation_id'],
								'name'          => (string) $p['name'],
								'price'         => (float) $p['price'],
								'period'        => (string) $p['period'],
								'cores'         => (int) $p['cores'],
								'memory_mb'     => (int) $p['memory_mb'],
								'disk_gb'       => (int) $p['disk_gb'],
								'in_stock'      => (bool) $p['in_stock'],
								'with_backups'  => $p['backups'] ? array( 'variation_id' => (int) $p['backups']['variation_id'], 'extra_price' => round( (float) $p['backups']['extra'], 2 ), 'days' => (int) $p['backups']['days'] ) : null,
								'pay_as_you_go' => $p['usage'] ? array( 'variation_id' => (int) $p['usage']['variation_id'], 'hourly' => (float) $p['usage']['hourly'], 'monthly_cap' => (float) $p['usage']['cap'] ) : null,
							),
							Offers::plans()
						)
					),
				);

			case 'database_plans':
				return array(
					'currency' => get_woocommerce_currency(),
					'note'     => 'Prices exclude tax.',
					'plans'    => array_values(
						array_map(
							static fn( $p ) => array(
								'variation_id' => (int) $p['variation_id'],
								'name'         => (string) $p['name'],
								'price'        => (float) $p['price'],
								'period'       => (string) $p['period'],
								'engine'       => (string) $p['engine'],
								'version'      => (int) $p['major'],
								'cores'        => (int) $p['cores'],
								'memory_mb'    => (int) $p['memory_mb'],
								'disk_gb'      => (int) $p['disk_gb'],
								'backup_days'  => (int) $p['backup_days'],
								'in_stock'     => (bool) $p['in_stock'],
							),
							DbPicker::plans()
						)
					),
				);

			case 'os_and_apps':
				$plan = $variation_id > 0 ? Offers::find( $variation_id ) : null;

				if ( null === $plan ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'Give the variation_id of a server plan from server_plans.', 'beltoft-services' ) );
				}

				$cluster = (array) Clusters::get( (string) $plan['cluster'] );
				$apps    = array();

				foreach ( Apps::offered( $cluster ) as $app_id => $app ) {
					$apps[] = array(
						'id'       => (string) $app_id,
						'name'     => (string) ( $app['name'] ?? $app_id ),
						'versions' => array_values( array_map( static fn( $v ) => array( 'id' => (string) $v['version_id'], 'name' => Apps::version_label( $v ) ), (array) $app['choices'] ) ),
					);
				}

				$systems = array();

				foreach ( OsField::options_for( (string) $plan['cluster'] ) as $template => $label ) {
					$systems[] = array( 'id' => (int) $template, 'name' => (string) $label );
				}

				return array( 'systems' => $systems, 'apps' => $apps );

			case 'addons':
				$r = $id > 0 ? Tools::owned( $id ) : new \WP_Error( 'beltoft_services_tool', __( 'Give the id of one of your services.', 'beltoft-services' ) );

				if ( $r instanceof \WP_Error ) {
					return $r;
				}

				return self::addons( $r );

			case 'domain_prices':
				$table = get_option( Prices::OPTION, array() );
				$out   = array();

				foreach ( is_array( $table ) ? array_keys( $table ) : array() as $tld ) {
					$price = Prices::price( (string) $tld );

					if ( null !== $price ) {
						$out[] = array( 'ending' => (string) $tld, 'price_per_year' => $price );
					}
				}

				return array( 'currency' => 'EUR', 'note' => 'Prices are per year and exclude tax. Use domain_check for one name.', 'endings' => array_slice( $out, 0, 500 ) );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * What can be added to one service.
	 *
	 * @param Resource $r Service.
	 * @return array<string, mixed>
	 */
	private static function addons( Resource $r ): array {
		$driver   = (string) $r->get( 'driver' );
		$currency = get_woocommerce_currency();
		$out      = array(
			'currency' => $currency,
			'note'     => 'Prices exclude tax.',
		);

		if ( 'proxmox' === $driver ) {
			$out['snapshot_packs'] = array_values(
				array_map(
					static fn( $v ) => array( 'variation_id' => $v->get_id(), 'slots' => SnapshotCart::slots_of( $v ), 'price' => (float) wc_get_price_excluding_tax( $v ) ),
					SnapshotCart::packs()
				)
			);
			$out['extra_disks']    = array_values(
				array_map(
					static fn( $v ) => array( 'variation_id' => $v->get_id(), 'price' => (float) wc_get_price_excluding_tax( $v ) ),
					DiskField::sizes()
				)
			);
		}

		if ( 'proxmox' === $driver || DbDriver::ID === $driver ) {
			$out['backups'] = Backups::enabled( $r ) ? array( 'enabled' => true ) : array( 'enabled' => false, 'extra_price' => Backups::price_label( $r, true ) );
		}

		return $out;
	}

	/**
	 * The customer's licenses.
	 *
	 * @param int $user_id Customer.
	 * @return array<string, mixed>
	 */
	private static function licenses( int $user_id ): array {
		$out = array();

		foreach ( LicenseAccount::licenses_for_user( $user_id ) as $license ) {
			$view = LicenseView::license( $license );

			$out[] = array(
				'id'      => (int) $license['id'],
				'product' => (string) $view['product'],
				'status'  => (string) $view['status'],
				'sites'   => array( 'used' => (int) $view['used'], 'allowed' => (int) $view['max'], 'list' => array_map( static fn( $a ) => (string) $a['domain'], Licenses::live_activations( (int) $license['id'], 'site' ) ) ),
				'service' => (int) ( ( Resource::for_license( (int) $license['id'] ) )?->id() ?? 0 ),
			);
		}

		return array( 'licenses' => $out );
	}

	/**
	 * The key of one license.
	 *
	 * @param int $user_id    Customer.
	 * @param int $license_id License id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function license_key( int $user_id, int $license_id ) {
		$license = Licenses::find( $license_id );

		if ( ! $license || ! LicenseAccount::owns( $license, $user_id ) ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'That license is not available.', 'beltoft-services' ) );
		}

		$view = LicenseView::license( $license );

		return empty( $view['show_key'] )
			? new \WP_Error( 'beltoft_services_tool', __( 'This license has no key to show. It is managed for you.', 'beltoft-services' ) )
			: array( 'license_id' => $license_id, 'key' => (string) $view['key'] );
	}
}
