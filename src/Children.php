<?php
/**
 * Child resources: a service that belongs to another service.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * A child resource follows its parent: when the parent is paused the children are paused, when the parent runs
 * again the children the parent paused run again, and when the parent is deleted the children are deleted. A
 * child has a license of its own (a resource needs one) but no subscription: the parent's license governs it, so
 * the license events of the child are not acted on (the child license has no service product).
 *
 * The link is the child's meta `parent_resource` (the parent's activation id) and the parent's meta `children`
 * (a list of child ids). The cascade runs on `beltoft_services_state_changed`, that is after the parent really
 * reached the state, and never touches a child that was paused for another reason: the child's meta
 * `paused_by_parent` says it was this cascade.
 */
final class Children {

	public const PARENT   = 'parent_resource';
	public const CHILDREN = 'children';
	public const MARKER   = 'paused_by_parent';

	/**
	 * Hooks the cascade.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_state_changed', array( __CLASS__, 'follow' ), 10, 3 );
	}

	/**
	 * Links a child to its parent.
	 *
	 * @param Resource $parent Parent.
	 * @param Resource $child  Child.
	 * @return bool True when both links are stored.
	 */
	public static function attach( Resource $parent, Resource $child ): bool {
		$fresh = Resource::find( $parent->id() ) ?? $parent;
		$ids   = self::ids( $fresh );

		if ( ! in_array( $child->id(), $ids, true ) ) {
			$ids[] = $child->id();
		}

		return $child->set( array( self::PARENT => $parent->id() ) ) && $fresh->set( array( self::CHILDREN => $ids ) );
	}

	/**
	 * The child ids of a parent.
	 *
	 * @param Resource $parent Parent.
	 * @return int[]
	 */
	public static function ids( Resource $parent ): array {
		return array_values( array_unique( array_map( 'intval', (array) $parent->get( self::CHILDREN ) ) ) );
	}

	/**
	 * The live children of a parent, whatever their state.
	 *
	 * @param Resource $parent Parent.
	 * @return Resource[]
	 */
	public static function of( Resource $parent ): array {
		$out = array();

		foreach ( self::ids( $parent ) as $id ) {
			$child = Resource::find( $id );

			if ( $child && $child->is_valid() && (int) $child->get( self::PARENT ) === $parent->id() ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * The parent of a child, or null.
	 *
	 * @param Resource $child Child.
	 */
	public static function parent_of( Resource $child ): ?Resource {
		$id = (int) $child->get( self::PARENT );

		return $id > 0 ? Resource::find( $id ) : null;
	}

	/**
	 * Action beltoft_services_state_changed: the parent reached a state, the children follow.
	 *
	 * @param mixed $r   Resource.
	 * @param mixed $old Previous state.
	 * @param mixed $new New state.
	 */
	public static function follow( $r, $old, $new ): void {
		if ( ! $r instanceof Resource ) {
			return;
		}

		unset( $old );

		foreach ( self::of( $r ) as $child ) {
			if ( 'deleted' === $child->get( 'state' ) ) {
				continue;
			}

			if ( 'paused' === $new ) {
				if ( 'paused' !== $child->get( 'desired' ) && $child->set( array( self::MARKER => 1 ) ) ) {
					self::desire( $child, 'paused', 'The parent service was paused' );
				}
			} elseif ( 'ready' === $new ) {
				if ( $child->get( self::MARKER ) && 'deleted' !== $child->get( 'desired' ) ) {
					$child->set( array( self::MARKER => null ) );
					self::desire( $child, 'ready', 'The parent service runs again' );
				}
			} elseif ( 'deleted' === $new && 'deleted' !== $child->get( 'desired' ) ) {
				self::desire( $child, 'deleted', 'The parent service was deleted' );
			}
		}
	}

	/**
	 * Sets a child's desired state and queues its job.
	 *
	 * @param Resource $child  Child.
	 * @param string   $state  ready, paused or deleted.
	 * @param string   $reason History text.
	 */
	private static function desire( Resource $child, string $state, string $reason ): void {
		if ( $child->desire( $state, $reason ) ) {
			Jobs::enqueue( $child->id() );
		}
	}
}
