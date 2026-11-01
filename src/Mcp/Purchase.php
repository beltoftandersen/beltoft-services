<?php
/**
 * Buying a server through Claude: the plans on sale, the summary, and the order itself.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\BillingRequirements;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\AppLicence;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Offers;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Servers are sold pay as you go: the order is a usage variation at 0, and the hours are invoiced monthly to the card the
 * customer saved on their usage account at the first checkout. Two cases follow, and the code never charges a card:
 *
 * - The customer has an active usage account whose card can be charged later (Account::chargeable): the order is 0, there
 *   is no payment step, and it is made server side by WooCommerce's own checkout inside the Sandbox, with the choices of
 *   the picker put on the cart line by the picker's own code. Every rule of the dashboard's checkout runs, because it is
 *   the dashboard's checkout.
 * - Any other customer needs the browser once, to save a card with the payment provider. Nothing is created: the answer is
 *   the picker's address with the choices filled in, and the reason.
 *
 * The picker's check() is the source of truth for what may be ordered; nothing of it is repeated here.
 */
final class Purchase {

	/**
	 * The tool.
	 */
	public const NAME = 'order_server';

	/**
	 * Orders a customer may make through Claude per hour (filter beltoft_services_mcp_order_limit; 0 turns it off).
	 */
	public const ORDERS_PER_HOUR = 5;

	/**
	 * Order meta: the connected app that placed the order.
	 */
	public const ORDER_META = '_beltoft_services_ordered_via_claude';

	// -- The plans on sale -----------------------------------------------------------------------

	/**
	 * The plans that can be ordered pay as you go, with what each offers.
	 *
	 * @return array<string, mixed>
	 */
	public static function plans(): array {
		$out = array();

		foreach ( Offers::plans() as $plan ) {
			if ( empty( $plan['usage'] ) ) {
				continue;
			}

			$usage   = (array) $plan['usage'];
			$system  = OsField::choice_for( (int) $plan['product_id'], (int) $plan['variation_id'] );
			$offer   = AppField::offer_for( (int) $plan['product_id'], (int) $plan['variation_id'] );
			$systems = array();
			$apps    = array();

			foreach ( null === $system ? array() : $system['options'] as $id => $label ) {
				$systems[] = array( 'id' => (int) $id, 'name' => (string) $label );
			}

			foreach ( null === $offer ? array() : $offer['apps'] as $app_id => $app ) {
				if ( AppLicence::paid( (string) $app_id ) ) {
					continue;
				}

				$versions = array();

				foreach ( (array) $app['choices'] as $version ) {
					if ( null === Apps::plan_refusal( (array) $version, $plan ) ) {
						$versions[] = array( 'id' => (string) $version['version_id'], 'name' => Apps::version_label( (array) $version ) );
					}
				}

				if ( array() !== $versions ) {
					$apps[] = array( 'id' => (string) $app_id, 'name' => (string) ( $app['name'] ?? $app_id ), 'versions' => $versions, 'order_as' => $app_id . '/' . $versions[0]['id'] );
				}
			}

			$out[] = array(
				'id'             => (int) $plan['variation_id'],
				'name'           => (string) $plan['name'],
				'specs'          => array( 'cpu_cores' => (int) $plan['cores'], 'memory_mb' => (int) $plan['memory_mb'], 'disk_gb' => (int) $plan['disk_gb'] ),
				'in_stock'       => (bool) $plan['in_stock'],
				'price'          => array(
					'hourly'      => round( (float) $usage['hourly'], 4 ),
					'monthly_cap' => round( (float) $usage['cap'], 2 ),
					'text'        => self::price_text( (float) $usage['hourly'], (float) $usage['cap'] ),
				),
				'backups'        => ! empty( $plan['backups'] ) && (int) $usage['backups_variation_id'] > 0 ? array(
					'available'   => true,
					'days_kept'   => (int) $plan['backups']['days'],
					'hourly'      => round( (float) $usage['backups_hourly'], 4 ),
					'monthly_cap' => round( (float) $usage['backups_cap'], 2 ),
					'text'        => self::price_text( (float) $usage['backups_hourly'], (float) $usage['backups_cap'] ),
				) : array( 'available' => false ),
				'systems'        => $systems,
				'apps'           => $apps,
			);
		}

		return array(
			'currency' => get_woocommerce_currency(),
			'note'     => 'Prices exclude VAT and are pay as you go: billed monthly for the hours used, never more than the monthly cap. Pass a plan id and a system id to order_server. An app runs on its own system, so give an app or a system, not both. Apps that have a licence cost are ordered in the dashboard.',
			'plans'    => $out,
		);
	}

