<?php
/**
 * The customer's page and the admin box of a storage box.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Storage;

use BeltoftServices\Account\Panel;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * The page shows the connection details, the password behind the emailed code, the usage, the protocol switches, the
 * snapshots and ready-made commands. The password is never in the HTML: a button asks for it through StorageRoutes. Nothing a
 * customer reads names the storage provider.
 */
final class StoragePanel {
	public const HANDLE = 'beltoft-services-storage';

	/** Hooks. */
	public static function register(): void {
		add_action( 'beltoft_services_account_assets', array( __CLASS__, 'early' ), 20, 2 );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'email_values' ), 10, 3 );
	}

	/**
	 * The page draws its own buttons, so the generic ones are not printed.
	 *
	 * @param mixed $buttons  Buttons.
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function buttons( $buttons, $resource ) {
		return is_array( $buttons ) && $resource instanceof Resource && StorageDriver::ID === $resource->get( 'driver' ) ? array() : $buttons;
	}

	/**
	 * The link of the service emails goes to the storage page.
	 *
	 * @param mixed $values Values.
	 * @param mixed $r      Resource.
	 * @return mixed
	 */
	public static function email_values( $values, $r ) {
		if ( is_array( $values ) && $r instanceof Resource && StorageDriver::ID === $r->get( 'driver' ) && $r->license() && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			$values['account_url'] = \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Storage::class )->url( $r->license() );
		}

		return $values;
	}

	/**
	 * Script and style of the page.
	 */
	public static function assets(): void {
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/storage.js', BELTOFT_SERVICES_FILE ), array( Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/storage.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'beltoftServicesStorage',
			array(
				'strings' => array(
					'failed'       => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'        => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'working'      => __( 'Working...', 'beltoft-services' ),
					'queued'       => __( 'Your request is on its way. This can take a minute.', 'beltoft-services' ),
					'copied'       => __( 'Copied', 'beltoft-services' ),
					'passwordNote' => __( 'This is your password. Keep it safe.', 'beltoft-services' ),
					'confirmReset' => __( 'Change the password? The old password stops working.', 'beltoft-services' ),
					'confirmBack'  => __( 'Restore this snapshot? Files changed since then are lost, and newer snapshots are removed.', 'beltoft-services' ),
					'confirmDrop'  => __( 'Delete this snapshot?', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * Listener of beltoft_services_account_assets.
	 *
	 * @param mixed $parent   Handle of the panel script.
	 * @param mixed $endpoint The endpoint shown.
	 */
	public static function early( $parent = null, $endpoint = null ): void {
		unset( $parent );

		if ( is_string( $endpoint ) && \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Storage::class )->slug() === $endpoint && is_user_logged_in() ) {
			self::assets();
		}
	}

	/**
	 * A customer word for the state.
	 *
	 * @param Resource $r Resource.
	 */
	public static function status_text( Resource $r ): string {
		if ( 'ready' === (string) $r->get( 'state' ) ) {
			return (int) $r->get( 'storage_locked_at' ) > 0 ? __( 'Access off', 'beltoft-services' ) : __( 'Active', 'beltoft-services' );
		}

		return 'paused' === (string) $r->get( 'state' ) ? __( 'Access off', 'beltoft-services' ) : __( 'Being set up', 'beltoft-services' );
	}

	/**
	 * The account part.
	 *
	 * @param Resource $r Resource.
	 */
	public static function account( Resource $r ): void {
		if ( (int) ( $r->license()['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}

		self::assets();

		$state = (string) $r->get( 'state' );
		$live  = in_array( $state, array( 'ready', 'paused' ), true ) && '' !== Boxes::id( $r );

		echo '<div class="beltoft-services-storage" data-resource="' . esc_attr( (string) $r->id() ) . '">';

		if ( ! $live ) {
			echo '<p>' . esc_html__( 'Your backup storage is being set up. The connection details appear here as soon as it is ready.', 'beltoft-services' ) . '</p>';
			echo '<div class="beltoft-services-storage__notice" role="status" aria-live="polite" hidden></div></div>';

			return;
		}

		if ( 'ready' === $state ) {
			Boxes::refresh( $r );
			$r = Resource::find( $r->id() ) ?? $r;
		}

		$c   = Boxes::connection( $r );
		$off = 'paused' === $state || (int) $r->get( 'storage_locked_at' ) > 0;

		echo '<dl class="beltoft-services-facts">';
		self::fact( __( 'Status', 'beltoft-services' ), self::status_text( $r ) );
		self::fact( __( 'Size', 'beltoft-services' ), Boxes::size_text( (int) $c['size_bytes'] ) );
		self::fact( __( 'Location', 'beltoft-services' ), (string) $c['country'] );
		echo '</dl>';

		if ( $off ) {
			echo '<p class="bs-note">' . esc_html__( 'Access is off while your subscription is on hold. Your files are kept for a limited time. Renew the subscription to turn access on again.', 'beltoft-services' ) . '</p>';
		}

		self::usage( $c );
		self::connection( $r, $c, $off );

		if ( ! $off ) {
			self::protocols( $c );
			self::snapshots( $r );
			self::commands( $c );
		}

		self::advice();
		echo '<div class="beltoft-services-storage__notice" role="status" aria-live="polite" hidden></div>';
		echo '</div>';
	}

	/**
	 * One fact.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function fact( string $label, string $value ): void {
		if ( '' !== $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
	}

	/**
	 * The usage bar: box totals only.
	 *
	 * @param array<string, mixed> $c Connection facts.
	 */
	private static function usage( array $c ): void {
		echo '<h3>' . esc_html__( 'Space used', 'beltoft-services' ) . '</h3>';
		echo '<span class="bs-meter' . ( (int) $c['percent'] >= 90 ? ' bs-meter--high' : '' ) . '" aria-hidden="true"><span class="bs-meter__fill" style="width: ' . (int) $c['percent'] . '%"></span></span>';
		/* translators: 1: space used, 2: size of the storage, 3: percent. */
		echo '<p>' . esc_html( sprintf( __( '%1$s of %2$s used (%3$d%%).', 'beltoft-services' ), Boxes::size_text( (int) $c['used_bytes'] + (int) $c['snapshot_bytes'] ), Boxes::size_text( (int) $c['size_bytes'] ), (int) $c['percent'] ) ) . '</p>';
		echo '<p class="bs-note">' . esc_html__( 'Snapshots use space from your storage.', 'beltoft-services' ) . '</p>';
	}

	/**
	 * The connection card.
	 *
	 * @param Resource             $r   Resource.
	 * @param array<string, mixed> $c   Connection facts.
	 * @param bool                 $off Whether access is off.
	 */
	private static function connection( Resource $r, array $c, bool $off ): void {
		echo '<h3>' . esc_html__( 'Connection', 'beltoft-services' ) . '</h3>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		self::row( __( 'Host', 'beltoft-services' ), (string) $c['host'] );
		self::row( __( 'User', 'beltoft-services' ), (string) $c['user'] );
		/* translators: %d: port number. */
		self::row( __( 'SFTP and SCP', 'beltoft-services' ), sprintf( __( 'Port %d', 'beltoft-services' ), (int) $c['port_sftp'] ) );
		/* translators: %d: port number. */
		self::row( __( 'rsync, borg and SFTP over SSH', 'beltoft-services' ), $c['ssh'] ? sprintf( __( 'Port %d', 'beltoft-services' ), (int) $c['port_ssh'] ) : __( 'Off', 'beltoft-services' ) );

		if ( StorageSettings::alias_enabled() || (bool) \BeltoftServices\Settings::get( 'storage_extra_protocols' ) ) {
			// SMB and WebDAV check the provider's own certificate name, so they are given that host, whatever the alias is.
			if ( $c['smb'] || $c['webdav'] ) {
				self::row( __( 'Host for SMB and WebDAV', 'beltoft-services' ), (string) $c['tls_host'] );
			}
		}

		echo '</tbody></table>';

		if ( $off ) {
			return;
		}

		echo '<p><button type="button" class="button bs-btn" data-storage-reveal>' . esc_html__( 'Show password', 'beltoft-services' ) . '</button> ';
		echo '<button type="button" class="button bs-btn" data-storage-reset>' . esc_html__( 'Change password', 'beltoft-services' ) . '</button></p>';
		echo '<p class="bs-note">' . esc_html__( 'We email you a code before the password is shown or changed. You can also log in with an SSH key: log in once with the password and add your public key to the file .ssh/authorized_keys on the storage.', 'beltoft-services' ) . '</p>';
		echo '<p data-storage-password hidden><input type="text" readonly size="40" data-storage-password-field aria-label="' . esc_attr__( 'Password', 'beltoft-services' ) . '"> <button type="button" class="button bs-btn bs-btn--sm" data-copy-field data-copied="' . esc_attr__( 'Copied', 'beltoft-services' ) . '">' . esc_html__( 'Copy', 'beltoft-services' ) . '</button></p>';
		unset( $r );
	}

	/**
	 * The protocol switches the customer may change.
	 *
	 * @param array<string, mixed> $c Connection facts.
	 */
	private static function protocols( array $c ): void {
		echo '<h3>' . esc_html__( 'Access', 'beltoft-services' ) . '</h3>';
		echo '<p class="bs-note">' . esc_html__( 'SFTP and SCP are always on and cannot be switched off.', 'beltoft-services' ) . '</p>';
		echo '<form data-storage-access><p><label><input type="checkbox" name="ssh" value="1"' . checked( $c['ssh'], true, false ) . '> ' . esc_html__( 'rsync, borg and SFTP over SSH (port 23)', 'beltoft-services' ) . '</label></p>';

		if ( \BeltoftServices\Settings::get( 'storage_extra_protocols' ) ) {
			echo '<p><label><input type="checkbox" name="smb" value="1"' . checked( $c['smb'], true, false ) . '> ' . esc_html__( 'SMB', 'beltoft-services' ) . '</label></p>';
			echo '<p><label><input type="checkbox" name="webdav" value="1"' . checked( $c['webdav'], true, false ) . '> ' . esc_html__( 'WebDAV', 'beltoft-services' ) . '</label></p>';
		}

		echo '<p><button type="submit" class="button bs-btn">' . esc_html__( 'Save access', 'beltoft-services' ) . '</button></p></form>';
	}

	/**
	 * The snapshots: list, take, delete, restore, plan.
	 *
	 * @param Resource $r Resource.
	 */
	private static function snapshots( Resource $r ): void {
		$list = Boxes::snapshots( $r );
		$plan = (array) $r->get( 'storage_snapshot_plan' );

		echo '<h3>' . esc_html__( 'Snapshots', 'beltoft-services' ) . '</h3>';
		echo '<p class="bs-note">' . esc_html__( 'A snapshot is a copy of the storage as it is now. Restoring one puts everything back as it was and removes newer snapshots.', 'beltoft-services' ) . '</p>';

		if ( is_array( $list ) && array() !== $list ) {
			echo '<table class="woocommerce-table shop_table"><tbody>';

			foreach ( $list as $snap ) {
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $snap['created'] ) ) . ( $snap['automatic'] ? ' (' . esc_html__( 'automatic', 'beltoft-services' ) . ')' : '' ) . '</td><td>';
				echo '<button type="button" class="button bs-btn bs-btn--sm" data-storage-rollback="' . esc_attr( (string) $snap['id'] ) . '">' . esc_html__( 'Restore', 'beltoft-services' ) . '</button> ';
				echo '<button type="button" class="button bs-btn bs-btn--sm bs-btn--danger" data-storage-drop="' . esc_attr( (string) $snap['id'] ) . '">' . esc_html__( 'Delete', 'beltoft-services' ) . '</button>';
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'There are no snapshots yet.', 'beltoft-services' ) . '</p>';
		}

		echo '<p><button type="button" class="button bs-btn" data-storage-snapshot>' . esc_html__( 'Take a snapshot', 'beltoft-services' ) . '</button></p>';

		$every = (string) ( $plan['every'] ?? 'off' );
		echo '<form data-storage-plan><p><label>' . esc_html__( 'Automatic snapshots', 'beltoft-services' ) . ' <select name="every">';

		foreach ( array( 'off' => __( 'Off', 'beltoft-services' ), 'day' => __( 'Every day', 'beltoft-services' ), 'week' => __( 'Every week', 'beltoft-services' ), 'month' => __( 'Every month', 'beltoft-services' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $every, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></label> <label>' . esc_html__( 'at hour (UTC)', 'beltoft-services' ) . ' <input type="number" name="hour" min="0" max="23" value="' . (int) ( $plan['hour'] ?? SnapshotPlan::HOUR ) . '"></label> ';
		echo '<button type="submit" class="button bs-btn">' . esc_html__( 'Save', 'beltoft-services' ) . '</button></p></form>';
	}

	/**
	 * Ready-made commands.
	 *
	 * @param array<string, mixed> $c Connection facts.
	 */
	private static function commands( array $c ): void {
		$commands = StorageCommands::for( (string) $c['host'], (string) $c['user'] );

		if ( array() === $commands ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Commands', 'beltoft-services' ) . '</h3>';
		echo '<p class="bs-note">' . esc_html__( 'Replace /path/to/data with the folder you want to back up. The tools ask for the password, or use your SSH key.', 'beltoft-services' ) . '</p>';

		foreach ( $commands as $tool => $lines ) {
			$text = implode( "\n", array_map( 'strval', (array) $lines ) );
			echo '<p><strong>' . esc_html( (string) $tool ) . '</strong></p><pre>' . esc_html( $text ) . '</pre>';
			echo '<p><button type="button" class="button bs-btn bs-btn--sm" data-storage-copy="' . esc_attr( $text ) . '" data-copied="' . esc_attr__( 'Copied', 'beltoft-services' ) . '">' . esc_html__( 'Copy', 'beltoft-services' ) . '</button></p>';
		}
	}

	/**
	 * Plain advice on encryption.
	 */
	private static function advice(): void {
		echo '<h3>' . esc_html__( 'Encrypt your backups', 'beltoft-services' ) . '</h3>';
		echo '<p>' . esc_html__( 'Encrypt your backups before they leave your server. borg with repokey-blake2 and restic encrypt by default. We cannot read your files and we do not hold your key. If you lose the key, the backup cannot be opened.', 'beltoft-services' ) . '</p>';
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
	 * The admin part: ids and state, never the password.
	 *
	 * @param Resource $r Resource.
	 */
	public static function admin( Resource $r ): void {
		$rows = array(
			__( 'Box id', 'beltoft-services' )         => Boxes::id( $r ),
			__( 'Our box name', 'beltoft-services' )   => (string) $r->get( 'storage_name' ),
			__( 'Type', 'beltoft-services' )           => (string) $r->get( 'storage_type' ),
			__( 'Location code', 'beltoft-services' )  => (string) $r->get( 'storage_location' ),
			__( 'Provider host', 'beltoft-services' )  => (string) $r->get( 'storage_host' ),
			__( 'Alias', 'beltoft-services' )          => (string) $r->get( 'storage_alias' ),
			__( 'User', 'beltoft-services' )           => (string) $r->get( 'storage_username' ),
			__( 'Password', 'beltoft-services' )       => '' === (string) $r->get( 'storage_password' ) ? __( 'none', 'beltoft-services' ) : __( 'kept (encrypted)', 'beltoft-services' ),
			__( 'Used', 'beltoft-services' )           => Boxes::size_text( (int) $r->get( 'storage_used_bytes' ) ) . ' + ' . Boxes::size_text( (int) $r->get( 'storage_snapshot_bytes' ) ),
		);

		echo '<h3>' . esc_html__( 'Backup storage', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
