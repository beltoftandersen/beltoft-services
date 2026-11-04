<?php
/**
 * My Account, payment methods: the card used for Pay as you go invoices.
 *
 * Override by copying to yourtheme/beltoft-services/account/card.php.
 *
 * @package BeltoftServices
 *
 * @var array{state: string, text: string, can_start: bool, flow: string, add_url: string, change: bool, notice: string, notice_kind: string, action: string} $card The data (see BeltoftServices\Card\Card::data()).
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Card\Card;
?>
<section class="beltoft-services-card">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Card', 'beltoft-services' ); ?></h2>
	</div>
	<p class="beltoft-services-card__sub"><?php esc_html_e( 'Card used for Pay as you go invoices', 'beltoft-services' ); ?></p>

	<?php if ( '' !== $card['notice'] ) : ?>
		<?php /* assets/card.js hands the result of the last card form to the status bar. */ ?>
		<p hidden data-bs-flash="<?php echo 'error' === $card['notice_kind'] ? 'fail' : 'done'; ?>"><?php echo esc_html( $card['notice'] ); ?></p>
	<?php endif; ?>

	<?php if ( 'valid' === $card['state'] ) : ?>
		<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
	<?php elseif ( 'expired' === $card['state'] ) : ?>
		<?php if ( '' !== $card['text'] ) : ?>
			<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
		<?php endif; ?>
		<p class="beltoft-services-card__warn"><?php esc_html_e( 'Your card has expired. Change it to keep Pay as you go services running.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'unknown' === $card['state'] ) : ?>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'We could not check your card just now.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'No card saved. Pay as you go services are invoiced to a saved card.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( $card['can_start'] ) : ?>
		<?php if ( 'token' === ( $card['flow'] ?? '' ) ) : ?>
		<div class="beltoft-services-card__form">
			<a class="button beltoft-services-card__btn" data-card-go href="<?php echo esc_url( $card['add_url'] ); ?>"><?php echo $card['change'] ? esc_html__( 'Change card', 'beltoft-services' ) : esc_html__( 'Add a card', 'beltoft-services' ); ?></a>
			<span class="beltoft-services-card__note"><?php esc_html_e( 'Nothing is charged. You enter your card on the payment provider\'s secure form.', 'beltoft-services' ); ?></span>
		</div>
		<?php else : ?>
		<form class="beltoft-services-card__form" method="post" action="<?php echo esc_url( Card::url() ); ?>" data-bs-working="<?php esc_attr_e( 'Working…', 'beltoft-services' ); ?>">
			<?php wp_nonce_field( Card::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( $card['action'] ); ?>" value="1">
			<button type="submit" class="button beltoft-services-card__btn"><?php echo $card['change'] ? esc_html__( 'Change card', 'beltoft-services' ) : esc_html__( 'Add a card', 'beltoft-services' ); ?></button>
			<span class="beltoft-services-card__note"><?php esc_html_e( 'Nothing is charged. You enter your card on the payment provider\'s secure form.', 'beltoft-services' ); ?></span>
		</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
