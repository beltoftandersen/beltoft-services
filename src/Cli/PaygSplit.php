<?php
/**
 * One-off migration of the twin model to the pay as you go product (plan 2026-11-20-payg-own-product.md, part D).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Cli;

use BeltoftServices\Currency;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Items;
use BeltoftServices\Usage\Rates;

defined( 'ABSPATH' ) || exit;

/**
 * Turns every product that has pay as you go variations into a pay as you go product, in place (same ids):
 *
 * 1. the parent gets the flag `_beltoft_services_billing` (usage, or counted when all its pay as you go variations are counted);
 * 2. every hourly pay as you go variation gets the monthly price its twin gave the OLD code (excluding tax, per currency) in its own
 *    meta, so Rates::monthly() and Rates::items() answer the old figures;
 * 3. every monthly variation gets the own marker `monthly` and goes private (off sale, never deleted); a live subscription on it stays;
 * 4. the figures before and after are compared for every variation and live resource in every currency; any difference undoes the run.
 *
 * Every change is written to one manifest (option) with the old value, so rollback restores exactly. A second run changes nothing.
 * Orders, subscriptions, licences and ledger rows are never touched. The old twin finder is copied here (old_monthly()) because the plugin
 * no longer has one.
 */
final class PaygSplit {

	public const OPTION = 'beltoft_services_payg_split';

	/**
	 * Runs the migration.
	 *
	 * @param bool $apply    False: a dry run that writes nothing.
	 * @param bool $rollback Restore the stored manifest instead.
	 * @return array{ok: bool, lines: string[]}
	 */
	public static function run( bool $apply, bool $rollback = false ): array {
		if ( $rollback ) {
			return self::rollback_run();
		}

		$lines    = array();
		$manifest = get_option( self::OPTION );
		$manifest = is_array( $manifest ) ? $manifest : null;

		// A run that stopped half way is undone first, then done again.
		if ( $apply && $manifest && empty( $manifest['done'] ) ) {
			$undo = self::rollback_run();

			if ( ! $undo['ok'] ) {
				return $undo;
			}

			$lines    = $undo['lines'];
			$lines[]  = 'An unfinished run was undone; starting again.';
			$manifest = null;
		}

		$scope = self::scope();

		if ( ! $scope ) {
			$lines[] = 'No product has pay as you go variations. Nothing to do.';

			return array( 'ok' => true, 'lines' => $lines );
		}

		if ( $manifest ) {
			$problems = self::compare( $manifest['before'], self::figures( false, $scope ) + array( 'items' => self::items( $scope ) ) );
			$lines[]  = 'Already applied on ' . gmdate( 'Y-m-d H:i', (int) $manifest['time'] ) . ' UTC. No change made.';

			foreach ( $problems as $p ) {
				$lines[] = 'DIFFERENT: ' . $p;
			}

			return array( 'ok' => ! $problems, 'lines' => $lines );
		}

		$before = self::figures( true, $scope );
		$lines  = array_merge( $lines, self::describe( $scope, $before, $apply ) );

		if ( ! $apply ) {
			$lines[] = 'Dry run: nothing written. Run with --apply.';

			return array( 'ok' => true, 'lines' => $lines );
		}

		$manifest = array( 'time' => time(), 'done' => false, 'meta' => array(), 'status' => array(), 'before' => $before );
		self::save( $manifest );

		// Step A: the monthly figures into the variations' own meta. The engine now answers the old figures (checked below), so the
		// hourly items measured here are what the old code gave; step B must not change them.
		foreach ( $scope as $pid => $p ) {
			self::write_figures( $manifest, $p, $before );
			self::resync( $pid );
			self::save( $manifest );
		}

		$before['items']     = self::items( $scope );
		$manifest['before']  = $before;
		self::save( $manifest );

		// Step B: the parent flag, the monthly marker and the monthly variations off sale.
		foreach ( $scope as $pid => $p ) {
			self::write_flags( $manifest, $pid, $p );
			self::resync( $pid );
			self::save( $manifest );
		}

		$problems = self::compare( $before, self::figures( false, $scope ) + array( 'items' => self::items( $scope ) ) );

		if ( $problems ) {
			foreach ( $problems as $p ) {
				$lines[] = 'DIFFERENT: ' . $p;
			}

			$undo    = self::rollback_run();
			$lines   = array_merge( $lines, $undo['lines'] );
			$lines[] = 'The figures before and after differ. Everything was undone.';

			return array( 'ok' => false, 'lines' => $lines );
		}

		$manifest['done'] = true;
		self::save( $manifest );
		self::purge();
		$lines[] = sprintf( 'Applied: %d meta values and %d statuses changed. Figures before and after are equal.', count( $manifest['meta'] ), count( $manifest['status'] ) );

		return array( 'ok' => true, 'lines' => $lines );
	}

