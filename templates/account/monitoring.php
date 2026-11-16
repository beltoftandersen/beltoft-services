<?php
/**
 * The monitoring panel of a server or a hosting account: the time range, the usage email switch, the CPU, memory and disk
 * (or storage) charts and the network and disk activity. The charts are drawn by assets/metrics.js, which asks the route
 * /resources/{id}/metrics; this file is only the markup, shared by both drivers.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $monitoring {
 *     @type string[] $states       Plain lines about what a usage email told the customer.
 *     bool           $alerts_shown Whether the usage email switch is shown.
 *     bool           $alerts_on    Whether it is on.
 *     string         $alerts_label Text of the switch.
 *     array          $disk         title, pct (?int), line, note (the disk or storage block).
 * }
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_disk = (array) $monitoring['disk'];
?>
		<section class="beltoft-vm-usage" data-vm-metrics>
			<div class="beltoft-vm-usage__head">
				<h4><?php esc_html_e( 'Monitoring', 'beltoft-services' ); ?></h4>
				<div class="beltoft-vm-usage__ranges" role="group" aria-label="<?php esc_attr_e( 'Time range', 'beltoft-services' ); ?>">
					<button type="button" data-range="hour" aria-pressed="true"><?php esc_html_e( '1 hour', 'beltoft-services' ); ?></button>
					<button type="button" data-range="day" aria-pressed="false"><?php esc_html_e( '1 day', 'beltoft-services' ); ?></button>
					<button type="button" data-range="month" aria-pressed="false"><?php esc_html_e( '30 days', 'beltoft-services' ); ?></button>
				</div>
			</div>
			<p class="beltoft-vm-usage__notice" data-metrics-notice role="status" aria-live="polite"><?php esc_html_e( 'Loading usage…', 'beltoft-services' ); ?></p>
			<?php if ( ! empty( $monitoring['states'] ) || ! empty( $monitoring['alerts_shown'] ) ) : ?>
				<div class="beltoft-vm-usage__alerts">
					<?php foreach ( (array) $monitoring['states'] as $beltoft_services_state ) : ?>
						<p class="beltoft-vm-usage__state"><?php echo esc_html( (string) $beltoft_services_state ); ?></p>
					<?php endforeach; ?>
					<?php if ( ! empty( $monitoring['alerts_shown'] ) ) : ?>
						<label class="beltoft-vm-usage__switch">
							<input type="checkbox" data-usage-alerts<?php echo ! empty( $monitoring['alerts_on'] ) ? ' checked' : ''; ?>>
							<span><?php echo esc_html( (string) $monitoring['alerts_label'] ); ?></span>
						</label>
						<p class="beltoft-vm-usage__switch-note" data-usage-alerts-note role="status" aria-live="polite"></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="beltoft-vm-usage__grid">
				<?php foreach ( array( 'cpu' => __( 'CPU', 'beltoft-services' ) ) as $beltoft_metric => $beltoft_title ) : ?>
					<div class="beltoft-vm-usage__chart" data-chart="<?php echo esc_attr( $beltoft_metric ); ?>">
						<div class="beltoft-vm-usage__top"><h5><?php echo esc_html( $beltoft_title ); ?></h5><span class="beltoft-vm-usage__now" data-now></span></div>
						<div class="beltoft-vm-usage__plot"></div>
						<p class="beltoft-vm-usage__axis" data-axis></p>
						<p class="beltoft-vm-usage__readout" data-readout></p>
					</div>
				<?php endforeach; ?>
				<?php // Memory is the guest's own figure from our history (never the hypervisor's), so the cache Linux holds is not counted. ?>
				<div class="beltoft-vm-usage__chart" data-chart="mem">
					<div class="beltoft-vm-usage__top"><h5><?php esc_html_e( 'Memory', 'beltoft-services' ); ?></h5><span class="beltoft-vm-usage__now" data-now></span></div>
					<div class="beltoft-vm-usage__plot"></div>
					<p class="beltoft-vm-usage__axis" data-axis></p>
					<p class="beltoft-vm-usage__readout" data-readout></p>
					<p class="beltoft-vm-usage__note" data-mem-hint role="status"></p>
					<p class="beltoft-vm-usage__note"><?php esc_html_e( 'Linux uses free memory as a file cache and gives it back when programs need it; that part is not counted.', 'beltoft-services' ); ?></p>
				</div>
				<?php // The disk has no history: one reading, drawn as a bar. ?>
				<div class="beltoft-vm-usage__chart beltoft-vm-usage__chart--disk">
					<div class="beltoft-vm-usage__top">
						<h5><?php echo esc_html( (string) $beltoft_services_disk['title'] ); ?></h5>
						<?php if ( null !== $beltoft_services_disk['pct'] ) : ?><span class="beltoft-vm-usage__now"><b><?php echo (int) $beltoft_services_disk['pct']; ?>%</b></span><?php endif; ?>
					</div>
					<?php if ( null !== $beltoft_services_disk['pct'] ) : ?>
						<div class="beltoft-vm-usage__bar" role="img" aria-label="<?php echo esc_attr( (string) $beltoft_services_disk['aria'] ); ?>"><i style="width:<?php echo (int) $beltoft_services_disk['pct']; ?>%"></i></div>
					<?php endif; ?>
					<p class="beltoft-vm-usage__disk" data-disk-line><?php echo esc_html( (string) $beltoft_services_disk['line'] ); ?></p>
					<?php if ( null !== $beltoft_services_disk['pct'] && '' !== (string) $beltoft_services_disk['note'] ) : ?><p class="beltoft-vm-usage__axis"><?php echo esc_html( (string) $beltoft_services_disk['note'] ); ?></p><?php endif; ?>
				</div>
			</div>
			<details class="beltoft-vm-usage__more">
				<summary><?php esc_html_e( 'Network and disk activity', 'beltoft-services' ); ?></summary>
				<div class="beltoft-vm-usage__grid">
					<?php foreach ( array( 'net' => __( 'Network', 'beltoft-services' ), 'io' => __( 'Disk activity', 'beltoft-services' ) ) as $beltoft_metric => $beltoft_title ) : ?>
						<div class="beltoft-vm-usage__chart" data-chart="<?php echo esc_attr( $beltoft_metric ); ?>">
							<div class="beltoft-vm-usage__top"><h5><?php echo esc_html( $beltoft_title ); ?></h5><span class="beltoft-vm-usage__now" data-now></span></div>
							<div class="beltoft-vm-usage__plot"></div>
							<p class="beltoft-vm-usage__axis" data-axis></p>
							<p class="beltoft-vm-usage__readout" data-readout></p>
						</div>
					<?php endforeach; ?>
				</div>
			</details>
		</section>
