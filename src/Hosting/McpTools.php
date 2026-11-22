<?php
/**
 * The MCP tools of WordPress hosting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Irreversible;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Mcp\ToolSet;
use BeltoftServices\Mcp\ToolSetDefaults;

defined( 'ABSPATH' ) || exit;

/**
 * Plan 16.6, on the levels the MCP already has, over the dashboard's own routes (HostingRoutes), so the owner check,
 * the plan's ceilings and the hourly limits are the same ones:
 *
 * - Read: hosting_info (sites, domains, ceilings, what an external domain must point at), hosting_usage (stats and the
 *   storage share), list_hosting_backups (the backups and the safety point).
 * - Actions: add_site_domain, set_php_version, clear_site_cache, create_hosting_backup.
 * - Full, each with the customer's confirmation and an e-mail afterwards: restore_hosting_backup (the whole account,
 *   one site's files or one site's database, after a safety point), delete_site.
 *
 * Not here, on purpose or for later: reading logs (the agent has no log operation yet), the SFTP password tools and the
 * WordPress sign-in link (a secret that needs the Secrets machinery and an agent job that is not built), and ordering
 * hosting (the purchase flow of the cart). Nothing here shows a password or a sign-in link, and no tool shows the database password or
 * sets a scheduled job (hosting_info lists the jobs, read only; ActionMap::capabilities_excluded() gives the reasons).
 */
final class McpTools implements ToolSet {

	use ToolSetDefaults;

	/** What a change that is queued answers: it is accepted, not done. A second change waits for it. */
	public const QUEUED = 'Accepted and queued: it runs in the background, one change of the account at a time (another change is refused until it is done). hosting_info shows the current task of the account and of each website.';

	/** Read tools. */
	public const READS = array( 'hosting_info', 'hosting_usage', 'list_hosting_backups' );

