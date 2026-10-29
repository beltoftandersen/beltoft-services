<?php
/** The app as part of a server's progress and status words. @package BeltoftServices */

namespace BeltoftServices\Proxmox;

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
	 * Where the app is, for a ready server that has one.
	 *
	 * @param Resource $r Resource.
	 * @return string '' (no app to speak of, or it is ready), installing (waiting or running), slow (no report for half an hour) or failed.
	 */
	public static function state( Resource $r ): string {
		if ( 'ready' !== $r->get( 'state' ) || SshKeyField::DRIVER !== $r->get( 'driver' ) || '' === (string) $r->get( 'app_id' ) ) {
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
		return SshKeyField::DRIVER === $r->get( 'driver' ) && '' !== (string) $r->get( 'app_id' ) && ! in_array( $r->get( 'app_state' ), array( 'ready', 'failed' ), true ) && null !== Apps::definition( (string) $r->get( 'app_id' ) );
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
}
