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
use BeltoftServices\Billing\SubscriptionNames;

$beltoft_services_groups = SubscriptionPages::groups( get_current_user_id() );

if ( array() === $beltoft_services_groups['live'] && array() === $beltoft_services_groups['ended'] ) {
	// Only the pay-as-you-go card, or nothing at all.
	if ( ! \BeltoftServices\Usage\Account::for_user( get_current_user_id() ) ) {
		echo '<p class="no_subscriptions woocommerce-info">' . esc_html__( 'You have no active subscriptions.', 'woocommerce-subscriptions' ) . '</p>'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	return;
}

/**
 * Prints one table of subscriptions.
 *
 * @param array<int, WC_Subscription> $list Subscriptions.
 */
$beltoft_services_table = static function ( array $list ): void {
	?>
	<table class="my_account_subscriptions my_account_orders woocommerce-orders-table woocommerce-MyAccount-subscriptions shop_table shop_table_responsive woocommerce-orders-table--subscriptions bs-subs">
		<thead>
			<tr>
				<th class="subscription-id"><span class="nobr"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span></th>
				<th class="subscription-status"><span class="nobr"><?php esc_html_e( 'Status', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span></th>
				<th class="subscription-next-payment"><span class="nobr"><?php echo esc_html_x( 'Next payment', 'table heading', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span></th>
				<th class="subscription-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span></th>
				<th class="subscription-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $list as $subscription ) : ?>
			<?php $beltoft_services_names = SubscriptionNames::names( $subscription ); ?>
			<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $subscription->get_status() ); ?>">
				<td class="subscription-id" data-title="">
					<a class="bs-sub-title" href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>"><?php echo esc_html( '' !== $beltoft_services_names ? $beltoft_services_names : sprintf( '#%s', $subscription->get_order_number() ) ); ?></a>
					<?php if ( '' !== $beltoft_services_names ) : ?>
						<span class="bs-sub-name"><?php echo esc_html( sprintf( '#%s', $subscription->get_order_number() ) ); ?></span>
					<?php endif; ?>
				</td>
				<td class="subscription-status" data-title="<?php esc_attr_e( 'Status', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>">
					<?php echo esc_html( wcs_get_subscription_status_name( $subscription->get_status() ) ); ?>
				</td>
				<td class="subscription-next-payment" data-title="<?php echo esc_attr_x( 'Next Payment', 'table heading', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>">
					<?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?>
					<?php if ( ! $subscription->is_manual() && $subscription->has_status( 'active' ) && $subscription->get_time( 'next_payment' ) > 0 ) : ?>
						<br/><small><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></small>
					<?php endif; ?>
				</td>
				<td class="subscription-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>">
					<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
				</td>
				<td class="subscription-actions">
					<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php echo esc_html_x( 'View', 'view a subscription', 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
					<?php do_action( 'woocommerce_my_subscriptions_actions', $subscription ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<div class="woocommerce_account_subscriptions bs-subscriptions">
	<h2 class="bs-subscriptions__title"><?php esc_html_e( 'Subscriptions', 'beltoft-services' ); ?></h2>
	<?php
	if ( array() !== $beltoft_services_groups['live'] ) {
		$beltoft_services_table( $beltoft_services_groups['live'] );
	}

	if ( array() !== $beltoft_services_groups['ended'] ) :
		?>
		<details class="bs-subscriptions__ended">
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of ended subscriptions. */ __( 'Ended (%d)', 'beltoft-services' ), count( $beltoft_services_groups['ended'] ) ) ); ?></summary>
			<?php $beltoft_services_table( $beltoft_services_groups['ended'] ); ?>
		</details>
	<?php endif; ?>
</div>