	/** Full access tools, each with a confirmation. */
	public const FULL = array( 'restore_hosting_backup', 'delete_site' );

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function tools(): array {
		return array(
			'hosting_info'     => Definitions::read( 'Shows one hosting account: its websites (name, whether it has WordPress, PHP version, workers, addresses with their state, the database name, user and host, the scheduled jobs), the plan ceilings (websites, PHP workers, PHP memory, upload size, offered PHP versions) and what an external domain must point at, and the task the account is busy with now (task: a change runs in the background, one at a time) and the last change that failed (last).' . Definitions::DATA, Definitions::by_id(), 30 ),
			'hosting_usage'    => Definitions::read( 'Shows the usage of one hosting account: CPU, memory in use (without the file cache, in percent), disk and database size, and how full the storage is of the plan. Files and databases count together.' . Definitions::DATA, Definitions::by_id(), 30 ),
			'list_hosting_backups' => Definitions::read( 'Lists the backups of one hosting account (nightly and manual, with date, size and the volume id that restore_hosting_backup takes) and the safety copy of the last restore, if there is one.' . Definitions::DATA, Definitions::by_id(), 30 ),
			'add_site_domain'  => Definitions::act( 'Adds a domain name to a website of a hosting account. A domain the customer holds on our name servers is pointed at the hosting at once; for another domain the result says which DNS records the customer must add. Connecting the name to the website runs in the background (queued: hosting_info shows the account\'s task until it is done; another change is refused until then). Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'site' => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'The website name, from hosting_info.' ), 'domain' => array( 'type' => 'string', 'maxLength' => 253, 'description' => 'The domain name, for example www.example.com.' ) ), array( 'id', 'site', 'domain' ) ), false ),
			'set_php_version'  => Definitions::act( 'Changes the PHP version of a website, and optionally its PHP memory limit and upload size (never above the plan). The website restarts its PHP. The change is queued and runs in the background (hosting_info shows the account\'s task; another change is refused until it is done). Ask the customer first.', Definitions::obj( array( 'id' => Definitions::id(), 'site' => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'The website name, from hosting_info.' ), 'php' => array( 'type' => 'string', 'maxLength' => 8, 'description' => 'A version from hosting_info, for example 8.4.' ), 'memory_mb' => array( 'type' => 'integer', 'minimum' => 64, 'description' => 'PHP memory limit in MB. Optional.' ), 'upload_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Upload size in MB. Optional.' ) ), array( 'id', 'site', 'php' ) ), false, true ),
			'clear_site_cache' => Definitions::act( 'Clears the page cache of a website of a hosting account. Queued: it runs in the background, and another change of the account is refused until it is done (hosting_info shows the task).', Definitions::obj( array( 'id' => Definitions::id(), 'site' => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'The website name, from hosting_info.' ) ), array( 'id', 'site' ) ), false, true ),
			'create_hosting_backup' => Definitions::act( 'Starts a backup of a hosting account now. Up to 3 a day; the 3 newest manual backups are kept. It runs in the background.', Definitions::by_id(), false ),
			'restore_hosting_backup' => Definitions::full(
				'Restores a hosting account from one of its backups: the whole account, the files of one website or the database of one website. What was changed since is replaced. A safety copy is made first and the customer can undo it in the dashboard for 24 hours. Get the backup from list_hosting_backups.',
				Definitions::obj( array( 'id' => Definitions::id(), 'scope' => array( 'type' => 'string', 'enum' => array( 'account', 'files', 'database' ) ), 'backup' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The volume id of the backup, from list_hosting_backups.' ), 'site' => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'The website name, for files and database.' ) ), array( 'id', 'scope', 'backup' ), true )
			),
			'delete_site'      => Definitions::full(
				'Deletes one website of a hosting account: its files, its database and its addresses, for good. The last website of an account cannot be deleted. The deletion is queued and runs in the background; hosting_info shows the website and its task until it is gone.',
				Definitions::obj( array( 'id' => Definitions::id(), 'site' => array( 'type' => 'string', 'maxLength' => 32, 'description' => 'The website name, from hosting_info.' ) ), array( 'id', 'site' ), true )
			),
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function routes(): array {
		return array(
			'hosting_info'             => array( 'GET #^/resources/\d+/hosting$#D' ),
			'hosting_usage'            => array( 'GET #^/resources/\d+/hosting/usage$#D' ),
			'list_hosting_backups'     => array( 'GET #^/resources/\d+/hosting/backups$#D' ),
			'add_site_domain'          => array( 'POST #^/resources/\d+/hosting/sites/[a-z0-9-]+/domains$#D' ),
			'set_php_version'          => array( 'POST #^/resources/\d+/hosting/sites/[a-z0-9-]+/php$#D' ),
			'clear_site_cache'         => array( 'POST #^/resources/\d+/hosting/sites/[a-z0-9-]+/cache$#D' ),
			'create_hosting_backup'    => array( 'POST #^/resources/\d+/hosting/backups$#D' ),
			'restore_hosting_backup'   => array( 'POST #^/resources/\d+/hosting/backups/restore$#D' ),
			'delete_site'              => array( 'DELETE #^/resources/\d+/hosting/sites/[a-z0-9-]+$#D' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		return array(
			'restore_hosting_backup'   => 'restore_hosting_backup',
			'delete_site'              => 'delete_site',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function capabilities_excluded(): array {
		return array(
			'delete_hosting_backup'       => 'never: a backup is the last copy after a mistake or an attack; deleting one is only done in the dashboard',
			'reveal_hosting_database_password' => 'never: showing the database password of a hosting account needs the code emailed to the account address, so it is only available in the dashboard',
			'hosting_scheduled_jobs'      => 'never: a scheduled job runs code on the hosting, so it is only set in the dashboard; hosting_info lists the jobs read only',
		);
	}

	/**
	 * Runs one of the tools of this set.
	 *
	 * @param string               $name    Tool.
	 * @param array<string, mixed> $args    Arguments, checked against the schema.
	 * @param int                  $user_id Customer.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $name, array $args, int $user_id ) {
		if ( in_array( $name, self::READS, true ) ) {
			return self::read( $name, $args );
		}

		if ( in_array( $name, self::FULL, true ) ) {
			return self::run_full( $name, $args );
		}

		return self::act( $name, $args );
	}

	/**
	 * A hosting account of the customer, or the refusal.
	 *
	 * @param int $id Service id.
	 * @return \BeltoftServices\Resource|\WP_Error
	 */
	public static function account( int $id ) {
		$r = Tools::owned( $id, true );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		return HostingDriver::ID === $r->get( 'driver' ) ? $r : new \WP_Error( 'beltoft_services_tool', __( 'That service is not a hosting account.', 'beltoft-services' ) );
	}

	/**
	 * Runs a read tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function read( string $name, array $args ) {
		$id = (int) $args['id'];

		switch ( $name ) {
			case 'hosting_info':
				return Tools::route( 'GET', '/resources/' . $id . '/hosting', array(), null, $id, 'hosting read' );

			case 'hosting_usage':
				return Tools::route( 'GET', '/resources/' . $id . '/hosting/usage', array(), null, $id, 'hosting usage read' );
		}

		$list = Tools::route( 'GET', '/resources/' . $id . '/hosting/backups', array(), null, $id, 'hosting backups read' );

		if ( $list instanceof \WP_Error ) {
			return $list;
		}

		// A backup is named by the volume id the restore tool takes; times are Unix times.
		return $list;
	}

	/**
	 * Runs an action tool.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments, checked against the schema.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function act( string $name, array $args ) {
		$id   = (int) $args['id'];
		$site = isset( $args['site'] ) ? rawurlencode( (string) $args['site'] ) : '';

		switch ( $name ) {
			case 'add_site_domain':
				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/domains', array(), array( 'host' => (string) $args['domain'] ), $id, 'site domain added' );

				return $done instanceof \WP_Error ? $done : array( 'added' => (string) $args['domain'], 'kind' => (string) $done['kind'], 'state' => (string) $done['state'], 'point_at' => (array) ( $done['point_at'] ?? array() ), 'queued' => true, 'message' => self::QUEUED );

			case 'set_php_version':
				$body = array( 'php' => (string) $args['php'] );

				foreach ( array( 'memory_mb', 'upload_mb' ) as $key ) {
					if ( isset( $args[ $key ] ) ) {
						$body[ $key ] = (int) $args[ $key ];
					}
				}

				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/php', array(), $body, $id, 'site PHP version changed' );

				return $done instanceof \WP_Error ? $done : array_merge( (array) $done, array( 'queued' => true, 'message' => self::QUEUED ) );

			case 'clear_site_cache':
				$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/sites/' . $site . '/cache', array(), array(), $id, 'site cache cleared' );

				return $done instanceof \WP_Error ? $done : array( 'queued' => true, 'site' => (string) $args['site'], 'message' => self::QUEUED );
		}

		$done = Tools::route( 'POST', '/resources/' . $id . '/hosting/backups', array(), array(), $id, 'hosting backup started' );

		return $done instanceof \WP_Error ? $done : array( 'started' => true, 'message' => 'The backup runs in the background. list_hosting_backups shows it when it is done.' );
	}

	/**
	 * The summary of a full tool: the checks of the dashboard, and what the call does in plain words.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{text: string, state: string}|\WP_Error
	 */
	public static function summary( string $name, array $args, int $user_id ) {
		$r = self::account( (int) $args['id'] );

		if ( $r instanceof \WP_Error ) {
			return $r;
		}

		$label = Irreversible::name( $r );

		if ( 'delete_site' === $name ) {
			$slug  = (string) $args['site'];
			$sites = Sites::for_resource( $r->id() );

			if ( null === Sites::find( $r->id(), $slug ) ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'That website does not exist.', 'beltoft-services' ) );
			}

			if ( count( $sites ) < 2 ) {
				return new \WP_Error( 'beltoft_services_tool', __( 'The last website of an account cannot be removed.', 'beltoft-services' ) );
			}

			return array(
				'text'  => sprintf(
					/* translators: 1: website name, 2: name of the service. */
					__( 'Delete the website %1$s of %2$s: its files and its database are deleted for good and cannot be brought back, and its addresses stop working. Backups that already exist are kept until they expire.', 'beltoft-services' ),
					$slug,
					$label
				),
				'state' => $r->id() . '|delete_site|' . $slug,
			);
		}

		$scope  = (string) $args['scope'];
		$volid  = (string) $args['backup'];
		$backup = null;

		foreach ( HostingBackups::stored( $r ) as $b ) {
			if ( $b['volid'] === $volid ) {
				$backup = $b;
			}
		}

		if ( null === $backup ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'That backup is not in the list. Use list_hosting_backups.', 'beltoft-services' ) );
		}

		if ( 'account' !== $scope && null === Sites::find( $r->id(), (string) ( $args['site'] ?? '' ) ) ) {
			return new \WP_Error( 'beltoft_services_tool', __( 'Name the website to restore.', 'beltoft-services' ) );
		}

		$what = array(
			'account'  => __( 'the whole hosting account: every website file and every database', 'beltoft-services' ),
			'files'    => __( 'the files of one website', 'beltoft-services' ),
			'database' => __( 'the database of one website', 'beltoft-services' ),
		)[ $scope ] ?? '';

		return array(
			'text'  => sprintf(
				/* translators: 1: what is restored, 2: name of the service, 3: backup date. */
				__( 'Restore %1$s of %2$s from the backup of %3$s. What was changed since then is replaced. A safety copy is made first and the customer can undo the restore in the dashboard for 24 hours. The restore runs in the background and takes a few minutes, longer for a whole account.', 'beltoft-services' ),
				$what,
				$label,
				wp_date( 'j F Y, H:i', (int) $backup['ctime'] )
			),
			'state' => $r->id() . '|restore_hosting_backup|' . $scope . '|' . $volid . '|' . (string) ( $args['site'] ?? '' ),
		);
	}

	/**
	 * Runs a full tool. The confirmation was accepted before this.
	 *
	 * @param string               $name Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_full( string $name, array $args ) {
		$id = (int) $args['id'];

		if ( 'delete_site' === $name ) {
			$done = Tools::route( 'DELETE', '/resources/' . $id . '/hosting/sites/' . rawurlencode( (string) $args['site'] ), array( 'confirm' => (string) $args['site'] ), null, $id, 'website deleted' );

			return $done instanceof \WP_Error ? $done : array( 'queued' => true, 'deleting' => (string) $done['removed'], 'message' => 'The website and its database are being deleted in the background. hosting_info shows the website and its task until it is gone.' );
		}

		$done = Tools::route(
			'POST',
			'/resources/' . $id . '/hosting/backups/restore',
			array(),
			array( 'scope' => (string) $args['scope'], 'volid' => (string) $args['backup'], 'slug' => (string) ( $args['site'] ?? '' ) ),
			$id,
			'hosting restore requested'
		);

		return $done instanceof \WP_Error ? $done : array( 'requested' => true, 'message' => 'The restore runs in the background. A safety copy was made first; the customer can undo it in the dashboard for 24 hours, and is told by e-mail when it has finished.' );
	}
}
