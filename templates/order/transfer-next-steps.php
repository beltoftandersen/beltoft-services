<?php
/**
 * Order received: what happens now for a domain transfer.
 * A theme can override this file as beltoft-services/order/transfer-next-steps.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, bool> $domains Domain => our DNS already serves it (the DNS stays as it is).
 * @var array<string, string> $choices Domain => where the DNS lives: first, after, keep, or '' for an older order.
 * @var array<string, bool> $free Domain => the transfer was free (optional).
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
	<?php $beltoft_choice = $beltoft_served ? '' : (string) ( $choices[ $beltoft_domain ] ?? '' ); ?>
	<?php if ( 'first' === $beltoft_choice ) : ?>
	<p><?php esc_html_e( 'We are copying your DNS records so your website and email keep working. The transfer starts when your domain uses our name servers.', 'beltoft-services' ); ?></p>
	<ol>
		<li><?php esc_html_e( 'Open the domain in My Account to see the name servers to set at your current registrar. Turn off DNSSEC there first.', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'When your domain uses our name servers, we send the transfer. We check every few minutes, and you can ask for a check in My Account.', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'Your current registrar may then ask you to approve the transfer. Look for an email to the domain owner, or approve it in your account at your current registrar.', 'beltoft-services' ); ?></li>
		<li>
			<?php
			/* translators: %d: number of days. */
			echo esc_html( sprintf( _n( 'The transfer takes up to %d day.', 'The transfer takes up to %d days.', $days, 'beltoft-services' ), $days ) );
			?>
		</li>
		<li><?php esc_html_e( 'We email you when the domain is with us.', 'beltoft-services' ); ?></li>
	</ol>
	<p><?php esc_html_e( 'If your domain does not use our name servers within 14 days, the transfer is not sent and your DNS stays where it is.', 'beltoft-services' ); ?></p>
	<?php else : ?>
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
	<?php endif; ?>
	<?php if ( ! empty( $free[ $beltoft_domain ] ) ) : ?>
		<p><?php esc_html_e( 'The transfer is free. The first yearly payment is due when the domain is up for renewal.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
	<?php if ( $beltoft_served ) : ?>
		<p><?php esc_html_e( 'Your DNS stays as it is.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'first' === $beltoft_choice ) : ?>
		<?php // Said above. ?>
	<?php elseif ( 'after' === $beltoft_choice ) : ?>
		<p><?php esc_html_e( 'We have copied your DNS records to our name servers, and your domain keeps using its current name servers until the transfer is done. Then we move it to ours. You can check and change the records in My Account before that.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Your DNS stays where it is now. Your name servers do not change. Check that your DNS stays active after the transfer, because your current registrar may switch it off.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $account_url ) : ?>
		<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'My Account, Domains', 'beltoft-services' ); ?></a></p>
	<?php endif; ?>
</section>
<?php endforeach; ?>
