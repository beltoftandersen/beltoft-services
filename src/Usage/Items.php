<?php
/**
 * The words of a ledger item for a counted unit, in one place.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\UsageItems;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A counted item is billed per unit, not per hour, and has no monthly cap. Its ledger item is a base name and, for the second step
 * of a price in steps and later, an underscore and the step number (`order`, `order_2`, `order_3`). The base names and their words
 * are here and can be added to through the filter `beltoft_services_usage_item_defs`; the hourly items (server, database, ...)
 * are declared by the drivers (Drivers\UsageItems), read here.
 */
final class Items {

	/**
	 * What a ledger item is, or null for an hourly item.
	 *
	 * @param string $item Ledger item.
	 * @return array{base: string, step: int, label: string, singular: string, plural: string}|null
	 */
	public static function counted( string $item ): ?array {
		$base = (string) preg_replace( '/_\d+$/', '', $item );
		$step = 1 === preg_match( '/_(\d+)$/', $item, $m ) ? (int) $m[1] : 1;
		$defs = array(
			'order' => array(
				'label'    => __( 'Orders processed', 'beltoft-services' ),
				'singular' => __( 'order', 'beltoft-services' ),
				'plural'   => __( 'orders', 'beltoft-services' ),
			),
		);

		/**
		 * Filters the counted items: base name => label (the line title), singular and plural (the unit).
		 *
		 * @param array<string, array<string, string>> $defs Definitions.
		 */
		$defs = apply_filters( 'beltoft_services_usage_item_defs', $defs );

		if ( ! is_array( $defs[ $base ] ?? null ) ) {
			return null;
		}

		return array(
			'base'     => $base,
			'step'     => $step,
			'label'    => (string) ( $defs[ $base ]['label'] ?? $base ),
			'singular' => (string) ( $defs[ $base ]['singular'] ?? $base ),
			'plural'   => (string) ( $defs[ $base ]['plural'] ?? $base ),
		);
	}

	/**
	 * The line title of a counted item: "Orders processed" and, from the second step, "Orders processed, step 2".
	 *
	 * @param array{step: int, label: string} $def Definition.
	 */
	public static function title( array $def ): string {
		/* translators: 1: what is counted ("Orders processed"), 2: price step number. */
		return $def['step'] > 1 ? sprintf( __( '%1$s, step %2$d', 'beltoft-services' ), $def['label'], $def['step'] ) : $def['label'];
	}

	/**
	 * "1 order", "380 orders".
	 *
	 * @param array{singular: string, plural: string} $def   Definition.
	 * @param float                                   $units Units.
	 */
	public static function units_text( array $def, float $units ): string {
		$n = (int) round( $units );

		/* translators: 1: number, 2: the unit word, such as "order" or "orders". */
		return sprintf( __( '%1$s %2$s', 'beltoft-services' ), number_format_i18n( $n ), 1 === $n ? $def['singular'] : $def['plural'] );
	}

	/**
	 * What the lookups share per request: the drivers, the words of every hourly item and the delete descriptions. Built once, and
	 * again when the registered drivers change (the filter `beltoft_services_drivers` got or lost a callback) or the language does.
	 *
	 * @var array{sig: string, drivers: array<string, UsageItems>, words: array<string, array<string, mixed>>, delete: array<string, array<string, mixed>>}|null
	 */
	private static ?array $memo = null;

	/**
	 * Forgets what the lookups built (a test that changes drivers or words inside one request).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * What would make the memo stale: the language and the callbacks of the drivers filter.
	 */
	private static function signature(): string {
		global $wp_filter;

		$hook = $wp_filter['beltoft_services_drivers'] ?? null;
		$keys = array();

		foreach ( $hook instanceof \WP_Hook ? $hook->callbacks : array() as $priority => $callbacks ) {
			$keys[ $priority ] = array_keys( $callbacks );
		}

		return get_locale() . '|' . md5( (string) wp_json_encode( $keys ) );
	}

	/**
	 * The memo, built when missing or stale.
	 *
	 * @return array{sig: string, drivers: array<string, UsageItems>, words: array<string, array<string, mixed>>, delete: array<string, array<string, mixed>>}
	 */
	private static function memo(): array {
		$sig = self::signature();

		if ( null !== self::$memo && self::$memo['sig'] === $sig ) {
			return self::$memo;
		}

		$memo = array( 'sig' => $sig, 'drivers' => array(), 'words' => array(), 'delete' => array() );

		foreach ( Registry::all() as $id => $driver ) {
			if ( $driver instanceof UsageItems ) {
				$memo['drivers'][ (string) $id ] = $driver;
			}
		}

		foreach ( $memo['drivers'] as $driver ) {
			foreach ( $driver->usage_words() as $item => $words ) {
				$memo['words'][ (string) $item ] ??= $words;
			}

			$spec = $driver->usage_delete();

			if ( null !== $spec ) {
				$memo['delete'][ (string) $spec['kind'] ] ??= $spec;
			}
		}

		self::$memo = $memo;

		return $memo;
	}

	/**
	 * The drivers the usage engine bills: every registered driver that implements UsageItems. The one registration point: a new pay as
	 * you go product needs a pay as you go variation (meta `_beltoft_services_billing`) and a driver that implements UsageItems.
	 *
	 * @return array<string, UsageItems> Driver id => driver.
	 */
	public static function drivers(): array {
		return self::memo()['drivers'];
	}

	/**
	 * The driver of a resource when the usage engine bills it.
	 *
	 * @param Resource $r Resource.
	 */
	public static function driver_of( Resource $r ): ?UsageItems {
		$driver = Registry::get( (string) $r->get( 'driver' ) );

		return $driver instanceof UsageItems ? $driver : null;
	}

	/**
	 * The words of an hourly item, declared by the drivers that bill it: label, word, plan, subject.
	 *
	 * @param string $item Item.
	 * @return array<string, mixed>
	 */
	public static function hourly( string $item ): array {
		return self::memo()['words'][ $item ] ?? array();
	}

	/**
	 * One field of an hourly item's words, the item name itself when it has none.
	 *
	 * @param string $item  Item.
	 * @param string $field label or word.
	 */
	public static function words( string $item, string $field ): string {
		return (string) ( self::hourly( $item )[ $field ] ?? $item );
	}

	/**
	 * The delete description (UsageItems::usage_delete()) of a resource's driver, or null.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	public static function delete_of( Resource $r ): ?array {
		$driver = self::driver_of( $r );

		return null === $driver ? null : $driver->usage_delete();
	}

	/**
	 * The delete description for a kind word (what the pages and tools pass around), or null.
	 *
	 * @param string $kind Kind word.
	 * @return array<string, mixed>|null
	 */
	public static function delete_for( string $kind ): ?array {
		return self::memo()['delete'][ $kind ] ?? null;
	}

	/**
	 * Is the item titled with its product and plan on an invoice (a server, a database)?
	 *
	 * @param string $item Item.
	 */
	public static function is_plan( string $item ): bool {
		return ! empty( self::hourly( $item )['plan'] );
	}
}
