<?php
/**
 * The customer's card of a manual server, and the admin form that delivers it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\ManualServer;

use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Resource;
use BeltoftServices\Usage\CustomerDelete;

defined( 'ABSPATH' ) || exit;

/**
 * The card names the server, its specs, its addresses and its state, and holds the Cancel block (the shared delete block, a
 * typed word, through CustomerDelete). The admin form delivers the server or, once delivered, changes its details. No
 * password is stored or shown anywhere: the note is plain text. Nothing a customer reads names the supplier.
 */
final class ManualServerPanel {
	public const ACTION = 'beltoft_services_manual_ready';

	/** Hooks. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'beltoft_services_account_buttons', array( __CLASS__, 'buttons' ), 10, 2 );
		add_filter( 'beltoft_services_email_values', array( __CLASS__, 'email_values' ), 10, 3 );
		add_filter( 'beltoft_services_email_details', array( __CLASS__, 'email_details' ), 10, 3 );
	}

	/**
	 * The page draws its own block, so the generic buttons are not printed.
	 *
	 * @param mixed $buttons  Buttons.
	 * @param mixed $resource Resource.
	 * @return mixed
	 */
	public static function buttons( $buttons, $resource ) {
		return is_array( $buttons ) && $resource instanceof Resource && ManualServerDriver::ID === $resource->get( 'driver' ) ? array() : $buttons;
	}

	/**
	 * The link of the service emails goes to the dedicated server page.
	 *
	 * @param mixed $values Values.
	 * @param mixed $r      Resource.
	 * @return mixed
	 */
	public static function email_values( $values, $r ) {
		if ( is_array( $values ) && $r instanceof Resource && ManualServerDriver::ID === $r->get( 'driver' ) && $r->license() && ! in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			$values['account_url'] = ServiceArea::get( \BeltoftServices\Account\Dedicated::class )->url( $r->license() );
		}

		return $values;
	}

	/**
	 * The lines of the service ready email: the addresses and the note. Never a password.
	 *
	 * @param mixed $details  Details.
	 * @param mixed $email_id Email id.
	 * @param mixed $r        Resource.
	 * @return mixed
	 */
	public static function email_details( $details, $email_id, $r ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! $r instanceof Resource || ManualServerDriver::ID !== $r->get( 'driver' ) ) {
			return $details;
		}

		foreach ( self::facts( $r ) as $label => $value ) {
			$details[ $label ] = $value;
		}

