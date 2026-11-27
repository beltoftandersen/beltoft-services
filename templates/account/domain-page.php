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
 * @var array<string, mixed> $d Values from BeltoftServices\Domains\DomainPage::build().
 */

defined( 'ABSPATH' ) || exit;

/** @var \BeltoftServices\Resource $r */
$r        = $d['r'];
$beltoft_tabs   = $d['tabs'];
$beltoft_active = $d['active'];
$beltoft_next   = \BeltoftServices\Domains\DomainPage::next_step( $d );
$beltoft_link   = static function ( string $slug ) use ( $d ): string {
	return \BeltoftServices\Account\ServiceArea::get( \BeltoftServices\Domains\Domains::class )->url( $d['license'], 'overview' === $slug ? '' : $slug );
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
<div class="beltoft-services-domain beltoft-services-domain--page" data-domain-page data-domain-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-bs-scope="<?php echo esc_attr( (string) $r->id() ); ?>" data-updating="<?php echo esc_attr__( 'Updating…', 'beltoft-services' ); ?>" data-updating-note="<?php echo esc_attr__( 'This can take up to a minute.', 'beltoft-services' ); ?>">
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
				<div><dt><?php esc_html_e( 'Subscription', 'beltoft-services' ); ?></dt><dd><?php echo esc_html( $d['renews'] ); ?></dd></div>
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
		<?php do_action( 'beltoft_services_domain_overview_end', $r, $d ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the plugin's own prefix. ?>
		<?php if ( $d['dns_only'] && true === $d['parent_ds'] && 'ours' !== $d['delegation'] ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'This domain uses DNSSEC at your registrar. Remove the DS record there before you change the name servers, then wait a day.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( $d['fix'] ) : ?>
			<?php $beltoft_fix = $d['fix']; include \BeltoftServices\Account\ServiceArea::file( 'domain-fix.php' ); ?>
		<?php endif; ?>
		<?php if ( $d['transfer'] && ! $d['transfer']['in_use'] ) : ?>
			<p class="beltoft-services-domain__note">
				<?php
				if ( 'first' === $d['transfer']['choice'] ) {
					esc_html_e( 'We have copied your DNS records here. The transfer starts when your domain uses our name servers, so your website and email keep working.', 'beltoft-services' );
				} else {
					esc_html_e( 'We have copied your DNS records here. When the transfer is done we move your domain to our name servers, so your website and email keep working.', 'beltoft-services' );
				}
				?>
				<a href="<?php echo esc_url( $beltoft_link( 'nameservers' ) ); ?>" data-tab-link="nameservers"><?php esc_html_e( 'Name servers', 'beltoft-services' ); ?></a>
			</p>
		<?php endif; ?>
		<?php if ( ( $d['dns_only'] || null !== $d['transfer'] ) && '' !== $d['import_error'] ) : ?>
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
				<?php
				$beltoft_i18n = array(
					'caption'      => __( 'DNS records', 'beltoft-services' ),
					'unavailable'  => __( 'Your DNS records cannot be shown right now. Reload the page in a minute.', 'beltoft-services' ),
					'name'         => __( 'Name', 'beltoft-services' ),
					'name_hint'    => __( '@ for the domain itself, or a name like www', 'beltoft-services' ),
					'type'         => __( 'Type', 'beltoft-services' ),
					'ttl'          => __( 'TTL', 'beltoft-services' ),
					'value'        => __( 'Value', 'beltoft-services' ),
					'status'       => __( 'Status', 'beltoft-services' ),
					'actions'      => __( 'Actions', 'beltoft-services' ),
					'priority'     => __( 'Priority', 'beltoft-services' ),
					'weight'       => __( 'Weight', 'beltoft-services' ),
					'port'         => __( 'Port', 'beltoft-services' ),
					'flag'         => __( 'Flag', 'beltoft-services' ),
					'tag'          => __( 'Tag', 'beltoft-services' ),
					'off'          => __( 'Off', 'beltoft-services' ),
					'add'          => __( 'Add record', 'beltoft-services' ),
					'edit'         => __( 'Edit', 'beltoft-services' ),
					'delete'       => __( 'Delete', 'beltoft-services' ),
					'keep'         => __( 'Keep', 'beltoft-services' ),
					'save'         => __( 'Save', 'beltoft-services' ),
					'saving'       => __( 'Saving…', 'beltoft-services' ),
					'cancel'       => __( 'Cancel', 'beltoft-services' ),
					'delete_q'     => __( 'Delete this record?', 'beltoft-services' ),
					/* translators: 1: record type, 2: record name */
					'edit_label'   => __( 'Edit %1$s record %2$s', 'beltoft-services' ),
					/* translators: 1: record type, 2: record name */
					'delete_label' => __( 'Delete %1$s record %2$s', 'beltoft-services' ),
					'search'       => __( 'Search records', 'beltoft-services' ),
					/* translators: %d: number of DNS records */
					'show_all'     => __( 'Show all %d records', 'beltoft-services' ),
					'show_fewer'   => __( 'Show fewer', 'beltoft-services' ),
					'no_match'     => __( 'No records match.', 'beltoft-services' ),
					'empty'        => __( 'No DNS records yet.', 'beltoft-services' ),
					'saved'        => __( 'Record saved.', 'beltoft-services' ),
					'deleted'      => __( 'Record deleted.', 'beltoft-services' ),
					'need_value'   => __( 'Enter a value for the record.', 'beltoft-services' ),
					'need_number'  => __( 'Enter whole numbers in the number fields.', 'beltoft-services' ),
					'network'      => __( 'The change could not be sent. Check your connection and try again.', 'beltoft-services' ),
					'set_hint'     => __( 'Name and type cannot change while this name has several records of this type.', 'beltoft-services' ),
					'ttl_60'       => __( '1 minute', 'beltoft-services' ),
					'ttl_300'      => __( '5 minutes', 'beltoft-services' ),
					'ttl_3600'     => __( '1 hour', 'beltoft-services' ),
					'ttl_14400'    => __( '4 hours', 'beltoft-services' ),
					'ttl_86400'    => __( '1 day', 'beltoft-services' ),
					/* translators: %d: seconds */
					'ttl_other'    => __( '%d seconds', 'beltoft-services' ),
				);
				?>
				<?php if ( $d['off'] ) : ?>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'DNS hosting is paused. Your records are kept but not answered.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<?php if ( $d['transfer'] && $d['transfer']['in_use'] ) : ?>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'In use: your domain uses our name servers.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<?php if ( $d['transfer'] && ! $d['transfer']['in_use'] ) : ?>
					<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the name servers the domain uses now. */ __( 'Not in use yet: your domain still uses %s. These records were copied from there. Check them and change what you need.', 'beltoft-services' ), implode( ', ', $d['transfer']['current'] ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( $d['transfer'] && $d['transfer']['review'] ) : ?>
					<div class="beltoft-services-domain__review">
						<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Import your DNS records', 'beltoft-services' ); ?></h4>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your current DNS stops when the domain leaves. Move your records here before the transfer starts: export the zone file from your current DNS provider and import it below. In most DNS providers this is called Export or Download zone file.', 'beltoft-services' ); ?></p>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'No zone file? The list below is what we copied. Records we could not look up are not here: other sub-names (for example shop. or api.), other email services and verification records. Compare with your current DNS provider and add anything that is missing.', 'beltoft-services' ); ?></p>
						<?php if ( '' !== $d['import_error'] ) : ?>
							<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the reason some records were not imported. */ __( 'Some records could not be added: %s.', 'beltoft-services' ), $d['import_error'] ) ); ?></p>
						<?php endif; ?>
						<form class="beltoft-services-domain-form" data-transfer-confirm data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Saving…', 'beltoft-services' ); ?>">
							<?php if ( ! $d['transfer']['imported'] ) : ?>
								<label class="beltoft-services-domain__choice"><input type="checkbox" name="ack" value="1"> <?php esc_html_e( 'I did not import a zone file. I understand that records we could not find are missing until I add them.', 'beltoft-services' ); ?></label>
							<?php endif; ?>
							<button type="submit" class="button"><?php esc_html_e( 'I have checked the records', 'beltoft-services' ); ?></button>
						</form>
					</div>
				<?php endif; ?>
				<div class="beltoft-dnse" data-dns-editor data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-zone="<?php echo esc_attr( strtolower( $d['domain'] ) ); ?>"<?php echo $d['off'] ? ' data-readonly="1"' : ''; ?><?php echo $d['rows_lazy'] ? ' data-rows-lazy' : ''; ?> data-rows="<?php echo esc_attr( (string) wp_json_encode( $d['rows'] ) ); ?>"<?php echo array() !== $d['notes'] ? ' data-notes="' . esc_attr( (string) wp_json_encode( $d['notes'] ) ) . '"' : ''; ?> data-i18n="<?php echo esc_attr( (string) wp_json_encode( $beltoft_i18n ) ); ?>">
					<noscript><p class="beltoft-services-domain__note"><?php esc_html_e( 'Turn on JavaScript to see and change your DNS records.', 'beltoft-services' ); ?></p></noscript>
				</div>
				<?php if ( ! $d['off'] && ! $d['zone_unavailable'] ) : ?>
					<div class="beltoft-services-domain__import" data-zone-import data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-i18n="<?php echo esc_attr( (string) wp_json_encode( \BeltoftServices\Domains\RecordImport::strings() ) ); ?>">
						<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Import from a zone file', 'beltoft-services' ); ?></h4>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'Export the zone file from your current DNS provider and import it here. In most DNS providers this is called Export or Download zone file.', 'beltoft-services' ); ?></p>
						<noscript><p class="beltoft-services-domain__note"><?php esc_html_e( 'Turn on JavaScript to import a zone file.', 'beltoft-services' ); ?></p></noscript>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( isset( $beltoft_tabs['nameservers'] ) ) : ?>
		<?php $beltoft_open( 'nameservers' ); ?>
			<?php if ( $d['transfer'] && ! $d['transfer']['in_use'] && $d['hosts'] ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your domain still uses these name servers:', 'beltoft-services' ); ?></p>
			<?php endif; ?>
			<ul class="beltoft-services-domain__hosts">
				<?php foreach ( $d['hosts'] as $beltoft_host ) : ?>
					<li><?php echo esc_html( $beltoft_host ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $d['external'] ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( '(DNS is hosted elsewhere)', 'beltoft-services' ); ?></p>
			<?php endif; ?>
			<?php if ( $d['transfer'] && $d['transfer']['review'] ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( 'First import or check your DNS records. The name server instructions follow after that.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $beltoft_link( 'dns' ) ); ?>" data-tab-link="dns"><?php esc_html_e( 'Open DNS records', 'beltoft-services' ); ?></a></p>
			<?php elseif ( $d['transfer'] && ! $d['transfer']['in_use'] ) : ?>
				<?php if ( 'first' === $d['transfer']['choice'] ) : ?>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'To go on with the transfer, set these name servers at your current registrar. We send the transfer when your domain uses them. This can take a few hours.', 'beltoft-services' ); ?></p>
				<?php else : ?>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'The transfer goes ahead with the name servers above. When it is done we move your domain to these name servers, and the DNS records copied here start to answer.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<ul class="beltoft-services-domain__hosts">
					<?php foreach ( $d['transfer']['ours'] as $beltoft_host ) : ?>
						<li><?php echo esc_html( $beltoft_host ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $d['transfer']['waiting'] ) : ?>
					<?php if ( $d['transfer']['checked'] > 0 ) : ?>
						<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: time of the last check. */ __( 'Last checked %s. Your domain does not use our name servers yet.', 'beltoft-services' ), wp_date( (string) get_option( 'time_format' ), $d['transfer']['checked'] ) ) ); ?></p>
					<?php endif; ?>
					<form class="beltoft-services-domain-form" data-transfer-check data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Checking…', 'beltoft-services' ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Check now', 'beltoft-services' ); ?></button></form>
				<?php endif; ?>
			<?php elseif ( $d['transfer'] && 'first' === $d['transfer']['choice'] && $d['transfer']['in_use'] && 'ready' !== (string) $r->get( 'state' ) ) : ?>
				<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your domain uses our name servers. The transfer is with the registry now. Your current registrar can take up to 5 days to release the domain. Approve the transfer at your current registrar, or in the email they send, to finish sooner.', 'beltoft-services' ); ?></p>
				<?php if ( $d['transfer']['registry'] ) : ?>
					<form class="beltoft-services-domain-form" data-transfer-registry data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Checking…', 'beltoft-services' ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Check now', 'beltoft-services' ); ?></button></form>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $d['adopt'] ) : ?>
				<div class="beltoft-services-domain__adopt">
					<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Use Beltoft DNS', 'beltoft-services' ); ?></h4>
					<p class="beltoft-services-domain__note"><?php esc_html_e( 'One place for your domain and its DNS. Name servers are the servers that answer for your domain. Your records have to be on our name servers before you switch, or your website and email stop.', 'beltoft-services' ); ?></p>
					<?php if ( ! $d['adopt']['started'] ) : ?>
						<form class="beltoft-services-domain-form" data-dns-adopt="start" data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Preparing…', 'beltoft-services' ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Use Beltoft DNS', 'beltoft-services' ); ?></button></form>
					<?php elseif ( ! $d['adopt']['reviewed'] ) : ?>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'Export the zone file from your current DNS provider (the list of all records of your domain, called Export or Download zone file in most DNS providers) and import it in DNS records. No zone file? We copied the records we could find. Records we could not look up are not there: other sub-names (for example shop. or api.), other email services and verification records.', 'beltoft-services' ); ?> <a href="<?php echo esc_url( $beltoft_link( 'dns' ) ); ?>" data-tab-link="dns"><?php esc_html_e( 'Open DNS records', 'beltoft-services' ); ?></a></p>
						<?php if ( '' !== $d['adopt']['error'] ) : ?>
							<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the reason some records were not imported. */ __( 'Some records could not be added: %s.', 'beltoft-services' ), $d['adopt']['error'] ) ); ?></p>
						<?php endif; ?>
						<form class="beltoft-services-domain-form" data-dns-adopt="confirm" data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Saving…', 'beltoft-services' ); ?>">
							<?php if ( ! $d['adopt']['imported'] ) : ?>
								<label class="beltoft-services-domain__choice"><input type="checkbox" name="ack" value="1"> <?php esc_html_e( 'I did not import a zone file. I understand that records we could not find are missing until I add them.', 'beltoft-services' ); ?></label>
							<?php endif; ?>
							<button type="submit" class="button"><?php esc_html_e( 'I have checked the records', 'beltoft-services' ); ?></button>
						</form>
					<?php else : ?>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your records are in place. Switching sets our name servers at your registrar. You can switch back in Name servers.', 'beltoft-services' ); ?></p>
						<form class="beltoft-services-domain-form" data-dns-adopt="switch" data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Switching…', 'beltoft-services' ); ?>"><button type="submit" class="button"><?php /* translators: %s: name of this shop. */ echo esc_html( sprintf( __( 'Switch to %s DNS', 'beltoft-services' ), \BeltoftServices\Brand::name() ) ); ?></button></form>
					<?php endif; ?>
				</div>
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

	<?php if ( isset( $beltoft_tabs['security'] ) ) : ?>
		<?php $beltoft_open( 'security' ); ?>
			<?php if ( $d['dnssec'] ) : ?>
				<?php $beltoft_dnssec = $d['dnssec']; ?>
				<div class="beltoft-services-domain__dnssec" data-dnssec>
					<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'DNSSEC', 'beltoft-services' ); ?></h4>
					<?php if ( $beltoft_dnssec['ours'] ) : ?>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'DNSSEC signs the DNS records of your domain, so a visitor can check that the answer really comes from your domain. Your domain uses our name servers, so we sign the records and give the registry the key.', 'beltoft-services' ); ?></p>
						<?php if ( $beltoft_dnssec['turning_off'] ) : ?>
							<p class="beltoft-services-domain__note"><?php esc_html_e( 'DNSSEC is being turned off. This takes about a day: the registry drops the key first, and the records stay signed until visitors no longer hold it, so nobody loses your website.', 'beltoft-services' ); ?></p>
						<?php else : ?>
							<p class="beltoft-services-domain__note"><strong><?php echo esc_html( $beltoft_dnssec['on'] ? __( 'DNSSEC is on.', 'beltoft-services' ) : __( 'DNSSEC is off.', 'beltoft-services' ) ); ?></strong></p>
							<?php if ( isset( $beltoft_dnssec['offered']['dnssec_enable'] ) ) : ?>
								<form class="beltoft-services-domain-dnssec" data-domain-dnssec="dnssec_enable"><button type="submit" class="button"><?php esc_html_e( 'Turn on DNSSEC', 'beltoft-services' ); ?></button></form>
								<p class="beltoft-services-domain__note"><?php esc_html_e( 'We email you a code to confirm. Turning it on signs your records and then gives the registry the key. This can take a few minutes.', 'beltoft-services' ); ?></p>
							<?php elseif ( isset( $beltoft_dnssec['offered']['dnssec_disable'] ) ) : ?>
								<form class="beltoft-services-domain-dnssec" data-domain-dnssec="dnssec_disable"><button type="submit" class="button"><?php esc_html_e( 'Turn off DNSSEC', 'beltoft-services' ); ?></button></form>
								<p class="beltoft-services-domain__note"><?php esc_html_e( 'We email you a code to confirm. The registry drops the key first. This takes about a day, so that no visitor loses your website.', 'beltoft-services' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					<?php else : ?>
						<p class="beltoft-services-domain__note"><?php esc_html_e( 'Your domain uses other name servers. If your DNS provider signs your domain with DNSSEC, enter the key it gives you here, so the registry knows it. We do not sign your records.', 'beltoft-services' ); ?></p>
						<?php if ( $beltoft_dnssec['keys'] ) : ?>
							<ul class="beltoft-services-domain__keys">
								<?php foreach ( $beltoft_dnssec['keys'] as $beltoft_key ) : ?>
									<li>
										<code><?php echo esc_html( sprintf( /* translators: 1: flags, 2: protocol, 3: algorithm number */ __( 'Flags %1$d, protocol %2$d, algorithm %3$d', 'beltoft-services' ), $beltoft_key['flags'], $beltoft_key['protocol'], $beltoft_key['alg'] ) ); ?></code>
										<code class="beltoft-services-domain__keytext"><?php echo esc_html( $beltoft_key['pub_key'] ); ?></code>
										<?php if ( ! $beltoft_key['readonly'] && isset( $beltoft_dnssec['offered']['dnssec_key_remove'] ) ) : ?>
											<form class="beltoft-services-domain-dnssec" data-domain-dnssec="dnssec_key_remove"><input type="hidden" name="key_id" value="<?php echo esc_attr( $beltoft_key['id'] ); ?>"><button type="submit" class="beltoft-services-domain__link"><?php esc_html_e( 'Remove key', 'beltoft-services' ); ?></button></form>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="beltoft-services-domain__note"><?php esc_html_e( 'No DNSSEC key is set.', 'beltoft-services' ); ?></p>
						<?php endif; ?>
						<?php if ( isset( $beltoft_dnssec['offered']['dnssec_key_add'] ) && ! $beltoft_dnssec['full'] ) : ?>
							<form class="beltoft-services-domain-dnssec beltoft-services-domain__keyform" data-domain-dnssec="dnssec_key_add">
								<label><?php esc_html_e( 'Flags', 'beltoft-services' ); ?>
									<select name="flags"><option value="257">257</option><option value="256">256</option></select></label>
								<label><?php esc_html_e( 'Protocol', 'beltoft-services' ); ?>
									<select name="protocol"><option value="3">3</option></select></label>
								<label><?php esc_html_e( 'Algorithm', 'beltoft-services' ); ?>
									<select name="alg">
										<?php foreach ( $beltoft_dnssec['algs'] as $beltoft_alg ) : ?>
											<option value="<?php echo esc_attr( (string) $beltoft_alg ); ?>"><?php echo esc_html( (string) $beltoft_alg ); ?></option>
										<?php endforeach; ?>
									</select></label>
								<label><?php esc_html_e( 'Public key', 'beltoft-services' ); ?>
									<textarea name="pub_key" rows="3" autocomplete="off" spellcheck="false" required></textarea></label>
								<button type="submit" class="button"><?php esc_html_e( 'Add key', 'beltoft-services' ); ?></button>
							</form>
							<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %d: the number of keys the extension allows */ _n( 'This extension allows %d key. We email you a code to confirm a change.', 'This extension allows up to %d keys. We email you a code to confirm a change.', $beltoft_dnssec['max'], 'beltoft-services' ), $beltoft_dnssec['max'] ) ); ?></p>
						<?php elseif ( $beltoft_dnssec['full'] ) : ?>
							<p class="beltoft-services-domain__note"><?php esc_html_e( 'The most keys this extension allows are set. Remove one to add another.', 'beltoft-services' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $d['whois'] ) : ?>
				<div class="beltoft-services-domain__whois" data-whois>
					<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'WHOIS privacy', 'beltoft-services' ); ?></h4>
					<form class="beltoft-services-domain-form" data-domain-action="whois_privacy">
						<input type="hidden" name="enabled" value="<?php echo $d['whois']['on'] ? '0' : '1'; ?>">
						<label class="beltoft-services-domain__choice"><input type="checkbox" data-domain-switch<?php checked( $d['whois']['on'] ); ?>> <?php esc_html_e( 'WHOIS privacy (free): hides your name, address, email and phone in the public WHOIS.', 'beltoft-services' ); ?></label>
					</form>
				</div>
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
								\BeltoftServices\Billing\TaxNote::pick( \BeltoftServices\Domains\Cart::domain_product(), __( 'At your current registrar, turn off DNSSEC if it is on, unlock the domain and copy the transfer code (also called auth or EPP code). The transfer adds one year to the registration: %s per year, excluding VAT.', 'beltoft-services' ), __( 'At your current registrar, turn off DNSSEC if it is on, unlock the domain and copy the transfer code (also called auth or EPP code). The transfer adds one year to the registration: %s per year, including VAT.', 'beltoft-services' ), __( 'At your current registrar, turn off DNSSEC if it is on, unlock the domain and copy the transfer code (also called auth or EPP code). The transfer adds one year to the registration: %s per year.', 'beltoft-services' ) ),
								\BeltoftServices\Billing\TaxNote::html( (float) $d['transfer_price'], 'domain-transfer', array( 'product' => \BeltoftServices\Domains\Cart::domain_product() ) )
							)
						);
						?>
					</p>
					<form class="beltoft-services-domain-transfer" data-domain-transfer data-domain="<?php echo esc_attr( $d['domain'] ); ?>" data-cart="<?php echo esc_url( rest_url( \BeltoftServices\Api\Routes::NAMESPACE . '/domains/cart' ) ); ?>" data-store-nonce="<?php echo esc_attr( wp_create_nonce( 'wc_store_api' ) ); ?>" data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-busy="<?php esc_attr_e( 'Starting transfer…', 'beltoft-services' ); ?>" data-empty="<?php esc_attr_e( 'Enter the transfer code.', 'beltoft-services' ); ?>" novalidate>
						<label class="beltoft-services-domain__label" for="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>"><?php esc_html_e( 'Transfer code', 'beltoft-services' ); ?></label>
						<span class="beltoft-services-domain__inline">
							<input type="text" id="beltoft-transfer-<?php echo esc_attr( (string) $r->id() ); ?>" name="auth_code" autocomplete="off" spellcheck="false" minlength="6" maxlength="64" required>
							<button type="submit" class="button"><?php esc_html_e( 'Transfer to us', 'beltoft-services' ); ?></button>
						</span>
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
					<?php include \BeltoftServices\Account\ServiceArea::file( 'domain-stepup.php' ); ?>
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
		if ( in_array( $beltoft_slug, array( 'overview', 'dns', 'nameservers', 'security', 'transfer' ), true ) ) {
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
