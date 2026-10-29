<?php
/**
 * My Account → Overview: a short list (domains and DNS, or plugin licenses).
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/rows.php.
 *
 * @package BeltoftServices
 *
 * @var string                                $class   Section name (domains, licenses).
 * @var string                                $heading Heading.
 * @var string                                $more    Link text.
 * @var string                                $url     Link address.
 * @var array<int, array<string, string>>     $rows    Rows: url, name, sub, state, tone, renews.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="beltoft-services-ov__section beltoft-services-ov__<?php echo esc_attr( $class ); ?>">
	<div class="beltoft-services-ov__head">
		<h2><?php echo esc_html( $heading ); ?></h2>
		<?php if ( '' !== $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $more ); ?></a><?php endif; ?>
	</div>
	<div class="beltoft-services-ov__rows">
		<?php foreach ( $rows as $beltoft_services_row ) : ?>
			<a class="beltoft-services-ov__row" href="<?php echo esc_url( $beltoft_services_row['url'] ); ?>">
				<span class="beltoft-services-ov__id">
					<b><?php echo esc_html( $beltoft_services_row['name'] ); ?></b>
					<?php if ( '' !== $beltoft_services_row['sub'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['sub'] ); ?></span><?php endif; ?>
				</span>
				<span class="beltoft-services-ov__end">
					<span class="beltoft-services-state is-<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span>
					<?php if ( '' !== $beltoft_services_row['renews'] ) : ?><span class="beltoft-services-srv__sub"><?php echo esc_html( $beltoft_services_row['renews'] ); ?></span><?php endif; ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</section>
