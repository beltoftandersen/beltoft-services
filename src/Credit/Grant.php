<?php
/**
 * Credit an administrator gives or corrects without a payment, and the screens and commands for it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Credit;

defined( 'ABSPATH' ) || exit;

/**
 * Promotional or goodwill credit: a ledger row of kind `promo` (a positive amount) or `adjust` (a negative correction),
 * reference type `admin`, reference id "admin-<admin user id>-<request id>", the reason as the note. It is not a sale, so
 * it carries no VAT, and the balance pays usage invoices with it exactly like a top-up. A refund or chargeback of a
 * top-up only touches that top-up's own rows, never these. The write holds the balance lock; a request id makes a
 * form that is sent twice write once. A correction that would take the balance below 0 is refused unless it is
 * explicitly allowed.
 */
final class Grant {

	public const ACTION = 'beltoft_services_credit_grant';
	public const NONCE  = 'beltoft_services_credit_grant';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Cents of a typed amount: digits with at most two decimals and an optional minus sign, not 0.
	 *
	 * @param mixed $raw Typed value.
	 */
	public static function parse( $raw ): ?int {
		$raw = trim( str_replace( ',', '.', (string) $raw ) );

		if ( ! preg_match( '/^-?\d{1,7}(\.\d{1,2})?$/D', $raw ) ) {
			return null;
		}

		$cents = Balance::cents( $raw );

		return 0 !== $cents ? $cents : null;
	}

	/**
	 * Adds (or, negative, takes away) credit.
	 *
	 * @param int    $user_id        Customer.
	 * @param int    $cents          Signed cents, not 0.
	 * @param string $reason         Why (required).
	 * @param int    $admin_id       Who.
	 * @param string $request_id     Unique id of this request; the same id is written once.
	 * @param bool   $allow_negative Whether a correction may take the balance below 0.
	 * @return true|string True when written (or already written for this request id), else why not.
	 */
	public static function add( int $user_id, int $cents, string $reason, int $admin_id, string $request_id, bool $allow_negative = false ) {
		$reason = trim( preg_replace( '/\s+/', ' ', $reason ) );

		if ( ! Config::enabled() ) {
			return __( 'Account credit is switched off.', 'beltoft-services' );
		}

		if ( ! get_userdata( $user_id ) ) {
			return __( 'There is no such customer.', 'beltoft-services' );
		}

		if ( 0 === $cents ) {
			return __( 'Enter an amount, such as 10 or -2.50.', 'beltoft-services' );
		}

		if ( '' === $reason ) {
			return __( 'A reason is required.', 'beltoft-services' );
		}

		if ( '' === $request_id || strlen( $request_id ) > 36 ) {
			return __( 'The request has no valid id.', 'beltoft-services' );
		}

		$ref = 'admin-' . $admin_id . '-' . $request_id;
		$cap = $cents > 0 ? Balance::PROMO : Balance::ADJUST;

		if ( Balance::exists( $cap, 'admin', $ref ) ) {
			return true;
		}

		// An admin credit is in the customer's credit currency (the one they have, else the one they would be priced in).
		$currency = Balance::currency_or_user( $user_id );

		list( $locked, $result ) = Balance::locked(
			$user_id,
			static function () use ( $user_id, $cents, $reason, $ref, $cap, $allow_negative, $currency ) {
				if ( $cents < 0 && ! $allow_negative && Balance::get( $user_id ) + $cents < 0 ) {
					return __( 'That would take the balance below 0. Tick the box to allow it.', 'beltoft-services' );
				}

				$note = substr( $reason, 0, 255 );
				$done = $cents > 0 ? Balance::credit( $user_id, $cents, $cap, 'admin', $ref, $note, $currency ) : Balance::debit( $user_id, -$cents, $cap, 'admin', $ref, $note, $currency );

				return $done ? true : __( 'The credit could not be written.', 'beltoft-services' );
			}
		);

		return $locked ? $result : __( 'The balance is busy. Try again.', 'beltoft-services' );
	}

