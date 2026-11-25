<?php
/**
 * The guest side of changing the domain of a running one-click app: a script pushed through the guest agent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Apps;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Same pattern as the extra IPv4 addresses (Ipv4Guest): the plugin writes a script and a request into /var/lib/beltoft-app/ (agent
 * file-write), starts the script detached (agent exec of systemd-run, so no call waits for the certificate) and later reads a status
 * file with the run token. The script is `apps/rename-common.script` (Caddyfile, certificate probe, rollback, status, lock) followed by
 * the app's own `rename.script` (where the app keeps its host setting), see the header of the common file for the order. The status
 * file is guest content and is never trusted: it is accepted only with the fixed schema of read().
 *
 * A running app is only ever moved to a domain: the request has no "no domain" mode. Links stored inside the customer's content are
 * never rewritten (no search-replace of any kind).
 *
 * Gate: an app has `"rename": {"since_recipe": n, "script": "rename.script"}` in app.json; a server installed from a recipe below n
 * needs a reinstall. The scripts are not part of apps/CHECKSUMS (that file is the install scripts the guest downloads); the request
 * carries the sha256 of the script that was pushed.
 */
final class AppRename {

	public const DIR    = '/var/lib/beltoft-app';
	public const SCRIPT = self::DIR . '/rename.sh';
	public const WANT   = self::DIR . '/rename.json';
	public const STATUS = self::DIR . '/rename.status.json';

	/** Meta: the run token and the domain the last push asked for (what read() holds the status against). */
	public const ASKED = 'app_rename_asked';

	public const STATES  = array( 'running', 'ready', 'failed' );
	public const STEPS   = array( 'caddy', 'certificate', 'app', 'verify', 'rollback', 'done' );
	public const REASONS = array( 'none', 'certificate', 'caddy', 'app', 'probe', 'busy' );

	private const NAME = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/D';

	/**
	 * Why this server's app cannot be moved to a domain, in words for the customer; null when it can.
	 *
	 * @param Resource $r Server.
	 */
	public static function supported( Resource $r ): ?string {
		$id   = (string) $r->get( 'app_id' );
		$app  = '' === $id ? null : Apps::definition( $id );
		$gate = is_array( $app ) && true === ( $app['domain'] ?? false ) && is_array( $app['rename'] ?? null ) ? $app['rename'] : null;

		if ( null === $gate || (int) ( $gate['since_recipe'] ?? 0 ) < 1 || '' === self::app_script( $id ) ) {
			return __( 'This app cannot change its domain on a running server. Reinstall it to choose a domain.', 'beltoft-services' );
		}

		$version = Apps::version( $id, (string) $r->get( 'app_version' ), true );
		$recipe  = (int) ( $r->get( 'app_recipe' ) ?: ( $version['recipe'] ?? 0 ) );

		if ( $recipe < (int) $gate['since_recipe'] ) {
			return __( 'This server was installed with an older version of the app set-up. Reinstall it to choose a domain.', 'beltoft-services' );
		}

		$power = \BeltoftServices\Proxmox\Vm\Operations::cached_status( $r->id() );

		if ( null !== $power && 'running' !== (string) $power['power'] ) {
			return __( 'The server is not running. Start it first.', 'beltoft-services' );
		}

		if ( 'ok' !== $r->get( 'agent' ) ) {
			return __( 'The server cannot be reached from the control panel right now. Please try again in a few minutes.', 'beltoft-services' );
		}

		return null;
	}

	/**
	 * The script the guest runs for an app: the common part, the app's part and the call. '' when a file is missing.
	 *
	 * @param string $id App id.
	 */
	public static function script( string $id ): string {
		$common = self::file( BELTOFT_SERVICES_PATH . 'apps/rename-common.script' );
		$app    = self::app_script( $id );

		return '' === $common || '' === $app ? '' : rtrim( $common ) . "\n\n" . rtrim( $app ) . "\n\nbeltoft_rename_main\n";
	}

