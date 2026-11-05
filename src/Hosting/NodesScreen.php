<?php
/**
 * Settings, Services, Hosting nodes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The nodes tab: a list, and a form to add or edit one. It plugs into the tab plumbing of Settings (the action
 * `beltoft_services_settings_tab_hosting-nodes`) and saves through admin-post, as ClustersScreen does. Saved
 * secrets are never printed.
 */
final class NodesScreen {

	public const TAB = 'hosting-nodes';

	/**
	 * Text fields of the form: key => [label, help].
	 */
	private static function text_fields(): array {
		return array(
			'label'        => array( __( 'Label', 'beltoft-services' ), '' ),
			'node_group'   => array( __( 'Node group', 'beltoft-services' ), __( 'Plans name the group they are built on.', 'beltoft-services' ) ),
			'api_url'      => array( __( 'Node API address', 'beltoft-services' ), __( 'https only, with the port and no path.', 'beltoft-services' ) ),
			'token_id'     => array( __( 'API token id', 'beltoft-services' ), __( 'Looks like user@realm!tokenname.', 'beltoft-services' ) ),
			'pve_node'     => array( __( 'Node name', 'beltoft-services' ), __( 'The Proxmox name of the node.', 'beltoft-services' ) ),
			'agent_url'    => array( __( 'Agent address', 'beltoft-services' ), __( 'https only, with the port and no path.', 'beltoft-services' ) ),
			'agent_key_id' => array( __( 'Agent key id', 'beltoft-services' ), '' ),
			'ca_file'      => array( __( 'CA file', 'beltoft-services' ), __( 'Optional absolute path to a certificate authority file.', 'beltoft-services' ) ),
			'pin_sha256'   => array( __( 'Node public key pin', 'beltoft-services' ), __( 'Optional: base64 of the SHA-256 hash.', 'beltoft-services' ) ),
			'agent_pin'    => array( __( 'Agent public key pin', 'beltoft-services' ), __( 'Optional: base64 of the SHA-256 hash.', 'beltoft-services' ) ),
			'pool'         => array( __( 'Pool', 'beltoft-services' ), '' ),
			'storage'      => array( __( 'Storage', 'beltoft-services' ), '' ),
			'bridge'       => array( __( 'Container bridge', 'beltoft-services' ), '' ),
			'gateway'      => array( __( 'Gateway', 'beltoft-services' ), __( 'IPv4 address.', 'beltoft-services' ) ),
			'lan_cidr'     => array( __( 'Container network', 'beltoft-services' ), __( 'For example 10.200.0.0/16.', 'beltoft-services' ) ),
			'ip_first'     => array( __( 'First host number', 'beltoft-services' ), __( 'The lower addresses stay free for the gateway, edge and database server.', 'beltoft-services' ) ),
			'ssh_port_min' => array( __( 'First SSH port', 'beltoft-services' ), '' ),
			'ssh_port_max' => array( __( 'Last SSH port', 'beltoft-services' ), '' ),
			'vmid_min'     => array( __( 'First customer container id', 'beltoft-services' ), '' ),
			'vmid_max'     => array( __( 'Last customer container id', 'beltoft-services' ), '' ),
			'template'     => array( __( 'Template container id', 'beltoft-services' ), __( 'Outside the customer range.', 'beltoft-services' ) ),
			'net_mbit'     => array( __( 'Network speed limit, Mbit/s', 'beltoft-services' ), __( '0 means no limit.', 'beltoft-services' ) ),
			'mem_gb'       => array( __( 'Memory of the node, GB', 'beltoft-services' ), __( 'What the node has. Capacity is counted against it.', 'beltoft-services' ) ),
			'disk_gb'      => array( __( 'Disk of the node, GB', 'beltoft-services' ), __( 'Real disk of the container storage.', 'beltoft-services' ) ),
			'backup_storage' => array( __( 'Backup storage', 'beltoft-services' ), __( 'Storage id for container backups. Empty: no backups on this node.', 'beltoft-services' ) ),
			'edge_ip'      => array( __( 'Edge address (IPv4)', 'beltoft-services' ), __( 'The public address names point to. Needed for the DNS step.', 'beltoft-services' ) ),
			'edge_ipv6'    => array( __( 'Edge address (IPv6)', 'beltoft-services' ), __( 'Optional.', 'beltoft-services' ) ),
			'db_host'      => array( __( 'Shared database server address', 'beltoft-services' ), __( 'IPv4 address.', 'beltoft-services' ) ),
		);
	}