	/**
	 * The "Add account credit" box on a user's profile.
	 *
	 * @param mixed $user Profile user.
	 */
	public static function render( $user ): void {
		if ( ! $user instanceof \WP_User || ! Config::enabled() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$currency = Balance::currency_or_user( (int) $user->ID );
		$notice   = isset( $_GET['beltoft_credit'] ) ? sanitize_text_field( wp_unslash( $_GET['beltoft_credit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$rows     = Balance::history( (int) $user->ID, 10 );
		?>
		<h2 id="beltoft-services-credit"><?php esc_html_e( 'Account credit', 'beltoft-services' ); ?></h2>
		<?php if ( 'done' === $notice ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'The credit was saved.', 'beltoft-services' ); ?></p></div>
		<?php elseif ( '' !== $notice ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Balance', 'beltoft-services' ); ?></th>
				<td><strong><?php echo esc_html( Page::money( Balance::get( (int) $user->ID ), $currency ) ); ?></strong></td>
			</tr>
			<tr>
				<th><label for="beltoft-credit-amount"><?php esc_html_e( 'Add account credit', 'beltoft-services' ); ?></label></th>
				<td>
					<input type="text" id="beltoft-credit-amount" name="beltoft_credit_admin_amount" form="beltoft-credit-form" inputmode="decimal" autocomplete="off" placeholder="10.00" class="regular-text">
					<p class="description"><?php esc_html_e( 'In the customer\'s credit currency, without VAT. A negative amount is a correction.', 'beltoft-services' ); ?></p>
					<p><input type="text" name="beltoft_credit_admin_reason" form="beltoft-credit-form" class="regular-text" placeholder="<?php esc_attr_e( 'Reason, for example Welcome credit', 'beltoft-services' ); ?>" aria-label="<?php esc_attr_e( 'Reason', 'beltoft-services' ); ?>"></p>
					<p><label><input type="checkbox" name="beltoft_credit_admin_negative" value="1" form="beltoft-credit-form"> <?php esc_html_e( 'Allow a correction to take the balance below 0', 'beltoft-services' ); ?></label></p>
					<p><button type="submit" form="beltoft-credit-form" class="button"><?php esc_html_e( 'Save credit', 'beltoft-services' ); ?></button></p>
				</td>
			</tr>
			<?php if ( $rows ) : ?>
				<tr>
					<th><?php esc_html_e( 'Latest rows', 'beltoft-services' ); ?></th>
					<td>
						<?php foreach ( $rows as $row ) : ?>
							<?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['created'] . ' UTC' ) ) . ': ' . Page::row_label( $row ) . ', ' . Page::money( (int) $row['amount_cents'], $currency, true ) ); ?><br>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
		// A form of its own: the profile page is one big form, and forms do not nest. The inputs above point at it with form=.
		add_action(
			'admin_footer',
			static function () use ( $user ): void {
				?>
				<form id="beltoft-credit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
					<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
				</form>
				<?php
			}
		);
	}

	/**
	 * The form's handler.
	 */
	public static function handle(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beltoft-services' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		$cents  = self::parse( isset( $_POST['beltoft_credit_admin_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['beltoft_credit_admin_amount'] ) ) : '' );
		$result = null === $cents
			? __( 'Enter an amount, such as 10 or -2.50.', 'beltoft-services' )
			: self::add(
				$user_id,
				$cents,
				isset( $_POST['beltoft_credit_admin_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['beltoft_credit_admin_reason'] ) ) : '',
				get_current_user_id(),
				isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : '',
				! empty( $_POST['beltoft_credit_admin_negative'] )
			);

		wp_safe_redirect( add_query_arg( 'beltoft_credit', true === $result ? 'done' : rawurlencode( (string) $result ), get_edit_user_link( $user_id ) ) . '#beltoft-services-credit' );
		exit;
	}
}