	/**
	 * "€0.0226 per hour, max €16.50 per month, billed monthly for the hours used (excluding VAT)".
	 *
	 * @param float $hourly Per hour.
	 * @param float $cap    Monthly cap.
	 */
	private static function price_text( float $hourly, float $cap ): string {
		/* translators: 1: "€0.0226 per hour, max €16.50 per month", 2: "Billed monthly for the hours used" in lower case. */
		return sprintf( __( '%1$s, %2$s (excluding VAT)', 'beltoft-services' ), \BeltoftServices\Usage\Rates::tile_terms( $hourly, $cap ), lcfirst( \BeltoftServices\Usage\Rates::billed_note() ) );
	}

	// -- Checking an order -----------------------------------------------------------------------

	/**
	 * A plan from what the customer or Claude gave: its id, or its name.
	 *
	 * @param string $given Id or name.
	 * @return array<string, mixed>|string The plan, or the refusal.
	 */
	private static function find_plan( string $given ) {
		$given = trim( $given );
		$plans = Offers::plans();

		if ( ctype_digit( $given ) ) {
			$id = (int) $given;

			foreach ( $plans as $plan ) {
				if ( $id === (int) $plan['variation_id'] || ( ! empty( $plan['usage'] ) && $id === (int) $plan['usage']['variation_id'] ) ) {
					return $plan;
				}
			}
		} elseif ( '' !== $given ) {
			$hits = array_values( array_filter( $plans, static fn( $p ) => 0 === strcasecmp( (string) $p['name'], $given ) ) );

			if ( 1 === count( $hits ) ) {
				return $hits[0];
			}

			if ( count( $hits ) > 1 ) {
				return __( 'More than one plan has that name. Use the id from list_server_plans.', 'beltoft-services' );
			}
		}

		return __( 'That plan is not offered. Use an id or a name from list_server_plans.', 'beltoft-services' );
	}

	/**
	 * The system the customer meant: a template id, or a name that is the name of exactly one system the plan offers.
	 *
	 * @param array<string, mixed> $plan  Plan.
	 * @param string               $given Id or name.
	 */
	private static function system_id( array $plan, string $given ): string {
		$given = trim( $given );
		$among = OsField::choice_for( (int) $plan['product_id'], (int) $plan['variation_id'] );

		if ( '' === $given || ctype_digit( $given ) || null === $among ) {
			return $given;
		}

		$hits = array_keys( array_filter( $among['options'], static fn( $label ) => 0 === strcasecmp( (string) $label, $given ) ) );

		return 1 === count( $hits ) ? (string) $hits[0] : $given;
	}

