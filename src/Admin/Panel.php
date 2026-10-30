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
use BeltoftServices\Lifecycle;
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

	public const CAPABILITY      = 'manage_woocommerce';
	public const ACTION          = 'beltoft_services_resource';
	public const ACTIONS         = array( 'retry', 'pause', 'resume', 'delete', 'mark_deleted', 'protect', 'unprotect', 'suspend', 'lift_hold', 'quarantine_lift' );
	public const CONFIRM         = 'DELETE';
	public const CONFIRM_MARK    = 'MARK DELETED';
	public const CONFIRM_SUSPEND = 'SUSPEND';
	public const CONFIRM_LIFT    = 'LIFT HOLD';
	public const REASON_MAX      = 500;

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
	 *   that is gone already, such as a stranded delete); needs $confirm = "MARK DELETED";
	 * - protect, unprotect: retention and driver switches never delete a protected service;
	 * - suspend: the breach hold. Needs $confirm = "SUSPEND" and a $reason; the service is
	 *   paused and nothing but lift_hold resumes it (license events, renewals, the admin's
	 *   Resume, the customer); retention deletes it after the breach days;
	 * - lift_hold: needs $confirm = "LIFT HOLD"; the normal state applies again (ready on an
	 *   active license, otherwise paused with the retention counted from now).
	 *
	 * @param Resource $r       Resource.
	 * @param string   $action  Action.
	 * @param string   $confirm Typed confirmation (delete, suspend, lift_hold).
	 * @param string   $reason  Why (suspend).
	 * @return true|\WP_Error
	 */
	public static function act( Resource $r, string $action, string $confirm = '', string $reason = '' ) {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return self::error( 'invalid' );
		}

		if ( ! $r->is_live() ) {
			return self::error( 'closed' );
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
					return self::error( 'deleting' );
				}

				if ( 'resume' === $action && $r->on_hold() ) {
					return self::error( 'hold' );
				}

				// Stored now, or queued for the next step when the lock is busy.
				$r->desire( 'pause' === $action ? 'paused' : 'ready', sprintf( 'pause' === $action ? 'Paused by %s' : 'Resumed by %s', $who ) );
				$stored = true;
				break;

			case 'delete':
				if ( self::CONFIRM !== $confirm ) {
					return self::error( 'confirm' );
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
					return self::error( 'protected' );
				}
				break;

			case 'suspend':
				if ( self::CONFIRM_SUSPEND !== $confirm ) {
					return self::error( 'confirm_suspend' );
				}

				$reason = trim( wp_strip_all_tags( $reason ) );

				if ( '' === $reason ) {
					return self::error( 'reason' );
				}

				$reason  = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, self::REASON_MAX ) : substr( $reason, 0, self::REASON_MAX );
				$refused = '';
				$stored  = $r->locked(
					static function () use ( $r, $who, $reason, &$refused ): bool {
						if ( 'deleted' === $r->get( 'desired' ) ) {
							$refused = 'deleting';
							return false;
						}

						if ( $r->on_hold() ) {
							$refused = 'held';
							return false;
						}

						if ( ! $r->set(
							array(
								'breach_at'     => time(),
								'breach_reason' => $reason,
							)
						) ) {
							return false;
						}

						// The license is marked too: the mark outlives the service.
						\BeltoftServices\Breach::mark( (int) ( $r->license()['id'] ?? 0 ), $reason );

						// The lock is re-entrant within this connection. Stored or queued: the hold is
						// in place either way, so a resume is refused from here on.
						$r->desire( 'paused', sprintf( 'Suspended for breach by %1$s: %2$s', $who, $reason ) );
						return true;
					}
				);

				if ( '' !== $refused ) {
					return self::error( $refused );
				}
				break;

			case 'lift_hold':
				if ( self::CONFIRM_LIFT !== $confirm ) {
					return self::error( 'confirm_lift' );
				}

				$refused = '';
				$stored  = $r->locked(
					static function () use ( $r, $who, $license_id, &$refused ): bool {
						if ( ! $r->on_hold() ) {
							$refused = 'no_hold';
							return false;
						}

						$active = \BeltoftLicenses\Licenses::STATUS_ACTIVE === \BeltoftLicenses\Licenses::effective_status( $r->license() );
						$meta   = array(
							'breach_at'      => null,
							'breach_reason'  => '',
							'breach_alerted' => null,
						);

						// Still paused: the retention period starts now, never from the pause the hold began.
						if ( ! $active ) {
							$meta['paused_at'] = time();
							$meta['warned_at'] = null;
							$meta['reported']  = '';
						}

						if ( ! $r->set( $meta ) ) {
							return false;
						}

						\BeltoftServices\Breach::clear( $license_id );

						$text = sprintf( 'Breach hold lifted by %s', $who );

						if ( $active ) {
							$r->desire( 'ready', $text );
						} else {
							Events::add( $license_id, 'service', $text );
						}

						return true;
					}
				);

				if ( '' !== $refused ) {
					return self::error( $refused );
				}
				break;

			case 'mark_deleted':
				if ( self::CONFIRM_MARK !== $confirm ) {
					return self::error( 'confirm_mark' );
				}

				return self::mark_deleted( $r, $who );

			case 'quarantine_lift':
				// Queued: the firewall job restores the outgoing policy and clears the flag when Proxmox shows it.
				return \BeltoftServices\Proxmox\Quarantine::request_lift( $r, $who ) ? true : self::error( 'no_quarantine' );

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
			return self::error( 'busy' );
		}

		if ( false === $stored ) {
			return self::error( 'error' );
		}

		if ( in_array( $action, array( 'retry', 'pause', 'resume', 'delete', 'suspend', 'lift_hold' ), true ) ) {
			Jobs::enqueue( $r->id() );
		}

		return true;
	}

	/**
	 * Creates the service of an active service license that has no live one, the same way
	 * a new license gets it (Lifecycle::provide: the resource, then its provisioning job).
	 *
	 * @param int    $license_id License id.
	 * @param string $confirm    Typed confirmation (only for a license marked for a breach).
	 * @return true|\WP_Error
	 */
	public static function create_service( int $license_id, string $confirm = '' ) {
		$license = \BeltoftLicenses\Licenses::find( $license_id );

		if ( ! is_array( $license ) || ! Product::is_service_license( $license ) ) {
			return self::error( 'no_license' );
		}

		if ( \BeltoftLicenses\Licenses::STATUS_ACTIVE !== \BeltoftLicenses\Licenses::effective_status( $license ) ) {
			return self::error( 'not_active' );
		}

		if ( Resource::for_license( $license_id ) ) {
			return self::error( 'has_service' );
		}

		// A license marked for a breach: only the typed confirmation overrides, and it clears the mark.
		$marked = \BeltoftServices\Breach::marked( $license_id );

		if ( $marked && \BeltoftServices\Breach::CONFIRM !== $confirm ) {
			return self::error( 'confirm_breach' );
		}

		Events::add( $license_id, 'service', sprintf( 'Service creation requested by %s', self::who() ) );
		Lifecycle::provide( $license, true );

		if ( ! Resource::for_license( $license_id ) ) {
			// The mark stays: a license with no service must not be left open to the automatic creation.
			return self::error( 'not_created' );
		}

		if ( $marked ) {
			\BeltoftServices\Breach::clear( $license_id );
			Events::add( $license_id, 'service', sprintf( 'Breach mark cleared by %s', self::who() ) );
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
			return self::error( 'busy' );
		}

		if ( ! $stored ) {
			return self::error( 'error' );
		}

		delete_option( Resource::pending_desire_option( $r->id() ) );
		Alerts::clear_problem( $r->id() );

		if ( 'deleted' !== $old ) {
			Reconciler::notify( $r, $old, 'deleted' );
		}

		return true;
	}

	/**
	 * Error messages by code. The redirect carries only the code, so a notice never shows
	 * text taken from the URL.
	 *
	 * @return array<string, string>
	 */
	public static function errors(): array {
		return array(
			'invalid'      => __( 'Unknown action.', 'beltoft-services' ),
			'missing'      => __( 'That service does not exist.', 'beltoft-services' ),
			'closed'       => __( 'This service is closed already.', 'beltoft-services' ),
			'deleting'     => __( 'The service is being deleted.', 'beltoft-services' ),
			/* translators: %s: the word to type. */
			'confirm'      => sprintf( __( 'Type %s to confirm the deletion.', 'beltoft-services' ), self::CONFIRM ),
			/* translators: %s: the words to type. */
			'confirm_mark' => sprintf( __( 'Type %s to confirm.', 'beltoft-services' ), self::CONFIRM_MARK ),
			/* translators: %s: the words to type. */
			'confirm_suspend' => sprintf( __( 'Type %s to confirm the suspension.', 'beltoft-services' ), self::CONFIRM_SUSPEND ),
			/* translators: %s: the words to type. */
			'confirm_lift' => sprintf( __( 'Type %s to confirm.', 'beltoft-services' ), self::CONFIRM_LIFT ),
			'reason'       => __( 'Give the reason for the suspension.', 'beltoft-services' ),
			/* translators: %s: the words to type. */
			'confirm_breach' => sprintf( __( 'This license is marked for a breach of the terms. Type %s to create a service anyway.', 'beltoft-services' ), \BeltoftServices\Breach::CONFIRM ),
			'hold'         => __( 'This service is on breach hold. Lift the hold first.', 'beltoft-services' ),
			'held'         => __( 'This service is on breach hold already.', 'beltoft-services' ),
			'no_quarantine' => __( 'This server has no network quarantine.', 'beltoft-services' ),
			'no_hold'      => __( 'This service is not on breach hold.', 'beltoft-services' ),
			'protected'    => __( 'This service is protected. Unprotect it first.', 'beltoft-services' ),
			'busy'         => __( 'The service is busy right now. Try again in a moment.', 'beltoft-services' ),
			'error'        => __( 'The change could not be stored.', 'beltoft-services' ),
			'no_license'   => __( 'That license does not exist or is not a service license.', 'beltoft-services' ),
			'not_active'   => __( 'The license is not active, so it gets no service.', 'beltoft-services' ),
			'has_service'  => __( 'This license has a service already.', 'beltoft-services' ),
			'not_created'  => __( 'The service could not be created. See the license history.', 'beltoft-services' ),
		);
	}

	/**
	 * An error by code (see errors()).
	 *
	 * @param string $code Code.
	 */
	private static function error( string $code ): \WP_Error {
		$errors = self::errors();

		return new \WP_Error( $code, $errors[ $code ] ?? $errors['error'] );
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

		if ( 'create' === $action ) {
			$license_id = isset( $_POST['license'] ) ? absint( $_POST['license'] ) : 0;

			check_admin_referer( self::nonce_action( $license_id, 'create' ) );

			$result = self::create_service( $license_id, isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '' );
			$args   = array( 'license' => $license_id );

			if ( is_wp_error( $result ) ) {
				$args['beltoft_services_error'] = $result->get_error_code();
			} else {
				$args['beltoft_services_notice'] = 'create';
			}

			wp_safe_redirect( \BeltoftLicenses\Admin\Screen::url( $args ) );
			exit;
		}

		check_admin_referer( self::nonce_action( $id, $action ) );

		$confirm  = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$resource = Resource::find( $id );

		if ( ! $resource ) {
			wp_safe_redirect( \BeltoftLicenses\Admin\Screen::url( array( 'beltoft_services_error' => 'missing' ) ) );
			exit;
		}

		$result = self::act( $resource, $action, $confirm, $reason );
		$args   = array( 'license' => (int) ( $resource->license()['id'] ?? 0 ) );

		if ( is_wp_error( $result ) ) {
			$args['beltoft_services_error'] = $result->get_error_code();
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
		$error  = isset( $_GET['beltoft_services_error'] ) ? sanitize_key( wp_unslash( $_GET['beltoft_services_error'] ) ) : '';
		// phpcs:enable

		$messages = array(
			'create'       => __( 'The service is being created.', 'beltoft-services' ),
			'retry'        => __( 'The service will be tried again.', 'beltoft-services' ),
			'pause'        => __( 'The service will be paused.', 'beltoft-services' ),
			'resume'       => __( 'The service will be resumed.', 'beltoft-services' ),
			'delete'       => __( 'The service will be deleted.', 'beltoft-services' ),
			'mark_deleted' => __( 'The service was marked deleted. The service itself was not called.', 'beltoft-services' ),
			'protect'      => __( 'The service is protected.', 'beltoft-services' ),
			'unprotect'    => __( 'The service is no longer protected.', 'beltoft-services' ),
			'suspend'      => __( 'The service is suspended for breach.', 'beltoft-services' ),
			'lift_hold'    => __( 'The breach hold was lifted.', 'beltoft-services' ),
			'quarantine_lift' => __( 'The network quarantine will be lifted.', 'beltoft-services' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}

		$errors = self::errors();

		if ( isset( $errors[ $error ] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $errors[ $error ] ) );
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

				if ( \BeltoftLicenses\Licenses::STATUS_ACTIVE === \BeltoftLicenses\Licenses::effective_status( $license ) ) {
					$license_id = (int) $license['id'];

					$mark = \BeltoftServices\Breach::get( $license_id );

					if ( $mark ) {
						echo '<p class="description"><strong>' . esc_html__( 'This license is marked for a breach of the terms, so no service is created by itself.', 'beltoft-services' ) . '</strong></p>';
					}

					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( self::nonce_action( $license_id, 'create' ) );

					if ( $mark ) {
						printf(
							'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label></p>',
							esc_html( sprintf( /* translators: %s: the words to type. */ __( 'Type %s to override:', 'beltoft-services' ), \BeltoftServices\Breach::CONFIRM ) )
						);
					}
					printf(
						'<input type="hidden" name="action" value="%1$s"><input type="hidden" name="license" value="%2$d"><input type="hidden" name="do" value="create"><button type="submit" class="button">%3$s</button></form>',
						esc_attr( self::ACTION ),
						(int) $license_id,
						esc_html__( 'Create the service', 'beltoft-services' )
					);
				}
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

		if ( $r->folded_into() > 0 ) {
			echo '<p class="description"><strong>' . esc_html__( 'Folded into the domain registration.', 'beltoft-services' ) . '</strong> ' . esc_html__( 'The customer does not see this service and is sent no email about it. It never changes the DNS zone.', 'beltoft-services' ) . '</p>';
		}

		if ( isset( $problems[ $r->id() ]['message'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $problems[ $r->id() ]['message'] ) . '</p></div>';
		}

		$rows = array(
			__( 'Driver', 'beltoft-services' )         => $driver ? $driver->label() : $r->get( 'driver' ) . ' ' . __( '(not available)', 'beltoft-services' ),
			__( 'Identifier', 'beltoft-services' )     => $r->get( 'uuid' ),
			__( 'State', 'beltoft-services' )          => Resource::state_label( (string) $r->get( 'state' ) ),
			__( 'Desired', 'beltoft-services' )        => Resource::state_label( (string) $r->get( 'desired' ) ),
			__( 'Operation', 'beltoft-services' )      => (string) $r->get( 'op' ) . ( '' !== (string) $r->get( 'failed_op' ) ? ' (' . sprintf( /* translators: %s: operation. */ __( 'failed: %s', 'beltoft-services' ), (string) $r->get( 'failed_op' ) ) . ')' : '' ),
			__( 'Attempts', 'beltoft-services' )       => (string) $r->get( 'attempts' ),
			__( 'Next attempt', 'beltoft-services' )   => $next ? wp_date( $format, $next ) : '',
			__( 'Waiting', 'beltoft-services' )        => '' !== (string) $r->get( 'waiting' ) ? sprintf( /* translators: %s: what the service waits for. */ __( 'Waiting: %s', 'beltoft-services' ), (string) $r->get( 'waiting' ) ) : '',
			__( 'Last error', 'beltoft-services' )     => $r->get( 'last_error' ),
			__( 'Protected', 'beltoft-services' )      => $r->get( 'protected' ) ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ),
			__( 'Paused since', 'beltoft-services' )   => $paused ? wp_date( $format, $paused ) : '',
			__( 'Deletion warned', 'beltoft-services' ) => $warned ? wp_date( $format, $warned ) : '',
		);

		$quarantine = $r->get( \BeltoftServices\Proxmox\Quarantine::META );

		if ( is_array( $quarantine ) ) {
			$rows[ __( 'Network quarantine', 'beltoft-services' ) ] = \BeltoftServices\Proxmox\Quarantine::describe( $quarantine );
		}

		if ( $r->on_hold() ) {
			$rows[ __( 'Breach hold since', 'beltoft-services' ) ] = wp_date( $format, (int) $r->get( 'breach_at' ) );
			$rows[ __( 'Breach reason', 'beltoft-services' ) ]     = (string) $r->get( 'breach_reason' );
		}

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
			// A held service has no Resume: only "Lift hold" (below) resumes it.
			if ( 'paused' === $r->get( 'desired' ) ) {
				if ( ! $r->on_hold() ) {
					self::button( $r, 'resume', __( 'Resume', 'beltoft-services' ) );
				}
			} else {
				self::button( $r, 'pause', __( 'Pause', 'beltoft-services' ) );
			}
		}

		if ( \BeltoftServices\Proxmox\Quarantine::active( $r ) ) {
			self::button( $r, 'quarantine_lift', __( 'Lift network quarantine', 'beltoft-services' ) );
		}

		if ( $r->get( 'protected' ) ) {
			self::button( $r, 'unprotect', __( 'Unprotect', 'beltoft-services' ) );
		} else {
			self::button( $r, 'protect', __( 'Protect', 'beltoft-services' ) );
		}

		echo '</div>';

		self::hold_forms( $r );

		if ( 'deleted' !== $r->get( 'desired' ) ) {
			echo '<h3>' . esc_html__( 'Delete the service', 'beltoft-services' ) . '</h3>';
			self::form_open( $r, 'delete' );
			printf(
				'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label> ',
				esc_html(
					sprintf(
						/* translators: %s: the word or words to type. */
						__( 'Deletes the service and its data. Type %s to confirm:', 'beltoft-services' ),
						self::CONFIRM
					)
				)
			);
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete', 'beltoft-services' ) . '</button></p>';

			if ( \BeltoftLicenses\Licenses::STATUS_ACTIVE === \BeltoftLicenses\Licenses::effective_status( $license ) ) {
				echo '<p class="description">' . esc_html__( 'The license is active: afterwards use "Create the service" on this screen to give it a new service.', 'beltoft-services' ) . '</p>';
			}

			echo '</form>';
		}

		echo '<h3>' . esc_html__( 'Mark deleted', 'beltoft-services' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'For a service that is already gone (removed by hand, or a deletion the service cannot complete): closes it here without calling the service.', 'beltoft-services' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'If the service still exists it keeps running and is no longer managed here. If the license is active, use "Create the service" afterwards to give it a new one.', 'beltoft-services' ) . '</strong></p>';
		self::form_open( $r, 'mark_deleted' );
		printf(
			'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label> ',
			esc_html(
				sprintf(
					/* translators: %s: the word or words to type. */
					__( 'Type %s to confirm:', 'beltoft-services' ),
					self::CONFIRM_MARK
				)
			)
		);
		echo '<button type="submit" class="button">' . esc_html__( 'Mark deleted', 'beltoft-services' ) . '</button></p></form>';
	}

	/**
	 * The breach hold forms: suspend (with a reason), or lift the hold.
	 *
	 * @param Resource $r Resource.
	 */
	private static function hold_forms( Resource $r ): void {
		if ( 'deleted' === $r->get( 'desired' ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Breach of terms', 'beltoft-services' ) . '</h3>';

		if ( $r->on_hold() ) {
			echo '<p class="description">' . esc_html__( 'The service is suspended for breach. Nothing resumes it except lifting the hold: not a renewal, not Resume, not the customer.', 'beltoft-services' ) . '</p>';
			self::form_open( $r, 'lift_hold' );
			printf(
				'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label> ',
				esc_html(
					sprintf(
						/* translators: %s: the word or words to type. */
						__( 'Type %s to confirm:', 'beltoft-services' ),
						self::CONFIRM_LIFT
					)
				)
			);
			echo '<button type="submit" class="button">' . esc_html__( 'Lift hold', 'beltoft-services' ) . '</button></p></form>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Pauses the service and keeps it paused whatever billing does, until you lift the hold. Billing is not touched: cancel the subscription in WooCommerce Subscriptions yourself. The customer sees only that the server is suspended.', 'beltoft-services' ) . '</p>';
		self::form_open( $r, 'suspend' );
		printf(
			'<p><label>%1$s <input type="text" name="reason" autocomplete="off" maxlength="%2$d" class="regular-text" required></label></p>',
			esc_html__( 'Reason (kept in the history, never shown to the customer):', 'beltoft-services' ),
			(int) self::REASON_MAX
		);
		printf(
			'<p><label>%1$s <input type="text" name="confirm" autocomplete="off" class="regular-text"></label> ',
			esc_html(
				sprintf(
					/* translators: %s: the word or words to type. */
					__( 'Type %s to confirm:', 'beltoft-services' ),
					self::CONFIRM_SUSPEND
				)
			)
		);
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Suspend for breach', 'beltoft-services' ) . '</button></p></form>';
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
