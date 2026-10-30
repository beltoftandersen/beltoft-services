<?php
/**
 * My Account → a domain: the second check before the transfer code is shown (the account password, or a code
 * from the customer's authenticator app when the site asks for one). Shown by "Show transfer code".
 *
 * Override by copying to yourtheme/beltoft-services/account/domain-stepup.php.
 *
 * @package BeltoftServices
 *
 * @var \BeltoftServices\Resource $r The domain's resource.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_ask = \BeltoftServices\StepUp::ask( get_current_user_id() );
$beltoft_id  = 'beltoft-domain-stepup-' . $r->id();
?>
<form class="beltoft-services-domain-stepup" hidden>
	<p class="beltoft-services-domain__note"><?php echo esc_html( 'code' === $beltoft_ask['kind'] ? __( 'To show the transfer code, enter the code from your authenticator app.', 'beltoft-services' ) : __( 'To show the transfer code, enter your account password.', 'beltoft-services' ) ); ?></p>
	<label for="<?php echo esc_attr( $beltoft_id ); ?>"><?php echo esc_html( $beltoft_ask['label'] ); ?></label>
	<span class="beltoft-services-domain-stepup__row">
		<input id="<?php echo esc_attr( $beltoft_id ); ?>" name="secret" required autocomplete="<?php echo esc_attr( $beltoft_ask['autocomplete'] ); ?>" spellcheck="false"<?php echo 'code' === $beltoft_ask['kind'] ? ' type="text" inputmode="numeric" maxlength="12"' : ' type="password"'; ?>>
		<button type="submit" class="button"><?php esc_html_e( 'Show code', 'beltoft-services' ); ?></button>
	</span>
	<p class="beltoft-services-domain-stepup__error" role="alert" hidden></p>
</form>
