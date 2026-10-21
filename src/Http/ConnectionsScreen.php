<?php
/**
 * Settings → Services → Connections.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Http;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Connections tab: a list, and a form to add or edit one. It plugs into the tab and page
 * plumbing of Settings (the `beltoft_services_settings_tabs` filter and the
 * `beltoft_services_settings_tab_connections` action) and saves through admin-post.
 */
final class ConnectionsScreen {

	public const TAB = 'connections';

	/**
	 * Hooks the tab and its handlers.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_settings_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_save_connection', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_beltoft_services_delete_connection', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param mixed $tabs Tabs so far.
	 * @return mixed
	 */
	public static function add_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = __( 'Connections', 'beltoft-services' );
		}

		return $tabs;
	}

	/**
	 * The tab's address.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 */
	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Settings::PAGE, 'tab' => self::TAB ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Stops anyone without the capability.
	 */
	private static function authorise( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Saves the posted connection and goes back to the tab.
	 */
	public static function handle_save(): void {
		self::authorise( 'beltoft_services_save_connection' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in authorise(); every value is cleaned in Connections::save().
		$id   = sanitize_key( (string) ( $post['connection_id'] ?? '' ) );
		$new  = '' === $id;

		if ( ! $new && ! isset( Connections::all()[ $id ] ) ) {
			self::flash( 'error', __( 'That connection no longer exists. It may have been deleted in another tab.', 'beltoft-services' ) );
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( $new ) {
			$id = sanitize_key( (string) ( $post['new_id'] ?? '' ) );

			if ( '' === $id ) {
				$label = (string) ( $post['label'] ?? '' );
				$slug  = sanitize_title( $label );

				// A label with no plain letters or numbers gives an encoded slug: use a short hash instead.
				$id = ( '' === $slug || false !== strpos( $slug, '%' ) ) ? 'connection-' . substr( md5( $label ), 0, 8 ) : sanitize_key( $slug );
			}

			if ( isset( Connections::all()[ $id ] ) ) {
				self::flash( 'error', __( 'A connection with that id already exists.', 'beltoft-services' ) );
				wp_safe_redirect( self::url( array( 'action' => 'new' ) ) );
				exit;
			}
		}

		$result = Connections::save( $id, is_array( $post ) ? $post : array() );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::url( $new ? array( 'action' => 'new' ) : array( 'edit' => $id ) ) );
			exit;
		}

		$warnings = Connections::warnings( (string) Connections::all()[ $id ]['base_url'] );

		if ( array() === $warnings ) {
			self::flash( 'success', __( 'Connection saved.', 'beltoft-services' ) );
		} else {
			self::flash( 'warning', __( 'Connection saved.', 'beltoft-services' ) . ' ' . implode( ' ', $warnings ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Deletes a connection and goes back to the tab.
	 */
	public static function handle_delete(): void {
		self::authorise( 'beltoft_services_delete_connection' );

		$id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorise().

		$products = Connections::in_use( $id );

		if ( $products > 0 ) {
			self::flash(
				'error',
				sprintf(
					/* translators: %d: number of products. */
					_n( 'This connection is used by %d product. Choose another connection on that product first.', 'This connection is used by %d products. Choose another connection on those products first.', $products, 'beltoft-services' ),
					$products
				)
			);
		} elseif ( Connections::delete( $id ) ) {
			self::flash( 'success', __( 'Connection deleted.', 'beltoft-services' ) );
		} else {
			self::flash( 'error', __( 'The connection could not be deleted. It may be gone already.', 'beltoft-services' ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Keeps a message for the next page load of this user.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message Message.
	 */
	private static function flash( string $type, string $message ): void {
		set_transient( 'beltoft_services_conn_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	/**
	 * Prints and clears the message.
	 */
	private static function show_flash(): void {
		$key   = 'beltoft_services_conn_notice_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) ) {
			return;
		}

		delete_transient( $key );
		$type = in_array( $flash[0], array( 'error', 'warning' ), true ) ? $flash[0] : 'success';

		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $flash[1] ) );
	}

	/**
	 * The tab: the list, or the form.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::show_flash();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$edit   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$adding = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) );
		// phpcs:enable

		if ( $adding || '' !== $edit ) {
			self::render_form( $edit );
			return;
		}

		self::render_list();
	}

	/**
	 * The list of connections.
	 */
	private static function render_list(): void {
		$all = Connections::all();

		printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( self::url( array( 'action' => 'new' ) ) ), esc_html__( 'Add connection', 'beltoft-services' ) );

		if ( array() === $all ) {
			echo '<p>' . esc_html__( 'No connections yet.', 'beltoft-services' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Id', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Base URL', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Authentication', 'beltoft-services' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $all as $id => $connection ) {
			echo '<tr>';
			printf(
				'<td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td>',
				esc_html( $connection['label'] ),
				esc_html( $id ),
				esc_html( $connection['base_url'] ),
				esc_html( 'hmac' === $connection['auth'] ? 'HMAC' : __( 'Static header', 'beltoft-services' ) )
			);
			echo '<td><a href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'beltoft-services' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this connection?', 'beltoft-services' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="beltoft_services_delete_connection"><input type="hidden" name="connection_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'beltoft_services_delete_connection' );
			echo '<button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete', 'beltoft-services' ) . '</button></form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One text row of the form.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $desc  Help text.
	 */
	private static function row( string $name, string $label, string $value, string $desc = '' ): void {
		printf(
			'<tr><th scope="row"><label for="bs_%1$s">%2$s</label></th><td><input type="text" id="bs_%1$s" name="%1$s" value="%3$s" class="regular-text">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			'' === $desc ? '' : '<p class="description">' . esc_html( $desc ) . '</p>'
		);
	}

	/**
	 * The add or edit form. The saved secret is never printed.
	 *
	 * @param string $id Connection id, empty to add.
	 */
	private static function render_form( string $id ): void {
		$saved = '' === $id ? null : Connections::get( $id );

		if ( '' !== $id && null === $saved ) {
			echo '<p>' . esc_html__( 'That connection does not exist.', 'beltoft-services' ) . '</p>';
			return;
		}

		$c = $saved ? $saved : Connections::defaults();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="beltoft_services_save_connection"><input type="hidden" name="connection_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'beltoft_services_save_connection' );
		echo '<table class="form-table" role="presentation">';

		if ( '' === $id ) {
			self::row( 'new_id', __( 'Id', 'beltoft-services' ), '', __( 'Letters, numbers, dashes and underscores. Left empty, it is made from the label. It cannot be changed later.', 'beltoft-services' ) );
		}

		self::row( 'label', __( 'Label', 'beltoft-services' ), $c['label'] );
		self::row( 'base_url', __( 'Base URL', 'beltoft-services' ), $c['base_url'], __( 'https, unless the host is a private or loopback IP address (such as 10.0.0.2).', 'beltoft-services' ) );

		echo '<tr><th scope="row"><label for="bs_auth">' . esc_html__( 'Authentication', 'beltoft-services' ) . '</label></th><td><select id="bs_auth" name="auth">';
		printf( '<option value="hmac" %1$s>HMAC</option><option value="header" %2$s>%3$s</option>', selected( $c['auth'], 'hmac', false ), selected( $c['auth'], 'header', false ), esc_html__( 'Static header', 'beltoft-services' ) );
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row"><label for="bs_secret">%1$s</label></th><td><input type="password" id="bs_secret" name="secret" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"><p class="description">%3$s</p>',
			esc_html__( 'Secret', 'beltoft-services' ),
			esc_attr( '' !== (string) $c['secret'] || ( '' !== $id && Connections::has_secret( $id ) ) ? __( 'Saved', 'beltoft-services' ) : '' ),
			esc_html__( 'Leave empty to keep the saved secret.', 'beltoft-services' )
		);

		echo '</td></tr>';

		self::row( 'header_timestamp', __( 'Timestamp header', 'beltoft-services' ), $c['header_timestamp'] );
		self::row( 'header_tenant', __( 'Tenant header', 'beltoft-services' ), $c['header_tenant'] );
		self::row( 'header_signature', __( 'Signature header', 'beltoft-services' ), $c['header_signature'] );
		self::row( 'header_static', __( 'Header name', 'beltoft-services' ), $c['header_static'], __( 'Static header authentication: the name of the header. The secret is sent as its value.', 'beltoft-services' ) );
		self::row( 'timeout', __( 'Timeout in seconds', 'beltoft-services' ), (string) $c['timeout'] );

		foreach ( Connections::ENDPOINTS as $name ) {
			printf( '<tr><th scope="row">%s</th><td><select name="endpoints[%s][method]">', esc_html( $name ), esc_attr( $name ) );
			foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
				printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $method ), selected( $c['endpoints'][ $name ]['method'], $method, false ) );
			}
			printf( '</select> <input type="text" name="endpoints[%1$s][path]" value="%2$s" class="regular-text code" placeholder="/path/{remote_id}"></td></tr>', esc_attr( $name ), esc_attr( $c['endpoints'][ $name ]['path'] ) );
		}

		printf(
			'<tr><th scope="row"><label for="bs_body_template">%1$s</label></th><td><textarea id="bs_body_template" name="body_template" rows="5" class="large-text code">%2$s</textarea><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Body template', 'beltoft-services' ),
			esc_textarea( $c['body_template'] ),
			esc_html( 'JSON. Placeholders: {resource_id} {remote_id} {license_key} {customer_id} {email} {limit} {plan}.' )
		);

		self::row( 'remote_id_field', __( 'Remote id field', 'beltoft-services' ), $c['remote_id_field'], __( 'Dot path in the provision reply.', 'beltoft-services' ) );
		self::row( 'usage_used_field', __( 'Usage: used field', 'beltoft-services' ), $c['usage_used_field'] );
		self::row( 'usage_limit_field', __( 'Usage: limit field', 'beltoft-services' ), $c['usage_limit_field'] );
		self::row( 'open_url', __( 'Open URL', 'beltoft-services' ), $c['open_url'], __( 'For services with their own front end. Use {token} where the token goes.', 'beltoft-services' ) );
		self::row( 'open_label', __( 'Open button label', 'beltoft-services' ), $c['open_label'] );

		echo '</table>';
		submit_button( __( 'Save connection', 'beltoft-services' ) );
		echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the list', 'beltoft-services' ) . '</a></form>';
	}
}
