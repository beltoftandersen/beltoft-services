<?php
/**
 * My Account → Domains: one row per domain, DNS hosting service and open claim.
 *
 * Override by copying to yourtheme/beltoft-services/account/domains-list.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, string>> $rows    Rows: id, url, name, sub, state, tone (ok, busy, off, bad), renews.
 * @var array<int, array<string, string>> $buttons Header buttons: label, url. The first is the main one.
 * @var string                            $empty   Text when there are no rows.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-domains<?php echo $rows ? '' : ' is-empty'; ?>">
	<?php if ( ! $rows ) : ?>
		<p class="beltoft-licenses__empty"><?php echo esc_html( $empty ); ?></p>
	<?php endif; ?>
	<?php if ( $buttons ) : ?>
		<?php // Over the list, at its right edge; with no list, under the empty line at the left. ?>
		<div class="beltoft-services-list-actions">
			<?php foreach ( $buttons as $beltoft_services_i => $beltoft_services_button ) : ?>
				<a class="beltoft-services-list-btn<?php echo count( $buttons ) - 1 === $beltoft_services_i ? '' : ' is-ghost'; ?>" href="<?php echo esc_url( $beltoft_services_button['url'] ); ?>"><?php echo esc_html( $beltoft_services_button['label'] ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php if ( $rows ) : ?>
		<div class="beltoft-services-servers beltoft-services-servers--domains">
			<?php foreach ( $rows as $beltoft_services_row ) : ?>
				<a class="beltoft-services-srv" id="beltoft-domain-<?php echo esc_attr( $beltoft_services_row['id'] ); ?>" href="<?php echo esc_url( $beltoft_services_row['url'] ); ?>">
					<span class="beltoft-services-srv__id">
						<span class="beltoft-services-srv__name"><?php echo esc_html( $beltoft_services_row['name'] ); ?></span>
						<?php if ( '' !== $beltoft_services_row['sub'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['sub'] ); ?></span><?php endif; ?>
					</span>
					<span class="beltoft-services-srv__state">
						<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span>
						<?php if ( '' !== $beltoft_services_row['renews'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['renews'] ); ?></span><?php endif; ?>
					</span>
					<span class="beltoft-services-srv__go" aria-hidden="true">&rsaquo;</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
