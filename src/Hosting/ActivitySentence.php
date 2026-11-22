<?php
/**
 * The sentences of the hosting changes in a customer's activity list.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the activity sentence filter (Account\ActivityMap) for the messages hosting logs: `Hosting change "<action>" done`.
 */
final class ActivitySentence {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_activity_sentence', array( __CLASS__, 'answer' ), 10, 2 );
	}

	/**
	 * The sentence of a hosting change message.
	 *
	 * @param mixed $sentence Sentence so far.
	 * @param mixed $message  The message the service logged.
	 * @return mixed
	 */
	public static function answer( $sentence, $message ) {
		if ( is_string( $message ) && 1 === preg_match( '/^Hosting change "([a-z_]+)" done$/D', $message, $m ) ) {
			return self::change( $m[1] );
		}

		return $sentence;
	}

	/**
	 * The sentence of a hosting change, by its action id (the ids of Hosting\Actions\Steps::table()). The labels there are
	 * phrases for work in progress ("Changing the PHP version"); a log says what was done.
	 *
	 * @param string $action Action id.
	 */
	private static function change( string $action ): ?string {
		switch ( $action ) {
			case 'add_site':
				return __( 'Website added', 'beltoft-services' );
			case 'remove_site':
				return __( 'Website removed', 'beltoft-services' );
			case 'set_php':
				return __( 'PHP version changed', 'beltoft-services' );
			case 'php_rollback':
				return __( 'Went back to the earlier PHP version', 'beltoft-services' );
			case 'set_workers':
				return __( 'PHP workers changed', 'beltoft-services' );
			case 'purge_cache':
				return __( 'Cache cleared', 'beltoft-services' );
			case 'set_country_block':
				return __( 'Country block changed', 'beltoft-services' );
			case 'names':
				return __( 'Addresses updated', 'beltoft-services' );
			case 'remove_domain':
				return __( 'Address removed', 'beltoft-services' );
			case 'set_sftp_password':
				return __( 'SFTP password set', 'beltoft-services' );
			case 'add_ssh_key':
				return __( 'SSH key added', 'beltoft-services' );
			case 'remove_ssh_key':
				return __( 'SSH key removed', 'beltoft-services' );
			case 'wordpress_address':
				return __( 'WordPress address changed', 'beltoft-services' );
			case 'staging_create':
				return __( 'Staging copy made', 'beltoft-services' );
			case 'staging_refresh':
				return __( 'Staging copy refreshed', 'beltoft-services' );
			case 'staging_push':
				return __( 'Staging copy pushed to the live website', 'beltoft-services' );
			case 'staging_undo':
				return __( 'Last push undone', 'beltoft-services' );
			case 'staging_delete':
				return __( 'Staging copy deleted', 'beltoft-services' );
			case 'backup_now':
				return __( 'Backup taken', 'beltoft-services' );
			case 'restore_backup':
				return __( 'Restored from a backup', 'beltoft-services' );
			case 'restore_undo':
				return __( 'Restore undone', 'beltoft-services' );
			case 'backup_delete':
				return __( 'Backup deleted', 'beltoft-services' );
			case 'set_cron':
				return __( 'Scheduled jobs saved', 'beltoft-services' );
		}

		return null;
	}
}
