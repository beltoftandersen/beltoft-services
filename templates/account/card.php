<?php
/**
 * My Account, payment methods: the card used for Pay as you go invoices.
 *
 * Override by copying to yourtheme/beltoft-services/account/card.php.
 *
 * @package BeltoftServices
 *
 * @var array{state: string, text: string, can_add: bool, can_replace: bool, notice: string, notice_kind: string, add_action: string, replace_action: string} $card The data (see BeltoftServices\Card\Card::data()).
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
		<p class="beltoft-services-card__notice is-<?php echo esc_attr( $card['notice_kind'] ); ?>" role="status"><?php echo esc_html( $card['notice'] ); ?></p>
	<?php endif; ?>

	<?php if ( 'valid' === $card['state'] ) : ?>
		<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
	<?php elseif ( 'expired' === $card['state'] ) : ?>
		<?php if ( '' !== $card['text'] ) : ?>
			<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
		<?php endif; ?>
		<p class="beltoft-services-card__warn"><?php esc_html_e( 'Your card has expired. Replace it to keep Pay as you go services running.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'unknown' === $card['state'] ) : ?>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'We could not check your card just now.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'No card saved. Pay as you go services are invoiced to a saved card.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( $card['can_add'] ) : ?>
		<form class="beltoft-services-card__form" method="post" action="<?php echo esc_url( Card::url() ); ?>">
			<?php wp_nonce_field( Card::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( $card['add_action'] ); ?>" value="1">
			<?php if ( ! empty( $card['cart_busy'] ) ) : ?>
				<button type="submit" class="button beltoft-services-card__btn"><?php esc_html_e( 'Empty cart and add a card', 'beltoft-services' ); ?></button>
				<span class="beltoft-services-card__note"><?php esc_html_e( 'Your cart has items. They are removed so the card is saved on its own. Nothing is charged.', 'beltoft-services' ); ?></span>
			<?php else : ?>
				<button type="submit" class="button beltoft-services-card__btn"><?php esc_html_e( 'Add a card', 'beltoft-services' ); ?></button>
				<span class="beltoft-services-card__note"><?php esc_html_e( 'Nothing is charged. You enter your billing details and card on the next pages.', 'beltoft-services' ); ?></span>
			<?php endif; ?>
		</form>
	<?php elseif ( $card['can_replace'] ) : ?>
		<form class="beltoft-services-card__form" method="post" action="<?php echo esc_url( Card::url() ); ?>">
			<?php wp_nonce_field( Card::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( $card['replace_action'] ); ?>" value="1">
			<button type="submit" class="button beltoft-services-card__btn"><?php esc_html_e( 'Replace card', 'beltoft-services' ); ?></button>
			<span class="beltoft-services-card__note"><?php esc_html_e( 'Nothing is charged. The old card is removed when the new one is saved.', 'beltoft-services' ); ?></span>
		</form>
	<?php endif; ?>
</section>
