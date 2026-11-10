<?php
/**
 * Buying a managed database through Claude: the summary with the breakdown, and the order.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mcp;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Proxmox\Db\DbDisk;
use BeltoftServices\Proxmox\Db\DbDiskField;
use BeltoftServices\Proxmox\Db\DbIpField;
use BeltoftServices\Proxmox\Db\DbTermsField;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * The counterpart of order_server, and it uses the same machinery: the rules and the order are those of the dashboard's
 * database page (DbPicker::process() puts the plan in the cart, DbIpField::swap() and DbDiskField::swap() make the choices
 * the checkout's own boxes make) and WooCommerce's own checkout inside the Sandbox, through Purchase::execute(). A database
 * is sold pay as you go through Claude: the order is a pay as you go variation at 0, never a fixed monthly one. A customer
 * without a usage account gets a link and nothing is ordered.
 */
final class PurchaseDb {

	/**
	 * The tool.
	 */
	public const NAME = 'order_database';

	/**
	 * A plan from its id (the plan's variation or its pay as you go variation) or its exact name.
	 *
	 * @param string $given Id or name.
	 * @return array<string, mixed>|string The plan, or the refusal.
	 */
	private static function find_plan( string $given ) {
		$given = trim( $given );
		$plans = DbPicker::plans();

		if ( ctype_digit( $given ) ) {
			foreach ( $plans as $plan ) {
				if ( (int) $given === (int) $plan['variation_id'] || ( ! empty( $plan['usage'] ) && (int) $given === (int) $plan['usage']['variation_id'] ) ) {
					return $plan;
				}
			}
		} elseif ( '' !== $given ) {
			$hits = array_values( array_filter( $plans, static fn( $p ) => 0 === strcasecmp( (string) $p['name'], $given ) ) );

			if ( 1 === count( $hits ) ) {
				return $hits[0];
			}

			if ( count( $hits ) > 1 ) {
				return __( 'More than one plan has that name. Use the variation_id from catalog (database_plans).', 'beltoft-services' );
			}
		}

		return __( 'That plan is not offered. Use a variation_id from catalog (database_plans).', 'beltoft-services' );
	}

