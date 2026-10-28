<?php
/**
 * Domain resource details. Variables provided by Panels::account().
 *
 * @package BeltoftServices
 */
defined( 'ABSPATH' ) || exit;

$beltoft_external = ! $dns_only && (bool) $r->get( 'dns_external' );
$beltoft_ours    = ! $beltoft_external && ( $dns_only || 'custom' !== $r->get( 'ns_mode' ) );
if ( $beltoft_external ) {
	$beltoft_hosts = 'custom' === $r->get( 'ns_mode' ) ? (array) $r->get( 'nameservers' ) : \BeltoftServices\Domains\DomainDriver::stored_nameservers( $r );
} else {
	$beltoft_hosts = $beltoft_ours ? \BeltoftServices\Domains\Zone::nameservers() : (array) $r->get( 'nameservers' );
}
$beltoft_locked  = (bool) $r->get( 'is_locked' );
$beltoft_can     = $r->accepts_actions();
$beltoft_off     = $dns_only && (bool) $r->get( 'records_off' );
$beltoft_expires = (string) $r->get( 'expires_at' );
$beltoft_time    = '' !== $beltoft_expires ? strtotime( $beltoft_expires . ' UTC' ) : false;
if ( $beltoft_time ) {
	$beltoft_expires = wp_date( (string) get_option( 'date_format' ), $beltoft_time );
}
?>
<div class="beltoft-services-domain" data-domain-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-updating="<?php echo esc_attr__( 'Updating…', 'beltoft-services' ); ?>" data-updating-note="<?php echo esc_attr__( 'This can take up to a minute.', 'beltoft-services' ); ?>">
	<?php if ( $dns_only ) : ?>
		<?php
		$beltoft_state = (string) $r->get( 'delegation' );
		if ( 'ours' === $beltoft_state ) {
			$beltoft_line = __( 'Pointing at our name servers', 'beltoft-services' );
		} elseif ( 'other' === $beltoft_state ) {
			$beltoft_line = __( 'Not pointing here yet', 'beltoft-services' );
		} else {
			$beltoft_line = __( 'Unable to check right now', 'beltoft-services' );
		}
		?>
		<p class="beltoft-services-domain__note"><?php echo esc_html( $beltoft_line ); ?></p>
		<?php if ( true === $r->get( 'parent_ds' ) && 'ours' !== $beltoft_state ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'This domain uses DNSSEC at your registrar. Remove the DS record there before you change the name servers, then wait a day.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( $beltoft_off ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'DNS hosting is paused. Your records are kept but not answered.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== (string) $r->get( 'import_error' ) ) : ?>
			<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the reason some records were not imported. */ __( 'Some imported records could not be added: %s. Add them below.', 'beltoft-services' ), (string) $r->get( 'import_error' ) ) ); ?></p>
		<?php endif; ?>
		<p class="beltoft-services-domain__note"><?php esc_html_e( 'Copy your DNS records here before changing the name servers at your registrar.', 'beltoft-services' ); ?></p>
		<?php
		$beltoft_transfer_price = $beltoft_can ? \BeltoftServices\Domains\Cart::price_for( array( 'beltoft_services_domain' => array( 'domain' => (string) $r->get( 'domain' ), 'mode' => 'transfer' ) ) ) : null;
		?>
		<?php if ( null !== $beltoft_transfer_price ) : ?>
			<section class="beltoft-services-domain__section beltoft-services-domain__transfer">
				<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Move the registration here', 'beltoft-services' ); ?></h4>
				<p class="beltoft-services-domain__note"><?php esc_html_e( 'Transfer the registration to us and keep the domain and its DNS in one place. Your DNS records here stay as they are. The free DNS hosting ends when the transfer is done, because DNS is included with a domain.', 'beltoft-services' ); ?></p>
				<p class="beltoft-services-domain__note">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: transfer price per year */
							__( 'At your current registrar, turn off DNSSEC if it is on, unlock the domain and copy the transfer code (also called auth or EPP code). The transfer adds one year to the registration: %s per year, excluding VAT.', 'beltoft-services' ),
							wc_price( $beltoft_transfer_price )
						)
					);
					?>
				</p>
				<form class="beltoft-services-domain-transfer" data-domain-transfer data-domain="<?php echo esc_attr( (string) $r->get( 'domain' ) ); ?>" data-cart="<?php echo esc_url( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/domains/cart' ) ); ?>" data-store-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_store_api' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-busy="<?php esc_attr_e( 'Adding to order…', 'beltoft-services' ); ?>">
					<label class="beltoft-services-domain__label" for="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>"><?php esc_html_e( 'Transfer code', 'beltoft-services' ); ?></label>
					<span class="beltoft-services-domain__inline">
						<input type="text" id="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>" name="auth_code" autocomplete="off" spellcheck="false" minlength="6" maxlength="64" required>
						<button type="submit" class="button"><?php esc_html_e( 'Transfer to us', 'beltoft-services' ); ?></button>
					</span>
					<p class="beltoft-services-domain__error" role="alert" hidden></p>
				</form>
			</section>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( '' !== $beltoft_expires ) : ?>
			<dl class="beltoft-services-domain__facts">
				<div><dt><?php esc_html_e( 'Expires', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $beltoft_expires ); ?></dd></div>
			</dl>
		<?php endif; ?>
	<?php endif; ?>

	<section class="beltoft-services-domain__section">
		<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Name servers', 'beltoft-services' ); ?></h4>
		<ul class="beltoft-services-domain__hosts">
			<?php foreach ( $beltoft_hosts as $beltoft_host ) : ?>
				<li><?php echo esc_html( (string) $beltoft_host ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $beltoft_external ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( '(DNS is hosted elsewhere)', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( $beltoft_can && $dns_only ) : ?>
			<form class="beltoft-services-domain-form" data-domain-action="check_delegation"><button type="submit" class="beltoft-services-domain__link"><?php esc_html_e( 'Check name servers', 'beltoft-services' ); ?></button></form>
		<?php elseif ( $beltoft_can ) : ?>
			<form class="beltoft-services-domain-form beltoft-services-domain__ns" data-domain-action="set_nameservers">
				<fieldset class="beltoft-services-domain__choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Name server mode', 'beltoft-services' ); ?></legend>
					<?php if ( ! $beltoft_external ) : ?>
					<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="ours"<?php checked( $beltoft_ours ); ?>> <?php esc_html_e( 'Our name servers', 'beltoft-services' ); ?></label>
					<?php endif; ?>
					<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="custom"<?php checked( ! $beltoft_ours ); ?>> <?php esc_html_e( 'Custom name servers', 'beltoft-services' ); ?></label>
				</fieldset>
				<label class="beltoft-services-domain__custom"<?php echo $beltoft_ours ? ' hidden' : ''; ?>><?php esc_html_e( 'Custom name servers, separated by commas', 'beltoft-services' ); ?> <input name="hosts" type="text" autocomplete="off" value="<?php echo esc_attr( $beltoft_ours ? '' : implode( ', ', array_map( 'strval', $beltoft_hosts ) ) ); ?>"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Save name servers', 'beltoft-services' ); ?></button>
			</form>
		<?php endif; ?>
	</section>

	<?php if ( ( $beltoft_can || $beltoft_off ) && $beltoft_ours && ! empty( $zone_unavailable ) ) : ?>
		<section class="beltoft-services-domain__section">
			<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></h4>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your DNS records cannot be shown right now. Reload the page in a minute.', 'beltoft-services' ); ?></p>
		</section>
	<?php elseif ( ( $beltoft_can || $beltoft_off ) && $beltoft_ours ) : ?>
		<section class="beltoft-services-domain__section">
			<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></h4>
			<form class="beltoft-services-domain-form beltoft-dns-editor" data-domain-action="set_records"<?php echo $beltoft_off ? ' data-readonly="1"' : ''; ?> data-rows="<?php echo esc_attr( (string) wp_json_encode( $rows ) ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( array(
				'name' => __( 'Name', 'beltoft-services' ), 'name_hint' => __( '@ for the domain itself, or a name like www', 'beltoft-services' ), 'type' => __( 'Type', 'beltoft-services' ), 'ttl' => __( 'TTL', 'beltoft-services' ), 'value' => __( 'Value', 'beltoft-services' ),
				'priority' => __( 'Priority', 'beltoft-services' ), 'weight' => __( 'Weight', 'beltoft-services' ), 'port' => __( 'Port', 'beltoft-services' ),
				'flag' => __( 'Flag', 'beltoft-services' ), 'tag' => __( 'Tag', 'beltoft-services' ), 'remove' => __( 'Remove', 'beltoft-services' ),
				'search' => __( 'Search records', 'beltoft-services' ),
				/* translators: %d: number of DNS records */
				'show_all' => __( 'Show all %d records', 'beltoft-services' ),
				'show_fewer' => __( 'Show fewer', 'beltoft-services' ),
				'no_match' => __( 'No records match.', 'beltoft-services' ),
			) ) ); ?>">
				<p class="beltoft-services-domain__empty" hidden><?php esc_html_e( 'No DNS records yet.', 'beltoft-services' ); ?></p>
				<table><thead><tr><th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'TTL', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th><th></th></tr></thead><tbody></tbody></table>
				<input type="hidden" name="records" value="[]">
				<p class="beltoft-dns-error" role="alert" hidden></p>
				<?php if ( ! $beltoft_off ) : ?>
				<p class="beltoft-dns-note"><?php esc_html_e( 'Changes are saved when you press Save DNS records.', 'beltoft-services' ); ?></p>
				<div class="beltoft-dns-actions">
					<button type="button" class="beltoft-services-domain__link beltoft-dns-add"><?php esc_html_e( 'Add record', 'beltoft-services' ); ?></button>
					<button type="submit" class="button"><?php esc_html_e( 'Save DNS records', 'beltoft-services' ); ?></button>
				</div>
				<?php endif; ?>
			</form>
		</section>
	<?php endif; ?>

	<?php if ( $beltoft_can && ! $dns_only ) : ?>
		<section class="beltoft-services-domain__section">
			<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Transfer to another registrar', 'beltoft-services' ); ?></h4>
			<p class="beltoft-services-domain__note"><?php echo esc_html( $beltoft_locked ? __( 'Your domain is locked, so it cannot be moved to another registrar.', 'beltoft-services' ) : __( 'Your domain is unlocked. Enter the transfer code at your new registrar.', 'beltoft-services' ) ); ?></p>
			<div class="beltoft-services-domain__actions">
				<?php if ( $beltoft_locked ) : ?>
					<form class="beltoft-services-domain-form" data-domain-action="unlock"><button type="submit" class="button"><?php esc_html_e( 'Unlock and show transfer code', 'beltoft-services' ); ?></button></form>
				<?php else : ?>
					<button type="button" class="button beltoft-services-domain-auth"><?php esc_html_e( 'Show transfer code', 'beltoft-services' ); ?></button>
					<form class="beltoft-services-domain-form" data-domain-action="lock"><button type="submit" class="beltoft-services-domain__link"><?php esc_html_e( 'Lock again', 'beltoft-services' ); ?></button></form>
				<?php endif; ?>
			</div>
			<div class="beltoft-services-domain__code" hidden>
				<label class="screen-reader-text" for="beltoft-domain-code-<?php echo esc_attr( (string) $r->id() ); ?>"><?php esc_html_e( 'Transfer code', 'beltoft-services' ); ?></label>
				<input type="text" readonly class="beltoft-services-domain-code" id="beltoft-domain-code-<?php echo esc_attr( (string) $r->id() ); ?>" value="" autocomplete="off" spellcheck="false">
				<button type="button" class="button beltoft-services-domain__copy" data-copied="<?php echo esc_attr__( 'Copied', 'beltoft-services' ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
			</div>
		</section>
	<?php endif; ?>
</div>
