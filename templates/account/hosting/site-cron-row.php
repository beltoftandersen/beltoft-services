<?php
/**
 * One scheduled job of a website as a row of the form. The page script reads the fields (data-cron-field) of every row when the list is saved.
 *
 * Variables: $v: row (SiteCron::view() jobs entry), php_versions, names.
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_row = $v['row'];
$beltoft_services_when = $beltoft_services_row['when'];
$beltoft_services_wp   = 'wordpress' === $beltoft_services_row['kind'];
$beltoft_services_kind = $beltoft_services_row['kind'];
global $wp_locale;
?>
<li class="bs-item bsh-cron__row" data-cron-row data-id="<?php echo esc_attr( $beltoft_services_row['id'] ); ?>" data-kind="<?php echo esc_attr( $beltoft_services_kind ); ?>">
	<div class="bs-item__main">
		<div class="beltoft-hosting-formrow">
			<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Name', 'beltoft-services' ); ?></span><input type="text" data-cron-field="label" maxlength="40" autocomplete="off" value="<?php echo esc_attr( $beltoft_services_wp && '' === $beltoft_services_row['label'] ? __( 'WordPress server cron', 'beltoft-services' ) : $beltoft_services_row['label'] ); ?>"></label>
			<label class="beltoft-hosting-check"><input type="checkbox" data-cron-field="enabled"<?php checked( $beltoft_services_row['enabled'] ); ?>> <span><?php esc_html_e( 'On', 'beltoft-services' ); ?></span></label>
		</div>

		<?php if ( ! $beltoft_services_wp ) : ?>
			<div class="beltoft-hosting-formrow">
				<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Runs', 'beltoft-services' ); ?></span>
					<select data-cron-field="kind" data-cron-kind>
						<option value="script"<?php selected( 'script', $beltoft_services_kind ); ?>><?php esc_html_e( 'A PHP file', 'beltoft-services' ); ?></option>
						<option value="url"<?php selected( 'url', $beltoft_services_kind ); ?>><?php esc_html_e( 'An address of this website', 'beltoft-services' ); ?></option>
					</select>
				</label>
				<div class="beltoft-hosting-formrow" data-cron-show="script"<?php echo 'script' === $beltoft_services_kind ? '' : ' hidden'; ?>>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'PHP version', 'beltoft-services' ); ?></span>
						<select data-cron-field="php">
							<?php foreach ( $v['php_versions'] as $beltoft_services_version ) : ?>
								<option value="<?php echo esc_attr( $beltoft_services_version ); ?>"<?php selected( $beltoft_services_version, $beltoft_services_row['php'] ); ?>><?php echo esc_html( 'PHP ' . $beltoft_services_version ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'File', 'beltoft-services' ); ?></span><input type="text" data-cron-field="path" maxlength="200" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="cron/run.php" value="<?php echo esc_attr( $beltoft_services_row['path'] ); ?>"></label>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Arguments', 'beltoft-services' ); ?></span><input type="text" data-cron-field="args" maxlength="560" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="schedule:run" value="<?php echo esc_attr( $beltoft_services_row['args'] ); ?>"></label>
				</div>
				<div class="beltoft-hosting-formrow" data-cron-show="url"<?php echo 'url' === $beltoft_services_kind ? '' : ' hidden'; ?>>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Address', 'beltoft-services' ); ?></span>
						<select data-cron-field="host">
							<?php foreach ( $v['names'] as $beltoft_services_name ) : ?>
								<option value="<?php echo esc_attr( $beltoft_services_name ); ?>"<?php selected( $beltoft_services_name, $beltoft_services_row['host'] ); ?>><?php echo esc_html( \BeltoftServices\Domains\HostName::display( $beltoft_services_name ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Path', 'beltoft-services' ); ?></span><input type="text" data-cron-field="urlpath" maxlength="500" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="/cron.php" value="<?php echo esc_attr( $beltoft_services_row['rest'] ); ?>"></label>
				</div>
			</div>
		<?php endif; ?>

		<div class="beltoft-hosting-formrow">
			<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Runs every', 'beltoft-services' ); ?></span>
				<select data-cron-field="preset" data-cron-preset>
					<?php
					foreach ( array(
						'every5'  => __( '5 minutes', 'beltoft-services' ),
						'every15' => __( '15 minutes', 'beltoft-services' ),
						'every30' => __( '30 minutes', 'beltoft-services' ),
						'hourly'  => __( 'Hour', 'beltoft-services' ),
						'daily'   => __( 'Day', 'beltoft-services' ),
						'weekly'  => __( 'Week', 'beltoft-services' ),
						'monthly' => __( 'Month', 'beltoft-services' ),
						'custom'  => __( 'Custom schedule', 'beltoft-services' ),
					) as $beltoft_services_preset => $beltoft_services_text ) :
						?>
						<option value="<?php echo esc_attr( $beltoft_services_preset ); ?>"<?php selected( $beltoft_services_preset, $beltoft_services_when['preset'] ); ?>><?php echo esc_html( $beltoft_services_text ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="beltoft-hosting-field" data-cron-show-for="weekly"<?php echo 'weekly' === $beltoft_services_when['preset'] ? '' : ' hidden'; ?>><span><?php esc_html_e( 'On', 'beltoft-services' ); ?></span>
				<select data-cron-field="weekday">
					<?php for ( $beltoft_services_day = 0; $beltoft_services_day < 7; $beltoft_services_day++ ) : ?>
						<option value="<?php echo (int) $beltoft_services_day; ?>"<?php selected( $beltoft_services_day, $beltoft_services_when['weekday'] ); ?>><?php echo esc_html( $wp_locale ? $wp_locale->get_weekday( $beltoft_services_day ) : (string) $beltoft_services_day ); ?></option>
					<?php endfor; ?>
				</select>
			</label>
			<label class="beltoft-hosting-field" data-cron-show-for="monthly"<?php echo 'monthly' === $beltoft_services_when['preset'] ? '' : ' hidden'; ?>><span><?php esc_html_e( 'On day', 'beltoft-services' ); ?></span>
				<select data-cron-field="monthday">
					<?php for ( $beltoft_services_day = 1; $beltoft_services_day <= 28; $beltoft_services_day++ ) : ?>
						<option value="<?php echo (int) $beltoft_services_day; ?>"<?php selected( $beltoft_services_day, $beltoft_services_when['day'] ); ?>><?php echo (int) $beltoft_services_day; ?></option>
					<?php endfor; ?>
				</select>
			</label>
			<label class="beltoft-hosting-field" data-cron-show-for="daily weekly monthly"<?php echo in_array( $beltoft_services_when['preset'], array( 'daily', 'weekly', 'monthly' ), true ) ? '' : ' hidden'; ?>><span><?php esc_html_e( 'At', 'beltoft-services' ); ?></span><input type="time" data-cron-field="time" value="<?php echo esc_attr( $beltoft_services_when['time'] ); ?>"></label>
			<label class="beltoft-hosting-field" data-cron-show-for="custom"<?php echo 'custom' === $beltoft_services_when['preset'] ? '' : ' hidden'; ?>><span><?php esc_html_e( 'Schedule', 'beltoft-services' ); ?></span><input type="text" data-cron-field="custom" maxlength="100" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="*/10 * * * *" value="<?php echo esc_attr( $beltoft_services_when['custom'] ); ?>"></label>
			<label class="beltoft-hosting-field"><span><?php esc_html_e( 'Stops after (seconds)', 'beltoft-services' ); ?></span><input type="number" data-cron-field="timeout" min="1" max="900" step="1" value="<?php echo (int) $beltoft_services_row['timeout']; ?>"></label>
		</div>

		<p class="beltoft-hosting-help" data-cron-last="<?php echo esc_attr( $beltoft_services_row['id'] ); ?>" hidden></p>
		<pre class="beltoft-hosting-output" data-cron-output hidden></pre>
	</div>
	<div class="bs-item__actions">
		<?php if ( '' !== $beltoft_services_row['id'] ) : ?>
			<button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="cron_output" data-id="<?php echo esc_attr( $beltoft_services_row['id'] ); ?>" hidden><?php esc_html_e( 'Show output', 'beltoft-services' ); ?></button>
		<?php endif; ?>
		<button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="cron_remove"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></button>
	</div>
</li>
