<?php
/**
 * The table of the MCP tools of one access level.
 *
 * Expects $beltoft_services_cls (the layout's class prefix), $beltoft_services_label (the level label) and $beltoft_services_rows (its tools).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="<?php echo esc_attr( $beltoft_services_cls ); ?>table <?php echo esc_attr( $beltoft_services_cls ); ?>tools">
	<caption><?php echo esc_html( $beltoft_services_label ); ?></caption>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'Tool', 'beltoft-services' ); ?></th>
		<th scope="col"><?php esc_html_e( 'What it does', 'beltoft-services' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $beltoft_services_rows as $beltoft_services_tool ) : ?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Tool', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_tool['name'] ); ?></code></td>
			<td data-label="<?php esc_attr_e( 'What it does', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_tool['description'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
