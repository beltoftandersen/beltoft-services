<?php
/**
 * Settings → Services → Proxmox clusters.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox;

use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The clusters tab: a list, and a form to add or edit one. It plugs into the tab plumbing of
 * Settings (the action `beltoft_services_settings_tab_proxmox-clusters`) and saves through
 * admin-post, as ConnectionsScreen does. The saved token secret is never printed.
 */
final class ClustersScreen {

	public const TAB = 'proxmox-clusters';

	/**
	 * Hooks the tab content and its handlers. The tab itself is added by ProxmoxSettings.
	 */
	public static function register(): void {
		add_action( 'beltoft_services_settings_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_beltoft_services_save_cluster', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_beltoft_services_delete_cluster', array( __CLASS__, 'handle_delete' ) );
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
	 * Saves the posted cluster and goes back to the tab.
	 */
	public static function handle_save(): void {
		self::authorise( 'beltoft_services_save_cluster' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in authorise(); every value is cleaned in Clusters::save().
		$post = is_array( $post ) ? $post : array();
		$id   = (string) ( $post['cluster_id'] ?? '' );
		$new  = '' === $id;

		if ( $new ) {
			$id = (string) ( $post['new_id'] ?? '' );

			if ( isset( Clusters::all()[ $id ] ) ) {
				self::flash( 'error', __( 'A cluster with that id already exists.', 'beltoft-services' ) );
				wp_safe_redirect( self::url( array( 'action' => 'new' ) ) );
				exit;
			}
		} elseif ( ! isset( Clusters::all()[ $id ] ) ) {
			self::flash( 'error', __( 'That cluster no longer exists. It may have been deleted in another tab.', 'beltoft-services' ) );
			wp_safe_redirect( self::url() );
			exit;
		}

		$result = Clusters::save( $id, $post );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::url( $new ? array( 'action' => 'new' ) : array( 'edit' => $id ) ) );
			exit;
		}

		self::flash( 'success', __( 'Cluster saved.', 'beltoft-services' ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Deletes a cluster and goes back to the tab.
	 */
	public static function handle_delete(): void {
		self::authorise( 'beltoft_services_delete_cluster' );

		$id = isset( $_POST['cluster_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cluster_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorise().

		if ( Clusters::in_use( $id ) > 0 ) {
			self::flash( 'error', __( 'Virtual machines still use this cluster, so it cannot be deleted.', 'beltoft-services' ) );
		} elseif ( Clusters::delete( $id ) ) {
			self::flash( 'success', __( 'Cluster deleted.', 'beltoft-services' ) );
		} else {
			self::flash( 'error', __( 'The cluster could not be deleted. It may be gone already.', 'beltoft-services' ) );
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
		set_transient( 'beltoft_services_cluster_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	/**
	 * Prints and clears the message.
	 */
	private static function show_flash(): void {
		$key   = 'beltoft_services_cluster_notice_' . get_current_user_id();
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
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Live Proxmox calls are switched off in wp-config.php. Clusters use the fake client.', 'beltoft-services' ) . '</p></div>';
		}

		if ( Clients::live_allowed() && '' === ProxmoxSettings::explicit_site_tag() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The site tag is not set, so every cluster uses the fake client. Set it in Settings, Proxmox before going live.', 'beltoft-services' ) . '</p></div>';
		}

		if ( $adding || '' !== $edit ) {
			self::render_form( $edit );
			return;
		}

		self::render_list();
	}

	/**
	 * The list of clusters.
	 */
	private static function render_list(): void {
		$all = Clusters::all();

		printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( self::url( array( 'action' => 'new' ) ) ), esc_html__( 'Add cluster', 'beltoft-services' ) );

		if ( array() === $all ) {
			echo '<p>' . esc_html__( 'No clusters yet.', 'beltoft-services' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Label', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Id', 'beltoft-services' ) . '</th><th>' . esc_html__( 'API address', 'beltoft-services' ) . '</th><th>' . esc_html__( 'Client', 'beltoft-services' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $all as $id => $cluster ) {
			$id = (string) $id;

			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td>',
				esc_html( (string) $cluster['label'] ),
				esc_html( $id ),
				esc_html( (string) $cluster['api_url'] ),
				esc_html( 'live' === $cluster['client'] ? __( 'Live', 'beltoft-services' ) : __( 'Fake', 'beltoft-services' ) )
			);
			echo '<td><a href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'beltoft-services' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this cluster?', 'beltoft-services' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="beltoft_services_delete_cluster"><input type="hidden" name="cluster_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'beltoft_services_delete_cluster' );
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
	 * @param string $type  Input type or "textarea".
	 */
	private static function row( string $name, string $label, string $value, string $desc = '', string $type = 'text' ): void {
		if ( 'textarea' === $type ) {
			$input = sprintf( '<textarea id="bs_%1$s" name="%1$s" rows="4" class="large-text code">%2$s</textarea>', esc_attr( $name ), esc_textarea( $value ) );
		} else {
			$input = sprintf( '<input type="%3$s" id="bs_%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="off">', esc_attr( $name ), esc_attr( $value ), esc_attr( $type ) );
		}

		printf(
			'<tr><th scope="row"><label for="bs_%1$s">%2$s</label></th><td>%3$s%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			'' === $desc ? '' : '<p class="description">' . esc_html( $desc ) . '</p>'
		);
	}

	/**
	 * The rows for ready-made apps: all off until the owner turns them on for the cluster.
	 *
	 * @param array<string, mixed> $c Cluster values.
	 */
	private static function apps_rows( array $c ): void {
		$families = '';

		foreach ( (array) $c['template_families'] as $vmid => $family ) {
			$families .= $vmid . ' = ' . $family . "\n";
		}

		echo '<tr><th scope="row">' . esc_html__( 'Apps', 'beltoft-services' ) . '</th><td><input type="hidden" name="apps_form" value="1">';
		printf(
			'<label><input type="checkbox" name="apps_ready" value="1" %1$s> %2$s</label><p class="description">%3$s</p>',
			checked( (int) $c['apps_ready'], 1, false ),
			esc_html__( 'Apps are ready on this cluster', 'beltoft-services' ),
			esc_html__( 'Turn on only when the vendor snippet above is vm-vendor-cloudinit-v2.yaml and a template of the family ubuntu-24.04 is listed below. Customers then see the app choice at checkout.', 'beltoft-services' )
		);

		foreach ( Apps::ids() as $id ) {
			$def = Apps::definition( $id );

			printf(
				'<p><label><input type="checkbox" name="apps_enabled[]" value="%1$s" %2$s> %3$s</label></p>',
				esc_attr( $id ),
				checked( in_array( $id, (array) $c['apps_enabled'], true ), true, false ),
				esc_html( null === $def ? $id : (string) $def['name'] )
			);
		}

		echo '</td></tr>';
		self::row( 'template_families_text', __( 'Template families', 'beltoft-services' ), $families, __( 'One per line: VMID = family, for example 9000 = ubuntu-24.04. Only a template in the list above can have a family. Apps run on ubuntu-24.04.', 'beltoft-services' ), 'textarea' );
	}

	/**
	 * The add or edit form. The saved secret is never printed.
	 *
	 * @param string $id Cluster id, empty to add.
	 */
	private static function render_form( string $id ): void {
		$saved = '' === $id ? null : Clusters::get( $id );

		if ( '' !== $id && null === $saved ) {
			echo '<p>' . esc_html__( 'That cluster does not exist.', 'beltoft-services' ) . '</p>';
			return;
		}

		$c = null !== $saved ? $saved : Clusters::defaults();

		$templates = '';

		foreach ( (array) $c['templates'] as $vmid => $label ) {
			$templates .= $vmid . ' = ' . $label . "\n";
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="beltoft_services_save_cluster"><input type="hidden" name="cluster_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'beltoft_services_save_cluster' );
		echo '<table class="form-table" role="presentation">';

		if ( '' === $id ) {
			self::row( 'new_id', __( 'Id', 'beltoft-services' ), '', __( 'Lowercase letters, numbers, dashes and underscores. It cannot be changed later.', 'beltoft-services' ) );
		}

		self::row( 'label', __( 'Label', 'beltoft-services' ), (string) $c['label'] );
		self::row( 'api_url', __( 'API address', 'beltoft-services' ), (string) $c['api_url'], __( 'https only, with the port and no path.', 'beltoft-services' ) );
		self::row( 'token_id', __( 'API token id', 'beltoft-services' ), (string) $c['token_id'], __( 'Looks like user@realm!tokenname.', 'beltoft-services' ) );

		printf(
			'<tr><th scope="row"><label for="bs_token_secret">%1$s</label></th><td><input type="password" id="bs_token_secret" name="token_secret" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'API token secret', 'beltoft-services' ),
			esc_attr( '' !== $id && Clusters::has_secret( $id ) ? __( 'Saved', 'beltoft-services' ) : '' ),
			esc_html__( 'At least 16 characters. Leave empty to keep the saved secret.', 'beltoft-services' )
		);

		self::row( 'ca_file', __( 'CA file', 'beltoft-services' ), (string) $c['ca_file'], __( 'Optional absolute path to the certificate authority file that signed the Proxmox certificate.', 'beltoft-services' ) );
		self::row( 'pin_sha256', __( 'Public key pin', 'beltoft-services' ), (string) $c['pin_sha256'], __( 'Optional: base64 of the SHA-256 hash of the server public key.', 'beltoft-services' ) );

		echo '<tr><th scope="row"><label for="bs_client">' . esc_html__( 'Client', 'beltoft-services' ) . '</label></th><td><select id="bs_client" name="client">';
		printf( '<option value="fake" %1$s>%2$s</option><option value="live" %3$s>%4$s</option>', selected( $c['client'], 'fake', false ), esc_html__( 'Fake (no calls leave this site)', 'beltoft-services' ), selected( $c['client'], 'live', false ), esc_html__( 'Live', 'beltoft-services' ) );
		echo '</select><p class="description">' . esc_html__( 'Live is accepted only when BELTOFT_SERVICES_PROXMOX_LIVE is defined as true in wp-config.php.', 'beltoft-services' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="bs_pool_api">' . esc_html__( 'Pool API', 'beltoft-services' ) . '</label></th><td><select id="bs_pool_api" name="pool_api">';
		printf( '<option value="pools" %1$s>pools</option><option value="pools_id" %2$s>pools_id</option>', selected( $c['pool_api'], 'pools', false ), selected( $c['pool_api'], 'pools_id', false ) );
		echo '</select><p class="description">' . esc_html__( 'Which Proxmox pool endpoint form this cluster version uses.', 'beltoft-services' ) . '</p></td></tr>';

		self::row( 'nodes', __( 'Nodes', 'beltoft-services' ), implode( ', ', (array) $c['nodes'] ), __( 'Node names machines may be built on, separated by commas.', 'beltoft-services' ) );
		self::row( 'storage', __( 'Disk storage', 'beltoft-services' ), (string) $c['storage'] );
		self::row( 'cloudinit_storage', __( 'Cloud-init storage', 'beltoft-services' ), (string) $c['cloudinit_storage'] );
		self::row( 'bridge', __( 'Network bridge', 'beltoft-services' ), (string) $c['bridge'] );
		self::row( 'private_bridge', __( 'Private network bridge', 'beltoft-services' ), (string) $c['private_bridge'], __( 'Optional. Configure an isolated VLAN-aware bridge on the host before using it.', 'beltoft-services' ) );
		self::row( 'vlan', __( 'VLAN tag', 'beltoft-services' ), $c['vlan'] ? (string) $c['vlan'] : '', __( 'Optional, 1 to 4094.', 'beltoft-services' ) );
		self::row( 'pool', __( 'Pool', 'beltoft-services' ), (string) $c['pool'], __( 'Optional Proxmox pool id for new machines.', 'beltoft-services' ) );
		self::row( 'boot_disk', __( 'Boot disk', 'beltoft-services' ), (string) $c['boot_disk'] );
		self::row( 'gateway', __( 'Gateway', 'beltoft-services' ), (string) $c['gateway'] );
		self::row( 'nameservers', __( 'Name servers', 'beltoft-services' ), implode( ', ', (array) $c['nameservers'] ), __( 'IPv4 addresses, separated by commas.', 'beltoft-services' ) );
		self::row( 'prefix', __( 'Network prefix', 'beltoft-services' ), (string) $c['prefix'], __( 'Bits in the netmask, 1 to 32.', 'beltoft-services' ) );
		self::row( 'vmid_min', __( 'First customer VMID', 'beltoft-services' ), (string) $c['vmid_min'], __( 'At least 100. Machines in this range are the only ones this site will change.', 'beltoft-services' ) );
		self::row( 'vmid_max', __( 'Last customer VMID', 'beltoft-services' ), (string) $c['vmid_max'] );
		self::row( 'templates', __( 'Templates', 'beltoft-services' ), $templates, __( 'One per line: VMID = label. The label is what customers see as the operating system. A template must be outside the customer VMID range.', 'beltoft-services' ), 'textarea' );
		self::row( 'default_template', __( 'Default template', 'beltoft-services' ), $c['default_template'] ? (string) $c['default_template'] : '', __( 'The VMID from the list above that customers get preselected at checkout and on reinstall. Empty: the first template of the list.', 'beltoft-services' ) );
		self::row( 'vendor_snippet', __( 'Vendor snippet', 'beltoft-services' ), (string) $c['vendor_snippet'], __( 'Optional cloud-init snippet volume, such as local:snippets/vendor.yaml.', 'beltoft-services' ) );
		self::apps_rows( $c );
		self::row( 'backup_storage', __( 'Backup storage', 'beltoft-services' ), (string) $c['backup_storage'], __( 'Optional storage id (a Proxmox Backup Server storage) for customer backups. Empty: no backups on this cluster.', 'beltoft-services' ) );

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="hidden" name="block_smtp" value="0"><label><input type="checkbox" name="block_smtp" value="1" %2$s> %3$s</label></td></tr>',
			esc_html__( 'Mail', 'beltoft-services' ),
			checked( (int) $c['block_smtp'], 1, false ),
			esc_html__( 'Block outgoing mail from new machines', 'beltoft-services' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="hidden" name="fw_enabled" value="0"><label><input type="checkbox" name="fw_enabled" value="1" %2$s> %3$s</label><br><input type="hidden" name="fw_ipfilter" value="0"><label><input type="checkbox" name="fw_ipfilter" value="1" %4$s> %5$s</label><p class="description">%6$s</p></td></tr>',
			esc_html__( 'Firewall', 'beltoft-services' ),
			checked( (int) $c['fw_enabled'], 1, false ),
			esc_html__( 'Customers manage the firewall of their servers from the dashboard', 'beltoft-services' ),
			checked( (int) $c['fw_ipfilter'], 1, false ),
			esc_html__( 'Anti-spoofing IP filter (the server\'s own address)', 'beltoft-services' ),
			esc_html__( 'Leave both off until the live checks of the firewall have passed.', 'beltoft-services' )
		);
		printf(
			'<tr><th scope="row"><label for="fw_pilot">%1$s</label></th><td><input type="text" id="fw_pilot" name="fw_pilot" value="%2$s" class="regular-text"><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Firewall pilot', 'beltoft-services' ),
			esc_attr( implode( ', ', array_map( 'intval', (array) ( $c['fw_pilot'] ?? array() ) ) ) ),
			esc_html__( 'License IDs of servers that get the dashboard firewall while the switch above is off, for a test or a staged start. Separate with commas.', 'beltoft-services' )
		);
		printf(
			'<tr><th scope="row"><label for="fw_default_mode">%1$s</label></th><td><select id="fw_default_mode" name="fw_default_mode"><option value="standard" %2$s>%3$s</option><option value="open" %4$s>%5$s</option></select> <label>%6$s <input type="number" name="fw_max_rules" min="1" max="%7$d" value="%8$d" class="small-text"></label></td></tr>',
			esc_html__( 'New servers', 'beltoft-services' ),
			selected( (string) $c['fw_default_mode'], 'standard', false ),
			esc_html__( 'Start with SSH, web and ping open', 'beltoft-services' ),
			selected( (string) $c['fw_default_mode'], 'open', false ),
			esc_html__( 'Start as today (nothing set)', 'beltoft-services' ),
			esc_html__( 'Most rules per server', 'beltoft-services' ),
			(int) Firewall::MAX_CEILING,
			(int) $c['fw_max_rules']
		);

		echo '</table>';
		submit_button( __( 'Save cluster', 'beltoft-services' ) );
		echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the list', 'beltoft-services' ) . '</a></form>';
	}
}
