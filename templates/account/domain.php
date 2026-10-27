<?php
/** Domain resource details. Variables provided by Panels::account(). @package BeltoftServices */
defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-services-domain" data-domain-resource="<?php echo esc_attr( (string) $r->id() ); ?>">
	<p><strong><?php echo esc_html( $domain ); ?></strong></p>
	<?php if ( $dns_only ) : ?>
		<p><?php echo esc_html( 'ours' === $r->get( 'delegation' ) ? __( 'Pointing at our name servers', 'beltoft-services' ) : __( 'Not pointing at our name servers yet', 'beltoft-services' ) ); ?></p>
		<p><?php esc_html_e( 'Copy your DNS records here before changing the name servers at your registrar.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p><?php
		/* translators: %s: domain expiry date. */
		echo esc_html( sprintf( __( 'Expires: %s', 'beltoft-services' ), (string) $r->get( 'expires_at' ) ) );
		?></p>
		<p><?php
		/* translators: %s: transfer lock state. */
		echo esc_html( sprintf( __( 'Transfer lock: %s', 'beltoft-services' ), $r->get( 'is_locked' ) ? __( 'on', 'beltoft-services' ) : __( 'off', 'beltoft-services' ) ) );
		?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Name servers:', 'beltoft-services' ); ?> <?php echo esc_html( implode( ', ', $dns_only || 'custom' !== $r->get( 'ns_mode' ) ? \BeltoftServices\Domains\Zone::nameservers() : (array) $r->get( 'nameservers' ) ) ); ?></p>
	<?php if ( $r->accepts_actions() ) : ?>
		<?php if ( ! $dns_only ) : ?>
			<form class="beltoft-services-domain-form" data-domain-action="set_nameservers">
				<label><?php esc_html_e( 'Name server mode', 'beltoft-services' ); ?> <select name="mode"><option value="ours"><?php esc_html_e( 'Our name servers', 'beltoft-services' ); ?></option><option value="custom"><?php esc_html_e( 'Custom', 'beltoft-services' ); ?></option></select></label>
				<label><?php esc_html_e( 'Custom name servers, separated by commas', 'beltoft-services' ); ?> <input name="hosts" type="text" autocomplete="off"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Save name servers', 'beltoft-services' ); ?></button>
			</form>
			<button type="button" class="button beltoft-services-domain-auth"><?php esc_html_e( 'Show transfer code', 'beltoft-services' ); ?></button><output class="beltoft-services-domain-code"></output>
		<?php endif; ?>
		<?php if ( $dns_only || 'custom' !== $r->get( 'ns_mode' ) ) : ?>
			<details class="beltoft-dns"><summary><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></summary>
				<form class="beltoft-services-domain-form beltoft-dns-editor" data-domain-action="set_records" data-rows="<?php echo esc_attr( (string) wp_json_encode( $rows ) ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( array(
					'name' => __( 'Name', 'beltoft-services' ), 'name_hint' => __( '@ for the domain itself, or a name like www', 'beltoft-services' ), 'value' => __( 'Value', 'beltoft-services' ),
					'priority' => __( 'Priority', 'beltoft-services' ), 'weight' => __( 'Weight', 'beltoft-services' ), 'port' => __( 'Port', 'beltoft-services' ),
					'flag' => __( 'Flag', 'beltoft-services' ), 'tag' => __( 'Tag', 'beltoft-services' ), 'remove' => __( 'Remove', 'beltoft-services' ),
				) ) ); ?>">
					<table><thead><tr><th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'TTL', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th><th></th></tr></thead><tbody></tbody></table>
					<input type="hidden" name="records" value="[]">
					<p class="beltoft-dns-error" role="alert" hidden></p>
					<p class="beltoft-dns-note"><?php esc_html_e( 'Changes are saved when you press Save DNS records.', 'beltoft-services' ); ?></p>
					<button type="button" class="button beltoft-dns-add"><?php esc_html_e( 'Add record', 'beltoft-services' ); ?></button>
					<button type="submit" class="button"><?php esc_html_e( 'Save DNS records', 'beltoft-services' ); ?></button>
				</form>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</div>