	/**
	 * Checks an order with the code of the database page and decides which way it goes.
	 *
	 * @param array<string, mixed> $args    plan, public_ipv4, extra_storage_gb.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
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

		$url = DbPicker::url();
		$ip  = ! empty( $args['public_ipv4'] );
		$gb  = max( 0, (int) ( $args['extra_storage_gb'] ?? 0 ) );

		if ( empty( $plan['usage'] ) ) {
			/* translators: %s: address of the New database page. */
			return $fail( sprintf( __( 'This plan is not sold pay as you go, so it cannot be ordered through Claude. The customer can order it here: %s', 'beltoft-services' ), $url ) );
		}

		if ( ! \BeltoftServices\Proxmox\Network\PrivateNets::available_for( $user_id, (string) $plan['cluster'] ) ) {
			return $fail( \BeltoftServices\Proxmox\Network\PrivateNets::unavailable_message() );
		}

		if ( ! $plan['in_stock'] ) {
			return $fail( __( 'That plan is out of stock. Choose another plan.', 'beltoft-services' ) );
		}

		/** This filter is documented in src/Account/DbPicker.php. */
		if ( ! apply_filters( 'beltoft_services_db_picker_can_buy', true, $plan, $user_id ) ) {
			return $fail( __( 'This plan cannot be ordered right now.', 'beltoft-services' ) );
		}

		$quote = Pricing::quote( array( 'plan' => (int) $plan['variation_id'], 'public_ipv4' => $ip, 'extra_storage_gb' => $gb ) );

		if ( $quote instanceof \WP_Error ) {
			return $quote;
		}

		// The choices must be ones that can be bought now: a free address, a step that fits.
		$bare = wc_get_product( (int) $plan['variation_id'] );
		$with = $bare instanceof \WC_Product_Variation && $ip ? Backups::sibling_by( $bare, 'public_ip', true ) : $bare;

		if ( $ip && ( ! $with || IpPool::available_for_db() < 1 || ! $with->is_purchasable() || ! $with->is_in_stock() ) ) {
			return $fail( __( 'No public IPv4 address is free right now. Order without it, or try again later.', 'beltoft-services' ) );
		}

		if ( $gb > 0 && $with instanceof \WC_Product_Variation ) {
			$step = DbDisk::ladder( $with )[ $gb ] ?? null;

			if ( null === $step || ! $step->is_purchasable() || ! $step->is_in_stock() || ! DbDisk::fits_new( \BeltoftServices\Product::plan( $step->get_parent_id(), $step->get_id() ) ) ) {
				return $fail( __( 'This amount of extra storage is not available right now. Choose a smaller size or try again later.', 'beltoft-services' ) );
			}
		}

		$state = Purchase::account_state( $user_id, $url );

		if ( $state instanceof \WP_Error ) {
			return $state;
		}

		$direct = $state['direct'];
		$put    = static function () use ( $plan, $ip, $gb ): ?string {
			// The usage variation of the plan is bought (monthly is not offered), as the database page does.
			$bought               = $plan;
			$bought['variation_id'] = (int) $plan['usage']['variation_id'];
			$added                = DbPicker::add_to_cart( $bought );

			if ( null !== $added ) {
				return $added;
			}

			if ( $ip && ! DbIpField::swap( true )['ok'] ) {
				return __( 'The public IPv4 address could not be added. Order without it, or try again later.', 'beltoft-services' );
			}

			if ( $gb > 0 && ! DbDiskField::swap( $gb )['ok'] ) {
				return __( 'The extra storage could not be added. Choose another size or try again later.', 'beltoft-services' );
			}

			return null;
		};

		return array(
			'plan'    => $plan,
			'quote'   => $quote,
			'ip'      => $ip,
			'gb'      => $gb,
			'mode'    => $direct ? 'direct' : 'browser',
			'account' => $state['account'],
			'url'     => $url,
			'put'     => $put,
		);
	}

	/**
	 * Will a call be a direct order (so it needs the customer's confirmation), or only a link or a refusal?
	 *
	 * @param int $user_id Customer.
	 */
	public static function goes_direct( int $user_id ): bool {
		return Purchase::goes_direct( $user_id );
	}

	/**
	 * The summary of an order, with the breakdown the customer says yes to.
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

		$plan  = $p['plan'];
		$quote = $p['quote'];
		$lines = array();

		$lines[] = 'direct' === $p['mode'] ? __( 'Order a pay as you go database.', 'beltoft-services' ) : __( 'A pay as you go database, to be ordered once in the browser.', 'beltoft-services' );
		/* translators: 1: plan name, 2: engine, 3: major version, 4: number of CPU cores, 5: memory such as 4 GB RAM, 6: data disk in GB. */
		$lines[] = sprintf( __( 'Plan: %1$s (%2$s %3$d, %4$d CPU cores, %5$s, %6$d GB data disk).', 'beltoft-services' ), (string) $plan['name'], (string) $plan['engine'], (int) $plan['major'], (int) $plan['cores'], ServerPicker::memory( (int) $plan['memory_mb'] ), (int) $plan['disk_gb'] );
		$lines[] = $p['ip'] ? __( 'Public IPv4 address: yes.', 'beltoft-services' ) : __( 'Public IPv4 address: no.', 'beltoft-services' );
		/* translators: %d: extra storage in GB. */
		$lines[] = $p['gb'] > 0 ? sprintf( __( 'Extra storage: %d GB. It can be made larger later in the dashboard, never smaller.', 'beltoft-services' ), (int) $p['gb'] ) : __( 'Extra storage: none. It can be added later in the dashboard.', 'beltoft-services' );
		$lines[] = __( 'Per month, at most:', 'beltoft-services' );

		foreach ( $quote['lines'] as $line ) {
			$lines[] = '- ' . $line['label'] . ': ' . $line['text'];
		}

		/* translators: %s: amount such as €54 */
		$lines[] = sprintf( __( 'Max per month: %s (excluding VAT).', 'beltoft-services' ), $quote['max_per_month_text'] );
		$lines[] = $quote['hourly_text'];

		if ( 'direct' === $p['mode'] ) {
			$lines[] = __( 'Nothing is charged now: the order total is 0. The hours the database runs are invoiced once a month to the card saved on your usage account (VAT is added on the invoice, and any account credit is applied first), and you can see the estimate any time with usage_summary.', 'beltoft-services' );
			$lines[] = __( 'What happens next: the order is placed at once and the database is built, usually within a few minutes. Follow it with list_services and service_details. Its connection details are in database_info; the password is revealed once with reveal_database_password.', 'beltoft-services' );

			$terms = self::engine_terms( $plan );

			if ( '' !== $terms ) {
				/* translators: %s: address of the licence terms of the database engine. */
				$lines[] = sprintf( __( 'By saying yes the customer accepts the licence terms of this database engine: %s', 'beltoft-services' ), $terms );
			}

			if ( function_exists( 'wc_terms_and_conditions_checkbox_enabled' ) && wc_terms_and_conditions_checkbox_enabled() ) {
				$page    = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
				$terms   = $page > 0 ? (string) get_permalink( $page ) : '';
				$lines[] = '' !== $terms
					/* translators: %s: address of the terms and conditions. */
					? sprintf( __( 'By saying yes the customer accepts the terms and conditions: %s', 'beltoft-services' ), $terms )
					: __( 'By saying yes the customer accepts the terms and conditions of the shop.', 'beltoft-services' );
			}
		} else {
			$lines[] = __( 'The customer has no usage account yet, so this first database is ordered once in the browser: pay as you go bills the hours to a card saved with the payment provider, and a card can only be saved on the provider\'s own page, with the customer present. Nothing is charged by saving it. After that, databases can be ordered here in the chat without the browser.', 'beltoft-services' );
			/* translators: %s: address of the New database page. */
			$lines[] = sprintf( __( 'Give the customer this link; they choose the plan and options there: %s', 'beltoft-services' ), $p['url'] );
		}

		return array(
			'text'  => implode( "\n", $lines ),
			'state' => implode( '|', array( (int) $plan['variation_id'], (int) $p['ip'], (int) $p['gb'], $quote['hourly'], $quote['max_per_month'], (int) $plan['in_stock'], $p['mode'], null === $p['account'] ? 0 : $p['account']->get_id() ) ),
		);
	}

	/**
	 * The address of the licence terms of the plan's engine, or ''.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	private static function engine_terms( array $plan ): string {
		$engine = \BeltoftServices\Proxmox\Db\DbEngines::for_plan( \BeltoftServices\Product::plan( (int) $plan['product_id'], (int) $plan['variation_id'] ) );

		return $engine instanceof \BeltoftServices\Proxmox\Db\DbEngineTerms ? $engine->terms_url() : '';
	}

	/**
	 * Does what the confirmed call asked: places the order, or only gives the link.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( array $args, int $user_id ) {
		$p = self::prepare( $args, $user_id );

		return $p instanceof \WP_Error ? $p : Purchase::execute( $p, $user_id, 'database' );
	}
}
