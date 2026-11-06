<?php
/**
 * A transfer that waits for something the customer fixes: a new transfer code, or the domain unlocked at the current registrar.
 *
 * Override by copying to yourtheme/beltoft-services/account/domain-fix.php.
 *
 * @package BeltoftServices
 *
 * @var \BeltoftServices\Resource $r
 * @var array<string, string>     $beltoft_fix From BeltoftServices\Domains\TransferFix::view().
 */

defined( 'ABSPATH' ) || exit;

$beltoft_fix_who = '' !== $beltoft_fix['registrar'] ? $beltoft_fix['registrar'] : __( 'your current registrar', 'beltoft-services' );
?>
<div class="beltoft-services-domain__fix" data-transfer-fix="<?php echo esc_attr( $beltoft_fix['reason'] ); ?>">
	<?php if ( 'auth_refused' === $beltoft_fix['reason'] ) : ?>
		<p class="beltoft-services-domain__note"><?php esc_html_e( 'The transfer code was not accepted.', 'beltoft-services' ); ?></p>
		<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the current registrar. */ __( 'Ask %s for a new transfer code (also called auth code or EPP code). You find it in the domain settings there. Enter the new code here and we send the transfer again.', 'beltoft-services' ), $beltoft_fix_who ) ); ?></p>
		<form class="beltoft-services-domain-form" data-transfer-code data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Saving…', 'beltoft-services' ); ?>">
			<label for="beltoft-transfer-code-<?php echo esc_attr( (string) $r->id() ); ?>"><?php esc_html_e( 'New transfer code', 'beltoft-services' ); ?></label>
			<input type="text" id="beltoft-transfer-code-<?php echo esc_attr( (string) $r->id() ); ?>" name="auth_code" autocomplete="off" spellcheck="false" minlength="6" maxlength="64" required>
			<button type="submit" class="button"><?php esc_html_e( 'Send the transfer again', 'beltoft-services' ); ?></button>
		</form>
	<?php else : ?>
		<p class="beltoft-services-domain__note"><?php echo esc_html( sprintf( /* translators: %s: the current registrar. */ __( 'The domain is locked at %s. Unlock it there (the setting is called transfer lock or registrar lock), then click Check now.', 'beltoft-services' ), $beltoft_fix_who ) ); ?></p>
		<form class="beltoft-services-domain-form" data-transfer-registry data-resource="<?php echo esc_attr( (string) $r->id() ); ?>" data-busy="<?php esc_attr_e( 'Checking…', 'beltoft-services' ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Check now', 'beltoft-services' ); ?></button></form>
	<?php endif; ?>
</div>
