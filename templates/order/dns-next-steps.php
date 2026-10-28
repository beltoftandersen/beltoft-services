<?php
/**
 * Order received: next steps for DNS hosting of a domain registered elsewhere.
 * A theme can override this file as beltoft-services/order/dns-next-steps.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, bool|null> $domains     Domain => parent DS present (null when unknown).
 * @var string[]                 $nameservers Our name servers.
 * @var string                   $account_url My Account Domains page.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $domains as $beltoft_domain => $beltoft_ds ) :
	$beltoft_display = function_exists( 'idn_to_utf8' ) ? (string) ( idn_to_utf8( $beltoft_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) ?: $beltoft_domain ) : $beltoft_domain;
	?>
<section class="beltoft-next__block beltoft-dns-next-steps">
	<h3>
		<?php
		/* translators: %s: domain name */
		echo esc_html( sprintf( __( 'Next steps for %s', 'beltoft-services' ), $beltoft_display ) );
		?>
	</h3>
	<p><?php esc_html_e( 'Your DNS zone is being set up with the records you chose. Nothing changes for your domain until you change its name servers.', 'beltoft-services' ); ?></p>
	<ol>
		<li>
			<?php esc_html_e( 'Check your records and add anything that is missing:', 'beltoft-services' ); ?>
			<?php if ( '' !== $account_url ) : ?>
				<a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'My Account, Domains', 'beltoft-services' ); ?></a>
			<?php endif; ?>
		</li>
		<?php if ( false !== $beltoft_ds ) : ?>
			<li>
				<?php
				echo esc_html(
					true === $beltoft_ds
						? __( 'Your domain uses DNSSEC. Remove the DS record at your registrar first, then wait a day before the next step.', 'beltoft-services' )
						: __( 'If your domain uses DNSSEC, remove the DS record at your registrar first, then wait a day before the next step.', 'beltoft-services' )
				);
				?>
			</li>
		<?php endif; ?>
		<li>
			<?php esc_html_e( 'At the registrar where the domain is registered, change its name servers to:', 'beltoft-services' ); ?>
			<ul>
				<?php foreach ( $nameservers as $beltoft_ns ) : ?>
					<li><code><?php echo esc_html( $beltoft_ns ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</li>
	</ol>
	<p><?php esc_html_e( 'The change usually shows within an hour. Your domain page shows when the name servers point here.', 'beltoft-services' ); ?></p>
</section>
<?php endforeach; ?>