	/**
	 * Checks an order with the code the picker and checkout use, and decides which way it goes.
	 *
	 * @param array<string, mixed> $args    Arguments of the tool (without the confirm value).
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error plan, checked (the picker's result), mode (direct or browser), account, hourly, cap, url.
	 */
	public static function prepare( array $args, int $user_id ) {
		$fail = static fn( string $text ) => new \WP_Error( 'beltoft_services_tool', $text );

		if ( $user_id < 1 || ! Access::user_allowed( $user_id ) ) {
			return $fail( __( 'This is not available.', 'beltoft-services' ) );
		}

		$plan = self::find_plan( (string) ( $args['plan'] ?? '' ) );

		if ( is_string( $plan ) ) {
			return $fail( $plan );
		}

		$url = ServerPicker::prefill_url( (int) $plan['variation_id'], array() );

		// Servers are sold pay as you go only through Claude: a plan without that offer is bought in the dashboard.
		if ( empty( $plan['usage'] ) ) {
			/* translators: %s: address of the New server page. */
			return $fail( sprintf( __( 'This plan is not sold pay as you go, so it cannot be ordered through Claude. The customer can order it here: %s', 'beltoft-services' ), $url ) );
		}

		list( $app, $version ) = array_pad( explode( '/', trim( (string) ( $args['app'] ?? '' ) ), 2 ), 2, '' );
		$app                   = 'none' === $app ? '' : $app;

		if ( '' !== $app && AppLicence::paid( $app ) ) {
			/* translators: %s: address of the New server page. */
			return $fail( sprintf( __( 'This app has a licence cost and is ordered in the dashboard. The customer can order it here: %s', 'beltoft-services' ), $url ) );
		}

		$backups = ! empty( $args['backups'] );
		$values  = array(
			'plan'      => (string) $plan['variation_id'],
			'backups'   => $backups ? '1' : '',
			'billing'   => 'usage',
			'os'        => self::system_id( $plan, (string) ( $args['os'] ?? '' ) ),
			'app'       => $app,
			'version'   => $version,
			'tier'      => '',
			'domain_on' => '',
			'own_on'    => '',
			'own'       => '',
			'zone'      => '',
			'sub'       => '',
			'name'      => (string) ( $args['server_name'] ?? '' ),
			'ssh_key'   => (string) ( $args['ssh_key'] ?? '' ),
		);

		// The picker's own check: stock, the site's rule for who may buy, backups, the system, the app, the name and the key.
		$checked = ServerPicker::check( $values, $user_id );

		if ( array() !== $checked['errors'] ) {
			return $fail( implode( ' ', array_values( $checked['errors'] ) ) . ( isset( $checked['errors']['os'] ) ? ' ' . __( 'The systems that are offered are in list_server_plans.', 'beltoft-services' ) : '' ) );
		}

		$usage   = (array) $plan['usage'];
		$target  = $backups ? (int) $usage['backups_variation_id'] : (int) $usage['variation_id'];
		$variant = $target > 0 ? wc_get_product( $target ) : null;

		if ( ! $variant instanceof \WC_Product || (int) $checked['variation_id'] !== $target || (float) $variant->get_price() > 0 ) {
			return $fail( __( 'This plan cannot be ordered pay as you go through Claude.', 'beltoft-services' ) );
		}

		$account  = Account::for_user( $user_id, Account::ESTABLISHED );
		$problems = null === $account ? array_filter( array( Cart::limit_problem( $user_id, null, 1 ) ) ) : Cart::problems( $user_id, '', 1 );

		if ( array() !== $problems ) {
			return $fail( implode( ' ', $problems ) );
		}

		$direct = self::can_charge_later( $account );

		if ( null !== $account && ! $direct ) {
			return $fail( __( 'Your usage account cannot be charged without you: it is not active or its saved card has no standing permission. Order the server in the dashboard, or add a card there first.', 'beltoft-services' ) . ' ' . $url );
		}

		if ( $direct ) {
			$customer = new \WC_Customer( $user_id, false );
			$missing  = array();

			foreach ( BillingRequirements::FIELDS as $field ) {
				$getter = 'get_' . $field;

				if ( '' === trim( (string) $customer->$getter() ) ) {
					$missing[] = str_replace( '_', ' ', substr( $field, 8 ) );
				}
			}

			if ( array() !== $missing ) {
				/* translators: %s: the missing billing details, such as "phone, postcode". */
				return $fail( sprintf( __( 'The billing details are incomplete (missing: %s). The customer completes them in the dashboard under Account details, then asks again.', 'beltoft-services' ), implode( ', ', $missing ) ) );
			}
		}

		return array(
			'plan'    => $plan,
			'checked' => $checked,
			'mode'    => $direct ? 'direct' : 'browser',
			'account' => $account,
			'hourly'  => $backups ? (float) $usage['backups_hourly'] : (float) $usage['hourly'],
			'cap'     => $backups ? (float) $usage['backups_cap'] : (float) $usage['cap'],
			'url'     => ServerPicker::prefill_url(
				(int) $plan['variation_id'],
				array(
					'backups' => $backups ? '1' : '',
					'os'      => $checked['choices']['os'],
					'app'     => $checked['choices']['app'],
					'version' => $checked['choices']['version'],
					'name'    => $checked['choices']['name'],
					'ssh_key' => $checked['choices']['ssh_key'],
				)
			),
		);
	}

