<?php
/**
 * The parameter table, the reply field table and the error statuses of one operation.
 *
 * Expects $beltoft_services_cls (the layout's class prefix) and $beltoft_services_op (the operation).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( $beltoft_services_op['params'] ) : ?>
	<?php $beltoft_services_desc = '' !== implode( '', array_column( $beltoft_services_op['params'], 'desc' ) ); ?>
	<table class="<?php echo esc_attr( $beltoft_services_cls ); ?>table">
		<caption><?php esc_html_e( 'Parameters', 'beltoft-services' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Required', 'beltoft-services' ); ?></th>
			<?php if ( $beltoft_services_desc ) : ?><th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $beltoft_services_op['params'] as $beltoft_services_param ) : ?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><code<?php echo 'path' === $beltoft_services_param['in'] ? ' class="' . esc_attr( $beltoft_services_cls ) . 'param"' : ''; ?>><?php echo esc_html( $beltoft_services_param['name'] ); ?></code></td>
				<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['type'] . ', ' . $beltoft_services_param['in'] ); ?></td>
				<td data-label="<?php esc_attr_e( 'Required', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['required'] ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ) ); ?></td>
				<?php if ( $beltoft_services_desc ) : ?><td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['desc'] ); ?></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php if ( $beltoft_services_op['reply'] ) : ?>
	<?php $beltoft_services_desc = '' !== implode( '', array_column( $beltoft_services_op['reply'], 'desc' ) ); ?>
	<table class="<?php echo esc_attr( $beltoft_services_cls ); ?>table">
		<caption><?php esc_html_e( 'Reply fields', 'beltoft-services' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Field', 'beltoft-services' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
			<?php if ( $beltoft_services_desc ) : ?><th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $beltoft_services_op['reply'] as $beltoft_services_field ) : ?>
			<tr>
				<td data-label="<?php esc_attr_e( 'Field', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_field['name'] ); ?></code></td>
				<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['type'] ); ?></td>
				<?php if ( $beltoft_services_desc ) : ?><td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['desc'] ); ?></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php if ( $beltoft_services_op['errors'] ) : ?>
	<p class="<?php echo esc_attr( $beltoft_services_cls ); ?>meta"><?php echo esc_html( sprintf( /* translators: %s: list of HTTP statuses. */ __( 'Error statuses: %s.', 'beltoft-services' ), implode( ', ', $beltoft_services_op['errors'] ) ) ); ?></p>
<?php endif; ?>
