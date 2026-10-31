<?php
/**
 * Paid apps with tiers: the catalogue, the licence of a server's app and the events a vendor driver listens to.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * DESIGN NOTE: how a paid app, and a vendor driver for it, fit in.
 *
 * Catalogue. An app is paid when its app.json says `"licence": {"product_slug": "<software slug>"}`. The slug
 * names a normal WooCommerce variable subscription product (attribute "Tier", one variation per tier, each
 * with its own price) whose service driver is AppLicenceDriver (`proxmox-app`), whose software slug is that
 * slug and whose plan field `app` is the app id. Each variation carries the plan fields `tier` (a key such as
 * "pro"), `tier_label` and any field added with the filter `beltoft_services_app_licence_plan_fields` (limits
 * such as domains). tiers() lists the purchasable variations. Nothing here creates a product.
 *
 * Ordering. The app field (checkout and the server picker) shows a paid app's tiers with their price. Choosing
 * one adds the tier variation as a second cart line linked to the new server line (AppLicenceCart, the same
 * pattern as the extra disk: `beltoft_services_applic_for => cart_server`), so the customer pays the server
 * and the tier. A paid app picked without a tier, or with a tier that cannot be bought, is refused.
 *
 * Licence. The tier line becomes its own beltoft-licenses license and subscription, with a resource of kind
 * `applic` linked to the server (`server_license`). The server's install job carries the tier (`tier`) and
 * the tier's other plan fields (`licence`), so an app recipe can register its licence with the vendor.
 *
 * Events. A vendor driver listens to the action `beltoft_services_app_licence_changed`
 * ( Resource $server, string $app_id, string $tier, string $event, Resource $licence ), with $event one of
 * `tier_changed`, `paused` (on hold), `resumed` and `cancelled` (ended, or the server went away). It runs in
 * the reconcile job of the licence, once per change, and may call the vendor's API; the plugin itself never
 * does. A tier change is billed by WooCommerce Subscriptions (a switch on the tier subscription; the App tab
 * offers the other tiers); the plugin only reads the new variation afterwards.
 *
 * Ending. When the licence ends the server keeps running and nothing is uninstalled: the App tab says the
 * licence ended. Deleting the server, or reinstalling it without this app, ends the tier subscription
 * (DiskCascade::cancel_undelivered, the way the other add-ons end); a server subscription that ends takes the
 * licence with it (DiskCascade).
 *
 * A future paid app (for example a panel with tiers) needs: its product (above), an app.json with the
 * `licence` key, an install recipe that reads `tier` and `licence` from the job, and a listener for the event.
 */
final class AppLicence {

	/** Action fired when the licence of a server's app changes. */
	public const EVENT = 'beltoft_services_app_licence_changed';

	/** Order line meta: this line is an app licence for the server of the same order. */
	public const ITEM_UID    = '_beltoft_services_applic_uid';
	public const ITEM_SERVER = '_beltoft_services_applic_server';

	/** Cart item data: the link to the new server of the cart, and the line's own id. */
	public const CART_FOR = 'beltoft_services_applic_for';
	public const CART_UID = 'beltoft_services_applic_uid';

	/** Resource meta: why the licence ended before its license did (empty while it runs). */
	public const ENDED = 'applic_ended';

