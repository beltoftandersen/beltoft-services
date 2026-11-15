<?php
/**
 * The Domains section of a website: each name a row with its state, the WordPress address, the www pair, the DNS guide and Connect a domain.
 *
 * Variables: $v: HostingView::site().
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_slug = $v['slug'];
$beltoft_services_d    = $v['domains'];
?>
<details class="bsh-sec" data-sec="domains" id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-domains' ); ?>"<?php echo $beltoft_services_d['open'] ? ' open' : ''; ?>>
	<summary class="bsh-sec__row"><span class="bsh-sec__label"><?php esc_html_e( 'Domains', 'beltoft-services' ); ?></span><span class="bsh-sec__value"><?php echo esc_html( $beltoft_services_d['value'] ); ?></span></summary>
	<div class="bsh-sec__body">
		<?php // Each name a row: the name and its state, its roles under it, and its button at the right. ?>
		<ul class="bs-list bsh-names">
			<?php foreach ( $beltoft_services_d['rows'] as $beltoft_services_row ) : ?>
				<li class="bs-item">
					<div class="bs-item__main">
						<p class="bs-item__title"><span class="bs-item__name"><?php echo esc_html( $beltoft_services_row['display'] ); ?></span> <span class="bs-state bs-state--<?php echo esc_attr( $beltoft_services_row['tone'] ); ?>"><?php echo esc_html( $beltoft_services_row['state'] ); ?></span></p><?php if ( '' !== $beltoft_services_row['roles'] ) : ?><p class="bs-item__meta"><?php echo esc_html( $beltoft_services_row['roles'] ); ?></p><?php endif; ?>
					</div>
					<?php if ( $beltoft_services_row['removable'] ) : ?>
						<div class="bs-item__actions"><button type="button" class="beltoft-hosting-btn bsh-btn--sm" data-hosting-action="remove_domain" data-host="<?php echo esc_attr( $beltoft_services_row['host'] ); ?>"><?php esc_html_e( 'Remove', 'beltoft-services' ); ?></button></div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( null !== $beltoft_services_d['wp'] ) : ?>
			<?php \BeltoftServices\Hosting\HostingView::render( 'site-wp-address', $beltoft_services_d['wp'] ); ?>
		<?php endif; ?>

		<?php foreach ( $beltoft_services_d['pairs'] as $beltoft_services_pair ) : ?>
			<div class="bs-action" data-hosting-scope>
				<p class="bs-action__text"><strong class="bsh-action__title" id="<?php echo esc_attr( $beltoft_services_pair['name'] ); ?>-label"><?php esc_html_e( 'Main address', 'beltoft-services' ); ?></strong> <?php echo esc_html( $beltoft_services_pair['text'] ); ?></p>
				<div class="bs-action__do">
					<span class="beltoft-hosting-inline">
						<span class="bsh-choice" role="radiogroup" aria-labelledby="<?php echo esc_attr( $beltoft_services_pair['name'] ); ?>-label">
							<?php foreach ( $beltoft_services_pair['hosts'] as $beltoft_services_host ) : ?>
								<label class="bsh-choice__opt"><input type="radio" name="<?php echo esc_attr( $beltoft_services_pair['name'] ); ?>" value="<?php echo esc_attr( $beltoft_services_host['host'] ); ?>"<?php echo $beltoft_services_host['checked'] ? ' checked' : ''; ?><?php echo $beltoft_services_pair['ready'] ? '' : ' disabled'; ?>><span><?php echo esc_html( $beltoft_services_host['display'] ); ?></span></label>
							<?php endforeach; ?>
						</span>
						<?php if ( $beltoft_services_pair['ready'] ) : ?>
							<button type="button" class="beltoft-hosting-btn" data-hosting-action="set_main"><?php esc_html_e( 'Save', 'beltoft-services' ); ?></button>
						<?php endif; ?>
					</span>
				</div>
			</div>
		<?php endforeach; ?>

		<?php \BeltoftServices\Hosting\DnsGuide::render( $v['resource'], $v['site'], $v['node'], $v['ready'] ); ?>

		<?php if ( $v['ready'] ) : ?>
			<div class="bs-action" data-hosting-scope id="<?php echo esc_attr( 'beltoft-site-' . $beltoft_services_slug . '-connect' ); ?>">
				<p class="bs-action__text"><label for="<?php echo esc_attr( 'beltoft-connect-' . $beltoft_services_slug ); ?>"><strong class="bsh-action__title"><?php esc_html_e( 'Connect a domain', 'beltoft-services' ); ?></strong></label> <?php esc_html_e( 'A domain is connected with and without www. A subdomain is connected only as typed.', 'beltoft-services' ); ?> <?php echo esc_html( $beltoft_services_d['allowance'] ); ?></p>
				<div class="bs-action__do"><span class="beltoft-hosting-inline"><input type="text" id="<?php echo esc_attr( 'beltoft-connect-' . $beltoft_services_slug ); ?>" data-hosting-field="host" placeholder="example.com" autocomplete="off" autocapitalize="off" spellcheck="false"><button type="button" class="beltoft-hosting-btn" data-hosting-action="add_domain" data-slug="<?php echo esc_attr( $beltoft_services_slug ); ?>"><?php esc_html_e( 'Connect', 'beltoft-services' ); ?></button></span></div>
			</div>
		<?php endif; ?>
	</div>
</details>
