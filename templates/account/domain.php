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
	<p><?php esc_html_e( 'Name servers:', 'beltoft-services' ); ?> <?php echo esc_html( implode( ', ', $dns_only || 'custom' !== $r->get( 'ns_mode' ) ? Zone::nameservers() : (array) $r->get( 'nameservers' ) ) ); ?></p>
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
			<details><summary><?php esc_html_e( 'DNS records', 'beltoft-services' ); ?></summary>
				<table><thead><tr><th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'TTL', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th></tr></thead><tbody>
				<?php foreach ( $rrsets as $beltoft_set ) : if ( in_array( $beltoft_set['type'] ?? '', array( 'NS', 'SOA' ), true ) ) { continue; } foreach ( (array) ( $beltoft_set['records'] ?? array() ) as $beltoft_entry ) : ?>
				<tr><td><?php echo esc_html( (string) ( $beltoft_set['name'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $beltoft_set['type'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $beltoft_set['ttl'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $beltoft_entry['content'] ?? '' ) ); ?></td></tr>
				<?php endforeach; endforeach; ?>
				</tbody></table>
				<form class="beltoft-services-domain-form" data-domain-action="set_records"><label><?php esc_html_e( 'Records as JSON. Saving replaces all customer records.', 'beltoft-services' ); ?> <textarea name="records" rows="8" cols="60">[]</textarea></label><button type="submit" class="button"><?php esc_html_e( 'Save DNS records', 'beltoft-services' ); ?></button></form>
			</details>
		<?php endif; ?>
	<?php endif; ?>
</div>
