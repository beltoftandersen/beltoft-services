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
use BeltoftServices\Api\Services;
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


				return Details::of( $r );

			case 'usage_summary':
				return self::usage( $user_id );

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
			'account'       => Pricing::account( $user_id ),
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

		return array_diff_key( self::order_row( $order ), array( 'items' => 1 ) ) + array(
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
			'order_page'    => $pay ? $order->get_view_order_url() : '',
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
				'pay_as_you_go_account' => \BeltoftServices\Usage\Account::is_account( $s ),
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
		// The feature that sells the kind answers: server plans, systems and add-ons (servers), database plans (databases), domain prices (domains).
		$answer = apply_filters( 'beltoft_services_mcp_catalog', null, $kind, $variation_id, $id );

		return null !== $answer ? $answer : new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
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

		if ( ! $license || (int) ( $license['user_id'] ?? 0 ) !== $user_id || ! LicenseAccount::owns( $license, $user_id ) ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'That license is not available.', 'beltoft-services' ) );
		}

		$view = LicenseView::license( $license );

		return empty( $view['show_key'] )
			? new \WP_Error( 'beltoft_services_tool', __( 'This license has no key to show. It is managed for you.', 'beltoft-services' ) )
			: array( 'license_id' => $license_id, 'key' => (string) $view['key'] );
	}
}
