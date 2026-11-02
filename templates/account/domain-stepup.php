<?php
/**
 * My Account: a domain: the second check before the transfer code is shown. A six digit code is emailed to the account
 * address when the customer asks for it ("Send code"); a site with its own second factor shows only the code field.
 * Shown by "Show transfer code".
 *
 * Override by copying to yourtheme/beltoft-services/account/domain-stepup.php.
 *
 * @package BeltoftServices
 *
 * @var \BeltoftServices\Resource $r The domain's resource.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_ask     = \BeltoftServices\StepUp::ask( get_current_user_id(), \BeltoftServices\StepUp::TRANSFER_CODE );
$beltoft_id      = 'beltoft-domain-stepup-' . $r->id();
$beltoft_emailed = 'emailed' === $beltoft_ask['kind'];
?>
<form class="beltoft-services-domain-stepup" hidden>
	<?php if ( $beltoft_emailed ) : ?>
		<span class="beltoft-services-domain-stepup__ask">
			<?php /* translators: %s: the account email with part of the name hidden. */ ?>
			<span class="beltoft-services-domain__note"><?php echo esc_html( sprintf( __( 'To show the transfer code we email a code to %s.', 'beltoft-services' ), $beltoft_ask['email'] ) ); ?></span>
			<button type="button" class="button beltoft-services-domain-send"><?php esc_html_e( 'Send code', 'beltoft-services' ); ?></button>
		</span>
	<?php endif; ?>
	<span class="beltoft-services-domain-stepup__enter"<?php echo $beltoft_emailed ? ' hidden' : ''; ?>>
		<?php if ( $beltoft_emailed ) : ?>
			<?php /* translators: %s: the account email with part of the name hidden. */ ?>
			<span class="beltoft-services-domain__note beltoft-services-domain-stepup__sent"><?php echo esc_html( sprintf( __( 'We sent a code to %s.', 'beltoft-services' ), $beltoft_ask['email'] ) ); ?></span>
		<?php endif; ?>
		<label for="<?php echo esc_attr( $beltoft_id ); ?>"><?php echo esc_html( $beltoft_ask['label'] ); ?></label>
		<span class="beltoft-services-domain-stepup__row">
			<span class="beltoft-services-domain-stepup__field"><input id="<?php echo esc_attr( $beltoft_id ); ?>" name="secret" type="text" inputmode="numeric" maxlength="12" required autocomplete="<?php echo esc_attr( $beltoft_ask['autocomplete'] ); ?>" spellcheck="false"></span>
			<button type="submit" class="button"><?php esc_html_e( 'Show code', 'beltoft-services' ); ?></button>
		</span>
		<?php if ( $beltoft_emailed ) : ?>
			<button type="button" class="button beltoft-services-domain-send beltoft-services-domain-send--again"><?php esc_html_e( 'Send a new code', 'beltoft-services' ); ?></button>
		<?php endif; ?>
	</span>
	<p class="beltoft-services-domain-stepup__error" role="alert" hidden></p>
</form>
