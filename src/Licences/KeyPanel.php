<?php
/** What My Account and the admin screen show of a licence key. @package BeltoftServices */

namespace BeltoftServices\Licences;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The account part shows the licence to its owner only, with the key once it is there. The admin part shows the vendor
 * ids and the state, never the key.
 */
final class KeyPanel {

	/**
	 * What the customer calls the licence.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function label( Resource $r ): string {
		$plan = (array) $r->get( 'plan' );

		if ( '' !== (string) ( $plan['lic_label'] ?? '' ) ) {
			return (string) $plan['lic_label'];
		}

		$key   = KeyOrder::key_of( $r );
		$names = KeyCatalog::keys_of( KeyCatalog::FAMILY_PLESK );

		if ( isset( $names[ $key ] ) ) {
			return 'Plesk ' . $names[ $key ];
		}

		return KeyCatalog::ADDONS[ $key ][1] ?? ucfirst( $key );
	}

	/**
	 * A customer word for the state.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function status_text( Resource $r ): string {
		if ( KeyOrder::ended( $r ) ) {
			return __( 'Ended', 'beltoft-services' );
		}

		return 'ready' === (string) $r->get( 'lic_state' ) && '' !== (string) $r->get( 'lic_key' ) ? __( 'Active', 'beltoft-services' ) : __( 'Being prepared', 'beltoft-services' );
	}

	/**
	 * The account part.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function account( Resource $r ): void {
		if ( (int) ( $r->license()['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}

		$key      = KeyOrder::key_of( $r );
		$edition  = KeyCatalog::is_edition( $key );
		$ended    = KeyOrder::ended( $r );
		$text     = $ended ? '' : KeyOrder::key_text( $r );
		$physical = KeyCatalog::is_physical( $key );

		echo '<div class="beltoft-services-lickey">';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		self::row( __( 'Licence', 'beltoft-services' ), self::label( $r ) );
		self::row( __( 'Server address', 'beltoft-services' ), (string) $r->get( 'lic_ip' ) );

		if ( $edition ) {
			self::row( __( 'Valid on', 'beltoft-services' ), $physical ? __( 'A physical server', 'beltoft-services' ) : __( 'A virtual server (VPS)', 'beltoft-services' ) );
		}

		self::row( __( 'Status', 'beltoft-services' ), self::status_text( $r ) );
		echo '</tbody></table>';

		if ( $ended ) {
			echo '<p>' . esc_html__( 'This licence has ended. The key no longer works.', 'beltoft-services' ) . '</p>';
		} elseif ( '' === $text ) {
			echo '<p>' . esc_html__( 'Your licence key is being prepared. It appears here as soon as it is ready.', 'beltoft-services' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Licence key', 'beltoft-services' ) . '</h3>';
			echo '<p>' . esc_html( self::how( $r, $edition ) ) . '</p>';
			echo '<p><textarea readonly rows="6" style="width:100%;font-family:monospace" onclick="this.select()" aria-label="' . esc_attr__( 'Licence key', 'beltoft-services' ) . '">' . esc_textarea( $text ) . '</textarea></p>';
			echo '<p>' . esc_html__( 'The licence works for the server address above. To use it on another address, order a new licence.', 'beltoft-services' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * How the key is used.
	 *
	 * @param Resource $r       Licence resource.
	 * @param bool     $edition Whether it is a Plesk edition.
	 */
	private static function how( Resource $r, bool $edition ): string {
		if ( 'file' === (string) $r->get( 'lic_key_kind' ) ) {
			return $edition
				? __( 'Save this key as a file on your server and run: plesk bin license --install followed by the file name. You can also upload it in Plesk under Tools and Settings, License Management.', 'beltoft-services' )
				: __( 'Save this key as a file and upload it in Plesk under Tools and Settings, License Management.', 'beltoft-services' );
		}

		return $edition
			? __( 'Enter this activation code in Plesk under Tools and Settings, License Management.', 'beltoft-services' )
			: __( 'Enter this key on your server. For a Plesk add-on, open Tools and Settings, License Management in Plesk and add it there.', 'beltoft-services' );
	}

	/**
	 * One table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * The admin part: ids and state, never the key.
	 *
	 * @param Resource $r Licence resource.
	 */
	public static function admin( Resource $r ): void {
		$id   = (int) $r->get( 'lic_key_id' );
		$rows = array(
			__( 'Licence', 'beltoft-services' )               => self::label( $r ),
			__( 'Catalogue key', 'beltoft-services' )         => KeyOrder::key_of( $r ),
			__( 'Server address', 'beltoft-services' )        => (string) $r->get( 'lic_ip' ),
			__( 'Openprovider licence id', 'beltoft-services' ) => $id > 0 ? (string) $id : ( (int) $r->get( 'lic_deleted' ) > 0 ? __( 'deleted', 'beltoft-services' ) : __( 'not ordered', 'beltoft-services' ) ),
			__( 'Openprovider item', 'beltoft-services' )     => (string) $r->get( 'lic_item' ),
			__( 'Parent Plesk licence id', 'beltoft-services' ) => (string) (int) $r->get( 'lic_parent' ),
			__( 'Key', 'beltoft-services' )                   => '' === (string) $r->get( 'lic_key' ) ? __( 'not delivered yet', 'beltoft-services' ) : sprintf( /* translators: %s: file or code. */ __( 'kept (%s)', 'beltoft-services' ), (string) $r->get( 'lic_key_kind' ) ),
			__( 'Delivery tries', 'beltoft-services' )        => (string) (int) $r->get( 'lic_tries' ),
			__( 'Fields the vendor answered', 'beltoft-services' ) => (string) $r->get( 'lic_shape' ),
		);

		echo '<h3>' . esc_html__( 'Licence key', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
