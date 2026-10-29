<?php
/**
 * The Danger zone tab of a domain page: auto-renew for a registered domain, stop DNS hosting for a DNS-only one.
 *
 * Plain posts to the page with a nonce, handled by BeltoftServices\Account\DomainDanger::handle(). The registrar is
 * never asked to delete anything.
 *
 * Override by copying to yourtheme/beltoft-services/account/domain-danger.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed>     $offer   What the tab offers (kind, manual, can_enable, next).
 * @var \BeltoftServices\Resource $r       The domain's resource.
 * @var string                   $domain  Domain name.
 * @var string                   $action  Address the forms post to.
 * @var string                   $nonce   Nonce of the forms.
 * @var string                   $expires Registered until, formatted, or ''.
 * @var int                      $license License id.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_field = \BeltoftServices\Account\DomainDanger::FIELD;
$beltoft_form  = static function ( string $what ) use ( $action, $nonce, $license, $beltoft_field ): void {
	?>
	<input type="hidden" name="<?php echo esc_attr( $beltoft_field ); ?>" value="<?php echo esc_attr( $what ); ?>">
	<input type="hidden" name="license" value="<?php echo (int) $license; ?>">
	<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
	<?php
};
?>
<div class="beltoft-services-danger">
<?php if ( 'renew' === $offer['kind'] ) : ?>
	<h4 class="beltoft-services-domain__title">
		<?php echo esc_html( $offer['manual'] ? __( 'Auto-renew is off', 'beltoft-services' ) : __( 'Auto-renew is on', 'beltoft-services' ) ); ?>
	</h4>
	<?php if ( $offer['manual'] ) : ?>
		<p class="beltoft-services-domain__note">
			<?php
			echo esc_html(
				'' !== $offer['next']
					? sprintf(
						/* translators: %s: renewal date */
						__( 'An invoice for the renewal is emailed to you on %s. Pay it to keep the domain.', 'beltoft-services' ),
						$offer['next']
					)
					: __( 'An invoice for the renewal is emailed to you. Pay it to keep the domain.', 'beltoft-services' )
			);
			?>
		</p>
	<?php else : ?>
		<p class="beltoft-services-domain__note">
			<?php
			echo esc_html(
				'' !== $offer['next']
					? sprintf(
						/* translators: %s: renewal date */
						__( 'The domain renews on %s and your payment method is charged then.', 'beltoft-services' ),
						$offer['next']
					)
					: __( 'The domain renews automatically and your payment method is charged then.', 'beltoft-services' )
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( ! $offer['manual'] ) : ?>
		<p class="beltoft-services-domain__note">
			<?php
			echo esc_html(
				'' !== $expires
					? sprintf(
						/* translators: %s: date the registration ends */
						__( 'If you turn it off, you get an invoice by email instead of an automatic charge. The domain stays registered until %s.', 'beltoft-services' ),
						$expires
					)
					: __( 'If you turn it off, you get an invoice by email instead of an automatic charge. The domain stays registered until its end date.', 'beltoft-services' )
			);
			?>
		</p>
	<?php elseif ( '' !== $expires ) : ?>
		<p class="beltoft-services-domain__note">
			<?php
			/* translators: %s: date the registration ends */
			echo esc_html( sprintf( __( 'The domain stays registered until %s.', 'beltoft-services' ), $expires ) );
			?>
		</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( $action ); ?>" class="beltoft-services-danger__form">
		<?php $beltoft_form( $offer['manual'] ? 'auto_renew_on' : 'auto_renew_off' ); ?>
		<?php if ( $offer['manual'] && ! $offer['can_enable'] ) : ?>
			<p class="beltoft-services-domain__note"><?php esc_html_e( 'To turn auto-renew back on, add a payment method to your account first.', 'beltoft-services' ); ?></p>
		<?php else : ?>
			<button type="submit" class="button"><?php echo esc_html( $offer['manual'] ? __( 'Turn auto-renew back on', 'beltoft-services' ) : __( 'Turn off auto-renew', 'beltoft-services' ) ); ?></button>
		<?php endif; ?>
	</form>
<?php else : ?>
	<h4 class="beltoft-services-domain__title"><?php esc_html_e( 'Stop DNS hosting', 'beltoft-services' ); ?></h4>
	<p class="beltoft-services-domain__note">
		<?php
		/* translators: %s: domain name */
		echo esc_html( sprintf( __( 'DNS hosting for %s ends at once. Its records stop answering and the subscription ends. Nothing is changed at your registrar: if the domain still uses our name servers, change them there first.', 'beltoft-services' ), $domain ) );
		?>
	</p>
	<p><button type="button" class="button beltoft-services-danger__open" data-danger-open="beltoft-danger-stop-<?php echo (int) $license; ?>"><?php esc_html_e( 'Stop DNS hosting', 'beltoft-services' ); ?></button></p>
	<dialog class="beltoft-services-domain__dialog" id="beltoft-danger-stop-<?php echo (int) $license; ?>" aria-labelledby="beltoft-danger-title-<?php echo (int) $license; ?>">
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="beltoft-services-danger__dialog-form" data-danger-domain="<?php echo esc_attr( strtolower( $domain ) ); ?>">
			<?php $beltoft_form( 'stop_hosting' ); ?>
			<h5 id="beltoft-danger-title-<?php echo (int) $license; ?>"><?php esc_html_e( 'Stop DNS hosting?', 'beltoft-services' ); ?></h5>
			<p>
				<?php
				/* translators: %s: domain name */
				echo esc_html( sprintf( __( 'The DNS records of %s stop answering at once and the subscription ends. This cannot be undone here; to host the domain with us again you add it as a new domain.', 'beltoft-services' ), $domain ) );
				?>
			</p>
			<label for="beltoft-danger-confirm-<?php echo (int) $license; ?>">
				<?php
				/* translators: %s: domain name */
				echo esc_html( sprintf( __( 'Type %s to confirm', 'beltoft-services' ), $domain ) );
				?>
			</label>
			<input type="text" id="beltoft-danger-confirm-<?php echo (int) $license; ?>" name="confirm" autocomplete="off" autocapitalize="off" spellcheck="false">
			<p class="beltoft-services-danger__buttons">
				<button type="submit" class="button beltoft-services-danger__confirm" disabled><?php esc_html_e( 'Stop DNS hosting', 'beltoft-services' ); ?></button>
				<button type="button" class="beltoft-services-domain__link" data-danger-cancel><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
	</dialog>
<?php endif; ?>
</div>
