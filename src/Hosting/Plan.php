<?php
/**
 * The hosting plan fields.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * The plan fields of a hosting product (plan section 3), their defaults and their typed reading. The fields
 * are read with Product::plan() from the product (a variation overrides the ones marked as variation fields).
 * The defaults are the Start plan; the numbers are starting values, set per product by the owner.
 */
final class Plan {

	/**
	 * The fields: key => [type, label, description, default, variation].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function fields(): array {
		return array(
			'node_group'     => array( 'text', __( 'Node group', 'beltoft-services' ), __( 'The group of hosting nodes this plan is built on. A node belongs to one group.', 'beltoft-services' ), '', true ),
			'sites'          => array( 'number', __( 'Websites', 'beltoft-services' ), __( 'Websites in the account. Staging copies do not count.', 'beltoft-services' ), 1, true ),
			'disk_gb'        => array( 'number', __( 'Storage (GB)', 'beltoft-services' ), __( 'Files and databases together. The container disk grows to this size and is never shrunk.', 'beltoft-services' ), 10, true ),
			'cores'          => array( 'number', __( 'CPU cores (cap)', 'beltoft-services' ), '', 1, true ),
			'memory_mb'      => array( 'number', __( 'Memory (MB, cap)', 'beltoft-services' ), '', 1024, true ),
			'swap_mb'        => array( 'number', __( 'Swap (MB)', 'beltoft-services' ), '', 0, true ),
			'php_workers'    => array( 'number', __( 'PHP workers, up to', 'beltoft-services' ), __( 'A ceiling for the whole account, not a guarantee. The memory cap is the real limit.', 'beltoft-services' ), 10, true ),
			'php_memory_mb'  => array( 'number', __( 'PHP memory_limit, up to (MB)', 'beltoft-services' ), __( 'A per-request ceiling.', 'beltoft-services' ), 512, true ),
			'upload_mb'      => array( 'number', __( 'Upload size, up to (MB)', 'beltoft-services' ), '', 1024, true ),
			'files'          => array( 'number', __( 'Files (inodes)', 'beltoft-services' ), '', 2000000, true ),
			'db_buffer_mb'   => array( 'number', __( 'Database buffer share (MB)', 'beltoft-services' ), __( 'The share of the shared database server this account counts for. Used for capacity planning only.', 'beltoft-services' ), 50, true ),
			'valkey_mb'      => array( 'number', __( 'Object cache (MB)', 'beltoft-services' ), __( 'Valkey memory. 0 means no object cache.', 'beltoft-services' ), 0, true ),
			'staging'        => array( 'text', __( 'Staging', 'beltoft-services' ), __( 'one (one copy for the account) or per_site (one per website).', 'beltoft-services' ), 'one', true ),
			'ssh'            => array( 'checkbox', __( 'SSH and WP-CLI', 'beltoft-services' ), __( 'Off means SFTP only.', 'beltoft-services' ), false, true ),
			'backup_days'    => array( 'number', __( 'Backup days', 'beltoft-services' ), __( 'Daily backups kept for this many days.', 'beltoft-services' ), 14, true ),
			'mail_mailboxes' => array( 'number', __( 'Mailboxes (email lite)', 'beltoft-services' ), '', 2, true ),
			'mail_quota_mb'  => array( 'number', __( 'Mailbox size (MB)', 'beltoft-services' ), '', 1024, true ),
			'mail_aliases'   => array( 'number', __( 'Mail aliases', 'beltoft-services' ), '', 0, true ),
			'site_mail_per_day' => array( 'number', __( 'Website mail per day', 'beltoft-services' ), __( 'Messages the websites may send per day through the system mailbox.', 'beltoft-services' ), 100, true ),
		);
	}

	/**
	 * The plan as the driver uses it: integers and a string or two, never trusting the stored shape.
	 *
	 * @param array<string, mixed> $plan The resource's plan snapshot.
	 * @return array<string, int|string|bool>
	 */
	public static function typed( array $plan ): array {
		$out = array();

		foreach ( self::fields() as $key => $field ) {
			$value = $plan[ $key ] ?? $field[3];

			if ( 'checkbox' === $field[0] ) {
				$out[ $key ] = true === $value || 'yes' === $value || '1' === $value || 1 === $value;
			} elseif ( 'number' === $field[0] ) {
				$out[ $key ] = max( 0, (int) $value );
			} else {
				$out[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		$out['staging'] = 'per_site' === $out['staging'] ? 'per_site' : 'one';

		return $out;
	}

	/**
	 * What a provision cannot start without: positive cores, memory and disk, at least one website, a node
	 * group. Returns the first problem as admin text, or null.
	 *
	 * @param array<string, int|string|bool> $plan Typed plan.
	 */
	public static function problem( array $plan ): ?string {
		foreach ( array( 'cores', 'memory_mb', 'disk_gb', 'sites' ) as $key ) {
			if ( (int) $plan[ $key ] <= 0 ) {
				return 'The plan value "' . $key . '" must be a positive number';
			}
		}

		if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/D', (string) $plan['node_group'] ) ) {
			return 'The plan has no valid node group';
		}

		return null;
	}

	/**
	 * Workers for each site pool: an equal share of the account total, at least 2 (plan section 3).
	 *
	 * @param array<string, int|string|bool> $plan  Typed plan.
	 * @param int                            $sites Websites in the account now (at least 1).
	 */
	public static function workers_per_site( array $plan, int $sites ): int {
		return max( 2, intdiv( (int) $plan['php_workers'], max( 1, $sites ) ) );
	}
}
