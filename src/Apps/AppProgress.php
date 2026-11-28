<?php
/** The app as part of a server's progress and status words. @package BeltoftServices */

namespace BeltoftServices\Apps;

use BeltoftServices\Drivers\ProvisionSteps;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * A server becomes ready before its app is installed (an app failure never fails the server), but for the
 * customer the build is not finished until the app is. This class says what a server with an app is doing
 * after it is ready, in one place, for the status words (Account\Panel), the progress (Progress), the server
 * card, the thank-you page and the lists. Only fixed texts: the guest only ever supplies a step code.
 */
final class AppProgress {

	/**
	 * Tells Progress about the app: the work of a ready server that is still installing it, its steps, its words.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_panel_group', array( __CLASS__, 'panel_group' ), 10, 3 );
		add_filter( 'beltoft_services_attention_waits_for_customer', static fn( $theirs, $r ) => $theirs || ( $r instanceof Resource && self::dns_wait( $r ) ), 10, 2 );
		add_filter( 'beltoft_services_panel_label', array( __CLASS__, 'panel_label' ), 10, 3 );
		add_filter( 'beltoft_services_panel_message', array( __CLASS__, 'panel_message' ), 20, 3 );
		add_filter( 'beltoft_services_progress_idle_kind', array( __CLASS__, 'idle_kind' ), 10, 2 );
		add_filter( 'beltoft_services_progress_work', array( __CLASS__, 'work' ), 10, 4 );
		add_filter( 'beltoft_services_progress_extra_steps', array( __CLASS__, 'extra_steps' ), 10, 4 );
		add_filter( 'beltoft_services_progress_text', array( __CLASS__, 'text' ), 10, 5 );
		add_filter( 'beltoft_services_progress_box', array( __CLASS__, 'box' ), 10, 4 );
	}

	/**
	 * A server that is ready but still installing its app is still getting ready (the badge group of My Account).
	 *
	 * @param mixed $group Group so far.
	 * @param mixed $state Resource state.
	 * @param mixed $r     Resource.
	 * @return mixed
	 */
	public static function panel_group( $group, $state, $r ) {
		return 'ready' === $group && 'ready' === $state && $r instanceof Resource && 'installing' === self::state( $r ) ? 'getting-ready' : $group;
	}

	/**
	 * The badge text of a server with an app to speak of.
	 *
	 * @param mixed $label Text so far.
	 * @param mixed $state Resource state (unused).
	 * @param mixed $r     Resource.
	 * @return mixed
	 */
	public static function panel_label( $label, $state, $r ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		return '' === (string) $label && $r instanceof Resource ? self::line( $r ) : $label;
	}

	/**
	 * The sentence of a server with an app to speak of, unless an earlier answer is there.
	 *
	 * @param mixed $message Sentence so far.
	 * @param mixed $state   Resource state (unused).
	 * @param mixed $r       Resource.
	 * @return mixed
	 */
	public static function panel_message( $message, $state, $r ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		return '' === (string) $message && $r instanceof Resource ? self::message( $r ) : $message;
	}

	/**
	 * A server that is ready but still installing its app is still working: the build is not over for the customer.
	 *
	 * @param mixed    $kind Kind found so far.
	 * @param Resource $r    Resource.
	 * @return mixed
	 */
	public static function idle_kind( $kind, $r ) {
		return '' === $kind && $r instanceof Resource && 'installing' === self::state( $r ) ? 'app' : $kind;
	}

	/**
	 * The steps of the app kind: the server's own steps, all done, then the app's.
	 *
	 * @param mixed    $work   Steps found so far.
	 * @param Resource $r      Resource.
	 * @param string   $kind   Kind.
	 * @param string   $driver Driver id.
	 * @return mixed
	 */
	public static function work( $work, $r, $kind, $driver ) {
		if ( 'app' !== $kind || ! $r instanceof Resource ) {
			return $work;
		}

		$found  = Registry::get( (string) $driver );
		$labels = $found instanceof ProvisionSteps ? $found->step_labels() : array();
		$done   = array_keys( $labels );
		$info   = self::steps( $r );

		// Waiting for the customer's own domain: that is the step in progress, not the first install step (owner 2026-11-27).
		if ( self::dns_wait( $r ) ) {
			$labels['app_dns'] = __( 'Waiting for your DNS record', 'beltoft-services' );
		}

		foreach ( $info['codes'] as $i => $code ) {
			$labels[ 'app_' . $code ] = self::step_label( $code );

			if ( false !== $info['at'] && $i < $info['at'] ) {
				$done[] = 'app_' . $code;
			}
		}

		return array( 'labels' => $labels, 'done' => $done );
	}

