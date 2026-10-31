<?php
/**
 * The extra storage add-on of a managed database: the plan's data disk is the plan's disk plus the extra storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftLicenses\Licenses;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Extra storage is part of the database plan: the plan field `extra_disk_gb` (a variation's own value) and a variation
 * attribute "Extra storage" (slug `extra-storage`). A variation's price is the price of the database with that much
 * storage. The data disk (scsi1) is `disk_gb + extra_disk_gb`. A subscription switch to a variation with more extra
 * storage makes the disk larger (DbGrow). The add-on only grows: a switch to less total disk is refused before
 * payment, and nothing here cancels it on its own.
 */
final class DbDisk {

	/** The variation attribute (slug) that tells the steps of the ladder apart. */
	public const ATTRIBUTE = 'extra-storage';

	/** The plan field. */
	public const FIELD = 'extra_disk_gb';

	/**
	 * The extra storage of a plan, in GB.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function extra( array $plan ): int {
		return max( 0, (int) ( $plan[ self::FIELD ] ?? 0 ) );
	}

	/**
	 * The data disk of a plan: its disk and the extra storage.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function total( array $plan ): int {
		return max( 0, (int) ( $plan['disk_gb'] ?? 0 ) ) + self::extra( $plan );
	}

	/**
	 * Is this attribute key the extra storage attribute (plain or a taxonomy)?
	 *
	 * @param string $key Attribute key.
	 */
	public static function is_attribute( string $key ): bool {
		$key = strtolower( $key );

		return self::ATTRIBUTE === $key || 'pa_' . self::ATTRIBUTE === $key || 'attribute_' . self::ATTRIBUTE === $key || 'attribute_pa_' . self::ATTRIBUTE === $key;
	}

	/**
	 * The largest data disk the shop offers with the add-on, in GB (setting `db_disk_max_gb`).
	 */
	public static function max_gb(): int {
		return max( 1, (int) Settings::get( 'db_disk_max_gb' ) );
	}

	/**
	 * The step the extra storage must be a multiple of (setting `db_disk_step_gb`).
	 */
	public static function step_gb(): int {
		return max( 1, (int) Settings::get( 'db_disk_step_gb' ) );
	}

