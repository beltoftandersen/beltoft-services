<?php
/**
 * The service panel on a license's admin screen.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Admin;

use BeltoftLicenses\Events;
use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a license's service on WooCommerce → Licenses (action beltoft_licenses_admin_license):
 * its state, desired state, attempts, last error, an alert that could not be sent and the
 * driver's own part. Its actions are admin-post handlers with a nonce per resource and
 * action and the manage_woocommerce capability; each writes a history event naming the
 * admin. Every meta write takes the resource lock, so a running job is never overwritten.
 */
final class Panel {

	public const CAPABILITY = 'manage_woocommerce';
	public const ACTION     = 'beltoft_services_resource';
	public const ACTIONS    = array( 'retry', 'pause', 'resume', 'delete', 'mark_deleted', 'protect', 'unprotect' );
	public const CONFIRM    = 'DELETE';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_admin_license', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * The nonce action of one action on one resource.
	 *
	 * @param int    $resource_id Activation id.
	 * @param string $action      Action.
	 */
	public static function nonce_action( int $resource_id, string $action ): string {
		return self::ACTION . '_' . $resource_id . '_' . $action;
	}

	/**
	 * The current admin's login, for the history.
	 */
	private static function who(): string {
		$user = wp_get_current_user();

		return $user->exists() ? (string) $user->user_login : 'unknown';
	}

