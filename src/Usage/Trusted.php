<?php
/**
 * Trusted pay-as-you-go customers: a shop manager lifts the new-account rules for one customer.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * A new account may run usage_new_limit services and is billed early at usage_threshold_new until its first usage invoice
 * is paid. A customer marked trusted on their user profile gets the rules of an account that has paid: no service limit
 * from this rule, and the normal early-billing threshold. Only shop managers see and change the mark.
 */
final class Trusted {

	public const META = '_beltoft_services_usage_trusted';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/**
	 * Whether the customer is marked trusted.
	 *
	 * @param int $user_id Customer.
	 */
	public static function is( int $user_id ): bool {
		return $user_id > 0 && 'yes' === get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Whether the new-account rules no longer apply: the account has paid a usage invoice, or the customer is trusted.
	 *
	 * @param int                   $user_id Customer.
	 * @param \WC_Subscription|null $account The customer's usage account, if any.
	 */
	public static function established( int $user_id, ?\WC_Subscription $account ): bool {
		return Account::charged( $account ) || self::is( $user_id );
	}

	/**
	 * The checkbox on the user profile.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public static function field( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_nonce_field( 'beltoft_services_usage_trusted', 'beltoft_services_usage_trusted_nonce' );
		?>
		<h2><?php esc_html_e( 'Pay as you go', 'beltoft-services' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Trusted customer', 'beltoft-services' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="beltoft_services_usage_trusted" value="yes" <?php checked( self::is( (int) $user->ID ) ); ?> />
						<?php esc_html_e( 'Lift the new-account limits: no limit on the number of pay as you go services, and early billing at the normal amount.', 'beltoft-services' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the checkbox.
	 *
	 * @param int $user_id User being saved.
	 */
	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['beltoft_services_usage_trusted_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['beltoft_services_usage_trusted_nonce'] ) ), 'beltoft_services_usage_trusted' ) ) {
			return;
		}
		$on = isset( $_POST['beltoft_services_usage_trusted'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['beltoft_services_usage_trusted'] ) );
		if ( $on ) {
			update_user_meta( $user_id, self::META, 'yes' );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}
}