	/**
	 * A server that will get an app lists the app's steps after its own, all still to do.
	 *
	 * @param mixed    $extra  Steps found so far.
	 * @param Resource $r      Resource.
	 * @param string   $kind   Kind.
	 * @param string   $driver Driver id.
	 * @return mixed
	 */
	public static function extra_steps( $extra, $r, $kind, $driver ) {
		if ( ! is_array( $extra ) || ! $r instanceof Resource || ! in_array( $kind, array( 'build', 'reinstall' ), true ) || ProxmoxDriver::ID !== $driver || ! self::planned( $r ) ) {
			return $extra;
		}

		foreach ( self::steps( $r )['codes'] as $code ) {
			$extra[ 'app_' . $code ] = self::step_label( $code );
		}

		return $extra;
	}

	/**
	 * The title and the sentence of the app install.
	 *
	 * @param mixed    $words Title and text found so far.
	 * @param Resource $r     Resource.
	 * @param string   $kind  Kind.
	 * @param string   $phase Phase.
	 * @param string   $eta   The "usually about N minutes" sentence.
	 * @return mixed
	 */
	public static function text( $words, $r, $kind, $phase, $eta ) {
		if ( 'app' !== $kind || ! is_array( $words ) || ! $r instanceof Resource ) {
			return $words;
		}

		if ( self::dns_wait( $r ) ) {
			return array(
				'title' => __( 'Point your domain at this server', 'beltoft-services' ),
				// The box (self::box) says it all; the sentence would only say it twice.
				'text'  => '',
			);
		}

		return array(
			/* translators: %s: app name such as WordPress */
			'title' => sprintf( __( 'Installing %s', 'beltoft-services' ), self::name( $r ) ),
			'text'  => trim( self::line( $r ) . '. ' . $eta ),
		);
	}

	/**
	 * The words for a step of an app's install.
	 *
	 * @param string $code Step code.
	 */
	private static function step_label( string $code ): string {
		return AppTab::step_labels()[ $code ] ?? __( 'Installing', 'beltoft-services' );
	}

	/**
	 * Where the app is, for a ready server that has one.
	 *
	 * @param Resource $r Resource.
	 * @return string '' (no app to speak of, or it is ready), installing (waiting or running), slow (no report for half an hour) or failed.
	 */
	public static function state( Resource $r ): string {
		if ( 'ready' !== $r->get( 'state' ) || ProxmoxDriver::ID !== $r->get( 'driver' ) || '' === (string) $r->get( 'app_id' ) ) {
			return '';
		}

		$state = (string) $r->get( 'app_state' );

		if ( ! in_array( $state, array( '', 'pending', 'installing', 'unknown', 'failed' ), true ) || null === Apps::definition( (string) $r->get( 'app_id' ) ) ) {
			return '';
		}

		return array( 'unknown' => 'slow', 'failed' => 'failed' )[ $state ] ?? 'installing';
	}

	/**
	 * The app's name, for a server that has an app with a known definition.
	 *
	 * @param Resource $r Resource.
	 */
	public static function name( Resource $r ): string {
		$app = '' === (string) $r->get( 'app_id' ) ? null : Apps::definition( (string) $r->get( 'app_id' ) );

		return null === $app ? '' : (string) ( $app['name'] ?? '' );
	}

	/**
	 * The step codes of the app's install in order (without the closing one) and the place the install is at.
	 *
	 * @param Resource $r Resource.
	 * @return array{codes: string[], at: int|false}
	 */
	public static function steps( Resource $r ): array {
		$app   = Apps::definition( (string) $r->get( 'app_id' ) );
		$all   = (array) ( $app['steps'] ?? array() );
		$codes = array_values( array_diff( $all, array( 'complete' ) ) );
		$at    = array_search( (string) $r->get( 'app_step' ), $codes, true );

		return array( 'codes' => $codes, 'at' => in_array( $r->get( 'app_state' ), array( 'installing' ), true ) ? $at : false );
	}

