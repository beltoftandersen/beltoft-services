<?php
/**
 * The emailed code field with its Send code button (used by both forms of the Files panel and by the database password).
 *
 * Variables: $v: purpose (optional: the purpose the code is sent for, hosting_files when none).
 *
 * @package BeltoftServices
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="beltoft-hosting-formrow"><label class="beltoft-hosting-field"><span><?php esc_html_e( 'Code from the email', 'beltoft-services' ); ?></span><input type="text" data-hosting-field="code" inputmode="numeric" autocomplete="one-time-code"></label><button type="button" class="beltoft-hosting-btn" data-hosting-action="send_code" data-purpose="<?php echo esc_attr( (string) ( $v['purpose'] ?? 'hosting_files' ) ); ?>"><?php esc_html_e( 'Send code', 'beltoft-services' ); ?></button></div>
