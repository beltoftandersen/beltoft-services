<?php
/**
 * The everyday action tools that are not a plain action of a service: firewall, access list, domain settings, auto-renew,
 * subscriptions, licenses, orders and the billing address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Account\Endpoint as LicenseAccount;
use BeltoftLicenses\Events;
use BeltoftLicenses\Key;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\AddressService;
use BeltoftServices\Account\Cards;
use BeltoftServices\Account\DomainDanger;
use BeltoftServices\Proxmox\PrivateAttach;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Each one does what the dashboard does and goes through the same code: the service routes and actions (Tools::route,
 * Tools::action, which the dashboard's own guards answer), DomainDanger::set_auto_renew, WCS's status handler,
 * Licenses, AddressService. Each writes a "Claude: ..." line where the customer can find it: the service's history, the
 * subscription's or order's notes, or the customer's own log (Audit).
 */
final class Acts {

	/**
	 * The tools this class runs.
	 */
	public const NAMES = array( 'rename_server', 'set_firewall', 'set_database_access', 'private_network', 'set_usage_alerts', 'retry_app_install', 'domain_settings', 'set_auto_renew', 'reactivate_subscription', 'free_license_site', 'cancel_unpaid_order', 'update_billing_address' );

	/**
	 * Runs one of the tools.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		switch ( $name ) {
			case 'rename_server':
				return Tools::action( (int) $args['id'], 'rename', array( 'name' => (string) $args['name'] ), 'rename requested' );

			case 'set_firewall':
				$id   = (int) $args['id'];
				$body = array( 'on' => (bool) $args['on'], 'rules' => $args['rules'] );

				$done = Tools::route( 'PUT', '/resources/' . $id . '/firewall', array(), $body, $id, 'firewall change requested' );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Requested. It is applied in the background. Use get_firewall to follow it.' );

			case 'set_database_access':
				$id   = (int) $args['id'];
				$body = array( 'op' => (string) $args['op'] );

				foreach ( array( 'address' => 'cidr', 'label' => 'label' ) as $from => $to ) {
					if ( isset( $args[ $from ] ) ) {
						$body[ $to ] = (string) $args[ $from ];
					}
				}

				$done = Tools::route( 'POST', '/resources/' . $id . '/db-access', array(), $body, $id, 'database access list: ' . str_replace( '_', ' ', (string) $args['op'] ) );

				return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'Requested. It is applied in the background. Use get_database_access to follow it.' );

			case 'private_network':
				return Tools::action( (int) $args['id'], 'attach' === $args['action'] ? PrivateAttach::ATTACH : PrivateAttach::DETACH, array(), 'private network ' . (string) $args['action'] . ' requested' );

			case 'set_usage_alerts':
				$id   = (int) $args['id'];
				$done = Tools::route( 'POST', '/resources/' . $id . '/usage-alerts', array(), array( 'on' => (bool) $args['on'] ), $id, 'usage e-mails turned ' . ( $args['on'] ? 'on' : 'off' ) );

				return $done instanceof \WP_Error ? $done : array( 'usage_emails' => ! empty( $done['on'] ) ? 'on' : 'off' );

			case 'retry_app_install':
				return Tools::action( (int) $args['id'], 'app_retry', array(), 'app install retry requested' );

			case 'domain_settings':
				return self::domain_settings( $args );

			case 'set_auto_renew':
				return self::auto_renew( $user_id, (int) $args['subscription_id'], (bool) $args['on'] );

			case 'reactivate_subscription':
				return self::reactivate( $user_id, (int) $args['subscription_id'] );

			case 'free_license_site':
				return self::free_site( $user_id, (int) $args['license_id'], (string) $args['domain'] );

			case 'cancel_unpaid_order':
				return self::cancel_order( $user_id, (int) $args['order_id'] );

			case 'update_billing_address':
				return self::billing_address( $user_id, $args );
		}

		return new \WP_Error( 'beltoft_services_tool', __( 'This is not available.', 'beltoft-services' ) );
	}

	/**
	 * The error notices as one text, or the fallback when there were none.
	 *
	 * @param string[] $errors   Error notices.
	 * @param string   $fallback Words for no notice.
	 */
	private static function said( array $errors, string $fallback ): string {
		return $errors ? implode( ' ', $errors ) : $fallback;
	}

