<?php
/**
 * The Delete block of a pay-as-you-go server or database: what deleting does, the billing line and the typed confirmation.
 * Printed by the server page (templates/account/vm.php) and the database page (templates/account/db.php); nothing is printed for
 * a service that is not pay as you go. The work is done by BeltoftServices\Usage\CustomerDelete through the route
 * resources/<id>/delete, driven by assets/delete.js.
 *
 * Variables: $beltoft_services_delete_id (the resource id).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_delete_r    = \BeltoftServices\Resource::find( (int) $beltoft_services_delete_id );
$beltoft_services_delete_kind = $beltoft_services_delete_r ? \BeltoftServices\Usage\CustomerDelete::kind( $beltoft_services_delete_r ) : '';

if ( '' === $beltoft_services_delete_kind ) {
	return;
}

\BeltoftServices\Usage\CustomerDelete::assets();

$beltoft_services_delete_why   = \BeltoftServices\Usage\CustomerDelete::blocker( $beltoft_services_delete_r );
$beltoft_services_delete_word  = \BeltoftServices\Usage\CustomerDelete::word( $beltoft_services_delete_r );
$beltoft_services_delete_title = 'database' === $beltoft_services_delete_kind ? __( 'Delete database', 'beltoft-services' ) : __( 'Delete server', 'beltoft-services' );
$beltoft_services_delete_list  = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'database' === $beltoft_services_delete_kind ? \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Account\Databases::class )->slug() : \BeltoftServices\Account\ServerLinks::slug() ) : '';
?>
<details class="beltoft-services-vm__more beltoft-services-delete" data-delete-box data-delete-after="<?php echo esc_url( $beltoft_services_delete_list ); ?>" data-resource-id="<?php echo (int) $beltoft_services_delete_id; ?>">
	<summary><?php echo esc_html( $beltoft_services_delete_title ); ?></summary>
	<p><?php echo esc_html( \BeltoftServices\Usage\CustomerDelete::text( $beltoft_services_delete_kind ) ); ?></p>
	<p class="description" data-delete-billing><?php echo esc_html( \BeltoftServices\Usage\CustomerDelete::billing_note( $beltoft_services_delete_kind ) ); ?></p>
	<?php if ( null !== $beltoft_services_delete_why ) : ?>
		<p class="beltoft-services-panel__notice" data-delete-blocked><?php echo esc_html( $beltoft_services_delete_why ); ?></p>
	<?php else : ?>
		<p>
			<label for="beltoft-delete-confirm-<?php echo (int) $beltoft_services_delete_id; ?>"><?php echo esc_html( sprintf( /* translators: %s: the word to type */ __( 'Type %s to confirm', 'beltoft-services' ), $beltoft_services_delete_word ) ); ?></label>
			<input type="text" id="beltoft-delete-confirm-<?php echo (int) $beltoft_services_delete_id; ?>" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?php echo esc_attr( $beltoft_services_delete_word ); ?>" data-delete-confirm>
		</p>
		<p><button type="button" class="button woocommerce-button beltoft-services-delete__go" data-delete-go><?php echo esc_html( $beltoft_services_delete_title ); ?></button></p>
	<?php endif; ?>
	<p class="beltoft-services-panel__notice" data-delete-notice role="status" aria-live="polite"></p>
</details>