	/**
	 * Will this server install an app (it is building or reinstalling and has one chosen, not yet done)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function planned( Resource $r ): bool {
		return ProxmoxDriver::ID === $r->get( 'driver' ) && '' !== (string) $r->get( 'app_id' ) && ! in_array( $r->get( 'app_state' ), array( 'ready', 'failed' ), true ) && null !== Apps::definition( (string) $r->get( 'app_id' ) );
	}

	/**
	 * The words for the app's state: "Installing WordPress: Setting up the database", "Server ready, WordPress failed".
	 *
	 * @param Resource $r Resource.
	 * @return string Empty when the app has nothing to say.
	 */
	public static function line( Resource $r ): string {
		$state = self::state( $r );

		if ( '' === $state ) {
			return '';
		}

		$name = self::name( $r );

		if ( 'failed' === $state ) {
			/* translators: %s: app name such as WordPress */
			return sprintf( __( 'Server ready, %s failed', 'beltoft-services' ), $name );
		}

		if ( 'slow' === $state ) {
			/* translators: %s: app name such as WordPress */
			return sprintf( __( 'Server ready, %s is taking longer than usual', 'beltoft-services' ), $name );
		}

		if ( self::dns_wait( $r ) ) {
			// The short line of every list (overview, servers, order rows) carries the action itself (owner 2026-11-27).
			$ip = self::server_ip( $r );

			/* translators: 1: domain name, 2: server IPv4 address */
			return '' !== $ip ? sprintf( __( 'Point %1$s to %2$s', 'beltoft-services' ), (string) $r->get( 'app_domain_own' ), $ip ) : __( 'Waiting for your DNS record', 'beltoft-services' );
		}

		$labels = AppTab::step_labels();
		$info   = self::steps( $r );
		$code   = false !== $info['at'] ? $info['codes'][ $info['at'] ] : ( $info['codes'][0] ?? '' );

		/* translators: 1: app name such as WordPress, 2: what is being done, such as "Installing packages" */
		return isset( $labels[ $code ] ) ? sprintf( __( 'Installing %1$s: %2$s', 'beltoft-services' ), $name, $labels[ $code ] ) : sprintf( /* translators: %s: app name */ __( 'Installing %s', 'beltoft-services' ), $name );
	}

	/**
	 * The sentence for the card under the status.
	 *
	 * @param Resource $r Resource.
	 */
	public static function message( Resource $r ): string {
		$state = self::state( $r );
		$name  = self::name( $r );

		if ( 'installing' === $state && self::dns_wait( $r ) ) {
			/* translators: %s: app name such as Odoo */
			return sprintf( __( '%s is waiting for your domain. Follow the steps below.', 'beltoft-services' ), $name );
		}

		if ( 'installing' === $state ) {
			/* translators: %s: app name such as WordPress */
			return sprintf( __( 'Your server is up and %s is being installed on it. This page updates by itself.', 'beltoft-services' ), $name );
		}

		if ( 'slow' === $state ) {
			/* translators: %s: app name such as WordPress */
			return sprintf( __( 'Your server is running, but %s has not reported back yet. Open the App tab for details.', 'beltoft-services' ), $name );
		}

		if ( 'failed' === $state ) {
			/* translators: %s: app name such as WordPress */
			return sprintf( __( 'Your server is running, but %s could not be installed. Open the App tab and try again.', 'beltoft-services' ), $name );
		}

		return '';
	}

	/**
	 * Is the install waiting for the customer's own domain to point at the server (nothing runs until it does)?
	 *
	 * @param Resource $r Resource.
	 */
	public static function dns_wait( Resource $r ): bool {
		return 'installing' === self::state( $r ) && in_array( (string) $r->get( 'app_state' ), array( '', 'pending' ), true ) && AppOwnDomain::waiting( $r );
	}

	/**
	 * The box that tells the customer how to point their domain (HTML), for the progress block of an install that waits for it.
	 *
	 * @param mixed    $box   Box so far.
	 * @param Resource $r     Resource.
	 * @param string   $kind  Kind of work.
	 * @param string   $phase Phase (unused).
	 * @return mixed
	 */
	public static function box( $box, $r, $kind, $phase ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		return 'app' === $kind && $r instanceof Resource && self::dns_wait( $r ) ? self::dns_box( $r ) : $box;
	}

