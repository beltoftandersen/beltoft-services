<?php
/**
 * The table of error statuses.
 *
 * Expects $beltoft_services_cls (the layout's class prefix) and $errors (status => meaning).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="<?php echo esc_attr( $beltoft_services_cls ); ?>table">
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'Status', 'beltoft-services' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Meaning', 'beltoft-services' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $errors as $beltoft_services_status => $beltoft_services_meaning ) : ?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Status', 'beltoft-services' ); ?>"><code><?php echo esc_html( (string) $beltoft_services_status ); ?></code></td>
			<td data-label="<?php esc_attr_e( 'Meaning', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_meaning ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
