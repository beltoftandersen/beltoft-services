<?php
/**
 * Settings → Services → IP pool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The pool of addresses: add an address or a network, block or unblock one, release the address of
 * a failed provision, and release the address of a closed resource with a typed `RELEASE <ip>`
 * (the machine of a "Mark deleted" resource may still exist and answer on that address). IPv4 only.
 * Saves through admin-post, one nonce per action, as ClustersScreen does.
 */
final class IpPoolScreen {

	public const TAB = 'ip-pool';

	private const OPS = array( 'add', 'block', 'unblock', 'release' );

	/**
	 * Hooks the tab content and its handler.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_ip_pool', array( __CLASS__, 'handle' ) );
	}

	/**
	 * The tab's address.
	 */
	private static function url(): string {
		return add_query_arg( array( 'page' => Settings::PAGE, 'tab' => self::TAB ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Runs one action. No capability or nonce check here: the handler does both first.
	 *
	 * @param string               $op    add, block, unblock or release.
	 * @param array<string, mixed> $input address, gateway, confirm.
	 * @return array{0: string, 1: string} success or error, and the message.
	 */
	public static function apply( string $op, array $input ): array {
		$address = trim( (string) ( $input['address'] ?? '' ) );

		try {
			switch ( $op ) {
				case 'add':
					$added = IpPool::add( $address, trim( (string) ( $input['gateway'] ?? '' ) ) );

					/* translators: %d: number of addresses. */
					return $added > 0 ? array( 'success', sprintf( _n( '%d address added.', '%d addresses added.', $added, 'beltoft-services' ), $added ) ) : array( 'error', __( 'Nothing was added. Give an IPv4 address or a network of /20 or smaller (for example 192.0.2.8/29); addresses already in the pool are skipped.', 'beltoft-services' ) );

				case 'block':
					return IpPool::block( $address ) ? array( 'success', __( 'Address blocked.', 'beltoft-services' ) ) : array( 'error', __( 'Only a free address can be blocked.', 'beltoft-services' ) );

				case 'unblock':
					return IpPool::unblock( $address ) ? array( 'success', __( 'Address unblocked.', 'beltoft-services' ) ) : array( 'error', __( 'That address is not blocked.', 'beltoft-services' ) );

				case 'release':
					return self::release( $address, (string) ( $input['confirm'] ?? '' ) );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e );

			return array( 'error', __( 'The pool is busy. Please try again.', 'beltoft-services' ) );
		}

		return array( 'error', __( 'Unknown action.', 'beltoft-services' ) );
	}

	/**
	 * Releases a leftover address. A closed resource's address needs the typed words.
	 *
	 * @param string $address Address.
	 * @param string $confirm What the admin typed.
	 * @return array{0: string, 1: string}
	 */
	private static function release( string $address, string $confirm ): array {
		foreach ( IpPool::rows() as $row ) {
			if ( $row['address'] !== $address ) {
				continue;
			}

			if ( '' === $row['leftover'] ) {
				return array( 'error', __( 'That address is not a leftover of a failed or closed service.', 'beltoft-services' ) );
			}

			if ( 'closed' === $row['leftover'] && trim( $confirm ) !== 'RELEASE ' . $address ) {
				/* translators: %s: the words to type, such as RELEASE 192.0.2.5. */
				return array( 'error', sprintf( __( 'Type %s to confirm. The server of a closed service may still be running on this address.', 'beltoft-services' ), 'RELEASE ' . $address ) );
			}

			return '' !== IpPool::release_leftover( $address ) ? array( 'success', __( 'Address released.', 'beltoft-services' ) ) : array( 'error', __( 'The address could not be released.', 'beltoft-services' ) );
		}

		return array( 'error', __( 'That address is not in the pool.', 'beltoft-services' ) );
	}

	/**
	 * Admin-post handler: capability, nonce, then the action.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line.

		if ( ! in_array( $op, self::OPS, true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'beltoft-services' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'beltoft_services_ip_' . $op );

		$input = array();

		foreach ( array( 'address', 'gateway', 'confirm' ) as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		}

		$result = self::apply( $op, $input );

		set_transient( 'beltoft_services_ip_notice_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * A small form with one button.
	 *
	 * @param string               $op     Action.
	 * @param string               $label  Button text.
	 * @param array<string, string> $fields Hidden fields.
	 * @param string               $typed  Name of a text input to show, empty for none.
	 * @param string               $hint   Placeholder of that input.
	 */
	private static function mini_form( string $op, string $label, array $fields, string $typed = '', string $hint = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="beltoft_services_ip_pool"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';

		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}

		if ( '' !== $typed ) {
			echo '<input type="text" name="' . esc_attr( $typed ) . '" placeholder="' . esc_attr( $hint ) . '" autocomplete="off" class="regular-text"> ';
		}

		wp_nonce_field( 'beltoft_services_ip_' . $op );
		echo '<button type="submit" class="button-link">' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * The tab.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key   = 'beltoft_services_ip_notice_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( is_array( $flash ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( 'error' === $flash[0] ? 'error' : 'success' ), esc_html( (string) $flash[1] ) );
		}

		echo '<p>' . esc_html__( 'Addresses handed to new virtual machines. IPv4 only.', 'beltoft-services' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="beltoft_services_ip_pool"><input type="hidden" name="op" value="add">';
		wp_nonce_field( 'beltoft_services_ip_add' );
		echo '<input type="text" name="address" placeholder="' . esc_attr__( '192.0.2.10 or 192.0.2.8/29', 'beltoft-services' ) . '" class="regular-text"> ';
		echo '<input type="text" name="gateway" placeholder="' . esc_attr__( 'Gateway (optional)', 'beltoft-services' ) . '" class="regular-text"> ';
		submit_button( __( 'Add', 'beltoft-services' ), 'secondary', 'submit', false );
		echo '</form>';

		$rows = IpPool::rows();

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'The pool is empty.', 'beltoft-services' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Address', 'beltoft-services' ) . '</th><th>' . esc_html__( 'State', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Service', 'beltoft-services' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$states = array(
				'free'     => __( 'Free', 'beltoft-services' ),
				'held'     => __( 'Held', 'beltoft-services' ),
				'assigned' => __( 'In use', 'beltoft-services' ),
				'blocked'  => __( 'Blocked', 'beltoft-services' ),
			);

			echo '<tr><td><code>' . esc_html( $row['address'] ) . '</code></td><td>' . esc_html( $states[ $row['state'] ] ?? $row['state'] );

			if ( 'failed' === $row['leftover'] ) {
				echo ' (' . esc_html__( 'failed service', 'beltoft-services' ) . ')';
			} elseif ( 'closed' === $row['leftover'] ) {
				echo ' (' . esc_html__( 'closed service', 'beltoft-services' ) . ')';
			}

			echo '</td><td>' . esc_html( null === $row['resource_id'] ? '' : '#' . $row['resource_id'] ) . '</td><td>';

			if ( 'free' === $row['state'] ) {
				self::mini_form( 'block', __( 'Block', 'beltoft-services' ), array( 'address' => $row['address'] ) );
			} elseif ( 'blocked' === $row['state'] ) {
				self::mini_form( 'unblock', __( 'Unblock', 'beltoft-services' ), array( 'address' => $row['address'] ) );
			} elseif ( 'failed' === $row['leftover'] ) {
				self::mini_form( 'release', __( 'Release', 'beltoft-services' ), array( 'address' => $row['address'] ) );
			} elseif ( 'closed' === $row['leftover'] ) {
				/* translators: %s: the words to type. */
				self::mini_form( 'release', __( 'Release', 'beltoft-services' ), array( 'address' => $row['address'] ), 'confirm', sprintf( __( 'Type %s', 'beltoft-services' ), 'RELEASE ' . $row['address'] ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