	/**
	 * Writes the script and the request into the guest and starts the script. Returns at once with the run token: the script works
	 * on its own and the answer is read with read().
	 *
	 * @param Resource             $r    Server.
	 * @param array<string, mixed> $want domain, www, old_domain, old_www, ip, app, recipe (every value is checked here). The request itself (version, run token, mode, script checksum) is made here, never by the caller.
	 * @return string|\WP_Error The run token.
	 */
	public static function push( Resource $r, array $want ) {
		$domain = strtolower( (string) ( $want['domain'] ?? '' ) );
		$www    = strtolower( (string) ( $want['www'] ?? '' ) );
		$old    = strtolower( (string) ( $want['old_domain'] ?? '' ) );
		$old_w  = strtolower( (string) ( $want['old_www'] ?? '' ) );
		$ip     = (string) ( $want['ip'] ?? '' );
		$app    = (string) ( $want['app'] ?? '' );

		$bad = ! self::name( $domain )
			|| ( '' !== $www && $www !== 'www.' . $domain )
			|| ( '' !== $old && ! self::name( $old ) )
			|| ( '' !== $old_w && ( '' === $old || $old_w !== 'www.' . $old ) )
			|| $domain === $old
			|| false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
			|| $app !== (string) $r->get( 'app_id' );

		if ( $bad ) {
			return new \WP_Error( 'beltoft_rename_invalid', 'The request to change the domain is not valid.' );
		}

		$script = self::script( $app );
		$guest  = AppWww::guest( $r );

		if ( '' === $script || null === $guest ) {
			return new \WP_Error( 'beltoft_rename_unavailable', 'The set-up for the change is not available.' );
		}

		$token   = bin2hex( random_bytes( 12 ) ); // The one run token: 24 lowercase hex characters.
		$request = wp_json_encode(
			array(
				'v'          => 1,
				'run'        => $token,
				'mode'       => 'domain',
				'domain'     => $domain,
				'www'        => $www,
				'old_domain' => $old,
				'old_www'    => $old_w,
				'ip'         => $ip,
				'app'        => $app,
				'recipe'     => (int) ( $want['recipe'] ?? 0 ),
				'sha256'     => hash( 'sha256', $script ),
			)
		);

		if ( false === $request ) {
			return new \WP_Error( 'beltoft_rename_unavailable', 'The request could not be made.' );
		}

		$client = $guest['client'];
		$base   = '/nodes/' . rawurlencode( $guest['node'] ) . '/qemu/' . $guest['vmid'];

		foreach ( array( self::SCRIPT => $script, self::WANT => $request ) as $file => $content ) {
			$wrote = $client->request( 'POST', $base . '/agent/file-write', array( 'file' => $file, 'content' => $content ) );

			if ( is_wp_error( $wrote ) ) {
				return new \WP_Error( 'beltoft_rename_agent', 'The server did not take the files: ' . self::clean( $wrote->get_error_message() ) );
			}
		}

		$exec = $client->request( 'POST', $base . '/agent/exec', array( 'command' => array( 'systemd-run', '--no-block', '--collect', '--unit=beltoft-rename', '/bin/bash', self::SCRIPT ) ) );

		if ( is_wp_error( $exec ) || ! is_array( $exec ) || ! isset( $exec['pid'] ) ) {
			return new \WP_Error( 'beltoft_rename_agent', 'The server refused to start the change' . ( is_wp_error( $exec ) ? ': ' . self::clean( $exec->get_error_message() ) : '.' ) );
		}

		$ended = self::wait_for( $client, $base, (int) $exec['pid'] );

		if ( null === $ended || 0 !== $ended ) {
			return new \WP_Error( 'beltoft_rename_agent', 'The server could not start the change.' );
		}

		$r->set( array( self::ASKED => array( 'run' => $token, 'domain' => $domain ) ) );

		return $token;
	}

