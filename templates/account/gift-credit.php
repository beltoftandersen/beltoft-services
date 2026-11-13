<?php
/**
 * My Account: credit held as gift cards, its history, the top-up form and the form that adds a gift card code.
 *
 * Override by copying to yourtheme/beltoft-services/account/gift-credit.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $credit The page data (see BeltoftServices\GiftCredit\Page::data()): currency, totals (paid, free, total in cents), history, topup (available, min, max, amounts), reserve.
 */

defined( 'ABSPATH' ) || exit;

use Bgca\Account;
use Bgca\Attach;
use Bgca\TopUp;

$beltoft_services_reserve = $credit['reserve'] ?? null;
$beltoft_services_money   = static fn( int $cents, bool $sign = false ): string => ( $cents < 0 ? '-' : ( $sign && $cents > 0 ? '+' : '' ) ) . Account::money( abs( $cents ), (string) $credit['currency'] );
$beltoft_services_first   = $credit['topup']['amounts'][0] ?? 0;
?>
<section class="beltoft-services-credit">
	<?php
	// What the last form did is told in the status bar (assets/credit.js); a refused code also marks its field.
	// A Bricks account page does not print notices for this page.
	if ( function_exists( 'wc_get_notices' ) ) {
		foreach ( wc_get_notices() as $beltoft_services_kind => $beltoft_services_list ) {
			foreach ( $beltoft_services_list as $beltoft_services_notice ) {
				$beltoft_services_text = wp_strip_all_tags( is_array( $beltoft_services_notice ) ? (string) ( $beltoft_services_notice['notice'] ?? '' ) : (string) $beltoft_services_notice );
				?>
				<p hidden data-bs-flash="<?php echo 'error' === $beltoft_services_kind ? 'fail' : 'done'; ?>"<?php echo 'error' === $beltoft_services_kind ? ' data-bs-field="#beltoft-credit-code"' : ''; ?>><?php echo esc_html( $beltoft_services_text ); ?></p>
				<?php
			}
		}
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
	}
	?>
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Credit', 'beltoft-services' ); ?></h2>
	</div>

	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-credit__label"><?php esc_html_e( 'Balance', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-credit__amount"><?php echo esc_html( $beltoft_services_money( (int) $credit['totals']['total'] ) ); ?></span>
	</div>
	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-credit__label"><?php esc_html_e( 'Paid credit', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-credit__amount"><?php echo esc_html( $beltoft_services_money( (int) $credit['totals']['paid'] ) ); ?></span>
	</div>
	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-credit__label"><?php esc_html_e( 'Free credit', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-credit__amount"><?php echo esc_html( $beltoft_services_money( (int) $credit['totals']['free'] ) ); ?></span>
	</div>
	<?php if ( (int) $credit['totals']['free'] > 0 ) : ?>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'Free credit pays for pay as you go usage and for the services marked for it. Paid credit pays for anything.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
	<?php if ( $beltoft_services_reserve ) : ?>
		<div class="beltoft-services-credit__balance">
			<span class="beltoft-services-credit__label"><?php esc_html_e( 'Server and database usage so far this month (estimate)', 'beltoft-services' ); ?></span>
			<span class="beltoft-services-credit__amount"><?php echo esc_html( $beltoft_services_money( (int) $beltoft_services_reserve['usage'] ) ); ?></span>
		</div>
		<div class="beltoft-services-credit__balance">
			<span class="beltoft-services-credit__label"><?php esc_html_e( "Left after this month's usage", 'beltoft-services' ); ?></span>
			<span class="beltoft-services-credit__amount"><?php echo esc_html( $beltoft_services_money( (int) $beltoft_services_reserve['left'] ) ); ?></span>
		</div>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'Usage is counted including VAT, as on the invoice. This is an estimate: nothing is taken from your balance until the invoice is paid.', 'beltoft-services' ); ?></p>
		<?php if ( $beltoft_services_reserve['carried'] ) : ?>
			<p class="beltoft-services-credit__note"><?php esc_html_e( 'This usage is under the minimum and is carried to next month, so no credit is used on it yet.', 'beltoft-services' ); ?></p>
		<?php elseif ( $beltoft_services_reserve['rest'] > 0 ) : ?>
			<p class="beltoft-services-credit__note"><?php echo esc_html( sprintf( /* translators: %s: amount with currency. */ __( 'Your usage is more than your balance. The rest, %s, is charged to your card.', 'beltoft-services' ), $beltoft_services_money( (int) $beltoft_services_reserve['rest'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $credit['topup']['available'] ) : ?>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'Top-ups are not available in your currency.', 'beltoft-services' ); ?></p>
	<?php else : ?>
	<form class="beltoft-services-credit__form" method="post" action="<?php echo esc_url( \BeltoftServices\GiftCredit\Page::url() ); ?>" data-bs-working="<?php esc_attr_e( 'Working…', 'beltoft-services' ); ?>">
		<fieldset class="beltoft-services-credit__amounts">
			<legend class="beltoft-services-credit__legend"><?php esc_html_e( 'Top up', 'beltoft-services' ); ?></legend>
			<div class="beltoft-services-credit__grid">
				<?php foreach ( $credit['topup']['amounts'] as $beltoft_services_amount ) : ?>
					<label class="beltoft-services-credit__tile">
						<input class="beltoft-services-credit__input" type="radio" name="bgca_amount" value="<?php echo esc_attr( (string) $beltoft_services_amount ); ?>"<?php checked( $beltoft_services_amount, $beltoft_services_first ); ?>>
						<span class="beltoft-services-credit__face"><?php echo esc_html( Account::money( (int) $beltoft_services_amount * 100, (string) $credit['currency'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
				<label class="beltoft-services-credit__tile">
					<input class="beltoft-services-credit__input" type="radio" name="bgca_amount" value="custom"<?php checked( 0, $beltoft_services_first ); ?>>
					<span class="beltoft-services-credit__face"><?php esc_html_e( 'Other', 'beltoft-services' ); ?></span>
				</label>
			</div>
			<p class="beltoft-services-credit__custom">
				<label class="screen-reader-text" for="beltoft-credit-custom"><?php esc_html_e( 'Other amount', 'beltoft-services' ); ?></label>
				<input id="beltoft-credit-custom" type="text" inputmode="decimal" name="bgca_custom" autocomplete="off" placeholder="<?php echo esc_attr( (string) $credit['topup']['min'] ); ?>">
			</p>
			<p class="beltoft-services-credit__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: smallest amount, 2: largest amount, with currency. */
						__( '%1$s to %2$s, all of it goes to your balance. No VAT on top-ups; VAT is on your usage invoices.', 'beltoft-services' ),
						Account::money( (int) $credit['topup']['min'] * 100, (string) $credit['currency'] ),
						Account::money( (int) $credit['topup']['max'] * 100, (string) $credit['currency'] )
					)
				);
				?>
			</p>
		</fieldset>
		<?php wp_nonce_field( TopUp::NONCE ); ?>
		<input type="hidden" name="<?php echo esc_attr( TopUp::ACTION ); ?>" value="1">
		<button type="submit" class="button beltoft-services-credit__submit"><?php esc_html_e( 'Continue to checkout', 'beltoft-services' ); ?></button>
	</form>
	<?php endif; ?>

	<form class="beltoft-services-credit__code" method="post" action="<?php echo esc_url( \BeltoftServices\GiftCredit\Page::url() ); ?>" novalidate data-bs-working="<?php esc_attr_e( 'Checking the code…', 'beltoft-services' ); ?>">
		<div class="beltoft-services-ov__head">
			<h3 class="beltoft-services-credit__legend"><?php esc_html_e( 'Have a gift card?', 'beltoft-services' ); ?></h3>
		</div>
		<div class="beltoft-services-credit__coderow">
			<label class="screen-reader-text" for="beltoft-credit-code"><?php esc_html_e( 'Gift card code', 'beltoft-services' ); ?></label>
			<input id="beltoft-credit-code" type="text" name="bgca_code" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
			<?php wp_nonce_field( Attach::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( Attach::ACTION ); ?>" value="1">
			<button type="submit" class="button beltoft-services-credit__submit"><?php esc_html_e( 'Add to my credit', 'beltoft-services' ); ?></button>
		</div>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'The gift card keeps its code and its balance becomes part of your credit.', 'beltoft-services' ); ?></p>
	</form>

	<div class="beltoft-services-ov__head beltoft-services-credit__historyhead">
		<h2><?php esc_html_e( 'History', 'beltoft-services' ); ?></h2>
	</div>
	<?php if ( ! $credit['history'] ) : ?>
		<p class="beltoft-services-credit__empty"><?php esc_html_e( 'Nothing yet. Top-ups and credit used on orders show here.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<table class="beltoft-services-credit__history">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Balance', 'beltoft-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $credit['history'] as $beltoft_services_row ) : ?>
					<?php
					$beltoft_services_order = $beltoft_services_row['order_id'] > 0 && $beltoft_services_row['cents'] < 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $beltoft_services_row['order_id'] ) : null;
					$beltoft_services_order = $beltoft_services_order instanceof \WC_Order && (int) $beltoft_services_order->get_customer_id() === get_current_user_id() ? $beltoft_services_order : null;
					?>
					<tr>
						<td><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $beltoft_services_row['created'] . ' UTC' ) ) ); ?></td>
						<td>
							<?php if ( $beltoft_services_order ) : ?>
								<?php esc_html_e( 'Used on order', 'beltoft-services' ); ?>
								<?php $beltoft_services_names = \BeltoftServices\GiftCredit\Page::order_names( $beltoft_services_order ); ?>
								<?php /* translators: %s: order number. */ ?>
								<a href="<?php echo esc_url( $beltoft_services_order->get_view_order_url() ); ?>"><?php echo esc_html( sprintf( _x( '#%s', 'order number in the credit history', 'beltoft-services' ), $beltoft_services_order->get_order_number() ) ); ?></a><?php echo '' !== $beltoft_services_names ? esc_html( sprintf( /* translators: %s: what the order was for. */ __( ': %s', 'beltoft-services' ), $beltoft_services_names ) ) : ''; ?>
							<?php elseif ( 'Moved from account credit' === (string) $beltoft_services_row['note'] ) : ?>
								<?php esc_html_e( 'Moved from account credit', 'beltoft-services' ); ?>
							<?php else : ?>
								<?php echo esc_html( $beltoft_services_row['note'] ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $beltoft_services_money( (int) $beltoft_services_row['cents'], true ) ); ?></td>
						<td><?php echo esc_html( $beltoft_services_money( (int) $beltoft_services_row['balance'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
