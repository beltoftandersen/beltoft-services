<?php
/**
 * My Account: credit, the balance, its history and the top-up form.
 *
 * Override by copying to yourtheme/beltoft-services/account/credit.php.
 *
 * @package BeltoftServices
 *
 * @var array{balance: int, history: array<int, array<string, mixed>>, amounts: int[], min: int, max: int, available: bool, currency: string} $credit The page data (see BeltoftServices\Credit\Page::data()).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Credit\Code;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Usage\Rates;

$beltoft_services_reserve = $credit['reserve'] ?? null;

$beltoft_services_credit_first = $credit['amounts'][0] ?? 0;
?>
<section class="beltoft-services-credit">
	<?php
	// A redeemed or refused code is told in the status bar (assets/credit.js); a refused code also marks its field (not repeated there).
	// A Bricks account page does not print notices for this page.
	if ( function_exists( 'wc_get_notices' ) ) {
		foreach ( wc_get_notices() as $beltoft_services_kind => $beltoft_services_list ) {
			foreach ( $beltoft_services_list as $beltoft_services_notice ) {
				$beltoft_services_text  = wp_strip_all_tags( is_array( $beltoft_services_notice ) ? (string) ( $beltoft_services_notice['notice'] ?? '' ) : (string) $beltoft_services_notice );
				$beltoft_services_field = is_array( $beltoft_services_notice ) && ! empty( $beltoft_services_notice['data']['beltoft_field'] );
				?>
				<p hidden data-bs-flash="<?php echo 'error' === $beltoft_services_kind ? 'fail' : 'done'; ?>"<?php echo $beltoft_services_field ? ' data-bs-field="#beltoft-credit-code"' : ''; ?>><?php echo esc_html( $beltoft_services_text ); ?></p>
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
		<span class="beltoft-services-credit__amount"><?php echo esc_html( Page::money( $credit['balance'], $credit['currency'] ) ); ?></span>
	</div>

	<?php if ( ! empty( $credit['promo'] ) ) : ?>
		<p class="beltoft-services-credit__note"><?php echo esc_html( sprintf( /* translators: %s: amount with currency. */ __( 'Of this, %s is promotional credit. It pays for pay as you go usage and for the services marked for it.', 'beltoft-services' ), Page::money( (int) $credit['promo'], $credit['currency'] ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( $beltoft_services_reserve ) : ?>
		<div class="beltoft-services-credit__balance">
			<span class="beltoft-services-credit__label"><?php esc_html_e( 'Used so far this month (estimate)', 'beltoft-services' ); ?></span>
			<span class="beltoft-services-credit__amount"><?php echo esc_html( Page::money( $beltoft_services_reserve['usage'], $credit['currency'] ) ); ?></span>
		</div>
		<div class="beltoft-services-credit__balance">
			<span class="beltoft-services-credit__label"><?php esc_html_e( "Left after this month's usage", 'beltoft-services' ); ?></span>
			<span class="beltoft-services-credit__amount"><?php echo esc_html( Page::money( $beltoft_services_reserve['left'], $credit['currency'] ) ); ?></span>
		</div>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'Usage is counted including VAT, as on the invoice. This is an estimate: nothing is taken from your balance until the invoice is made.', 'beltoft-services' ); ?></p>
		<?php if ( $beltoft_services_reserve['carried'] ) : ?>
			<p class="beltoft-services-credit__note"><?php esc_html_e( 'This usage is under the minimum and is carried to next month, so no credit is used on it yet.', 'beltoft-services' ); ?></p>
		<?php elseif ( $beltoft_services_reserve['rest'] > 0 ) : ?>
			<p class="beltoft-services-credit__note"><?php echo esc_html( sprintf( /* translators: %s: amount with currency. */ __( 'Your usage is more than your balance. The rest, %s, is charged to your card.', 'beltoft-services' ), Page::money( $beltoft_services_reserve['rest'], $credit['currency'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $credit['available'] ) : ?>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'Top-ups are not available in your currency.', 'beltoft-services' ); ?></p>
	<?php else : ?>
	<form class="beltoft-services-credit__form" method="post" action="<?php echo esc_url( Page::url() ); ?>" data-bs-working="<?php esc_attr_e( 'Working…', 'beltoft-services' ); ?>">
		<fieldset class="beltoft-services-credit__amounts">
			<legend class="beltoft-services-credit__legend"><?php esc_html_e( 'Top up', 'beltoft-services' ); ?></legend>
			<div class="beltoft-services-credit__grid">
				<?php foreach ( $credit['amounts'] as $beltoft_services_credit_amount ) : ?>
					<label class="beltoft-services-credit__tile">
						<input class="beltoft-services-credit__input" type="radio" name="beltoft_credit_amount" value="<?php echo esc_attr( (string) $beltoft_services_credit_amount ); ?>"<?php checked( $beltoft_services_credit_amount, $beltoft_services_credit_first ); ?>>
						<span class="beltoft-services-credit__face"><?php echo esc_html( Rates::money( (float) $beltoft_services_credit_amount, 0, $credit['currency'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
				<label class="beltoft-services-credit__tile">
					<input class="beltoft-services-credit__input" type="radio" name="beltoft_credit_amount" value="custom"<?php checked( 0, $beltoft_services_credit_first ); ?>>
					<span class="beltoft-services-credit__face"><?php esc_html_e( 'Other', 'beltoft-services' ); ?></span>
				</label>
			</div>
			<p class="beltoft-services-credit__custom">
				<label class="screen-reader-text" for="beltoft-credit-custom"><?php esc_html_e( 'Other amount', 'beltoft-services' ); ?></label>
				<input id="beltoft-credit-custom" type="text" inputmode="decimal" name="beltoft_credit_custom" autocomplete="off" placeholder="<?php echo esc_attr( (string) $credit['min'] ); ?>">
			</p>
			<p class="beltoft-services-credit__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: smallest amount, 2: largest amount, with currency. */
						__( '%1$s to %2$s, all of it goes to your balance. No VAT on top-ups; VAT is on your usage invoices.', 'beltoft-services' ),
						Rates::money( (float) $credit['min'], 0, $credit['currency'] ),
						Rates::money( (float) $credit['max'], 0, $credit['currency'] )
					)
				);
				?>
			</p>
		</fieldset>
		<?php wp_nonce_field( Topup::NONCE ); ?>
		<input type="hidden" name="<?php echo esc_attr( Topup::ACTION ); ?>" value="1">
		<button type="submit" class="button beltoft-services-credit__submit"><?php esc_html_e( 'Continue to checkout', 'beltoft-services' ); ?></button>
	</form>
	<?php endif; ?>

	<form class="beltoft-services-credit__code" method="post" action="<?php echo esc_url( Page::url() ); ?>" novalidate data-bs-working="<?php esc_attr_e( 'Checking the code…', 'beltoft-services' ); ?>">
		<div class="beltoft-services-ov__head">
			<h2><?php esc_html_e( 'Have a credit code?', 'beltoft-services' ); ?></h2>
		</div>
		<div class="beltoft-services-credit__coderow">
			<label class="screen-reader-text" for="beltoft-credit-code"><?php esc_html_e( 'Credit code', 'beltoft-services' ); ?></label>
			<input id="beltoft-credit-code" type="text" name="beltoft_credit_code" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
			<?php wp_nonce_field( Code::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( Code::ACTION ); ?>" value="1">
			<button type="submit" class="button beltoft-services-credit__submit"><?php esc_html_e( 'Redeem', 'beltoft-services' ); ?></button>
		</div>
		<p class="beltoft-services-credit__note"><?php esc_html_e( 'You need a saved card to redeem a code. Each card can use a code once.', 'beltoft-services' ); ?></p>
	</form>

	<div class="beltoft-services-ov__head beltoft-services-credit__historyhead">
		<h2><?php esc_html_e( 'History', 'beltoft-services' ); ?></h2>
	</div>
	<?php if ( ! $credit['history'] ) : ?>
		<p class="beltoft-services-credit__empty"><?php esc_html_e( 'Nothing yet. Top-ups and credit used on invoices show here.', 'beltoft-services' ); ?></p>
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
				<?php foreach ( $credit['history'] as $beltoft_services_credit_row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $beltoft_services_credit_row['created'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( Page::row_label( $beltoft_services_credit_row ) ); ?></td>
						<td><?php echo esc_html( Page::money( (int) $beltoft_services_credit_row['amount_cents'], $credit['currency'], true ) ); ?></td>
						<td><?php echo esc_html( Page::money( (int) $beltoft_services_credit_row['balance_after_cents'], $credit['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