	/**
	 * Will a call be a direct order (so it needs the customer's confirmation), or only a link or a refusal, which change nothing?
	 *
	 * @param int $user_id Customer.
	 */
	public static function goes_direct( int $user_id ): bool {
		$account = Account::for_user( $user_id, Account::ESTABLISHED );

		return self::can_charge_later( $account );
	}

	/**
	 * The one place that says whether the customer's usage account can take a new server with no payment step: it is active
	 * and its saved card (a Mollie customer and mandate) can be charged later without the customer. A customer who only
	 * added a card, with no server yet, passes this as well. Kept in one method so it can be switched to a shared helper.
	 *
	 * @param \WC_Subscription|null $account The customer's usage account, if any.
	 */
	private static function can_charge_later( ?\WC_Subscription $account ): bool {
		return null !== $account && Account::chargeable( $account );
	}

	// -- The summary -----------------------------------------------------------------------------

	/**
	 * The summary of an order, and what it depends on.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( array $args, int $user_id ) {
		$p = self::prepare( $args, $user_id );

		if ( $p instanceof \WP_Error ) {
			return $p;
		}

		$plan    = $p['plan'];
		$choices = $p['checked']['choices'];
		$lines   = array();

		$lines[] = 'direct' === $p['mode'] ? __( 'Order a pay as you go server.', 'beltoft-services' ) : __( 'A pay as you go server, ready to order in the browser.', 'beltoft-services' );
		/* translators: 1: plan name, 2: number of CPU cores, 3: memory such as 4 GB RAM, 4: disk in GB. */
		$lines[] = sprintf( _n( 'Plan: %1$s (%2$d CPU core, %3$s, %4$d GB disk).', 'Plan: %1$s (%2$d CPU cores, %3$s, %4$d GB disk).', (int) $plan['cores'], 'beltoft-services' ), (string) $plan['name'], (int) $plan['cores'], ServerPicker::memory( (int) $plan['memory_mb'] ), (int) $plan['disk_gb'] );
		$lines[] = self::choice_line( $plan, $choices );
		/* translators: 1: server name or "chosen for you", 2: "yes" or "none". */
		$lines[] = sprintf( __( 'Server name: %1$s. SSH key: %2$s.', 'beltoft-services' ), '' !== $choices['name'] ? $choices['name'] : __( 'chosen for you', 'beltoft-services' ), '' !== $choices['ssh_key'] ? __( 'yes', 'beltoft-services' ) : __( 'none (a password is made)', 'beltoft-services' ) );
		$lines[] = ! empty( $args['backups'] )
			/* translators: %d: days backups are kept. */
			? sprintf( __( 'Daily backups: yes, kept %d days. Included in the price below.', 'beltoft-services' ), (int) ( $plan['backups']['days'] ?? 0 ) )
			: __( 'Daily backups: no.', 'beltoft-services' );
		/* translators: %s: price terms such as "€0.0226 per hour, max €16.50 per month, billed monthly for the hours used (excluding VAT)". */
		$lines[] = sprintf( __( 'Price: %s.', 'beltoft-services' ), self::price_text( $p['hourly'], $p['cap'] ) );