	/**
	 * Runs an admin action on a resource.
	 *
	 * - retry: resets the attempt count (and operation) so the reconciler runs the failed
	 *   operation again, and queues it;
	 * - pause, resume: the desired state;
	 * - delete: the desired state deleted, without a replacement; needs $confirm = "DELETE"
	 *   and an unprotected resource;
	 * - mark_deleted: closes the resource as deleted without calling the service (for one
	 *   that is gone already, such as a stranded delete);
	 * - protect, unprotect: retention and driver switches never delete a protected service.
	 *
	 * @param Resource $r       Resource.
	 * @param string   $action  Action.
	 * @param string   $confirm Typed confirmation (delete).
	 * @return true|\WP_Error
	 */
	public static function act( Resource $r, string $action, string $confirm = '' ) {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'invalid', __( 'Unknown action.', 'beltoft-services' ) );
		}

		if ( ! $r->is_live() ) {
			return new \WP_Error( 'closed', __( 'This service is closed already.', 'beltoft-services' ) );
		}

		$who        = self::who();
		$license_id = (int) ( $r->license()['id'] ?? 0 );

		switch ( $action ) {
			case 'retry':
				$stored = $r->locked(
					static function () use ( $r, $who, $license_id ): bool {
						if ( ! $r->set(
							array(
								'op'              => '',
								'attempts'        => 0,
								'next_attempt_at' => null,
								'alerted'         => '',
							)
						) ) {
							return false;
						}

						Events::add( $license_id, 'service', sprintf( 'Retry requested by %s', $who ) );
						return true;
					}
				);
				break;

			case 'pause':
			case 'resume':
				if ( 'deleted' === $r->get( 'desired' ) ) {
					return new \WP_Error( 'deleting', __( 'The service is being deleted.', 'beltoft-services' ) );
				}

				// Stored now, or queued for the next step when the lock is busy.
				$r->desire( 'pause' === $action ? 'paused' : 'ready', sprintf( 'pause' === $action ? 'Paused by %s' : 'Resumed by %s', $who ) );
				$stored = true;
				break;

			case 'delete':
				if ( self::CONFIRM !== $confirm ) {
					/* translators: %s: the word to type. */
					return new \WP_Error( 'confirm', sprintf( __( 'Type %s to confirm the deletion.', 'beltoft-services' ), self::CONFIRM ) );
				}

				$refused = false;
				$stored  = $r->locked(
					static function () use ( $r, $who, &$refused ): bool {
						if ( $r->get( 'protected' ) ) {
							$refused = true;
							return false;
						}

						// The lock is re-entrant within this connection.
						return $r->desire( 'deleted', sprintf( 'Deletion requested by %s', $who ), false );
					}
				);

				if ( $refused ) {
					return new \WP_Error( 'protected', __( 'This service is protected. Unprotect it first.', 'beltoft-services' ) );
				}
				break;

			case 'mark_deleted':
				return self::mark_deleted( $r, $who );

			default:
				$protect = 'protect' === $action;
				$stored  = $r->locked(
					static function () use ( $r, $who, $license_id, $protect ): bool {
						if ( ! $r->set( array( 'protected' => $protect ) ) ) {
							return false;
						}

						Events::add( $license_id, 'service', sprintf( $protect ? 'Protected by %s' : 'Unprotected by %s', $who ) );
						return true;
					}
				);
		}

		if ( null === $stored ) {
			return self::busy();
		}

		if ( false === $stored ) {
			return new \WP_Error( 'error', __( 'The change could not be stored.', 'beltoft-services' ) );
		}

		if ( in_array( $action, array( 'retry', 'pause', 'resume', 'delete' ), true ) ) {
			Jobs::enqueue( $r->id() );
		}

		return true;
	}

	/**
	 * Closes a resource as deleted without any driver call, under its lock, and tells
	 * listeners (the resource is closed first).
	 *
	 * @param Resource $r   Resource.
	 * @param string   $who Admin login.
	 * @return true|\WP_Error
	 */
	private static function mark_deleted( Resource $r, string $who ) {
		$old    = '';
		$stored = $r->locked(
			static function () use ( $r, $who, &$old ): bool {
				$old = $r->get( 'state' );

				$cleared = $r->set(
					array(
						'desired'              => 'deleted',
						'desired_at'           => microtime( true ),
						'replace_after_delete' => false,
						'op'                   => '',
						'attempts'             => 0,
						'next_attempt_at'      => null,
						'failed_op'            => '',
						'failed_desired'       => '',
						'alerted'              => '',
					)
				);

				if ( ! $cleared || ! $r->transition( 'deleted', sprintf( 'Marked deleted by %s, without calling the service', $who ) ) ) {
					return false;
				}

				$r->close();
				return true;
			}
		);

		if ( null === $stored ) {
			return self::busy();
		}

		if ( ! $stored ) {
			return new \WP_Error( 'error', __( 'The change could not be stored.', 'beltoft-services' ) );
		}

		delete_option( Resource::pending_desire_option( $r->id() ) );
		Alerts::clear_problem( $r->id() );

		if ( 'deleted' !== $old ) {
			Reconciler::notify( $r, $old, 'deleted' );
		}

		return true;
	}

	/**
	 * The error when a job holds the resource.
	 */
	private static function busy(): \WP_Error {
		return new \WP_Error( 'busy', __( 'The service is busy right now. Try again in a moment.', 'beltoft-services' ) );
	}

	/**
	 * admin-post: one action on one resource, then back to the license screen.
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		$id     = isset( $_POST['resource'] ) ? absint( $_POST['resource'] ) : 0;
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		check_admin_referer( self::nonce_action( $id, $action ) );

		$confirm  = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		$resource = Resource::find( $id );

		if ( ! $resource ) {
			wp_safe_redirect( \BeltoftLicenses\Admin\Screen::url( array( 'beltoft_services_error' => __( 'That service does not exist.', 'beltoft-services' ) ) ) );
			exit;
		}

		$result = self::act( $resource, $action, $confirm );
		$args   = array( 'license' => (int) ( $resource->license()['id'] ?? 0 ) );

		if ( is_wp_error( $result ) ) {
			$args['beltoft_services_error'] = $result->get_error_message();
		} else {
			$args['beltoft_services_notice'] = $action;
		}

		wp_safe_redirect( \BeltoftLicenses\Admin\Screen::url( $args ) );
		exit;
	}

	/**
	 * The result of the last action, and a site-wide notice while a failure alert could not be
	 * sent (the alert address is invalid).
	 */
	public static function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$notice = isset( $_GET['beltoft_services_notice'] ) ? sanitize_key( wp_unslash( $_GET['beltoft_services_notice'] ) ) : '';
		$error  = isset( $_GET['beltoft_services_error'] ) ? sanitize_text_field( wp_unslash( $_GET['beltoft_services_error'] ) ) : '';
		// phpcs:enable

		$messages = array(
			'retry'        => __( 'The service will be tried again.', 'beltoft-services' ),
			'pause'        => __( 'The service will be paused.', 'beltoft-services' ),
			'resume'       => __( 'The service will be resumed.', 'beltoft-services' ),
			'delete'       => __( 'The service will be deleted.', 'beltoft-services' ),
			'mark_deleted' => __( 'The service was marked deleted. The service itself was not called.', 'beltoft-services' ),
			'protect'      => __( 'The service is protected.', 'beltoft-services' ),
			'unprotect'    => __( 'The service is no longer protected.', 'beltoft-services' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}

		$count = count( self::problems() );

		if ( $count > 0 ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of services. */
						_n( 'A failure alert for %d service could not be sent: the alert address is not a valid email address.', 'Failure alerts for %d services could not be sent: the alert address is not a valid email address.', $count, 'beltoft-services' ),
						$count
					)
				),
				esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE ) ),
				esc_html__( 'Fix the alert address', 'beltoft-services' ),
				esc_url( Screen::url( array( 'failed' => '1' ) ) ),
				esc_html__( 'Failed services', 'beltoft-services' )
			);
		}
	}

	/**
	 * Unsent alerts of resources that are still live, keyed by resource id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function problems(): array {
		$live = array();

		foreach ( Alerts::problems() as $id => $problem ) {
			$resource = Resource::find( (int) $id );

			if ( $resource && $resource->is_live() && is_array( $problem ) ) {
				$live[ (int) $id ] = $problem;
			}
		}

		return $live;
	}

	/**
	 * The panel.
	 *
	 * @param mixed $license License row.
	 */
	public static function render( $license ): void {
		if ( ! is_array( $license ) || empty( $license['id'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$r = Resource::for_license( (int) $license['id'] );

		if ( ! $r ) {
			if ( Product::is_service_license( $license ) ) {
				echo '<h2>' . esc_html__( 'Service', 'beltoft-services' ) . '</h2><p>' . esc_html__( 'This license has no live service.', 'beltoft-services' ) . '</p>';
			}
			return;
		}

		$driver   = Registry::get( $r->get( 'driver' ) );
		$problems = Alerts::problems();
		$next     = $r->get( 'next_attempt_at' );
		$paused   = $r->get( 'paused_at' );
		$warned   = $r->get( 'warned_at' );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<h2>' . esc_html__( 'Service', 'beltoft-services' ) . '</h2>';

		if ( isset( $problems[ $r->id() ]['message'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $problems[ $r->id() ]['message'] ) . '</p></div>';
		}

		$rows = array(
			__( 'Driver', 'beltoft-services' )         => $driver ? $driver->label() : $r->get( 'driver' ) . ' ' . __( '(not available)', 'beltoft-services' ),
			__( 'Identifier', 'beltoft-services' )     => $r->get( 'uuid' ),
			__( 'State', 'beltoft-services' )          => $r->get( 'state' ),
			__( 'Desired', 'beltoft-services' )        => $r->get( 'desired' ),
			__( 'Operation', 'beltoft-services' )      => (string) $r->get( 'op' ) . ( '' !== (string) $r->get( 'failed_op' ) ? ' (' . sprintf( /* translators: %s: operation. */ __( 'failed: %s', 'beltoft-services' ), (string) $r->get( 'failed_op' ) ) . ')' : '' ),
			__( 'Attempts', 'beltoft-services' )       => (string) $r->get( 'attempts' ),
			__( 'Next attempt', 'beltoft-services' )   => $next ? wp_date( $format, $next ) : '',
			__( 'Last error', 'beltoft-services' )     => $r->get( 'last_error' ),
			__( 'Protected', 'beltoft-services' )      => $r->get( 'protected' ) ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ),
			__( 'Paused since', 'beltoft-services' )   => $paused ? wp_date( $format, $paused ) : '',
			__( 'Deletion warned', 'beltoft-services' ) => $warned ? wp_date( $format, $warned ) : '',
		);

		echo '<table class="widefat striped beltoft-services-panel"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === (string) $value ? '—' : (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $driver ) {
			$driver->render_admin( $r );
		}

		echo '<div class="beltoft-services-actions" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">';

		self::button( $r, 'retry', __( 'Retry now', 'beltoft-services' ) );

		if ( 'deleted' !== $r->get( 'desired' ) ) {
			if ( 'paused' === $r->get( 'desired' ) ) {
				self::button( $r, 'resume', __( 'Resume', 'beltoft-services' ) );
			} else {
				self::button( $r, 'pause', __( 'Pause', 'beltoft-services' ) );
			}
		}

		if ( $r->get( 'protected' ) ) {
			self::button( $r, 'unprotect', __( 'Unprotect', 'beltoft-services' ) );
		} else {
			self::button( $r, 'protect', __( 'Protect', 'beltoft-services' ) );
		}

		echo '</div>';

		if ( 'deleted' !== $r->get( 'desired' ) ) {
			echo '<h3>' . esc_html__( 'Delete the service', 'beltoft-services' ) . '</h3>';
			self::form_open( $r, 'delete' );
			printf(
				'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label> ',
				esc_html(
					sprintf(
						/* translators: %s: the word to type. */
						__( 'Deletes the service and its data. Type %s to confirm:', 'beltoft-services' ),
						self::CONFIRM
					)
				)
			);
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete', 'beltoft-services' ) . '</button></p></form>';
		}

		echo '<h3>' . esc_html__( 'Mark deleted', 'beltoft-services' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'For a service that is already gone (removed by hand, or a deletion the service cannot complete): closes it here without calling the service.', 'beltoft-services' ) . '</p>';
		self::form_open( $r, 'mark_deleted' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Mark deleted', 'beltoft-services' ) . '</button></p></form>';
	}

	/**
	 * Opens an action form.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action.
	 */
	private static function form_open( Resource $r, string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::nonce_action( $r->id(), $action ) );
		printf(
			'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="resource" value="%2$d"><input type="hidden" name="do" value="%3$s">',
			esc_attr( self::ACTION ),
			(int) $r->id(),
			esc_attr( $action )
		);
	}

	/**
	 * A one-button action form.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action.
	 * @param string   $label  Button label.
	 */
	private static function button( Resource $r, string $action, string $label ): void {
		self::form_open( $r, $action );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}
}
