<?php
/**
 * Order received: what happens now for a domain transfer.
 * A theme can override this file as beltoft-services/order/transfer-next-steps.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, bool> $domains Domain => our DNS already serves it (the DNS stays as it is).
 * @var int      $days        The most days a transfer can take.
 * @var string   $account_url My Account Domains page.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $domains as $beltoft_domain => $beltoft_served ) :
	$beltoft_display = function_exists( 'idn_to_utf8' ) ? (string) ( idn_to_utf8( $beltoft_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) ?: $beltoft_domain ) : $beltoft_domain;
	?>
<section class="beltoft-next__block beltoft-transfer-next-steps">
	<h3>
		<?php
		/* translators: %s: domain name */
		echo esc_html( sprintf( __( 'What happens now with %s', 'beltoft-services' ), $beltoft_display ) );
		?>
	</h3>
	<p><?php esc_html_e( 'We have asked for the transfer of your domain.', 'beltoft-services' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Your current registrar may ask you to approve the transfer. Look for an email to the domain owner, or approve it in your account at your current registrar.', 'beltoft-services' ); ?></li>
		<li>
			<?php
			/* translators: %d: number of days. */
			echo esc_html( sprintf( _n( 'The transfer takes up to %d day.', 'The transfer takes up to %d days.', $days, 'beltoft-services' ), $days ) );
			?>
		</li>
		<li><?php esc_html_e( 'We email you when the domain is with us.', 'beltoft-services' ); ?></li>
	</ol>
	<?php if ( $beltoft_served ) : ?>
		<p><?php esc_html_e( 'Your DNS stays as it is.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your DNS stays where it is now. Your name servers do not change.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $account_url ) : ?>
		<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'My Account, Domains', 'beltoft-services' ); ?></a></p>
	<?php endif; ?>
</section>
<?php endforeach; ?>
