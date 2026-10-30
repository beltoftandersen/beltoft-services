<?php
/**
 * My Account → Databases or Apps: one row per service.
 *
 * Override by copying to yourtheme/beltoft-services/account/area-list.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>> $rows  Rows from BeltoftServices\Account\ServiceArea::row(): id, url, name, details, state, tone (ok, busy, off, bad), extra, action (label and url, or empty).
 * @var string                           $empty Text when there are no rows.
 */

defined( 'ABSPATH' ) || exit;

if ( ! $rows ) {
	echo '<p class="beltoft-licenses__empty">' . esc_html( $empty ) . '</p>';

	return;
}
?>
<div class="beltoft-services-servers beltoft-services-servers--area">
	<?php foreach ( $rows as $beltoft_services_row ) : ?>
		<?php
		// A row with a button is not one big link: a link may not hold a link.
		$beltoft_services_tag = $beltoft_services_row['action'] ? 'div' : 'a';
		?>
		<<?php echo $beltoft_services_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'div' or 'a'. ?> class="beltoft-services-srv" id="beltoft-service-<?php echo (int) $beltoft_services_row['id']; ?>"<?php echo 'a' === $beltoft_services_tag ? ' href="' . esc_url( $beltoft_services_row['url'] ) . '"' : ''; ?>>
			<span class="beltoft-services-srv__id">
				<?php if ( 'a' === $beltoft_services_tag ) : ?>
					<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_row['name'] ); ?></span>
				<?php else : ?>
					<a class="beltoft-services-srv__name" href="<?php echo esc_url( $beltoft_services_row['url'] ); ?>"><?php echo esc_html( $beltoft_services_row['name'] ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $beltoft_services_row['details'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['details'] ); ?></span><?php endif; ?>
			</span>
			<span class="beltoft-services-srv__state">
				<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span>
			</span>
			<span class="beltoft-services-srv__extra"><?php echo esc_html( $beltoft_services_row['extra'] ); ?></span>
			<?php if ( $beltoft_services_row['action'] ) : ?>
				<a class="button woocommerce-button beltoft-services-http-open" href="<?php echo esc_url( $beltoft_services_row['action']['url'] ); ?>" data-beltoft-services-open target="_blank" rel="noopener noreferrer"><?php echo esc_html( $beltoft_services_row['action']['label'] ); ?></a>
			<?php else : ?>
				<span class="beltoft-services-srv__go" aria-hidden="true">&rsaquo;</span>
			<?php endif; ?>
		</<?php echo $beltoft_services_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php endforeach; ?>
</div>