	/**
	 * The "Point your domain" box as HTML, one template for the server page and the App tab.
	 *
	 * @param Resource $r Resource.
	 */
	public static function dns_box( Resource $r ): string {
		$box = self::dns_view( $r );

		ob_start();
		( static function ( array $box ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/app-dns-box.php';
		} )( $box );

		return (string) ob_get_clean();
	}

	/**
	 * What the box shows. Everything comes from what the check job stored: no look-up when a page is shown.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	public static function dns_view( Resource $r ): array {
		$name    = (string) $r->get( 'app_domain_own' );
		$www     = AppWww::view( $r );
		$www_name = (string) ( $www['name'] ?? '' );
		$checked = (int) $r->get( 'app_domain_dns_checked' );
		$reason  = (string) $r->get( 'app_domain_dns_reason' );
		$seen    = array_values( array_filter( (array) $r->get( 'app_domain_dns_seen' ), 'is_string' ) );
		$deadline = AppOwnDomain::lost( $r ) ? 0 : AppOwnDomain::deadline( $r );
		$today   = wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $checked );
		$next    = AppOwnDomain::manual_next( $r );

		// The label of the www record is what the DNS panel of a provider asks for: "www", not the whole name.
		$www_label = '' !== $www_name && str_ends_with( $www_name, '.' . $name ) ? substr( $www_name, 0, -strlen( $name ) - 1 ) : $www_name;

		// What DNS said at the last look: nothing yet, could not be read, or the addresses found.
		if ( 0 === $checked ) {
			$now = __( 'We have not looked yet. We check soon.', 'beltoft-services' );
		} elseif ( 'unreachable' === $reason ) {
			$now = __( 'The name could not be looked up. We will try again.', 'beltoft-services' );
		} elseif ( array() === $seen && 'none' === $reason ) {
			/* translators: %s: domain name */
			$now = sprintf( __( 'Right now %s has no A record.', 'beltoft-services' ), $name );
		} elseif ( array() === $seen ) {
			// A look from before the addresses were stored: say only what the verdict knows, never "no A record" (owner 2026-11-27).
			$now = match ( $reason ) {
				'other' => __( 'The name has an A record, but it does not point to this server only. Remove the other addresses.', 'beltoft-services' ),
				'aaaa'  => __( 'The name points to this server, but it also has an AAAA record. Remove the AAAA record, because this server has no IPv6 address.', 'beltoft-services' ),
				default => __( 'We have not looked yet. We check soon.', 'beltoft-services' ),
			};
		} else {
			/* translators: 1: domain name, 2: IPv4 address or addresses */
			$now = sprintf( __( 'Right now %1$s points to %2$s.', 'beltoft-services' ), $name, implode( ', ', $seen ) );
		}

		return array(
			'app'       => self::name( $r ),
			'name'      => $name,
			'ip'        => self::server_ip( $r ),
			'www'       => $www_name,
			'www_label' => $www_label,
			'found'     => 'live' === (string) $r->get( 'app_domain_dns' ),
			'lost'      => AppOwnDomain::lost( $r ),
			'aaaa'      => 'aaaa' === $reason,
			'now'       => $now,
			'checked'   => $checked > 0 ? sprintf(
				/* translators: %s: time of day such as 09:41, or a date and time when it was not today */
				__( 'Last checked at %s', 'beltoft-services' ),
				$today ? wp_date( get_option( 'time_format' ), $checked ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked )
			) : '',
			'deadline'  => $deadline > 0 ? wp_date( get_option( 'date_format' ), $deadline ) : '',
			'id'        => $r->id(),
			'next'      => $next,
			'next_text' => $next > 0 ? \BeltoftServices\Domains\ManualCheck::text( $next ) : '',
		);
	}

	/**
	 * The server's IPv4 address the customer's A record must point to: the pool's, else the resource's own.
	 *
	 * @param Resource $r Resource.
	 */
	private static function server_ip( Resource $r ): string {
		$ip = AppOwnDomain::address( $r );

		return '' !== $ip ? $ip : ( false !== filter_var( (string) $r->get( 'ip' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? (string) $r->get( 'ip' ) : '' );
	}
}
