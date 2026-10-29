<?php
/**
 * One domain's page: tabs for the overview, the DNS records, the name servers and the transfer.
 *
 * Every pane is printed and the inactive ones are hidden, so the forms keep working in any tab, and without script
 * the links still open the right tab (each tab has its own address). Printed inside the service panel by
 * BeltoftServices\Domains\Panels::account(). The attributes assets/domain.js binds to (data-domain-resource,
 * data-domain-action, data-domain-transfer) stay as they are.
 *
 * Override by copying to yourtheme/beltoft-services/account/domain-page.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $d Values from BeltoftServices\Account\DomainPage::build().
 */

defined( 'ABSPATH' ) || exit;

/** @var \BeltoftServices\Resource $r */
$r        = $d['r'];
$beltoft_tabs   = $d['tabs'];
$beltoft_active = $d['active'];
$beltoft_next   = \BeltoftServices\Account\DomainPage::next_step( $d );
$beltoft_link   = static function ( string $slug ) use ( $d ): string {
	return \BeltoftServices\Account\Domains::url( $d['license'], 'overview' === $slug ? '' : $slug );
};
$beltoft_open   = static function ( string $slug ) use ( $beltoft_active, $r ): void {
	?>
	<section class="beltoft-services-tab" id="beltoft-domain-tab-<?php echo esc_attr( $slug ); ?>-<?php echo (int) $r->id(); ?>" data-tab="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === $beltoft_active ? '' : ' hidden'; ?>>
	<?php
};
$beltoft_hosts_text = $d['external'] || ! $d['ours'] ? __( 'Using other name servers', 'beltoft-services' ) : __( 'Using our name servers', 'beltoft-services' );
if ( $d['dns_only'] ) {
	if ( 'ours' === $d['delegation'] ) {
		$beltoft_hosts_text = __( 'Pointing at our name servers', 'beltoft-services' );
	} elseif ( 'other' === $d['delegation'] ) {
		$beltoft_hosts_text = __( 'Not pointing here yet', 'beltoft-services' );
	} else {
		$beltoft_hosts_text = __( 'Unable to check right now', 'beltoft-services' );
	}
}
?>
<div class="beltoft-services-domain beltoft-services-domain--page" data-domain-page data-domain-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-updating="<?php echo esc_attr__( 'Updating…', 'beltoft-services' ); ?>" data-updating-note="<?php echo esc_attr__( 'This can take up to a minute.', 'beltoft-services' ); ?>">
	<nav class="beltoft-services-tabs" aria-label="<?php esc_attr_e( 'Domain sections', 'beltoft-services' ); ?>">
		<?php foreach ( $beltoft_tabs as $beltoft_slug => $beltoft_title ) : ?>
			<a href="<?php echo esc_url( $beltoft_link( $beltoft_slug ) ); ?>" data-tab-link="<?php echo esc_attr( $beltoft_slug ); ?>"<?php echo $beltoft_slug === $beltoft_active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $beltoft_title ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php $beltoft_open( 'overview' ); ?>
		<dl class="beltoft-services-facts">
			<div><dt><?php esc_html_e( 'Service', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $d['kind'] ); ?></dd></div>
			<?php if ( ! $d['dns_only'] && '' !== $d['expires'] ) : ?>
				<div><dt><?php esc_html_e( 'Registered until', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $d['expires'] ); ?></dd></div>
			<?php endif; ?>
			<?php if ( '' !== $d['renews'] ) : ?>
				<div><dt><?php esc_html_e( 'Renewal', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $d['renews'] ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Name servers', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $beltoft_hosts_text ); ?></dd></div>
			<?php if ( ! $d['dns_only'] ) : ?>
				<div><dt><?php esc_html_e( 'Transfer lock', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $d['locked'] ? __( 'Locked', 'beltoft-services' ) : __( 'Unlocked', 'beltoft-services' ) ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'DNS', 'beltoft-services' ); ?></dt><dd>
				<?php
				if ( $d['off'] ) {
					esc_html_e( 'Paused', 'beltoft-services' );
				} elseif ( $d['ours'] ) {
					esc_html_e( 'Answered by our servers', 'beltoft-services' );
				} else {
					esc_html_e( 'Hosted elsewhere', 'beltoft-services' );
				}
				?>
			</dd></div>
		</dl>
		<?php if ( $d['dns_only'] && true === $d['parent_ds'] && 'ours' !== $d['delegation'] ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'This domain uses DNSSEC at your registrar. Remove the DS record there before you change the name servers, then wait a day.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( $d['dns_only'] && '' !== $d['import_error'] ) : ?>
			<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the reason some records were not imported. */ __( 'Some imported records could not be added: %s. Add them in DNS records.', 'beltoft-services' ), $d['import_error'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $beltoft_next && isset( $beltoft_tabs[ $beltoft_next['tab'] ] ) ) : ?>
			<p class="beltoft-services-domain__note"><?php echo esc_html( $beltoft_next['text'] ); ?> <a href="<?php echo esc_url( $beltoft_link( $beltoft_next['tab'] ) ); ?>" data-tab-link="<?php echo esc_attr( $beltoft_next['tab'] ); ?>"><?php echo esc_html( $beltoft_next['link'] ); ?></a></p>
		<?php endif; ?>
	</section>

	<?php if ( isset( $beltoft_tabs['dns'] ) ) : ?>
		<?php $beltoft_open( 'dns' ); ?>
			<?php if ( $d['zone_unavailable'] ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your DNS records cannot be shown right now. Reload the page in a minute.', 'beltoft-services' ); ?></p>
			<?php else : ?>
				<form class="beltoft-services-domain-form beltoft-dns-editor" data-domain-action="set_records"<?php echo $d['off'] ? ' data-readonly="1"' : ''; ?> data-rows="<?php echo esc_attr( (string) wp_json_encode( $d['rows'] ) ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( array(
					'name' => __( 'Name', 'beltoft-services' ), 'name_hint' => __( '@ for the domain itself, or a name like www', 'beltoft-services' ), 'type' => __( 'Type', 'beltoft-services' ), 'ttl' => __( 'TTL', 'beltoft-services' ), 'value' => __( 'Value', 'beltoft-services' ),
					'priority' => __( 'Priority', 'beltoft-services' ), 'weight' => __( 'Weight', 'beltoft-services' ), 'port' => __( 'Port', 'beltoft-services' ),
					'flag' => __( 'Flag', 'beltoft-services' ), 'tag' => __( 'Tag', 'beltoft-services' ), 'remove' => __( 'Remove', 'beltoft-services' ),
					'search' => __( 'Search records', 'beltoft-services' ),
					/* translators: %d: number of DNS records */
					'show_all' => __( 'Show all %d records', 'beltoft-services' ),
					'show_fewer' => __( 'Show fewer', 'beltoft-services' ),
					'no_match' => __( 'No records match.', 'beltoft-services' ),
				) ) ); ?>">
					<?php if ( $d['off'] ) : ?>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'DNS hosting is paused. Your records are kept but not answered.', 'beltoft-services' ); ?></p>
					<?php endif; ?>
					<p class="beltoft-services-domain__empty" hidden><?php esc_html_e( 'No DNS records yet.', 'beltoft-services' ); ?></p>
					<table><thead><tr><th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'TTL', 'beltoft-services' ); ?></th><th><?php esc_html_e( 'Value', 'beltoft-services' ); ?></th><th></th></tr></thead><tbody></tbody></table>
					<input type="hidden" name="records" value="[]">
					<p class="beltoft-dns-error" role="alert" hidden></p>
					<?php if ( ! $d['off'] ) : ?>
					<p class="beltoft-dns-note"><?php esc_html_e( 'Changes are saved when you press Save DNS records.', 'beltoft-services' ); ?></p>
					<div class="beltoft-dns-actions">
						<button type="button" class="beltoft-services-domain__link beltoft-dns-add"><?php esc_html_e( 'Add record', 'beltoft-services' ); ?></button>
						<button type="submit" class="button"><?php esc_html_e( 'Save DNS records', 'beltoft-services' ); ?></button>
					</div>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( isset( $beltoft_tabs['nameservers'] ) ) : ?>
		<?php $beltoft_open( 'nameservers' ); ?>
			<ul class="beltoft-services-domain__hosts">
				<?php foreach ( $d['hosts'] as $beltoft_host ) : ?>
					<li><?php echo esc_html( $beltoft_host ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $d['external'] ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( '(DNS is hosted elsewhere)', 'beltoft-services' ); ?></p>
			<?php endif; ?>
			<?php if ( $d['can'] && $d['dns_only'] ) : ?>
				<form class="beltoft-services-domain-form" data-domain-action="check_delegation"><button type="submit" class="beltoft-services-domain__link"><?php esc_html_e( 'Check name servers', 'beltoft-services' ); ?></button></form>
			<?php elseif ( $d['can'] ) : ?>
				<form class="beltoft-services-domain-form beltoft-services-domain__ns" data-domain-action="set_nameservers">
					<fieldset class="beltoft-services-domain__choices" aria-label="<?php esc_attr_e( 'Name server mode', 'beltoft-services' ); ?>">
						<?php if ( ! $d['external'] ) : ?>
						<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="ours"<?php checked( $d['ours'] ); ?>> <?php esc_html_e( 'Our name servers', 'beltoft-services' ); ?></label>
						<?php endif; ?>
						<label class="beltoft-services-domain__choice"><input type="radio" name="mode" value="custom"<?php checked( ! $d['ours'] ); ?>> <?php esc_html_e( 'Custom name servers', 'beltoft-services' ); ?></label>
					</fieldset>
					<label class="beltoft-services-domain__custom"<?php echo $d['ours'] ? ' hidden' : ''; ?>><?php esc_html_e( 'Custom name servers, separated by commas', 'beltoft-services' ); ?> <input name="hosts" type="text" autocomplete="off" value="<?php echo esc_attr( $d['ours'] ? '' : implode( ', ', $d['hosts'] ) ); ?>"></label>
					<button type="submit" class="button"><?php esc_html_e( 'Save name servers', 'beltoft-services' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( isset( $beltoft_tabs['transfer'] ) ) : ?>
		<?php $beltoft_open( 'transfer' ); ?>
			<?php if ( null !== $d['transfer_price'] ) : ?>
				<div class="beltoft-services-domain__transfer">
					<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Move the registration here', 'beltoft-services' ); ?></h4>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'Transfer the registration to us and keep the domain and its DNS in one place. Your DNS records here stay as they are. The free DNS hosting ends when the transfer is done, because DNS is included with a domain.', 'beltoft-services' ); ?></p>
					<p class="beltoft-services-domain__note">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: transfer price per year */
								__( 'At your current registrar, turn off DNSSEC if it is on, unlock the domain and copy the transfer code (also called auth or EPP code). The transfer adds one year to the registration: %s per year, excluding VAT.', 'beltoft-services' ),
								wc_price( $d['transfer_price'] )
							)
						);
						?>
					</p>
					<form class="beltoft-services-domain-transfer" data-domain-transfer data-domain="<?php echo esc_attr( $d['domain'] ); ?>" data-cart="<?php echo esc_url( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/domains/cart' ) ); ?>" data-store-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_store_api' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-busy="<?php esc_attr_e( 'Starting transfer…', 'beltoft-services' ); ?>">
						<label class="beltoft-services-domain__label" for="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>"><?php esc_html_e( 'Transfer code', 'beltoft-services' ); ?></label>
						<span class="beltoft-services-domain__inline">
							<input type="text" id="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>" name="auth_code" autocomplete="off" spellcheck="false" minlength="6" maxlength="64" required>
							<button type="submit" class="button"><?php esc_html_e( 'Transfer to us', 'beltoft-services' ); ?></button>
						</span>
						<p class="beltoft-services-domain__error" role="alert" hidden></p>
					</form>
				</div>
			<?php endif; ?>
			<?php if ( $d['can'] && ! $d['dns_only'] ) : ?>
				<div class="beltoft-services-domain__transfer-out">
					<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Transfer to another registrar', 'beltoft-services' ); ?></h4>
					<p class="beltoft-services-domain__note"><?php echo esc_html( $d['locked'] ? __( 'Your domain is locked, so it cannot be moved to another registrar.', 'beltoft-services' ) : __( 'Your domain is unlocked. Enter the transfer code at your new registrar.', 'beltoft-services' ) ); ?></p>
					<div class="beltoft-services-domain__actions">
						<?php if ( $d['locked'] ) : ?>
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
				</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php
	// A tab an extension added (the danger zone is one) prints its pane here.
	foreach ( $beltoft_tabs as $beltoft_slug => $beltoft_title ) {
		if ( in_array( $beltoft_slug, array( 'overview', 'dns', 'nameservers', 'transfer' ), true ) ) {
			continue;
		}
		$beltoft_open( (string) $beltoft_slug );
		/**
		 * Prints the pane of a tab added with the filter beltoft_services_domain_tabs.
		 *
		 * @param string                      $slug     Tab slug.
		 * @param \BeltoftServices\Resource   $resource The domain's resource.
		 * @param array<string, mixed>        $d        Page values.
		 */
		do_action( 'beltoft_services_domain_tab_pane', (string) $beltoft_slug, $r, $d );
		echo '</section>';
	}
	?>
</div>