	/**
	 * Does the plan's extra storage keep to the settings (a multiple of the step, the total within the largest disk)?
	 * A plan without extra storage always does.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function within_limits( array $plan ): bool {
		$extra = self::extra( $plan );

		return 0 === $extra || ( 0 === $extra % self::step_gb() && self::total( $plan ) <= self::max_gb() );
	}

	// -- The ladder -----------------------------------------------------------------------------------

	/**
	 * The variations of the same plan that differ only in the extra storage (every other attribute and every other
	 * plan field is the same, so the public IP choice is kept), with the variation itself, by extra storage GB. A step
	 * outside the settings, one that is not published or has no price is left out (the variation itself stays).
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<int, \WC_Product_Variation> Extra GB => variation, ascending.
	 */
	public static function ladder( \WC_Product_Variation $variation ): array {
		$parent = function_exists( 'wc_get_product' ) ? wc_get_product( $variation->get_parent_id() ) : null;

		if ( ! $parent instanceof \WC_Product_Variable ) {
			return array();
		}

		$own   = Product::plan( $parent->get_id(), $variation->get_id() );
		$mine  = $variation->get_attributes();
		$other = array_diff_key( $own, array( self::FIELD => 1 ) );
		$out   = array( self::extra( $own ) => $variation );

		foreach ( $parent->get_children() as $id ) {
			$step = wc_get_product( (int) $id );

			if ( ! $step instanceof \WC_Product_Variation || $step->get_id() === $variation->get_id() || 'publish' !== $step->get_status() || '' === (string) $step->get_price() ) {
				continue;
			}

			$theirs = $step->get_attributes();
			$keys   = array_unique( array_merge( array_keys( $mine ), array_keys( $theirs ) ) );
			$differ = array();

			foreach ( $keys as $key ) {
				if ( (string) ( $mine[ $key ] ?? '' ) !== (string) ( $theirs[ $key ] ?? '' ) ) {
					$differ[] = (string) $key;
				}
			}

			if ( 1 !== count( $differ ) || ! self::is_attribute( $differ[0] ) ) {
				continue;
			}

			$plan = Product::plan( $parent->get_id(), $step->get_id() );

			if ( array_diff_key( $plan, array( self::FIELD => 1 ) ) !== $other || ! self::within_limits( $plan ) ) {
				continue;
			}

			$out[ self::extra( $plan ) ] = $step;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The next larger step of the ladder, or null.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	public static function next_larger( \WC_Product_Variation $variation ): ?\WC_Product_Variation {
		$own = self::extra( Product::plan( $variation->get_parent_id(), $variation->get_id() ) );

		foreach ( self::ladder( $variation ) as $extra => $step ) {
			if ( $extra > $own ) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * "+€8.00 / month" of a step compared with another.
	 *
	 * @param \WC_Product_Variation $from From.
	 * @param \WC_Product_Variation $to   To.
	 */
	public static function price_label( \WC_Product_Variation $from, \WC_Product_Variation $to ): string {
		return Backups::price_difference( $from, $to );
	}

	// -- Capacity -------------------------------------------------------------------------------------

	/**
	 * Does a new database with this plan's data disk fit on a node of its cluster? Only a fresh reading of the storage
	 * says yes (see DiskCapacity::fits()).
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function fits_new( array $plan ): bool {
		$cluster = Clusters::all()[ (string) ( $plan['cluster'] ?? '' ) ] ?? null;
		$size    = self::total( $plan );

		if ( ! is_array( $cluster ) || $size < 1 ) {
			return false;
		}

		foreach ( (array) $cluster['nodes'] as $node ) {
			if ( DiskCapacity::fits( (string) $plan['cluster'], (string) $node, (string) $cluster['storage'], $size ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is any database variation sold with extra storage? Then the storage of the nodes is read.
	 */
	public static function sold(): bool {
		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
				'numberposts'    => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one row, once every ten minutes (DiskCapacity::wanted()).
					array(
						'key'     => Product::META_PLAN . self::FIELD,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return array() !== $ids;
	}

	/**
	 * The data disk a database was promised, in GB: what was built or last grown to, else its plan's total.
	 *
	 * @param Resource $r Resource.
	 */
	public static function promised_gb( Resource $r ): int {
		$built = (int) $r->get( 'disk_gb' );

		return $built > 0 ? $built : self::total( (array) $r->get( 'plan' ) );
	}

	// -- The shop side --------------------------------------------------------------------------------

	/**
	 * The refusal of a switch of a database subscription to a variation with less data disk, or with extra storage that
	 * the settings do not allow or that does not fit on the node now. Then the public IP checks. Empty when the switch
	 * may go on.
	 *
	 * @param int                  $subscription_id Subscription id.
	 * @param int                  $product_id      Target product.
	 * @param array<string, mixed> $target          Target plan.
	 * @param mixed                $item            The line item switched.
	 */
	public static function switch_error( int $subscription_id, int $product_id, array $target, $item ): string {
		if ( ! self::within_limits( $target ) ) {
			return __( 'This amount of storage is not available.', 'beltoft-services' );
		}

		$item_product = is_object( $item ) && method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		foreach ( Licenses::where( 'subscription_id', $subscription_id ) as $license ) {
			if ( ( $item_product > 0 && (int) ( $license['product_id'] ?? 0 ) !== $item_product ) || (int) ( $license['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}

			$resource = Resource::for_license( (int) $license['id'] );

			if ( ! $resource || DbDriver::ID !== $resource->get( 'driver' ) ) {
				continue;
			}

			$have = self::promised_gb( $resource );
			$want = self::total( $target );

			if ( $want > 0 && $have > $want ) {
				return __( "Storage can't be made smaller. Choose a size at least as large as your current storage.", 'beltoft-services' );
			}

			if ( $want > $have && ! self::room_to_grow( $resource, $want - $have ) ) {
				return __( 'No more storage is available right now. Please try again later.', 'beltoft-services' );
			}
		}

		return DbIp::switch_error( $subscription_id, $product_id, $target, $item );
	}

	/**
	 * Is there room for the growth on the node of the database? Refused only when a fresh reading says no: with no reading
	 * the switch goes on (DbGrow reads again and waits).
	 *
	 * @param Resource $r  Resource.
	 * @param int      $gb Growth in GB.
	 */
	private static function room_to_grow( Resource $r, int $gb ): bool {
		$record  = VmRecords::for_resource( $r->id() );
		$cluster = $record ? Clusters::all()[ (string) $record['cluster'] ] ?? null : null;

		if ( ! $record || ! is_array( $cluster ) ) {
			return true;
		}

		$storage = (string) $cluster['storage'];

		if ( null === DiskCapacity::available( (string) $record['cluster'], (string) $record['node'], $storage ) ) {
			return true;
		}

		return DiskCapacity::fits( (string) $record['cluster'], (string) $record['node'], $storage, $gb, $r->id() );
	}

	/**
	 * What the dashboard shows of the storage, from stored data only (no Proxmox call).
	 *
	 * - `gb`: the data disk now.
	 * - `growing`: it is being made larger, or the guest still has to take the new size.
	 * - `manual`: the new size is there but the file system is not grown yet (the admin was told).
	 * - `options`: the strictly larger steps, each `extra`, `total`, `price` and `url` (empty when no switch is possible).
	 * - `full`: there is a larger step in the shop but none can be offered now.
	 *
	 * @param Resource $r Resource.
	 * @return array{gb:int,growing:bool,manual:bool,options:array<int,array<string,mixed>>,full:bool}
	 */
	public static function view( Resource $r ): array {
		$plan    = (array) $r->get( 'plan' );
		$out     = array(
			'gb'      => self::promised_gb( $r ),
			'growing' => DbGrow::wanted( $r ),
			'manual'  => DbGrow::manual( $r ),
			'options' => array(),
			'full'    => false,
		);
		$idle    = 'ready' === $r->get( 'db_state' ) && $r->accepts_actions() && ! $out['growing'] && DbIp::idle( $r );
		$id      = (int) ( $r->license()['variation_id'] ?? 0 );
		$current = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

		if ( ! $idle || ! $current instanceof \WC_Product_Variation ) {
			return $out;
		}

		$own     = self::extra( $plan );
		$larger  = false;

		foreach ( self::ladder( $current ) as $extra => $step ) {
			if ( $extra <= $own ) {
				continue;
			}

			$larger = true;
			$more   = self::total( Product::plan( $step->get_parent_id(), $step->get_id() ) ) - $out['gb'];

			if ( $more < 1 || ! self::room_to_grow( $r, $more ) ) {
				continue;
			}

			$url = Backups::switch_to( $r, $step );

			if ( '' === $url ) {
				continue;
			}

			$out['options'][] = array(
				'extra' => $extra,
				'total' => $out['gb'] + $more,
				'price' => self::price_label( $current, $step ),
				'url'   => $url,
			);
		}

		$out['full'] = $larger && array() === $out['options'];

		return $out;
	}

	/**
	 * The link that makes the data disk larger by the next step (the switch to the next larger variation). Empty when
	 * there is none.
	 *
	 * @param Resource $r Resource.
	 */
	public static function grow_url( Resource $r ): string {
		$view = self::view( $r );

		return array() === $view['options'] ? '' : (string) $view['options'][0]['url'];
	}
}