	/**
	 * A setting of a domain: name servers, transfer lock, a check of the name servers. Never the unlock.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function domain_settings( array $args ) {
		$action = (string) $args['action'];
		$send   = array();

		if ( 'set_nameservers' === $action ) {
			$send['mode'] = (string) ( $args['mode'] ?? '' );

			if ( isset( $args['hosts'] ) ) {
				$send['hosts'] = implode( ',', array_map( 'strval', (array) $args['hosts'] ) );
			}
		}

		return Tools::action( (int) $args['id'], $action, $send, str_replace( '_', ' ', $action ) . ' requested' );
	}

	/**
	 * A subscription of the customer, or the same refusal for someone else's and a missing one.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return \WC_Subscription|\WP_Error
	 */
	private static function subscription( int $user_id, int $id ) {
		$s = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

		if ( ! $s instanceof \WC_Subscription || $user_id <= 0 || (int) $s->get_user_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This subscription is not available.', 'beltoft-services' ) );
		}

		return $s;
	}

	/**
	 * Writes a line to the notes of a subscription and the history of the services it pays for.
	 *
	 * @param \WC_Subscription $s    Subscription.
	 * @param string           $text What happened, after "Claude: ".
	 */
	private static function log_subscription( \WC_Subscription $s, string $text ): void {
		$s->add_order_note( 'Claude: ' . $text );

		foreach ( Licenses::where( 'subscription_id', $s->get_id() ) as $license ) {
			if ( (int) ( $license['user_id'] ?? 0 ) === get_current_user_id() ) {
				Events::add( (int) $license['id'], 'api', 'Claude: ' . $text );
			}
		}
	}

