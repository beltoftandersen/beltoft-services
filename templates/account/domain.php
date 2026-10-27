<?php
/**
 * Domain resource details. Variables provided by Panels::account().
 *
 * @package BeltoftServices
 */
defined( 'ABSPATH' ) || exit;

$beltoft_ours    = $dns_only || 'custom' !== $r->get( 'ns_mode' );
$beltoft_hosts   = $beltoft_ours ? \BeltoftServices\Domains\Zone::nameservers() : (array) $r->get( 'nameservers' );
$beltoft_locked  = (bool) $r->get( 'is_locked' );
$beltoft_can     = $r->accepts_actions();
$beltoft_expires = (string) $r->get( 'expires_at' );
$beltoft_time    = '' !== $beltoft_expires ? strtotime( $beltoft_expires . ' UTC' ) : false;
if ( $beltoft_time ) {
	$beltoft_expires = wp_date( (string) get_option( 'date_format' ), $beltoft_time );
}
?>
<div class="beltoft-services-domain" data-domain-resource="<?php echo esc_attr( (string) $r->id() ); ?>">
	<?php if ( $dns_only ) : ?>
		<p class="beltoft-services-domain__note"><?php echo esc_html( 'ours' === $r->get( 'delegation' ) ? __( 'Pointing at our name servers', 'beltoft-services' ) : __( 'Not pointing at our name servers yet', 'beltoft-services' ) ); ?></p>
		<p class="beltoft-services-domain__note"><?php esc_html_e( 'Copy your DNS records here before changing the name servers at your registrar.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<dl class="beltoft-services-domain__facts">
			<?php if ( '' !== $beltoft_expires ) : ?>
				<div><dt><?php esc_html_e( 'Expires', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $beltoft_expires ); ?></dd></div>
			<?php endif; ?>
			<div>
				<dt><?php esc_html_e( 'Transfer lock', 'beltoft-services' ); ?></dt>
				<dd>
					<span><?php echo esc_html( $beltoft_locked ? __( 'On', 'beltoft-services' ) : __( 'Off', 'beltoft-services' ) ); ?></span>
					<?php if ( $beltoft_can ) : ?>
						<form class="beltoft-services-domain-form" data-domain-action="<?php echo esc_attr( $beltoft_locked ? 'unlock' : 'lock' ); ?>"><button type="submit" class="beltoft-services-domain__link"><?php echo esc_html( $beltoft_locked ? __( 'Unlock transfer', 'beltoft-services' ) : __( 'Lock transfer', 'beltoft-services' ) ); ?></button></form>
						<button type="button" class="beltoft-services-domain__link beltoft-services-domain-auth"><?php esc_html_e( 'Show transfer code', 'beltoft-services' ); ?></button><output class="beltoft-services-domain-code"></output>
					<?php endif; ?>
				</dd>
			</div>
		</dl>
	<?php endif; ?>

	<section class="beltoft-services-domain__section">
		<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Name servers', 'beltoft-services' ); ?></h4>
		<ul class="beltoft-services-domain__hosts">
			<?php foreach ( $beltoft_hosts as $beltoft_host ) : ?>
				<li><?php echo esc_html( (string) $beltoft_host ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $beltoft_can && $dns_only ) : ?>
			<form class="beltoft-services-domain-form" data-domain-action="check_delegation"><button type="submit" class="beltoft-services-domain__link"><?php esc_html_e( 'Check name servers', 'beltoft-services' ); ?></button></form>
		<?php elseif ( $beltoft_can ) : ?>
			<form class="beltoft-services-domain-form beltoft-services-domain__ns" data-domain-action="set_nameservers">
				<fieldset class="beltoft-services-domain__choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Name server mode', 'beltoft-services' ); ?></legend>
					<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="ours"<?php checked( $beltoft_ours ); ?>> <?php esc_html_e( 'Our name servers', 'beltoft-services' ); ?></label>
					<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="custom"<?php checked( ! $beltoft_ours ); ?>> <?php esc_html_e( 'Custom name servers', 'beltoft-services' ); ?></label>
				</fieldset>
				<label class="beltoft-services-domain__custom"<?php echo $beltoft_ours ? ' hidden' : ''; ?>><?php esc_html_e( 'Custom name servers, separated by commas', 'beltoft-services' ); ?> <input name="hosts" type="text" autocomplete="off" value="<?php echo esc_attr( $beltoft_ours ? '' : implode( ', ', array_map( 'strval', $beltoft_hosts ) ) ); ?>"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Save name servers', 'beltoft-services' ); ?></button>
			</form>
		<?php endif; ?>
	</section>

	<?php if ( $beltoft_can && $beltoft_ours ) : ?>
		<section class="beltoft-services-domain__section">
			<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></h4>
			<form class="beltoft-services-domain-form beltoft-dns-editor" data-domain-action="set_records" data-rows="<?php echo esc_attr( (string) wp_json_encode( $rows ) ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( array(
				'name' => __( 'Name', 'beltoft-services' ), 'name_hint' => __( '@ for the domain itself, or a name like www', 'beltoft-services' ), 'type' => __( 'Type', 'beltoft-services' ), 'ttl' => __( 'TTL', 'beltoft-services' ), 'value' => __( 'Value', 'beltoft-services' ),
				'priority' => __( 'Priority', 'beltoft-services' ), 'weight' => __( 'Weight', 'beltoft-services' ), 'port' => __( 'Port', 'beltoft-services' ),
				'flag' => __( 'Flag', 'beltoft-services' ), 'tag' => __( 'Tag', 'beltoft-services' ), 'remove' => __( 'Remove', 'beltoft-services' ),
			) ) ); ?>">
				<p class="beltoft-services-domain__empty" hidden><?php esc_html_e( 'No DNS records yet.', 'beltoft-services' ); ?></p>
				<table><thead><tr><th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'TTL', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th><th></th></tr></thead><tbody></tbody></table>
				<input type="hidden" name="records" value="[]">
				<p class="beltoft-dns-error" role="alert" hidden></p>
				<p class="beltoft-dns-note"><?php esc_html_e( 'Changes are saved when you press Save DNS records.', 'beltoft-services' ); ?></p>
				<div class="beltoft-dns-actions">
					<button type="button" class="beltoft-services-domain__link beltoft-dns-add"><?php esc_html_e( 'Add record', 'beltoft-services' ); ?></button>
					<button type="submit" class="button"><?php esc_html_e( 'Save DNS records', 'beltoft-services' ); ?></button>
				</div>
			</form>
		</section>
	<?php endif; ?>
</div>