	/**
	 * Hooks the tab content and its handlers. The tab itself is added by HostingSettings.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_save_hosting_node', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_beltoft_services_delete_hosting_node', array( __CLASS__, 'handle_delete' ) );
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
	 * Stops anyone without the capability or a valid nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorise( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Saves the posted node and goes back to the tab.
	 */
	public static function handle_save(): void {
		self::authorise( 'beltoft_services_save_hosting_node' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in authorise(); every value is cleaned in Nodes::save().
		$post = is_array( $post ) ? $post : array();
		$id   = (string) ( $post['node_id'] ?? '' );
		$new  = '' === $id;

		if ( $new ) {
			$id = (string) ( $post['new_id'] ?? '' );

			if ( isset( Nodes::all()[ $id ] ) ) {
				self::flash( 'error', __( 'A node with that id already exists.', 'beltoft-services' ) );
				wp_safe_redirect( self::url( array( 'action' => 'new' ) ) );
				exit;
			}
		} elseif ( ! isset( Nodes::all()[ $id ] ) ) {
			self::flash( 'error', __( 'That node no longer exists. It may have been deleted in another tab.', 'beltoft-services' ) );
			wp_safe_redirect( self::url() );
			exit;
		}

		// An unchecked box is absent from a post: the form says it with a hidden zero.
		$result = Nodes::save( $id, $post );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::url( $new ? array( 'action' => 'new' ) : array( 'edit' => $id ) ) );
			exit;
		}