		if ( 'direct' === $p['mode'] ) {
			$lines[] = __( 'Nothing is charged now: the order total is 0. The hours the server runs are invoiced once a month to the card saved on your usage account, and you can see the estimate any time with usage_summary.', 'beltoft-services' );
			$lines[] = __( 'What happens next: the order is placed at once and the server is built and started, usually within a few minutes. Follow it with list_services and server_status. When it is ready, its address and system are shown there; the password is revealed once in the dashboard or with reveal_server_password.', 'beltoft-services' );

			if ( function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) && wc_terms_and_conditions_checkbox_enabled() ) {
				$page    = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
				$terms   = $page > 0 ? (string) get_permalink( $page ) : '';
				$lines[] = '' !== $terms
					/* translators: %s: address of the terms and conditions. */
					? sprintf( __( 'By saying yes the customer accepts the terms and conditions: %s', 'beltoft-services' ), $terms )
					: __( 'By saying yes the customer accepts the terms and conditions of the shop.', 'beltoft-services' );
			}
		} else {
			$lines[] = __( 'The customer has no usage account yet, so this first server is ordered once in the browser: pay as you go bills the hours to a card saved with the payment provider, and a card can only be saved on the provider\'s own page, with the customer present. Nothing is charged by saving it. After that, servers can be ordered here in the chat without the browser.', 'beltoft-services' );
			/* translators: %s: address of the New server page with the choices filled in. */
			$lines[] = sprintf( __( 'Give the customer this link. It shows the same choices, and they continue to checkout: %s', 'beltoft-services' ), $p['url'] );
		}

		return array(
			'text'  => implode( "\n", $lines ),
			'state' => implode( '|', array( (int) $plan['variation_id'], (int) $p['checked']['variation_id'], $p['hourly'], $p['cap'], (int) $plan['in_stock'], $p['mode'], null === $p['account'] ? 0 : $p['account']->get_id() ) ),
		);
	}

	/**
	 * "System: Ubuntu 24.04." or "App: WordPress 6.8 (on its own system)."
	 *
	 * @param array<string, mixed>  $plan    Plan.
	 * @param array<string, string> $choices The checked choices.
	 */
	private static function choice_line( array $plan, array $choices ): string {
		if ( '' !== $choices['app'] ) {
			$offer = AppField::offer_for( (int) $plan['product_id'], (int) $plan['variation_id'] );
			$app   = $offer['apps'][ $choices['app'] ] ?? array();
			$ver   = $app['choices'][ $choices['version'] ] ?? array();

			/* translators: %s: app and version, such as WordPress 6.8. */
			return sprintf( __( 'App: %s, installed on its own system.', 'beltoft-services' ), trim( (string) ( $app['name'] ?? $choices['app'] ) . ' ' . ( array() !== $ver ? Apps::version_label( $ver ) : $choices['version'] ) ) );
		}

		$system = OsField::choice_for( (int) $plan['product_id'], (int) $plan['variation_id'] );
		$label  = null !== $system ? (string) ( $system['options'][ (int) $choices['os'] ] ?? $choices['os'] ) : $choices['os'];

		/* translators: %s: system such as Ubuntu 24.04. */
		return sprintf( __( 'System: %s.', 'beltoft-services' ), $label );
	}

	// -- The order -------------------------------------------------------------------------------

	/**
	 * Does what the confirmed call asked: places the order, or, for a customer who needs the browser, only gives the link.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( array $args, int $user_id ) {
		$p = self::prepare( $args, $user_id );

		if ( $p instanceof \WP_Error ) {
			return $p;
		}

		if ( 'browser' === $p['mode'] ) {
			Irreversible::trail( array(), 'gave the checkout link for a server (' . $p['plan']['name'] . '), nothing ordered' );

			return array(
				'requested'    => false,
				'ordered'      => false,
				'checkout_url' => $p['url'],
				'message'      => 'Nothing was ordered. This customer has no usage account yet, so the first server is finished once in the browser: open checkout_url, check the choices, continue to checkout and save a card on the payment provider\'s page (nothing is charged by saving it). Tell the customer this. After that, order_server places orders in the chat without the browser.',
			);
		}

		/**
		 * Most servers a customer may order through Claude in an hour (0 turns the limit off).
		 *
		 * @param int $limit Default 5.
		 */
		if ( RateLimit::exceeded( $user_id, 'beltoft-services:mcp-order', (int) apply_filters( 'beltoft_services_mcp_order_limit', self::ORDERS_PER_HOUR ), HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Too many servers were ordered in the last hour. Wait a while, or order in the dashboard.', 'beltoft-services' ) );
		}

		try {
			$done = Sandbox::run( $user_id, array(), static fn( \WC_Cart $cart ) => self::place( $cart, $p, $user_id ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The order could not be started. Try again in a moment.', 'beltoft-services' ) );
		}

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		$order = wc_get_order( $done['order_id'] );

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'The order could not be placed.', 'beltoft-services' ) );
		}

		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$service  = null;

		foreach ( $licenses as $license ) {
			$resource = Resource::for_license( (int) $license['id'] );

			if ( $resource && 'usage' === ( $license['source'] ?? '' ) ) {
				$service = $resource;
				Events::add( (int) $license['id'], 'api', 'Claude: ordered server ' . (string) $p['plan']['name'] . ' (order #' . $order->get_id() . ')' );
				break;
			}
		}

		Audit::add( $user_id, 'ordered server ' . (string) $p['plan']['name'] . ' (order #' . $order->get_id() . ')' );

		return array(
			'requested'  => true,
			'ordered'    => true,
			'order_id'   => $order->get_id(),
			'status'     => $order->get_status(),
			'service_id' => null === $service ? null : $service->id(),
			'message'    => 'The order is placed and nothing was charged. The server is being set up now. Use list_services and server_status' . ( null === $service ? '' : ' (service ' . $service->id() . ')' ) . ' to follow it, and tell the customer when it is ready.',
		);
	}

	/**
	 * In the Sandbox: the server into the cart with the picker's own code, the form WooCommerce's checkout expects, the checkout.
	 *
	 * @param \WC_Cart             $cart    Cart (empty).
	 * @param array<string, mixed> $p       Result of prepare().
	 * @param int                  $user_id Customer.
	 * @return array{order_id: int}|\WP_Error
	 */
	private static function place( \WC_Cart $cart, array $p, int $user_id ) {
		$fail = static fn( string $text ) => new \WP_Error( 'beltoft_services_tool', $text );
		$put  = ServerPicker::add_to_cart( $p['checked'] );

		if ( null !== $put ) {
			return $fail( $put );
		}

		$cart->calculate_totals();

		// The one rule that makes this safe: nothing that costs money is ever placed from here.
		if ( (float) $cart->get_total( 'edit' ) > 0 || $cart->needs_payment() ) {
			return $fail( __( 'This order is not free of payment, so it cannot be placed through Claude. The customer orders it in the dashboard.', 'beltoft-services' ) );
		}

		$checkout = WC()->checkout();
		$form     = array(
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
			'payment_method'                     => '',
		);

		foreach ( array( 'billing', 'order' ) as $group ) {
			foreach ( array_keys( (array) $checkout->get_checkout_fields( $group ) ) as $key ) {
				$value = $checkout->get_value( $key );

				if ( is_scalar( $value ) ) {
					$form[ $key ] = (string) $value;
				}
			}
		}

		// The customer said yes to the summary, which showed the terms.
		if ( function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) && wc_terms_and_conditions_checkbox_enabled() ) {
			$form['terms']       = 'on';
			$form['terms-field'] = '1';
		}

		$_POST    = $form;
		$_REQUEST = $form;

		$mark = static function ( $order_id ): void {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				$order->update_meta_data( self::ORDER_META, Acts::app_name() );
				$order->add_order_note( 'Ordered through Claude after the customer confirmed the summary.' );
				$order->save();
			}
		};

		add_action( 'woocommerce_checkout_order_processed', $mark, 5 );

		try {
			$end = Sandbox::checkout();
		} finally {
			remove_action( 'woocommerce_checkout_order_processed', $mark, 5 );
		}

		if ( 'success' !== $end['result'] || $end['order_id'] < 1 ) {
			// An order that was made but not completed is not left waiting for a payment nobody will make.
			$order = $end['order_id'] > 0 ? wc_get_order( $end['order_id'] ) : null;

			if ( $order instanceof \WC_Order && $order->has_status( array( 'pending', 'failed' ) ) ) {
				$order->update_status( 'cancelled', 'Ordered through Claude but not completed.' );
			}

			return $fail( array() !== $end['messages'] ? implode( ' ', $end['messages'] ) : __( 'The order was not placed. Nothing was charged.', 'beltoft-services' ) );
		}

		return array( 'order_id' => $end['order_id'] );
	}
}