	/** Seconds the install waits for the licence of a paid app to exist and be active. */
	private const WAIT = HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_initial_meta', array( __CLASS__, 'initial_meta' ), 10, 3 );
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'state_changed' ), 10, 3 );
		add_action( 'beltoft_services_reinstall_started', array( __CLASS__, 'reinstalled' ), 10, 2 );
	}

	// -- Catalogue -----------------------------------------------------------------------------

	/**
	 * The software slug of a paid app's licence product, '' for a free app.
	 *
	 * @param string $app_id App id.
	 */
	public static function slug( string $app_id ): string {
		$def  = Apps::definition( $app_id );
		$slug = is_array( $def ) && is_array( $def['licence'] ?? null ) ? $def['licence']['product_slug'] ?? '' : '';

		return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : '';
	}

	/**
	 * Does the app cost money?
	 *
	 * @param string $app_id App id.
	 */
	public static function paid( string $app_id ): bool {
		return '' !== self::slug( $app_id );
	}

	/**
	 * The licence product of a paid app: the published variable subscription product with the app's software slug,
	 * the app licence driver and the app id in its plan. 0 when there is none.
	 *
	 * @param string $app_id App id.
	 */
	public static function product_id( string $app_id ): int {
		$slug = self::slug( $app_id );

		if ( '' === $slug || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Service catalogue is small and bounded.
					array( 'key' => \BeltoftLicenses\Software::META_SLUG, 'value' => $slug ),
					array( 'key' => Product::META_DRIVER, 'value' => AppLicenceDriver::ID ),
					array( 'key' => Product::META_PLAN . 'app', 'value' => $app_id ),
				),
			)
		);

		return array() === $ids ? 0 : (int) $ids[0];
	}

	/**
	 * The tiers of a paid app: the variations of its licence product that are subscriptions and carry a tier key.
	 *
	 * @param string $app_id App id.
	 * @param bool   $buyable Only those that can be bought now (purchasable and in stock).
	 * @return array<string, array{key: string, label: string, product_id: int, variation_id: int, variation: \WC_Product_Variation, plan: array<string, mixed>, price: string}> By tier key.
	 */
	public static function tiers( string $app_id, bool $buyable = true ): array {
		$id      = self::product_id( $app_id );
		$product = $id > 0 ? wc_get_product( $id ) : null;
		$out     = array();

		if ( ! $product instanceof \WC_Product_Variable || ! class_exists( '\\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			return $out;
		}

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() || ! \WC_Subscriptions_Product::is_subscription( $variation ) ) {
				continue;
			}

			$plan = Product::plan( $id, (int) $variation_id );
			$key  = (string) ( $plan['tier'] ?? '' );

			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/D', $key ) || isset( $out[ $key ] ) || ( $buyable && ( ! $variation->is_purchasable() || ! $variation->is_in_stock() ) ) ) {
				continue;
			}

			$out[ $key ] = array(
				'key'          => $key,
				'label'        => self::label_of( $variation, $plan ),
				'product_id'   => $id,
				'variation_id' => (int) $variation_id,
				'variation'    => $variation,
				'plan'         => $plan,
				'price'        => self::price( $variation ),
			);
		}

		return $out;
	}

	/**
	 * One tier of a paid app.
	 *
	 * @param string $app_id App id.
	 * @param string $key    Tier key.
	 * @param bool   $buyable Only when it can be bought now.
	 * @return array<string, mixed>|null
	 */
	public static function tier( string $app_id, string $key, bool $buyable = true ): ?array {
		return self::tiers( $app_id, $buyable )[ $key ] ?? null;
	}

	/**
	 * The pay-as-you-go variation of a tier: its usage sibling (same tier, Billing "pay as you go", price 0 at checkout),
	 * when it can be bought and the tier has a monthly price to bill from. Null when there is none.
	 *
	 * @param array<string, mixed> $tier A tier from tiers().
	 */
	public static function usage_variation( array $tier ): ?\WC_Product_Variation {
		$variation = $tier['variation'] ?? null;

		if ( ! $variation instanceof \WC_Product_Variation || null === Rates::for_plan( $variation ) ) {
			return null;
		}

		$usage = Rates::sibling( $variation, true );

		return $usage && $usage->is_purchasable() && $usage->is_in_stock() ? $usage : null;
	}

	/**
	 * The variation that goes in the cart for a tier: the tier's own (monthly) one, or for a pay-as-you-go server
	 * its usage sibling. A pay-as-you-go server never gets the monthly one: null when the tier has no usage sibling.
	 *
	 * @param array<string, mixed> $tier  A tier from tiers().
	 * @param bool                 $usage Whether the server is billed pay as you go.
	 */
	public static function line_variation( array $tier, bool $usage ): ?\WC_Product_Variation {
		return $usage ? self::usage_variation( $tier ) : ( $tier['variation'] ?? null );
	}

	/**
	 * What a tier costs on a pay-as-you-go server, "0.0226 per hour, max 16.50 per month excluding VAT", or ''.
	 *
	 * @param array<string, mixed> $tier A tier from tiers().
	 */
	public static function usage_price( array $tier ): string {
		$rates = null !== self::usage_variation( $tier ) ? Rates::for_plan( $tier['variation'] ) : null;

		return null === $rates ? '' : Rates::terms( $rates['hourly'], $rates['cap'] );
	}

	/**
	 * Is this server (or licence) billed from the usage ledger, pay as you go?
	 *
	 * @param Resource $resource Server or licence resource.
	 */
	public static function usage_billed( Resource $resource ): bool {
		return 'usage' === (string) ( $resource->license()['source'] ?? '' );
	}

	/**
	 * The app a licence variation belongs to, or null when it is no tier of a known app.
	 *
	 * @param int $variation_id Variation id.
	 */
	public static function app_of( int $variation_id ): ?string {
		$parent = (int) wp_get_post_parent_id( $variation_id );
		$app    = $parent > 0 ? (string) ( Product::plan( $parent, $variation_id )['app'] ?? '' ) : '';
		$driver = $parent > 0 ? Product::driver_for( $parent ) : null;

		return $driver && AppLicenceDriver::ID === $driver->id() && '' !== $app && self::paid( $app ) ? $app : null;
	}

	/**
	 * What the customer calls a tier: its name, else the variation's own attribute value, else the key.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param array<string, mixed>  $plan      Its plan.
	 */
	private static function label_of( \WC_Product_Variation $variation, array $plan ): string {
		$label = (string) ( $plan['tier_label'] ?? '' );

		if ( '' === $label ) {
			$values = array_values( array_filter( array_map( 'strval', $variation->get_variation_attributes( false ) ) ) );
			$label  = $values[0] ?? '';
		}

		return '' !== $label ? $label : ucfirst( (string) ( $plan['tier'] ?? '' ) );
	}

	/**
	 * "10,00 kr. / month excl. VAT".
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	private static function price( \WC_Product_Variation $variation ): string {
		$period = function_exists( 'wcs_get_subscription_period_strings' )
			? ' / ' . (string) wcs_get_subscription_period_strings( max( 1, (int) \WC_Subscriptions_Product::get_interval( $variation ) ), (string) \WC_Subscriptions_Product::get_period( $variation ) )
			: '';

		return sprintf( '%s%s %s', wp_strip_all_tags( wc_price( wc_get_price_excluding_tax( $variation ) ) ), $period, __( 'excl. VAT', 'beltoft-services' ) );
	}

	// -- The licence of a server's app ----------------------------------------------------------

	/**
	 * Live licence resources linked to a server, whatever their state, newest first.
	 *
	 * @param Resource $server Server.
	 * @return Resource[]
	 */
	public static function for_server( Resource $server ): array {
		global $wpdb;

		$license_id = (int) ( $server->license()['id'] ?? 0 );
		$out        = array();

		if ( $license_id < 1 ) {
			return $out;
		}

		// One query for the candidates (the license id is in the meta text), then the exact check on each.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE kind = 'applic' AND deactivated_at IS NULL AND meta LIKE %s ORDER BY id DESC", \BeltoftLicenses\Install::tables()['activations'], '%\"server_license\":%' . $wpdb->esc_like( (string) $license_id ) . '%' ) );

		foreach ( (array) $ids as $id ) {
			$licence = Resource::find( (int) $id );

			if ( $licence && AppLicenceDriver::ID === $licence->get( 'driver' ) && (int) $licence->get( 'server_license' ) === $license_id ) {
				$out[] = $licence;
			}
		}

		return $out;
	}

	/**
	 * The licence of the app the server runs now: one that has not ended first, else the newest.
	 *
	 * @param Resource $server Server.
	 */
	public static function current( Resource $server ): ?Resource {
		$app   = (string) $server->get( 'app_id' );
		$found = null;

		foreach ( self::for_server( $server ) as $licence ) {
			if ( (string) ( ( (array) $licence->get( 'plan' ) )['app'] ?? '' ) !== $app ) {
				continue;
			}

			if ( 'ended' !== self::state( $licence ) ) {
				return $licence;
			}

			$found = $found ?? $licence;
		}

		return $found;
	}

	/**
	 * The server of a licence resource, or null.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function server_of( Resource $licence ): ?Resource {
		$id = (int) $licence->get( 'server_license' );

		if ( $id < 1 ) {
			return null;
		}

		$server = Resource::for_license( $id );

		return $server && SshKeyField::DRIVER === $server->get( 'driver' ) ? $server : null;
	}

	/**
	 * The licence's state in words the plugin uses: active, paused (on hold) or ended.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function state( Resource $licence ): string {
		if ( '' !== (string) $licence->get( self::ENDED ) || in_array( $licence->get( 'state' ), array( 'failed', 'deleting', 'deleted' ), true ) ) {
			return 'ended';
		}

		$status = Licenses::effective_status( (array) $licence->license() );

		if ( Licenses::STATUS_ACTIVE === $status ) {
			return 'active';
		}

		return Licenses::STATUS_SUSPENDED === $status ? 'paused' : 'ended';
	}

	/**
	 * The tier of a licence as the customer reads it.
	 *
	 * @param Resource $licence Licence resource.
	 */
	public static function tier_label( Resource $licence ): string {
		$plan = (array) $licence->get( 'plan' );
		$tier = self::tier( (string) ( $plan['app'] ?? '' ), (string) ( $plan['tier'] ?? '' ), false );

		return null !== $tier ? $tier['label'] : ucfirst( (string) ( $plan['tier'] ?? '' ) );
	}

	/**
	 * Tells the vendor driver. Only a change is announced: an event already sent for this state is not sent again.
	 *
	 * @param Resource $licence Licence resource.
	 * @param string   $event   tier_changed, paused, resumed or cancelled.
	 */
	public static function announce( Resource $licence, string $event ): void {
		$server = self::server_of( $licence );

		if ( ! $server ) {
			return;
		}

		if ( 'tier_changed' !== $event && $event === (string) $licence->get( 'applic_event' ) ) {
			return;
		}

		$licence->set( array( 'applic_event' => $event ) );
		$plan = (array) $licence->get( 'plan' );

		/**
		 * The licence of a server's paid app changed. A vendor driver registers, changes or ends the vendor's licence here.
		 *
		 * @param Resource $server  The server.
		 * @param string   $app_id  App id.
		 * @param string   $tier    Tier key now.
		 * @param string   $event   tier_changed, paused, resumed or cancelled.
		 * @param Resource $licence The licence resource.
		 */
		do_action( self::EVENT, $server, (string) ( $plan['app'] ?? '' ), (string) ( $plan['tier'] ?? '' ), $event, $licence );
	}

	/**
	 * Ends the tier subscription of a licence that no longer has an app to belong to. The server is not touched.
	 *
	 * @param Resource $licence Licence resource.
	 * @param string   $reason  Note on the subscription.
	 */
	public static function end( Resource $licence, string $reason ): void {
		$licence->set( array( self::ENDED => $reason ) );

		// A pay-as-you-go licence has no subscription of its own (the usage account bills it): closing it stops the meter.
		if ( self::usage_billed( $licence ) ) {
			if ( 'deleted' !== $licence->get( 'state' ) && $licence->transition( 'deleted', $reason ) ) {
				self::announce( $licence, 'cancelled' );
				$licence->close();
			}

			return;
		}

		DiskCascade::cancel_undelivered( $licence, $reason );
	}

	/**
	 * What the App tab shows about the licence, or null for a free app.
	 *
	 * @param Resource $server Server.
	 * @return array{state: string, tier: string, usage: string, tiers: array<int, array{label: string, price: string, url: string}>}|null
	 */
	public static function view( Resource $server ): ?array {
		$app = (string) $server->get( 'app_id' );

		if ( '' === $app || ! self::paid( $app ) ) {
			return null;
		}

		$licence = self::current( $server );

		if ( ! $licence ) {
			return array( 'state' => 'none', 'tier' => '', 'usage' => '', 'tiers' => array() );
		}

		$state    = self::state( $licence );
		$own      = (string) ( ( (array) $licence->get( 'plan' ) )['tier'] ?? '' );
		$switches = array();

		$usage = '';

		if ( self::usage_billed( $licence ) ) {
			// Billed by the hour with the server: no subscription of its own to switch.
			$items = Rates::items( $licence );
			$usage = $items ? Rates::terms( (float) $items[0]['rate'], round( (float) $items[0]['rate'] * Rates::HOURS, 2 ) ) : '';
		} elseif ( 'active' === $state && get_current_user_id() === (int) ( $licence->license()['user_id'] ?? 0 ) ) {
			foreach ( self::tiers( $app ) as $key => $tier ) {
				$url = $key === $own ? '' : Backups::switch_to( $licence, $tier['variation'] );

				if ( '' !== $url ) {
					$switches[] = array( 'label' => $tier['label'], 'price' => $tier['price'], 'url' => $url );
				}
			}
		}

		return array( 'state' => $state, 'tier' => self::tier_label( $licence ), 'usage' => $usage, 'tiers' => $switches );
	}

	// -- Hand-over to the server ----------------------------------------------------------------

	/**
	 * What the server's install job carries for a paid app: the tier and the tier's other plan fields.
	 * A poll while the licence is not there yet (or is on hold), a failure once it has ended or the wait ran out.
	 *
	 * @param Resource $server Server.
	 * @return array{tier: string, licence: array<string, mixed>}|\BeltoftServices\Drivers\StepResult
	 */
	public static function for_install( Resource $server ) {
		$licence = self::current( $server );
		$state   = $licence ? self::state( $licence ) : 'none';

		if ( 'active' === $state ) {
			$plan = (array) $licence->get( 'plan' );

			return array(
				'tier'    => (string) ( $plan['tier'] ?? '' ),
				'licence' => array_diff_key( $plan, array_flip( array( 'app', 'tier', 'tier_label' ) ) ),
			);
		}

		if ( 'ended' === $state ) {
			return \BeltoftServices\Drivers\StepResult::fail( 'The app licence has ended' );
		}

		$until = (int) $server->get( 'app_licence_wait_until' );
		$until = $until > 0 ? $until : time() + self::WAIT;

		if ( time() >= $until ) {
			return \BeltoftServices\Drivers\StepResult::fail( 'The app licence is missing' );
		}

		return \BeltoftServices\Drivers\StepResult::poll( 30, 'app: waiting for the app licence', 900, array( 'app_licence_wait_until' => $until ) );
	}

	/**
	 * A licence's order line says which server it is for: the one of the same order. The nonce was checked when the
	 * line was added to the cart.
	 *
	 * @param mixed                $meta    Meta so far.
	 * @param mixed                $license License row.
	 * @param mixed                $driver  Driver.
	 * @return mixed
	 */
	public static function initial_meta( $meta, $license, $driver ) {
		if ( ! is_array( $meta ) || ! is_array( $license ) || ! $driver instanceof Driver || AppLicenceDriver::ID !== $driver->id() ) {
			return $meta;
		}

		$order_id = (int) ( $license['order_id'] ?? 0 );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			return $meta;
		}

		$uid             = '';
		$subscription_id = (int) ( $license['subscription_id'] ?? 0 );

		if ( $subscription_id > 0 && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = wcs_get_subscription( $subscription_id );

			foreach ( $subscription ? $subscription->get_items() : array() as $item ) {
				if ( (int) $item->get_product_id() === (int) $license['product_id'] ) {
					$uid = (string) $item->get_meta( self::ITEM_UID, true );
					break;
				}
			}
		}

		$matches = array();

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== (int) $license['product_id'] || ( '' !== $uid && $uid !== (string) $item->get_meta( self::ITEM_UID, true ) ) ) {
				continue;
			}

			$matches[] = $item;
		}

		if ( 1 !== count( $matches ) ) {
			return $meta;
		}

		$link = $matches[0]->get_meta( self::ITEM_SERVER, true );

		if ( 'cart_server' === $link ) {
			$meta['server_order_id'] = $order_id;
		} elseif ( is_numeric( $link ) && (int) $link > 0 ) {
			// Bought for a server the customer already has (the reinstall form).
			$server = Licenses::find( (int) $link );
			$driver = $server ? Product::driver_for( (int) ( $server['product_id'] ?? 0 ) ) : null;

			if ( $server && (int) ( $server['user_id'] ?? 0 ) === (int) ( $license['user_id'] ?? 0 ) && $driver && SshKeyField::DRIVER === $driver->id() ) {
				$meta['server_license'] = (int) $link;
			}
		}

		$meta['order_item_id'] = (int) $matches[0]->get_id();

		return $meta;
	}

	/**
	 * A server that is deleted ends its app licences: the resource is closed and the subscription cancelled (when it
	 * holds only add-ons; else the admin is told, as for the other add-ons).
	 *
	 * @param mixed  $resource Resource.
	 * @param string $old      Old state (unused).
	 * @param string $new      New state.
	 */
	public static function state_changed( $resource, $old, $new ): void {
		unset( $old );

		if ( ! $resource instanceof Resource || 'deleted' !== $new || SshKeyField::DRIVER !== $resource->get( 'driver' ) ) {
			return;
		}

		foreach ( self::for_server( $resource ) as $licence ) {
			if ( 'deleted' === $licence->get( 'state' ) ) {
				continue;
			}

			$id = (int) ( $licence->license()['subscription_id'] ?? 0 );

			if ( $licence->transition( 'deleted', 'Parent server deleted; app licence no longer available' ) ) {
				self::announce( $licence, 'cancelled' );
				DiskCascade::cancel_undelivered( $licence, 'The server was deleted, so its app licence ends.' );
				$licence->close();

				$subscription = $id > 0 && function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $id ) : null;

				if ( $subscription instanceof \WC_Subscription && ! in_array( $subscription->get_status(), array( 'cancelled', 'expired', 'trash' ), true ) ) {
					Alerts::admin( $licence, 'The parent server was deleted while the app licence subscription may still bill. Check subscription #' . $id . '.' );
				}
			}
		}
	}

	/**
	 * A reinstall that left the app (another app, or none) ends the licence of the old one.
	 *
	 * @param mixed $r    Server.
	 * @param mixed $args Reinstall arguments (unused).
	 */
	public static function reinstalled( $r, $args = array() ): void {
		unset( $args );

		if ( ! $r instanceof Resource || SshKeyField::DRIVER !== $r->get( 'driver' ) ) {
			return;
		}

		$app = (string) $r->get( 'app_id' );

		foreach ( self::for_server( $r ) as $licence ) {
			if ( 'ended' !== self::state( $licence ) && (string) ( ( (array) $licence->get( 'plan' ) )['app'] ?? '' ) !== $app ) {
				self::end( $licence, 'The server was reinstalled without this app, so its licence ends.' );
			}
		}
	}
}