		return $details;
	}

	/**
	 * What the customer reads about the server: label => text. Empty values are left out.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, string>
	 */
	public static function facts( Resource $r ): array {
		$plan = (array) $r->get( 'plan' );
		$ips  = ManualServerDelivery::ips( $r );
		$rows = array(
			__( 'Server', 'beltoft-services' )         => (string) $r->get( 'server_name' ),
			__( 'Processor', 'beltoft-services' )      => (string) ( $plan['server_cpu'] ?? '' ),
			__( 'Memory', 'beltoft-services' )         => (string) ( $plan['server_memory'] ?? '' ),
			__( 'Storage', 'beltoft-services' )        => (string) ( $plan['server_storage'] ?? '' ),
			__( 'Location', 'beltoft-services' )       => (string) ( $plan['server_location'] ?? '' ),
			__( 'IP address', 'beltoft-services' )     => implode( ', ', $ips ),
			__( 'Login', 'beltoft-services' )          => (string) $r->get( ManualServerDelivery::NOTE ),
		);

		return array_filter( $rows, static fn( $value ): bool => '' !== trim( $value ) );
	}

	/**
	 * The script of the card.
	 */
	public static function assets(): void {
		wp_enqueue_script( 'beltoft-services-manual', plugins_url( 'assets/manual-server.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Account\Panel::HANDLE, \BeltoftServices\Status::HANDLE, \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/manual-server.js' ), true );
		wp_localize_script(
			'beltoft-services-manual',
			'beltoftServicesManual',
			array(
				'strings' => array(
					'failed'  => __( 'Something went wrong. Please try again.', 'beltoft-services' ),
					'limit'   => __( 'Too many requests. Please wait and try again later.', 'beltoft-services' ),
					'working' => __( 'Working...', 'beltoft-services' ),
					'note'    => __( 'This is your root password. Keep it safe, and change it after you log in.', 'beltoft-services' ),
				),
			)
		);
	}

	/**
	 * The customer's card.
	 *
	 * @param Resource $r Resource.
	 */
	public static function account( Resource $r ): void {
		if ( (int) ( $r->license()['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}

		echo '<div class="beltoft-services-manual" data-resource="' . esc_attr( (string) $r->id() ) . '">';

		$delivered = (int) $r->get( ManualServerDelivery::READY_AT ) > 0;

		if ( ! $delivered ) {
			/* translators: %d: hours. */
			echo '<p>' . esc_html( sprintf( __( 'Your server is being set up by hand. It is delivered within %d hours of your payment. We email you when it is ready, and the address appears here.', 'beltoft-services' ), ManualServerDelivery::promise_hours() ) ) . '</p>';
		}

		echo '<dl class="beltoft-services-facts">';
		echo '<div><dt>' . esc_html__( 'Status', 'beltoft-services' ) . '</dt><dd>' . esc_html( ServiceArea::state( $r )[0] ) . '</dd></div>';

		foreach ( self::facts( $r ) as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}

		echo '</dl>';
		echo '<p class="bs-note">' . esc_html( CustomerDelete::billing_note( 'dedicated' ) ) . '</p>';

		if ( 'paused' === (string) $r->get( 'state' ) ) {
			echo '<p class="bs-note">' . esc_html__( 'Your server is switched off because the usage account has an unpaid invoice. Pay it to switch the server on again.', 'beltoft-services' ) . '</p>';
		}

		if ( ManualServerDelivery::has_root_password( $r ) && $delivered ) {
			self::assets();
			echo '<p><button type="button" class="button bs-btn" data-manual-reveal>' . esc_html__( 'Show root password', 'beltoft-services' ) . '</button></p>';
			echo '<p class="bs-note">' . esc_html__( 'We email you a code before the password is shown.', 'beltoft-services' ) . '</p>';
			echo '<p data-manual-password hidden><input type="text" readonly size="40" data-manual-password-field aria-label="' . esc_attr__( 'Root password', 'beltoft-services' ) . '"> <button type="button" class="button bs-btn bs-btn--sm" data-manual-copy>' . esc_html__( 'Copy', 'beltoft-services' ) . '</button></p>';
		}

		$beltoft_services_delete_id = $r->id();
		include BELTOFT_SERVICES_PATH . 'templates/account/delete.php';

		echo '</div>';
	}

	/**
	 * The admin part: delivery state and the form.
	 *
	 * @param Resource $r Resource.
	 */
	public static function admin( Resource $r ): void {
		$plan      = (array) $r->get( 'plan' );
		$delivered = (int) $r->get( ManualServerDelivery::READY_AT ) > 0;
		$rows      = array(
			__( 'Server name', 'beltoft-services' )         => (string) $r->get( 'server_name' ),
			__( 'Supplier product', 'beltoft-services' )    => (string) ( $plan['server_ref'] ?? '' ),
			__( 'Supplier location', 'beltoft-services' )   => (string) ( $plan['server_location_code'] ?? '' ),
			__( 'Cost per month (EUR)', 'beltoft-services' ) => (string) ( $plan['cost_monthly'] ?? '' ),
			__( 'Cost of setup (EUR)', 'beltoft-services' )  => (string) ( $plan['cost_setup'] ?? '' ),
			__( 'Ordered', 'beltoft-services' )             => (int) $r->get( ManualServerDelivery::REQUESTED_AT ) > 0 ? wp_date( 'Y-m-d H:i', (int) $r->get( ManualServerDelivery::REQUESTED_AT ) ) : '',
			__( 'Delivered', 'beltoft-services' )           => $delivered ? wp_date( 'Y-m-d H:i', (int) $r->get( ManualServerDelivery::READY_AT ) ) . ' ' . (string) $r->get( 'manual_ready_by' ) : '',
		);

		echo '<h3>' . esc_html__( 'Dedicated server', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '-' : $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( in_array( (string) $r->get( 'state' ), array( 'deleting', 'deleted' ), true ) ) {
			return;
		}

		echo '<h4>' . esc_html( $delivered ? __( 'Change the delivery details', 'beltoft-services' ) : __( 'Mark ready', 'beltoft-services' ) ) . '</h4>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::nonce_action( $r->id() ) );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="resource" value="' . esc_attr( (string) $r->id() ) . '">';
		echo '<p><label>' . esc_html__( 'IP addresses (one or more, shown to the customer)', 'beltoft-services' ) . '<br><textarea name="ips" rows="3" cols="50">' . esc_textarea( implode( "\n", ManualServerDelivery::ips( $r ) ) ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Login note for the customer (plain text, never a password)', 'beltoft-services' ) . '<br><textarea name="note" rows="3" cols="50" maxlength="' . (int) ManualServerDelivery::NOTE_MAX . '">' . esc_textarea( (string) $r->get( ManualServerDelivery::NOTE ) ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Supplier server number (staff only, never shown)', 'beltoft-services' ) . '<br><input type="text" name="provider_number" size="20" maxlength="40" value="' . esc_attr( (string) $r->get( ManualServerDelivery::PROVIDER_NO ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Root password (optional, stored encrypted, shown to the customer only behind an emailed code)', 'beltoft-services' ) . '<br><input type="password" name="root_password" size="30" maxlength="' . (int) ManualServerDelivery::PASSWORD_MAX . '" autocomplete="new-password" value=""></label><br><span class="description">' . esc_html( ManualServerDelivery::has_root_password( $r ) ? __( 'A password is set. Type a new one to replace it, or leave this empty to keep it.', 'beltoft-services' ) : __( 'No password is set.', 'beltoft-services' ) ) . '</span></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $delivered ? __( 'Save the details', 'beltoft-services' ) : __( 'Mark ready and tell the customer', 'beltoft-services' ) ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The nonce action of one server.
	 *
	 * @param int $resource_id Activation id.
	 */
	public static function nonce_action( int $resource_id ): string {
		return self::ACTION . '_' . $resource_id;
	}

	/**
	 * admin-post: mark a server ready, or change its details, then back to the licence screen.
	 */
	public static function handle(): void {
		if ( ! current_user_can( \BeltoftServices\Admin\Panel::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_POST['resource'] ) ? absint( $_POST['resource'] ) : 0;

		check_admin_referer( self::nonce_action( $id ) );

		$r    = Resource::find( $id );
		$args = array();

		if ( ! $r ) {
			$args['beltoft_services_manual_error'] = 'missing';
		} else {
			$user   = wp_get_current_user();
			$result = ManualServerDelivery::save(
				$r,
				isset( $_POST['ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ips'] ) ) : '',
				isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				isset( $_POST['provider_number'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_number'] ) ) : '',
				$user->exists() ? (string) $user->user_login : 'unknown',
				(int) $r->get( ManualServerDelivery::READY_AT ) < 1,
				isset( $_POST['root_password'] ) ? (string) wp_unslash( $_POST['root_password'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is not sanitized; it is length and character checked, then encrypted.
			);
			$args   = array( 'license' => (int) ( $r->license()['id'] ?? 0 ) );

			if ( is_wp_error( $result ) ) {
				$args['beltoft_services_manual_error'] = $result->get_error_message();
			} else {
				$args['beltoft_services_manual_ok'] = '1';
			}
		}

		wp_safe_redirect( \BeltoftLicenses\Admin\Screen::url( $args ) );
		exit;
	}

	/**
	 * The result of the last form.
	 */
	public static function notices(): void {
		if ( ! current_user_can( \BeltoftServices\Admin\Panel::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$ok    = isset( $_GET['beltoft_services_manual_ok'] );
		$error = isset( $_GET['beltoft_services_manual_error'] ) ? sanitize_text_field( wp_unslash( $_GET['beltoft_services_manual_error'] ) ) : '';
		// phpcs:enable

		if ( $ok ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The server details were saved.', 'beltoft-services' ) . '</p></div>';
		}

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
	}
}