		self::flash( 'success', __( 'Node saved.', 'beltoft-services' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Deletes a node and goes back to the tab.
	 */
	public static function handle_delete(): void {
		self::authorise( 'beltoft_services_delete_hosting_node' );

		$id = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorise().

		if ( Nodes::in_use( $id ) > 0 ) {
			self::flash( 'error', __( 'Hosting accounts still use this node, so it cannot be deleted.', 'beltoft-services' ) );
		} elseif ( Nodes::delete( $id ) ) {
			self::flash( 'success', __( 'Node deleted.', 'beltoft-services' ) );
		} else {
			self::flash( 'error', __( 'The node could not be deleted. It may be gone already.', 'beltoft-services' ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Keeps a message for the next page load of this user.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 */
	private static function flash( string $type, string $message ): void {
		set_transient( 'beltoft_services_hosting_node_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	/**
	 * Prints and clears the message.
	 */
	private static function show_flash(): void {
		$key   = 'beltoft_services_hosting_node_notice_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( ! is_array( $flash ) ) {
			return;
		}

		delete_transient( $key );

		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( 'error' === $flash[0] ? 'error' : 'success' ), esc_html( (string) $flash[1] ) );
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
		$edit   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$adding = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) );
		// phpcs:enable

		if ( ! Clients::live_allowed() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Live hosting calls are switched off in wp-config.php. Nodes use the fake clients.', 'beltoft-services' ) . '</p></div>';
		}

		if ( $adding || '' !== $edit ) {
			self::render_form( $edit );
			return;
		}

		self::render_list();
	}

	/**
	 * The list of nodes.
	 */
	private static function render_list(): void {
		$all = Nodes::all();

		printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( self::url( array( 'action' => 'new' ) ) ), esc_html__( 'Add node', 'beltoft-services' ) );

		if ( array() === $all ) {
			echo '<p>' . esc_html__( 'No nodes yet.', 'beltoft-services' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Id', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Group', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Accounts', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Client', 'beltoft-services' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $all as $id => $node ) {
			$id = (string) $id;

			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$d</td><td>%5$s</td>',
				esc_html( (string) $node['label'] ),
				esc_html( $id ),
				esc_html( (string) $node['node_group'] . ( $node['enabled'] ? '' : ' (' . __( 'off', 'beltoft-services' ) . ')' ) ),
				(int) Nodes::in_use( $id ),
				esc_html( 'live' === $node['client'] ? __( 'Live', 'beltoft-services' ) : __( 'Fake', 'beltoft-services' ) )
			);
			echo '<td><a href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'beltoft-services' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this node?', 'beltoft-services' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="beltoft_services_delete_hosting_node"><input type="hidden" name="node_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'beltoft_services_delete_hosting_node' );
			echo '<button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete', 'beltoft-services' ) . '</button></form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One input row of the form.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $desc  Help text.
	 */
	private static function row( string $name, string $label, string $value, string $desc = '' ): void {
		printf(
			'<tr><th scope="row"><label for="bs_%1$s">%2$s</label></th><td><input type="text" id="bs_%1$s" name="%1$s" value="%3$s" class="regular-text" autocomplete="off">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			'' === $desc ? '' : '<p class="description">' . esc_html( $desc ) . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
		);
	}

	/**
	 * A password row: the saved secret is never printed.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param bool   $saved Whether a secret is saved.
	 */
	private static function secret_row( string $name, string $label, bool $saved ): void {
		printf(
			'<tr><th scope="row"><label for="bs_%1$s">%2$s</label></th><td><input type="password" id="bs_%1$s" name="%1$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s"><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $saved ? __( 'Saved', 'beltoft-services' ) : '' ),
			esc_html__( 'At least 16 characters. Leave empty to keep the saved secret.', 'beltoft-services' )
		);
	}

	/**
	 * The add or edit form.
	 *
	 * @param string $id Node id, empty to add.
	 */
	private static function render_form( string $id ): void {
		$saved = '' === $id ? null : Nodes::get( $id );

		if ( '' !== $id && null === $saved ) {
			echo '<p>' . esc_html__( 'That node does not exist.', 'beltoft-services' ) . '</p>';
			return;
		}

		$n = null !== $saved ? $saved : Nodes::defaults();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="beltoft_services_save_hosting_node"><input type="hidden" name="node_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'beltoft_services_save_hosting_node' );
		echo '<table class="form-table" role="presentation">';

		if ( '' === $id ) {
			self::row( 'new_id', __( 'Id', 'beltoft-services' ), '', __( 'Lowercase letters, numbers, dashes and underscores. It cannot be changed later.', 'beltoft-services' ) );
		}

		foreach ( self::text_fields() as $key => $field ) {
			self::row( $key, $field[0], '0' === (string) ( $n[ $key ] ?? '' ) ? '' : (string) ( $n[ $key ] ?? '' ), $field[1] );

			if ( 'token_id' === $key ) {
				self::secret_row( 'token_secret', __( 'API token secret', 'beltoft-services' ), '' !== $id && Nodes::has_secret( $id ) );
			}

			if ( 'agent_key_id' === $key ) {
				self::secret_row( 'agent_key', __( 'Agent key', 'beltoft-services' ), '' !== $id && Nodes::has_secret( $id, 'agent_key' ) );
			}
		}

		echo '<tr><th scope="row"><label for="bs_client">' . esc_html__( 'Client', 'beltoft-services' ) . '</label></th><td><select id="bs_client" name="client">';
		printf( '<option value="fake" %1$s>%2$s</option><option value="live" %3$s>%4$s</option>', selected( $n['client'], 'fake', false ), esc_html__( 'Fake (no calls leave this site)', 'beltoft-services' ), selected( $n['client'], 'live', false ), esc_html__( 'Live', 'beltoft-services' ) );
		echo '</select><p class="description">' . esc_html__( 'Live is accepted only when BELTOFT_SERVICES_HOSTING_LIVE is defined as true in wp-config.php and the site tag is set.', 'beltoft-services' ) . '</p></td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1" %2$s> %3$s</label></td></tr>',
			esc_html__( 'Use', 'beltoft-services' ),
			checked( (int) $n['enabled'], 1, false ),
			esc_html__( 'New accounts may be placed on this node', 'beltoft-services' )
		);

		echo '</table>';
		submit_button( __( 'Save node', 'beltoft-services' ) );
		echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the list', 'beltoft-services' ) . '</a></form>';
	}
}
