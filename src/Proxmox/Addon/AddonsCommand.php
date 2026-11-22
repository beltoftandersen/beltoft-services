<?php
/**
 * WP-CLI: add-on licences left active after their parent was deleted.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Proxmox\Addon;


defined( 'ABSPATH' ) || exit;

/**
 * Lists the add-on licences that are active while their own service is deleted and the licence of their server or database has
 * ended, and ends them the way AddonCascade does now.
 */
final class AddonsCommand {

	/**
	 * Lists the orphaned add-on licences. A dry run unless --apply is given.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : End the licences (revoked, no email, the subscription of a flat-priced one cancelled when nothing else lives on it).
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-services addons end-orphans
	 *     wp beltoft-services addons end-orphans --apply
	 *
	 * @subcommand end-orphans
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function end_orphans( array $args, array $assoc_args ): void {
		$apply = isset( $assoc_args['apply'] );
		$rows  = AddonEnd::orphans();

		if ( ! $rows ) {
			\WP_CLI::success( 'No add-on licence is left active after its parent ended.' );

			return;
		}

		$ended = 0;

		foreach ( $rows as $row ) {
			if ( $apply && AddonEnd::end( $row['resource'] ) ) {
				++$ended;
			}

			\WP_CLI::log( sprintf( '%s licence %d, user %d, kind %s, parent licence %d', $apply ? 'Ended' : 'Would end', $row['license_id'], $row['user_id'], $row['kind'], $row['parent_license'] ) );
		}

		if ( $apply ) {
			\WP_CLI::success( sprintf( '%d add-on licence(s) ended.', $ended ) );

			return;
		}

		\WP_CLI::success( sprintf( '%d add-on licence(s) would be ended. Add --apply to do it.', count( $rows ) ) );
	}

	/**
	 * Registers the command when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-services addons', self::class );
		}
	}
}
