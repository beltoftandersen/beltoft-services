<?php
/**
 * REST routes of a hosting account.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

use BeltoftServices\Api\Routes;
use BeltoftServices\RateLimit;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Under beltoft-services/v1/resources/<id>/hosting. Every route goes through Routes::guard() (logged in with the
 * wp_rest nonce, a rate limit, the license's owner), and the work is HostingActions, which checks the plan's
 * ceilings.
 *
 * - GET    hosting                         sites, domains, ceilings
 * - POST   hosting/sites                   slug, label, php, install
 * - DELETE hosting/sites/<slug>            confirm (the site name)
 * - POST   hosting/sites/<slug>/php        php, memory_mb, upload_mb
 * - POST   hosting/sites/<slug>/php/rollback  back to the version before an automatic switch (7 days)
 * - POST   hosting/sites/<slug>/workers    workers
 * - POST   hosting/sites/<slug>/cache      clears the page cache
 * - POST   hosting/sites/<slug>/login      a one-time WordPress sign-in address
 * - POST   hosting/sites/<slug>/password   a one-time address to WordPress's own "choose a new password" screen
 * - POST   hosting/sites/<slug>/domains    host
 * - DELETE hosting/domains/<host>
 * - POST   hosting/domains/<host>/main     the main address of a with-and-without-www pair (the other one redirects)
 * - POST   hosting/files/password         code (emailed), password (optional): the SFTP password, shown once
 * - POST   hosting/files/keys              code (emailed), key: adds a public SSH key
 * - DELETE hosting/files/keys              fingerprint: removes one
 * - POST   hosting/mail                    domain (optional, must be the account's own domain): sets up Email lite
 * - GET    hosting/usage                   stats, database size, storage share
 * - GET    hosting/backups                 the backups, the safety point
 * - POST   hosting/backups                 back up now (3 a day)
 * - POST   hosting/backups/restore         scope (account, files, database), volid, slug
 * - POST   hosting/backups/undo            back to the safety point
 * - POST   hosting/checklist              item (build or backups) or dismiss
 * - POST   hosting/mail/sites              websites send their mail from the Email lite domain
 */
final class HostingRoutes {

	/** Changes per resource and hour, so a loop cannot fill a node. */
	public const CHANGE_LIMIT = 60;

	/** Sign-in links per resource and hour. */
	public const LOGIN_LIMIT = 20;

	/** File access changes (password, keys) per resource and hour. */
	public const FILES_LIMIT = 10;