	/**
	 * Auto-renew of a subscription on or off, where WooCommerce Subscriptions lets the customer.
	 *
	 * @param int  $user_id Customer.
	 * @param int  $id      Subscription id.
	 * @param bool $on      Renew by itself.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function auto_renew( int $user_id, int $id, bool $on ) {
		$s = self::subscription( $user_id, $id );

		if ( $s instanceof \WP_Error ) {
			return $s;
		}

		if ( ! class_exists( 'WCS_My_Account_Auto_Renew_Toggle' ) || ! \WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $s, $user_id ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Auto-renew cannot be changed for this subscription.', 'beltoft-services' ) );
		}

		// Renewing by itself charges the saved card: there must be one that can be charged.
		if ( $on && ! Cards::has_valid_default( $user_id ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'There is no valid default saved payment method. The customer adds a card in the dashboard first, then auto-renew can be turned on.', 'beltoft-services' ) );
		}

		$done = DomainDanger::set_auto_renew( $s, $on, true );

		if ( ! $done['ok'] ) {
			return new \WP_Error( 'beltoft_services_tool', $done['message'] );
		}

		self::log_subscription( $s, 'auto-renew turned ' . ( $on ? 'on' : 'off' ) );

		return array( 'subscription_id' => $id, 'renews_by_itself' => ! wcs_get_subscription( $id )->is_manual() );
	}

	/**
	 * Reactivates a subscription when the dashboard offers it.
	 *
	 * @param int $user_id Customer.
	 * @param int $id      Subscription id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function reactivate( int $user_id, int $id ) {
		$s = self::subscription( $user_id, $id );

		if ( $s instanceof \WP_Error ) {
			return $s;
		}

		// The same oracle as the dashboard's buttons: nothing is done that the page would not offer.
		if ( ! isset( wcs_get_all_user_actions_for_subscription( $s, $user_id )['reactivate'] ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The dashboard does not offer reactivation for this subscription.', 'beltoft-services' ) );
		}

		$said = Notices::capture( static fn() => \WCS_User_Change_Status_Handler::change_users_subscription( $s, 'active' ) );
		$now  = wcs_get_subscription( $id );
		$ok   = $now instanceof \WC_Subscription && $now->has_status( 'active' );

		if ( $ok ) {
			self::log_subscription( $now, 'subscription reactivated' );
		}

		return $ok
			? array( 'subscription_id' => $id, 'status' => 'active', 'message' => implode( ' ', $said['messages'] ) )
			: new \WP_Error( 'beltoft_services_tool', self::said( $said['errors'], __( 'The subscription could not be reactivated.', 'beltoft-services' ) ) );
	}

	/**
	 * Frees a site of a license.
	 *
	 * @param int    $user_id    Customer.
	 * @param int    $license_id License id.
	 * @param string $domain     Site.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function free_site( int $user_id, int $license_id, string $domain ) {
		$domain = Key::domain( $domain );
		$found  = Licenses::find( $license_id );

		// The same answer for someone else's license and a missing one; the plugin's own function checks it again.
		if ( ! $found || ! LicenseAccount::owns( $found, $user_id ) || '' === $domain ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'That license is not available.', 'beltoft-services' ) );
		}

		$said = Notices::capture( static fn() => LicenseAccount::free_site( $license_id, $domain, $user_id ) );

		if ( ! $said['result'] ) {
			return new \WP_Error( 'beltoft_services_tool', self::said( $said['errors'], __( 'That site was not active on this license.', 'beltoft-services' ) ) );
		}

		Events::add( $license_id, 'api', 'Claude: site removed (' . $domain . ')' );

		return array( 'license_id' => $license_id, 'removed' => $domain, 'message' => implode( ' ', $said['messages'] ) );
	}

	/**
	 * Cancels an unpaid order, as the Cancel link of the order page does, for the customer's own order only.
	 *
	 * @param int $user_id  Customer.
	 * @param int $order_id Order id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function cancel_order( int $user_id, int $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || $user_id <= 0 || (int) $order->get_customer_id() !== $user_id ) {
			return new \WP_Error( 'beltoft_services_forbidden', __( 'This order is not available.', 'beltoft-services' ) );
		}

		/** This filter is documented in WooCommerce, class-wc-form-handler.php. */
		$valid = apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order );

		if ( ! $order->has_status( $valid ) || ! current_user_can( 'cancel_order', $order_id ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'This order can no longer be cancelled.', 'beltoft-services' ) );
		}

		// WooCommerce Subscriptions cancels the subscriptions of a parent order with it: that is cancelling a service.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' ) ) {
			foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
				if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
					return new \WP_Error( 'beltoft_services_tool', __( 'This order started a subscription, so cancelling it would cancel the subscription. The customer does that in the dashboard.', 'beltoft-services' ) );
				}
			}
		}

		$order->update_status( 'cancelled', __( 'Order cancelled by the customer through Claude.', 'beltoft-services' ) );

		do_action( 'woocommerce_cancelled_order', $order->get_id() );

		return array( 'order_id' => $order_id, 'status' => 'cancelled' );
	}

	/**
	 * Changes some fields of the billing address. The country and the VAT number are not among them.
	 *
	 * @param int                  $user_id Customer.
	 * @param array<string, mixed> $args    Fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function billing_address( int $user_id, array $args ) {
		$values = array_map( 'strval', $args );

		if ( ! $values ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Give at least one field to change.', 'beltoft-services' ) );
		}

		$customer = new \WC_Customer( $user_id );
		$old      = array();

		foreach ( array_keys( $values ) as $key ) {
			$old[ $key ] = is_callable( array( $customer, 'get_billing_' . $key ) ) ? (string) $customer->{'get_billing_' . $key}() : '';
		}

		$said = Notices::capture( static fn() => AddressService::save( $user_id, 'billing', $values ) );

		if ( ! $said['result'] || ! $said['ok'] ) {
			return new \WP_Error( 'beltoft_services_tool', self::said( $said['errors'], __( 'The address was not changed.', 'beltoft-services' ) ) );
		}

		$changes = array();
		$after   = new \WC_Customer( $user_id );

		foreach ( $values as $key => $unused ) {
			$new = is_callable( array( $after, 'get_billing_' . $key ) ) ? (string) $after->{'get_billing_' . $key}() : '';

			if ( $new !== $old[ $key ] ) {
				$changes[] = $key . ': "' . $old[ $key ] . '" to "' . $new . '"';
			}
		}

		if ( ! $changes ) {
			return array( 'changed' => array(), 'message' => 'Nothing was different.' );
		}

		Audit::add( $user_id, 'billing address changed (' . implode( '; ', $changes ) . ')' );
		self::tell_customer( $user_id, $changes );

		return array( 'changed' => array_keys( $values ), 'message' => 'The billing address was changed. The customer was told by e-mail.' );
	}

	/**
	 * Tells the customer by e-mail that a connected app changed their billing address, through the WooCommerce mailer
	 * (its header and footer, the site's From address). A plain note, no new kind of e-mail.
	 *
	 * @param int      $user_id Customer.
	 * @param string[] $changes What changed, from and to.
	 */
	private static function tell_customer( int $user_id, array $changes ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || '' === (string) $user->user_email ) {
			return;
		}

		$mailer = WC()->mailer();
		$body   = '<p>' . esc_html__( 'An app you connected to your account (Claude) changed your billing address at your request:', 'beltoft-services' ) . '</p><ul>';

		foreach ( $changes as $line ) {
			$body .= '<li>' . esc_html( $line ) . '</li>';
		}

		$body .= '</ul><p>' . esc_html__( 'If this was not you, disconnect the app under Account details, Security, and change your password.', 'beltoft-services' ) . '</p>';

		$mailer->send( (string) $user->user_email, __( 'Your billing address was changed', 'beltoft-services' ), $mailer->wrap_message( __( 'Your billing address was changed', 'beltoft-services' ), $body ) );
	}
}
