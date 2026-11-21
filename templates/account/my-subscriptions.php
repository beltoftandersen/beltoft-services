<?php
/**
 * My Account → Billing → Subscriptions: the list.
 *
 * Replaces WooCommerce Subscriptions' myaccount/my-subscriptions.php (swapped by Account\SubscriptionPages).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

use BeltoftServices\Account\SubscriptionPages;

// The pay-as-you-go card first: this template runs after WooCommerce's notices, so notices stay on top.
echo \BeltoftServices\Account\UsageView::card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.

$beltoft_services_groups = SubscriptionPages::groups( get_current_user_id() );

if ( array() === $beltoft_services_groups['live'] && array() === $beltoft_services_groups['ended'] ) {
	// Only the pay-as-you-go card, or nothing at all.
	if ( ! \BeltoftServices\Usage\Account::for_user( get_current_user_id() ) ) {
		echo '<p class="no_subscriptions woocommerce-info">' . esc_html__( 'You have no active subscriptions.', 'woocommerce-subscriptions' ) . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	return;
}

?>
<div class="woocommerce_account_subscriptions bs-subscriptions">
	<h2 class="bs-subscriptions__title"><?php esc_html_e( 'Subscriptions', 'beltoft-services' ); ?></h2>
	<?php
	if ( array() !== $beltoft_services_groups['live'] ) {
		echo SubscriptionPages::rows_html( $beltoft_services_groups['live'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built.
	}

	if ( array() !== $beltoft_services_groups['ended'] ) :
		?>
		<details class="bs-subscriptions__ended">
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of ended subscriptions. */ __( 'Ended subscriptions (%d)', 'beltoft-services' ), count( $beltoft_services_groups['ended'] ) ) ); ?></summary>
			<?php echo SubscriptionPages::rows_html( $beltoft_services_groups['ended'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
		</details>
	<?php endif; ?>
</div>
