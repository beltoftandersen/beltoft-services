<?php
/**
 * My Account, payment methods: the card used for Pay as you go invoices (the customer's default saved card).
 *
 * Override by copying to yourtheme/beltoft-services/account/card.php.
 *
 * @package BeltoftServices
 *
 * @var array{state: string, text: string, can_start: bool, add_url: string, change: bool} $card The data (see BeltoftServices\Card\Card::data()).
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-card">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Card', 'beltoft-services' ); ?></h2>
	</div>

	<?php if ( 'valid' === $card['state'] ) : ?>
		<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'This card is used for Pay as you go invoices.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'expired' === $card['state'] ) : ?>
		<p class="beltoft-services-card__card"><?php echo esc_html( $card['text'] ); ?></p>
		<p class="beltoft-services-card__warn"><?php esc_html_e( 'Your card has expired. Add a new card to keep Pay as you go services running.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p class="beltoft-services-card__note"><?php esc_html_e( 'No card saved. Pay as you go services are invoiced to a saved card.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( $card['can_start'] ) : ?>
		<div class="beltoft-services-card__form">
			<a class="button beltoft-services-card__btn" href="<?php echo esc_url( $card['add_url'] ); ?>"><?php echo $card['change'] ? esc_html__( 'Change card', 'beltoft-services' ) : esc_html__( 'Add a card', 'beltoft-services' ); ?></a>
		</div>
	<?php endif; ?>
</section>