	/**
	 * The script's status for this run, checked: null unless it is a status file of the fixed schema for the run token and the
	 * domain that was asked. Any other key is dropped.
	 *
	 * @param Resource $r      Server.
	 * @param string   $run    Run token push() returned.
	 * @param string   $domain The domain that was asked ('' takes the one kept by push()).
	 * @return array{state: string, step: string, reason: string, domain: string, updated: string}|null
	 */
	public static function read( Resource $r, string $run, string $domain = '' ): ?array {
		$guest = AppWww::guest( $r );

		if ( null === $guest || '' === $run ) {
			return null;
		}

		if ( '' === $domain ) {
			$asked  = $r->get( self::ASKED );
			$domain = is_array( $asked ) && ( $asked['run'] ?? '' ) === $run ? (string) ( $asked['domain'] ?? '' ) : '';
		}

		$raw = Apps::read_file( $guest['client'], $guest['node'], $guest['vmid'], self::STATUS );

		return null === $raw ? null : self::parse( $raw, $run, strtolower( $domain ) );
	}

	/**
	 * Parses a status file (untrusted).
	 *
	 * @param string $raw    File content.
	 * @param string $run    Run token.
	 * @param string $domain Domain asked.
	 * @return array{state: string, step: string, reason: string, domain: string, updated: string}|null
	 */
	public static function parse( string $raw, string $run, string $domain ): ?array {
		$data = strlen( $raw ) <= 65536 ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) || '' === $run || '' === $domain ) {
			return null;
		}

		$ok = 1 === ( $data['v'] ?? null )
			&& is_string( $data['run'] ?? null ) && hash_equals( $run, $data['run'] )
			&& is_string( $data['state'] ?? null ) && in_array( $data['state'], self::STATES, true )
			&& is_string( $data['step'] ?? null ) && in_array( $data['step'], self::STEPS, true )
			&& is_string( $data['reason'] ?? null ) && in_array( $data['reason'], self::REASONS, true )
			&& is_string( $data['domain'] ?? null ) && $data['domain'] === $domain
			&& is_string( $data['updated'] ?? null ) && false !== strtotime( $data['updated'] );

		if ( ! $ok ) {
			return null;
		}

		return array(
			'state'   => $data['state'],
			'step'    => $data['step'],
			'reason'  => $data['reason'],
			'domain'  => $data['domain'],
			'updated' => $data['updated'],
		);
	}

	/**
	 * Polls exec-status until the start command ended; its exit code, or null.
	 *
	 * @param \BeltoftServices\Proxmox\Pve\Client $client Client.
	 * @param string                              $base   VM path.
	 * @param int                                 $pid    Process id.
	 */
	private static function wait_for( $client, string $base, int $pid ): ?int {
		for ( $try = 0; $try < 10; $try++ ) {
			$answer = $client->request( 'GET', $base . '/agent/exec-status', array( 'pid' => $pid ) );

			if ( is_wp_error( $answer ) ) {
				return null;
			}

			if ( is_array( $answer ) && ! empty( $answer['exited'] ) ) {
				return (int) ( $answer['exitcode'] ?? 1 );
			}

			/**
			 * Seconds between two looks at the start command (tests set 0).
			 *
			 * @param int $seconds Seconds.
			 */
			$seconds = max( 0, (int) apply_filters( 'beltoft_services_rename_wait', 1 ) );

			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		}

		return null;
	}

	/** The app's own part of the script, '' when the app has none. */
	private static function app_script( string $id ): string {
		$app  = Apps::definition( $id );
		$name = is_array( $app ) && is_array( $app['rename'] ?? null ) ? (string) ( $app['rename']['script'] ?? '' ) : '';

		return 1 === preg_match( '/^[a-z0-9.-]+\.script$/D', $name ) ? self::file( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $name ) : '';
	}

	private static function file( string $path ): string {
		$text = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin file.

		return is_string( $text ) ? $text : '';
	}

	private static function name( string $name ): bool {
		return 1 === preg_match( self::NAME, $name ) && str_contains( $name, '.' );
	}

	private static function clean( string $text ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_check_invalid_utf8( $text, true ) ) ) ), 0, 120 );
	}
}
