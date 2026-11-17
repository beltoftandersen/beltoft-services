<?php
/**
 * My Account → Overview: the activity log, newest first, grouped by day.
 *
 * Override by copying to yourtheme/beltoft-services/account/overview/activity.php. The script assets/activity.js adds the
 * "Show more" lines and the service filter and builds the same markup.
 *
 * @package BeltoftServices
 *
 * @var array<int, array{day: string, label: string, lines: array<int, array<string, mixed>>}> $days     Days, newest first.
 * @var int                                                                                    $next     Cursor of the next page, 0 when there is none.
 * @var array<int, string>                                                                     $services Service name by license id, for the filter.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_last = $days ? $days[ count( $days ) - 1 ]['day'] : '';
?>
<section class="beltoft-services-ov__section beltoft-services-ov__activity" data-activity data-next="<?php echo esc_attr( (string) $next ); ?>" data-day="<?php echo esc_attr( $beltoft_services_last ); ?>">
	<div class="beltoft-services-ov__head">
		<h2><?php esc_html_e( 'Recent activity', 'beltoft-services' ); ?></h2>
		<?php if ( count( $services ) > 1 ) : ?>
			<select class="beltoft-services-ov__filter" data-activity-filter aria-label="<?php esc_attr_e( 'Show activity for', 'beltoft-services' ); ?>">
				<option value="0"><?php esc_html_e( 'All services', 'beltoft-services' ); ?></option>
				<?php foreach ( $services as $beltoft_services_id => $beltoft_services_name ) : ?>
					<option value="<?php echo esc_attr( (string) $beltoft_services_id ); ?>"><?php echo esc_html( $beltoft_services_name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
	</div>
	<div class="beltoft-services-ov__feed" data-activity-list>
		<?php foreach ( $days as $beltoft_services_day ) : ?>
			<h3 class="beltoft-services-ov__day"><?php echo esc_html( $beltoft_services_day['label'] ); ?></h3>
			<ul class="beltoft-services-ov__log">
				<?php foreach ( $beltoft_services_day['lines'] as $beltoft_services_line ) : ?>
					<li>
						<time datetime="<?php echo esc_attr( $beltoft_services_line['datetime'] ); ?>"><?php echo esc_html( $beltoft_services_line['time'] ); ?></time>
						<?php if ( '' !== $beltoft_services_line['url'] ) : ?>
							<a class="beltoft-services-ov__who" href="<?php echo esc_url( $beltoft_services_line['url'] ); ?>"><?php echo esc_html( $beltoft_services_line['name'] ); ?></a>
						<?php else : ?>
							<span class="beltoft-services-ov__who"><?php echo esc_html( $beltoft_services_line['name'] ); ?></span>
						<?php endif; ?>
						<span class="beltoft-services-ov__what"><?php echo esc_html( $beltoft_services_line['sentence'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</div>
	<p class="beltoft-services-ov__logmsg" data-activity-note role="status" hidden></p>
	<p class="beltoft-services-ov__more"<?php echo $next > 0 ? '' : ' hidden'; ?>><button type="button" class="bs-btn bs-btn--secondary" data-activity-more><?php esc_html_e( 'Show more', 'beltoft-services' ); ?></button></p>
</section>