	/**
	 * Products with pay as you go variations (the old own flag): parent id => kind, pay as you go and monthly variation ids.
	 *
	 * @return array<int, array{kind: string, payg: int[], counted: int[], monthly: int[]}>
	 */
	private static function scope(): array {
		$ids = get_posts(
			array(
				'post_type'        => 'product_variation',
				'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
				'meta_query'       => array( array( 'key' => Rates::META_BILLING, 'value' => array( Rates::KIND_HOURLY, Rates::KIND_COUNTED ), 'compare' => 'IN' ) ),
				'fields'           => 'ids',
				'nopaging'         => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$out = array();

		foreach ( $ids as $id ) {
			$pid = (int) wp_get_post_parent_id( (int) $id );

			if ( $pid > 0 && ! isset( $out[ $pid ] ) ) {
				$out[ $pid ] = array( 'kind' => Rates::KIND_HOURLY, 'payg' => array(), 'counted' => array(), 'monthly' => array() );
			}
		}

		foreach ( $out as $pid => $p ) {
			$children = get_posts(
				array(
					'post_type'        => 'product_variation',
					'post_parent'      => $pid,
					'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
					'fields'           => 'ids',
					'nopaging'         => true,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			);

			foreach ( $children as $vid ) {
				$own = (string) get_post_meta( (int) $vid, Rates::META_BILLING, true );

				if ( Rates::KIND_HOURLY === $own ) {
					$out[ $pid ]['payg'][] = (int) $vid;
				} elseif ( Rates::KIND_COUNTED === $own ) {
					$out[ $pid ]['counted'][] = (int) $vid;
				} else {
					$out[ $pid ]['monthly'][] = (int) $vid;
				}
			}

			if ( $out[ $pid ]['counted'] && ! $out[ $pid ]['payg'] ) {
				$out[ $pid ]['kind'] = Rates::KIND_COUNTED;
			}
		}

		return $out;
	}

	/**
	 * The monthly price the OLD code gave a pay as you go variation, in the current currency: its twin's price excluding tax (the twin is
	 * the published monthly variation of the same parent with the same attributes except Billing and the same plan), else the variation's
	 * own figure in the shop's currency, else null.
	 *
	 * @param \WC_Product_Variation $v Pay as you go variation.
	 */
	private static function old_monthly( \WC_Product_Variation $v ): ?float {
		$twin = self::twin( $v );

		if ( $twin && '' !== (string) $twin->get_price() ) {
			return (float) wc_get_price_excluding_tax( $twin );
		}

		if ( Currency::current() !== Currency::base() ) {
			return null;
		}

		$meta = (string) $v->get_meta( Rates::META_MONTHLY );

		return is_numeric( $meta ) ? (float) $meta : null;
	}

	/**
	 * The monthly twin of a pay as you go variation (the old Rates::sibling).
	 *
	 * @param \WC_Product_Variation $v Variation.
	 */
	private static function twin( \WC_Product_Variation $v ): ?\WC_Product_Variation {
		$parent = wc_get_product( $v->get_parent_id() );

		if ( ! $parent instanceof \WC_Product_Variable ) {
			return null;
		}

		$mine = $v->get_attributes();
		$own  = Product::plan( $parent->get_id(), $v->get_id() );
		$kinds = array( Rates::KIND_HOURLY, Rates::KIND_COUNTED );

		foreach ( $parent->get_children() as $id ) {
			$other = wc_get_product( $id );

			if ( ! $other instanceof \WC_Product_Variation || $other->get_id() === $v->get_id() || 'publish' !== $other->get_status() || in_array( (string) $other->get_meta( Rates::META_BILLING ), $kinds, true ) ) {
				continue;
			}

			$theirs  = $other->get_attributes();
			$billing = self::billing_key( $mine ) ?? self::billing_key( $theirs );

			foreach ( array_unique( array_merge( array_keys( $mine ), array_keys( $theirs ) ) ) as $key ) {
				if ( $key !== $billing && (string) ( $mine[ $key ] ?? '' ) !== (string) ( $theirs[ $key ] ?? '' ) ) {
					continue 2;
				}
			}

			if ( Product::plan( $parent->get_id(), $other->get_id() ) === $own ) {
				return $other;
			}
		}

		return null;
	}

	/**
	 * The key of the Billing attribute among variation attributes, or null.
	 *
	 * @param array<string, mixed> $attributes Variation attributes.
	 */
	private static function billing_key( array $attributes ): ?string {
		foreach ( array_keys( $attributes ) as $key ) {
			if ( 1 === preg_match( '/^(attribute_)?(pa_)?billing$/D', (string) $key ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * Kinds and monthly figures of every variation in scope in every known currency, by the old code (`$old`) or by Rates.
	 *
	 * @param bool                             $old   Measure the way the old code answered.
	 * @param array<int, array<string, mixed>> $scope Scope.
	 * @return array{kinds: array<int, string>, monthly: array<int, array<string, ?float>>}
	 */
	private static function figures( bool $old, array $scope ): array {
		$codes = Currency::known_codes();
		$out   = array( 'kinds' => array(), 'monthly' => array() );

		foreach ( $scope as $p ) {
			foreach ( array( 'payg' => Rates::KIND_HOURLY, 'counted' => Rates::KIND_COUNTED, 'monthly' => '' ) as $group => $kind ) {
				foreach ( $p[ $group ] as $vid ) {
					$out['kinds'][ $vid ] = $old ? $kind : Rates::kind( $vid );
				}
			}

			foreach ( $p['payg'] as $vid ) {
				foreach ( $codes as $code ) {
					list( $ok, $f ) = Currency::with(
						$code,
						static function () use ( $old, $vid ) {
							$v = wc_get_product( $vid );

							if ( ! $v instanceof \WC_Product_Variation ) {
								return null;
							}

							return $old ? self::old_monthly( $v ) : Rates::monthly( $v );
						}
					);

					$out['monthly'][ $vid ][ $code ] = $ok && null !== $f && $f > 0 ? (float) $f : null;
				}
			}
		}

		return $out;
	}

	/**
	 * The live resources on a variation of the products in scope.
	 *
	 * @param array<int, array<string, mixed>> $scope Scope.
	 * @return array<int, Resource>
	 */
	private static function resources( array $scope ): array {
		$vids = array();

		foreach ( $scope as $p ) {
			$vids = array_merge( $vids, $p['payg'], $p['counted'], $p['monthly'] );
		}

		$out = array();

		foreach ( Registry::kinds() as $kind ) {
			foreach ( Resource::live_ids( $kind ) as $rid ) {
				$r = Resource::find( (int) $rid );

				if ( $r && in_array( (int) ( $r->license()['variation_id'] ?? 0 ), $vids, true ) ) {
					$out[ (int) $rid ] = $r;
				}
			}
		}

		return $out;
	}

	/**
	 * The hourly items of every live resource in scope in every known currency (Rates::items).
	 *
	 * @param array<int, array<string, mixed>> $scope Scope.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( array $scope ): array {
		$out = array();

		foreach ( self::resources( $scope ) as $rid => $r ) {
			foreach ( Currency::known_codes() as $code ) {
				list( $ok, $items ) = Currency::with( $code, static fn() => Rates::items( $r ) );

				$out[ $rid ][ $code ] = $ok ? $items : null;
			}
		}

		return $out;
	}

	/**
	 * Differences between two measurements.
	 *
	 * @param array<string, mixed> $before Before.
	 * @param array<string, mixed> $after  After.
	 * @return string[]
	 */
	private static function compare( array $before, array $after ): array {
		$problems = array();

		foreach ( $before['kinds'] as $vid => $kind ) {
			if ( ( $after['kinds'][ $vid ] ?? null ) !== $kind ) {
				$problems[] = sprintf( 'variation %d kind "%s" became "%s"', $vid, $kind, (string) ( $after['kinds'][ $vid ] ?? '' ) );
			}
		}

		foreach ( $before['monthly'] as $vid => $codes ) {
			foreach ( $codes as $code => $f ) {
				if ( ( $after['monthly'][ $vid ][ $code ] ?? null ) !== $f ) {
					$problems[] = sprintf( 'variation %d %s monthly %s became %s', $vid, $code, var_export( $f, true ), var_export( $after['monthly'][ $vid ][ $code ] ?? null, true ) );
				}
			}
		}

		foreach ( $before['items'] ?? array() as $rid => $codes ) {
			foreach ( $codes as $code => $items ) {
				if ( wp_json_encode( $items ) !== wp_json_encode( $after['items'][ $rid ][ $code ] ?? null ) ) {
					$problems[] = sprintf( 'resource %d %s hourly items differ', $rid, $code );
				}
			}
		}

		return $problems;
	}

	/**
	 * Step A: copies the old monthly figures into the pay as you go variations of a product.
	 *
	 * @param array<string, mixed> $manifest Manifest (changed).
	 * @param array<string, mixed> $p        Scope of the product.
	 * @param array<string, mixed> $before   Figures before.
	 */
	private static function write_figures( array &$manifest, array $p, array $before ): void {
		foreach ( $p['payg'] as $vid ) {
			foreach ( $before['monthly'][ $vid ] ?? array() as $code => $f ) {
				if ( null !== $f ) {
					self::put( $manifest, $vid, Currency::base() === $code ? Rates::META_MONTHLY : Rates::META_MONTHLY . '_' . $code, (string) $f );
				}
			}
		}
	}

	/**
	 * Step B: flags the parent, marks the monthly variations and takes them off sale.
	 *
	 * @param array<string, mixed> $manifest Manifest (changed).
	 * @param int                  $pid      Parent id.
	 * @param array<string, mixed> $p        Scope of the product.
	 */
	private static function write_flags( array &$manifest, int $pid, array $p ): void {
		if ( ! in_array( (string) get_post_meta( $pid, Rates::META_BILLING, true ), array( Rates::KIND_HOURLY, Rates::KIND_COUNTED ), true ) ) {
			self::put( $manifest, $pid, Rates::META_BILLING, $p['kind'] );
		}

		foreach ( $p['monthly'] as $vid ) {
			self::put( $manifest, $vid, Rates::META_BILLING, Rates::KIND_MONTHLY );

			if ( 'publish' === get_post_status( $vid ) ) {
				$manifest['status'][] = array( $vid, 'publish' );
				wp_update_post( array( 'ID' => $vid, 'post_status' => 'private' ) );
			}
		}
	}

	/**
	 * Sets a meta value once and records its first old value.
	 *
	 * @param array<string, mixed> $manifest Manifest (changed).
	 * @param int                  $id       Post id.
	 * @param string               $key      Meta key.
	 * @param string               $value    New value.
	 */
	private static function put( array &$manifest, int $id, string $key, string $value ): void {
		$had = metadata_exists( 'post', $id, $key );
		$old = $had ? (string) get_post_meta( $id, $key, true ) : null;

		if ( $had && $old === $value ) {
			return;
		}

		foreach ( $manifest['meta'] as $m ) {
			if ( $m[0] === $id && $m[1] === $key ) {
				update_post_meta( $id, $key, $value );

				return;
			}
		}

		$manifest['meta'][] = array( $id, $key, $old );
		update_post_meta( $id, $key, $value );
	}

	/**
	 * Undoes the stored manifest exactly.
	 *
	 * @return array{ok: bool, lines: string[]}
	 */
	private static function rollback_run(): array {
		$manifest = get_option( self::OPTION );

		if ( ! is_array( $manifest ) ) {
			return array( 'ok' => false, 'lines' => array( 'No manifest: nothing to roll back.' ) );
		}

		$parents = array();

		foreach ( array_reverse( $manifest['status'] ) as $s ) {
			wp_update_post( array( 'ID' => $s[0], 'post_status' => $s[1] ) );
			$parents[ (int) wp_get_post_parent_id( (int) $s[0] ) ] = true;
		}

		foreach ( array_reverse( $manifest['meta'] ) as $m ) {
			if ( null === $m[2] ) {
				delete_post_meta( $m[0], $m[1] );
			} else {
				update_post_meta( $m[0], $m[1], $m[2] );
			}

			$parents[ Product::parent_id( (int) $m[0] ) ] = true;
		}

		foreach ( array_keys( $parents ) as $pid ) {
			self::resync( (int) $pid );
		}

		delete_option( self::OPTION );
		self::purge();

		return array( 'ok' => true, 'lines' => array( sprintf( 'Rolled back: %d meta values and %d statuses restored.', count( $manifest['meta'] ), count( $manifest['status'] ) ) ) );
	}

	/**
	 * Prints what a run does.
	 *
	 * @param array<int, array<string, mixed>> $scope  Scope.
	 * @param array<string, mixed>             $before Measurement before.
	 * @param bool                             $apply  Whether it is applied.
	 * @return string[]
	 */
	private static function describe( array $scope, array $before, bool $apply ): array {
		$lines = array();

		foreach ( $scope as $pid => $p ) {
			$lines[] = sprintf( '#%d %s: parent flag "%s", %d pay as you go, %d counted, %d monthly variations go off sale.', $pid, get_the_title( $pid ), $p['kind'], count( $p['payg'] ), count( $p['counted'] ), count( $p['monthly'] ) );

			foreach ( $p['payg'] as $vid ) {
				$parts = array();

				foreach ( $before['monthly'][ $vid ] ?? array() as $code => $f ) {
					$parts[] = null === $f ? "$code none" : "$code $f";
				}

				$lines[] = sprintf( '  #%d monthly figure: %s', $vid, implode( ', ', $parts ) );
			}
		}

		$lines[] = sprintf( '%d live resources checked in %d currencies.', count( self::resources( $scope ) ), count( Currency::known_codes() ) );

		return $lines;
	}

	/**
	 * Saves the manifest (no autoload).
	 *
	 * @param array<string, mixed> $manifest Manifest.
	 */
	private static function save( array $manifest ): void {
		update_option( self::OPTION, $manifest, false );
	}

	/**
	 * Recounts a parent's price range and clears its caches.
	 *
	 * @param int $pid Parent id.
	 */
	private static function resync( int $pid ): void {
		if ( $pid > 0 && class_exists( '\WC_Product_Variable' ) ) {
			\WC_Product_Variable::sync( $pid );
			wc_delete_product_transients( $pid );
		}
	}

	/**
	 * Purges the page cache when the site has one.
	 */
	private static function purge(): void {
		if ( function_exists( 'beltoft_cache_purge_all' ) ) {
			beltoft_cache_purge_all();
		}
	}
}
