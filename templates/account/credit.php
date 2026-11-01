<?php
/**
 * My Account: credit, the balance, its history and the top-up form.
 *
 * Override by copying to yourtheme/beltoft-services/account/credit.php.
 *
 * @package BeltoftServices
 *
 * @var array{balance: int, history: array<int, array<string, mixed>>, amounts: int[], min: int, max: int, currency: string} $credit The page data (see BeltoftServices\Credit\Page::data()).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;

$beltoft_services_credit_first = $credit['amounts'][0] ?? 0;
?>
<section class="beltoft-services-credit">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Credit', 'beltoft-services' ); ?></h2>
	</div>

	<div class="beltoft-services-credit__balance">
		<span class="beltoft-services-ov__label"><?php esc_html_e( 'Balance', 'beltoft-services' ); ?></span>
		<span class="beltoft-services-ov__big"><?php echo esc_html( Page::money( $credit['balance'], $credit['currency'] ) ); ?></span>
	</div>

	<form class="beltoft-services-credit__form" method="post" action="<?php echo esc_url( Page::url() ); ?>">
		<fieldset class="beltoft-services-credit__amounts">
			<legend><?php esc_html_e( 'Top up', 'beltoft-services' ); ?></legend>
			<div class="beltoft-services-credit__grid">
				<?php foreach ( $credit['amounts'] as $beltoft_services_credit_amount ) : ?>
					<label class="beltoft-services-credit__tile">
						<input class="beltoft-services-credit__input" type="radio" name="beltoft_credit_amount" value="<?php echo esc_attr( (string) $beltoft_services_credit_amount ); ?>"<?php checked( $beltoft_services_credit_amount, $beltoft_services_credit_first ); ?>>
						<span class="beltoft-services-credit__face"><?php echo esc_html( Page::money( $beltoft_services_credit_amount * 100, $credit['currency'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
				<label class="beltoft-services-credit__tile">
					<input class="beltoft-services-credit__input" type="radio" name="beltoft_credit_amount" value="custom"<?php checked( 0, $beltoft_services_credit_first ); ?>>
					<span class="beltoft-services-credit__face"><?php esc_html_e( 'Other amount', 'beltoft-services' ); ?></span>
				</label>
			</div>
			<p class="beltoft-services-credit__custom">
				<label for="beltoft-credit-custom"><?php esc_html_e( 'Other amount', 'beltoft-services' ); ?></label>
				<input id="beltoft-credit-custom" type="text" inputmode="decimal" name="beltoft_credit_custom" autocomplete="off" placeholder="<?php echo esc_attr( (string) $credit['min'] ); ?>">
			</p>
			<p class="beltoft-services-credit__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: smallest amount, 2: largest amount, with currency. */
						__( 'From %1$s to %2$s. You pay this amount and all of it is added to your balance.', 'beltoft-services' ),
						Topup::label_amount( $credit['min'] * 100, $credit['currency'] ),
						Topup::label_amount( $credit['max'] * 100, $credit['currency'] )
					)
				);
				?>
			</p>
			<p class="beltoft-services-credit__note"><?php echo esc_html( Topup::receipt_text() ); ?></p>
		</fieldset>
		<?php wp_nonce_field( Topup::NONCE ); ?>
		<input type="hidden" name="<?php echo esc_attr( Topup::ACTION ); ?>" value="1">
		<button type="submit" class="button beltoft-services-credit__submit"><?php esc_html_e( 'Continue to checkout', 'beltoft-services' ); ?></button>
	</form>

	<h3><?php esc_html_e( 'History', 'beltoft-services' ); ?></h3>
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