	/** The purpose of the emailed code before a password or a key is set for file access. */
	public const STEP_PURPOSE = 'hosting_files';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$id   = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) );
		$site = array( 'slug' => array( 'type' => 'string', 'required' => true, 'description' => 'The site name.' ) );
		$base = '/resources/(?P<id>\d+)/hosting';
		$ns   = Routes::NAMESPACE;
		$post = \WP_REST_Server::CREATABLE;

		register_rest_route( $ns, $base, array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ) );
		register_rest_route( $ns, $base . '/sites', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'add_site' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'slug'    => array( 'type' => 'string', 'required' => true, 'description' => 'Used in the temporary address.' ),
				'label'   => array( 'type' => 'string', 'description' => 'A title.' ),
				'php'     => array( 'type' => 'string', 'description' => 'PHP version. Default: the standard one.' ),
				'install' => array( 'type' => 'boolean', 'description' => 'Install WordPress. Default true.' ),
			),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'remove_site' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'confirm' => array( 'type' => 'string', 'description' => 'The site name.' ) ),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/php', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'set_php' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array(
				'php'       => array( 'type' => 'string', 'required' => true, 'description' => 'PHP version.' ),
				'memory_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'memory_limit in MB, up to the plan.' ),
				'upload_mb' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Upload size in MB, up to the plan.' ),
			),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/php/rollback', array( 'methods' => $post, 'callback' => array( __CLASS__, 'php_rollback' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/workers', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'set_workers' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'workers' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
		) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/cache', array( 'methods' => $post, 'callback' => array( __CLASS__, 'purge_cache' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/login', array( 'methods' => $post, 'callback' => array( __CLASS__, 'login_link' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/password', array( 'methods' => $post, 'callback' => array( __CLASS__, 'password_link' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id + $site ) );
		register_rest_route( $ns, $base . '/sites/(?P<slug>[a-z0-9-]+)/domains', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'add_domain' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + $site + array( 'host' => array( 'type' => 'string', 'required' => true, 'description' => 'The domain name.' ) ),
		) );
		register_rest_route( $ns, $base . '/domains/(?P<host>[^/]+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'remove_domain' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'host' => array( 'type' => 'string', 'required' => true ) ),
		) );
		register_rest_route( $ns, $base . '/domains/(?P<host>[^/]+)/check', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'check_dns' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'host' => array( 'type' => 'string', 'required' => true, 'description' => 'One of the names of a domain the customer points here: its DNS is looked up now.' ) ),
		) );
		register_rest_route( $ns, $base . '/domains/(?P<host>[^/]+)/main', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'set_main_address' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'host' => array( 'type' => 'string', 'required' => true, 'description' => 'The name that becomes the main address: example.com or www.example.com.' ) ),
		) );
		register_rest_route( $ns, $base . '/files/password', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'sftp_password' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'code'     => array( 'type' => 'string', 'required' => true, 'description' => 'The code emailed to the account owner (POST step-up/send with purpose hosting_files).' ),
				'password' => array( 'type' => 'string', 'default' => '', 'description' => 'Your own password, 16 characters or more. Empty: one is generated. It is returned once and never stored.' ),
			),
		) );
		register_rest_route( $ns, $base . '/files/keys', array(
			array(
				'methods'             => $post,
				'callback'            => array( __CLASS__, 'add_ssh_key' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => $id + array(
					'code' => array( 'type' => 'string', 'required' => true, 'description' => 'The code emailed to the account owner (POST step-up/send with purpose hosting_files).' ),
					'key'  => array( 'type' => 'string', 'required' => true, 'description' => 'A public SSH key line.' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_ssh_key' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => $id + array( 'fingerprint' => array( 'type' => 'string', 'required' => true, 'description' => 'The key\'s SHA256 fingerprint as listed.' ) ),
			),
		) );
		register_rest_route( $ns, $base . '/usage', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'usage' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ) );
		register_rest_route( $ns, $base . '/backups', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'backups' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ),
			array( 'methods' => $post, 'callback' => array( __CLASS__, 'backup_now' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ),
		) );
		register_rest_route( $ns, $base . '/backups/restore', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'restore' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'scope' => array( 'type' => 'string', 'required' => true, 'description' => 'account, files or database.' ),
				'volid' => array( 'type' => 'string', 'required' => true, 'description' => 'The backup, from the list.' ),
				'slug'  => array( 'type' => 'string', 'description' => 'The website, for files and database.' ),
			),
		) );
		register_rest_route( $ns, $base . '/backups/undo', array( 'methods' => $post, 'callback' => array( __CLASS__, 'undo' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ) );
		register_rest_route( $ns, $base . '/checklist', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'checklist' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array(
				'item'    => array( 'type' => 'string', 'description' => 'build or backups.' ),
				'dismiss' => array( 'type' => 'boolean', 'description' => 'Hide the getting-started list.' ),
			),
		) );
		register_rest_route( $ns, $base . '/addons', array(
			array(
				'methods'             => $post,
				'callback'            => array( __CLASS__, 'buy_addon' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => $id + array(
					'kind'    => array( 'type' => 'string', 'required' => true, 'description' => 'domain (an extra domain) or mail_pack (5 GB for one Email lite mailbox).' ),
					'target'  => array( 'type' => 'string', 'required' => true, 'description' => 'The domain name, or the mailbox address.' ),
					'host'    => array( 'type' => 'string', 'default' => '', 'description' => 'For an extra domain: the name the customer typed. When the order is paid through the pay link, it is added to the website.' ),
					'slug'    => array( 'type' => 'string', 'default' => '', 'description' => 'For an extra domain: the website the name is for.' ),
					'confirm' => array( 'type' => 'integer', 'description' => 'The amount charged now, in cents, that the customer accepted (amount_cents of the 402 answer). Without it, or when the price has changed, nothing is bought and the price is returned as the error message.' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_pack' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => $id + array( 'target' => array( 'type' => 'string', 'required' => true, 'description' => 'The mailbox address: one storage pack of it ends at the next renewal.' ) ),
			),
		) );
		register_rest_route( $ns, $base . '/mail/sites', array( 'methods' => $post, 'callback' => array( __CLASS__, 'mail_for_sites' ), 'permission_callback' => 'is_user_logged_in', 'args' => $id ) );
		register_rest_route( $ns, $base . '/mail', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'enable_mail' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => $id + array( 'domain' => array( 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'The mail domain. Email lite is on the account\'s own domain; leave empty to use it.' ) ),
		) );
	}

	/**
	 * The guard, and the check that the resource is a hosting account.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $ready   Whether the service must accept actions.
	 * @param int              $limit   Requests a minute.
	 * @param string           $group   Hourly change group ('' for none).
	 * @param int              $hourly  Hourly limit.
	 * @return Resource|\WP_REST_Response
	 */
	private static function guard( \WP_REST_Request $request, bool $ready, int $limit, string $group = '', int $hourly = self::CHANGE_LIMIT ) {
		$r = Routes::guard( $request, $ready, $limit );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		if ( HostingDriver::ID !== $r->get( 'driver' ) ) {
			return self::error( 'not_hosting', __( 'This is not a hosting account.', 'beltoft-services' ) );
		}

		if ( '' !== $group && RateLimit::exceeded( get_current_user_id(), 'beltoft-services:hosting-' . $group . ':' . $r->id(), $hourly, HOUR_IN_SECONDS ) ) {
			return self::error( 'rate_limited', __( 'Too many changes. Please try again later.', 'beltoft-services' ) );
		}

		return $r;
	}

	/**
	 * A reply from a result or a WP_Error of HostingActions.
	 *
	 * @param array<string, mixed>|\WP_Error $result Result.
	 * @param int                            $status Status of a success.
	 */
	private static function reply( $result, int $status = 200 ): \WP_REST_Response {
		return is_wp_error( $result ) ? self::error( $result->get_error_code(), $result->get_error_message(), is_array( $result->get_error_data() ) ? array_diff_key( $result->get_error_data(), array( 'status' => 1 ) ) : array() ) : self::ok( $result, $status );
	}

	/**
	 * @param string $code    Short code.
	 * @param string $message Words for the customer.
	 */
	private static function error( string $code, string $message, array $data = array() ): \WP_REST_Response {
		$status = array(
			'invalid'      => 422,
			'limit'        => 409,
			'exists'       => 409,
			'busy'         => 409,
			'not_ready'    => 409,
			'not_hosting'  => 409,
			'not_found'    => 404,
			'busy_round'   => 409,
			'confirm'      => 402,
			'step_up'      => 403,
			'addon_required' => 402,
			'failed'       => 502,
			'rate_limited' => 429,
		)[ $code ] ?? 400;

		$response = rest_convert_error_to_response( new \WP_Error( 'beltoft_services_hosting_' . $code, $message, array_merge( $data, array( 'status' => $status ) ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A reply.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param int                  $status Status.
	 */
	private static function ok( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	public static function overview( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, false, Routes::STATUS_RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$actions = new HostingActions( $r );

		return self::ok(
			array(
				'sites'  => $actions->sites(),
				'limits' => $actions->limits(),
				'point_at' => SiteDns::instructions( (array) ( Nodes::get( (string) ( Records::for_resource( $r->id() )['node'] ?? '' ) ) ?? array() ) ),
			)
		);
	}

	/**
	 * Runs one change: guard, hourly limit, the action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param callable         $do      fn( HostingActions ): array|\WP_Error.
	 * @param int              $status  Status of a success.
	 */
	private static function change( \WP_REST_Request $request, callable $do, int $status = 200 ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'change' );

		return $r instanceof Resource ? self::reply( $do( new HostingActions( $r ) ), $status ) : $r;
	}

	public static function add_site( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change(
			$request,
			static fn( HostingActions $a ) => $a->add_site( (string) $request['slug'], (string) $request['label'], (string) $request['php'], null === $request['install'] || (bool) $request['install'] ),
			201
		);
	}

	public static function remove_site( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->remove_site( (string) $request['slug'], (string) $request['confirm'] ) );
	}

	public static function set_php( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change(
			$request,
			static fn( HostingActions $a ) => $a->set_php( (string) $request['slug'], (string) $request['php'], null === $request['memory_mb'] ? null : (int) $request['memory_mb'], null === $request['upload_mb'] ? null : (int) $request['upload_mb'] )
		);
	}

	public static function php_rollback( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->php_rollback( (string) $request['slug'] ) );
	}

	public static function set_workers( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->set_workers( (string) $request['slug'], (int) $request['workers'] ) );
	}

	public static function purge_cache( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->purge_cache( (string) $request['slug'] ) );
	}

	public static function add_domain( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'change' );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$out = ( new HostingActions( $r ) )->add_domain( (string) $request['slug'], (string) $request['host'] );

		// A first look at the DNS right away, so the guide shows what is there and where the DNS is managed.
		if ( is_array( $out ) && 'external' === ( $out['kind'] ?? '' ) && 'active' !== ( $out['state'] ?? '' ) && ! empty( $out['host'] ) ) {
			DnsGuide::check( $r, (string) $out['host'] );
		}

		return self::reply( $out );
	}

	public static function remove_domain( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->remove_domain( rawurldecode( (string) $request['host'] ) ) );
	}

	public static function buy_addon( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->buy_addon( (string) $request['kind'], (string) $request['target'], (int) $request['confirm'], (string) $request['host'], (string) $request['slug'] ), 201 );
	}

	public static function remove_pack( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->remove_pack( (string) $request['target'] ) );
	}

	public static function enable_mail( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->enable_mail( (string) $request['domain'] ), 201 );
	}

	public static function usage( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, false, Routes::STATUS_RATE_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		$usage   = (array) $r->get( 'usage' );
		$percent = HostingUsage::storage_percent( $r );

		return self::ok(
			array(
				'at'                => (int) ( $usage['at'] ?? 0 ),
				'status'            => (string) ( $usage['status'] ?? '' ),
				'cpu'               => (float) ( $usage['cpu'] ?? 0 ),
				'memory_mb'         => (int) ( $usage['mem_mb'] ?? 0 ),
				'disk_mb'           => (int) ( $usage['disk_mb'] ?? 0 ),
				'database_mb'       => (int) ( $usage['db_mb'] ?? 0 ),
				'storage_limit_gb'  => (int) Plan::typed( (array) $r->get( 'plan' ) )['disk_gb'],
				'storage_percent'   => null === $percent ? null : (int) round( $percent ),
			)
		);
	}

	public static function backups( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, false, Routes::STATUS_RATE_LIMIT );

		return $r instanceof Resource ? self::ok( ( new HostingActions( $r ) )->backups() ) : $r;
	}

	public static function backup_now( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->backup_now(), 202 );
	}

	public static function restore( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->restore( (string) $request['scope'], (string) $request['volid'], (string) $request['slug'] ), 202 );
	}

	public static function undo( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->undo(), 202 );
	}

	public static function checklist( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->checklist( (string) $request['item'], (bool) $request['dismiss'] ) );
	}

	public static function mail_for_sites( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->use_email_for_sites() );
	}

	/**
	 * Looks the DNS of a domain up now, on the customer's request. Its own hourly group: every check asks the resolver.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function check_dns( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'dnscheck', DnsGuide::LIMIT );

		return $r instanceof Resource ? self::reply( DnsGuide::check( $r, rawurldecode( (string) $request['host'] ) ) ) : $r;
	}

	public static function set_main_address( \WP_REST_Request $request ): \WP_REST_Response {
		return self::change( $request, static fn( HostingActions $a ) => $a->set_main_address( rawurldecode( (string) $request['host'] ) ) );
	}

	/**
	 * A change of file access: its own hourly group, and the emailed code first (a password or a key stays valid, unlike a
	 * sign-in link). The code is checked after the guard, so a wrong code is also counted by the rate limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param callable         $do      fn( HostingActions ): array|\WP_Error.
	 */
	private static function files_change( \WP_REST_Request $request, callable $do ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'files', self::FILES_LIMIT );

		if ( ! $r instanceof Resource ) {
			return $r;
		}

		if ( ! \BeltoftServices\StepUp::verify( get_current_user_id(), (string) $request['code'], self::STEP_PURPOSE ) ) {
			return self::error( 'step_up', __( 'That code is not right or has expired. Send a new code.', 'beltoft-services' ) );
		}

		return self::reply( $do( new HostingActions( $r ) ) );
	}

	public static function sftp_password( \WP_REST_Request $request ): \WP_REST_Response {
		return self::files_change( $request, static fn( HostingActions $a ) => $a->set_sftp_password( (string) $request['password'] ) );
	}

	public static function add_ssh_key( \WP_REST_Request $request ): \WP_REST_Response {
		return self::files_change( $request, static fn( HostingActions $a ) => $a->add_ssh_key( (string) $request['key'] ) );
	}

	public static function remove_ssh_key( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'files', self::FILES_LIMIT );

		return $r instanceof Resource ? self::reply( ( new HostingActions( $r ) )->remove_ssh_key( (string) $request['fingerprint'] ) ) : $r;
	}

	public static function password_link( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'password', self::LOGIN_LIMIT );

		return $r instanceof Resource ? self::reply( ( new HostingActions( $r ) )->password_link( (string) $request['slug'] ) ) : $r;
	}

	public static function login_link( \WP_REST_Request $request ): \WP_REST_Response {
		$r = self::guard( $request, true, Routes::RATE_LIMIT, 'login', self::LOGIN_LIMIT );

		return $r instanceof Resource ? self::reply( ( new HostingActions( $r ) )->login_link( (string) $request['slug'] ) ) : $r;
	}
}
