<?php
/**
 * One domain or DNS hosting row, the same for the Overview and the Domains list.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Account;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Plain values for a row: the page address, the name, what kind of service it is, its state and renewal.
 * Reads the resource meta and the subscription only.
 */
final class DomainRows {

	/**
	 * A domain or DNS hosting row.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 * @return array<string, string>
	 */
	public static function row( array $view, Resource $resource ): array {
		$state = (string) $resource->get( 'state' );
		$group = Panel::group( $state, $resource );

		if ( 'ready' === $group && ! $resource->on_hold() ) {
			$tone = 'ok';
		} elseif ( 'getting-ready' === $group ) {
			$tone = 'busy';
		} else {
			$tone = 'bad';
		}

		return array(
			'id'     => (string) $resource->id(),
			'url'    => Domains::url( $view['license'] ),
			'name'   => (string) $view['product'],
			'sub'    => self::kind( $resource ),
			'state'  => Panel::label( $state, $resource ),
			'tone'   => $tone,
			'renews' => self::renews( $view, $resource, $group ),
		);
	}

	/**
	 * What the service is, in a few words.
	 *
	 * @param Resource $resource Resource.
	 */
	public static function kind( Resource $resource ): string {
		$dns_only = 'dns' === $resource->get( 'driver' );
		$external = ! $dns_only && (bool) $resource->get( 'dns_external' );
		$ours     = ! $external && ( $dns_only || 'custom' !== $resource->get( 'ns_mode' ) );

		if ( $dns_only ) {
			return __( 'DNS with us', 'beltoft-services' );
		}

		return $ours ? __( 'Registered and DNS with us', 'beltoft-services' ) : __( 'Registered with us', 'beltoft-services' );
	}

	/**
	 * The renewal line of a resource, from its own license.
	 *
	 * @param Resource $resource Resource.
	 */
	public static function renewal( Resource $resource ): string {
		return self::renews( array( 'license' => $resource->license() ), $resource, Panel::group( (string) $resource->get( 'state' ), $resource ) );
	}

	/**
	 * "Renews 12 Mar 2027" from the subscription, else "Expires ..." from the registration, else nothing.
	 *
	 * @param array<string, mixed> $view     License view.
	 * @param Resource             $resource Resource.
	 * @param string               $group    Panel group of the state.
	 */
	private static function renews( array $view, Resource $resource, string $group ): string {
		$renews = Servers::renewal( $view['license'] );

		if ( '' === $renews && 'ready' === $group ) {
			$expires = (string) $resource->get( 'expires_at' );
			$time    = '' !== $expires ? strtotime( $expires . ' UTC' ) : false;

			if ( $time ) {
				/* translators: %s: date the registration ends */
				$renews = sprintf( __( 'Expires %s', 'beltoft-services' ), wp_date( (string) get_option( 'date_format' ), $time ) );
			}
		}

		return $renews;
	}
}
