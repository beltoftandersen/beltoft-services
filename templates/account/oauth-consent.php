<?php
/**
 * The page where a customer lets an app connect to their account.
 *
 * Override by copying to yourtheme/beltoft-services/account/oauth-consent.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $result Decision of Oauth\Server::authorize(): kind, status, client, host, wants.
 * @var array<string, string> $fields The request values to carry through the form.
 * @var string               $nonce  Nonce of the form.
 * @var string               $action Address the form posts to.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_kind = (string) $result['kind'];
?>
<main class="beltoft-services-oauth">
	<?php if ( 'consent' === $beltoft_services_kind ) : ?>
		<h1><?php esc_html_e( 'Connect an app', 'beltoft-services' ); ?></h1>
		<p>
			<?php
			/* translators: %s: name of the app, for example Claude */
			echo esc_html( sprintf( __( '%s wants to read your servers, databases, domains and DNS.', 'beltoft-services' ), (string) $result['client']['name'] ) );
			?>
		</p>
		<p>
			<?php
			/* translators: %s: the website the app returns to, for example claude.ai */
			echo esc_html( sprintf( __( 'After you choose, you are sent back to %s. It never sees your password or any other secret.', 'beltoft-services' ), (string) $result['host'] ) );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php foreach ( $fields as $beltoft_services_key => $beltoft_services_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $beltoft_services_key ); ?>" value="<?php echo esc_attr( $beltoft_services_value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<label>
					<input type="checkbox" name="allow_actions" value="1" <?php checked( 'actions' === $result['wants'] ); ?>>
					<?php esc_html_e( 'and run actions (restart, snapshots, DNS changes)', 'beltoft-services' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" name="decision" value="approve" class="button"><?php esc_html_e( 'Allow', 'beltoft-services' ); ?></button>
				<button type="submit" name="decision" value="deny" class="button"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
		<p><?php esc_html_e( 'You can disconnect the app at any time under Account details, Security.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'user_off' === $beltoft_services_kind ) : ?>
		<h1><?php esc_html_e( 'Connecting apps is turned off', 'beltoft-services' ); ?></h1>
		<p><?php esc_html_e( 'You turned off Allow Claude (MCP) access for your account. Turn it on under Account details, Security, then connect again.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'off' === $beltoft_services_kind ) : ?>
		<h1><?php esc_html_e( 'Not available', 'beltoft-services' ); ?></h1>
		<p><?php esc_html_e( 'Connecting apps is not available on this site.', 'beltoft-services' ); ?></p>
	<?php elseif ( 'expired' === $beltoft_services_kind ) : ?>
		<h1><?php esc_html_e( 'This page expired', 'beltoft-services' ); ?></h1>
		<p><?php esc_html_e( 'Start the connection again from the app.', 'beltoft-services' ); ?></p>
	<?php else : ?>
		<h1><?php esc_html_e( 'The request is not valid', 'beltoft-services' ); ?></h1>
		<p><?php esc_html_e( 'The app did not ask in the right way. Start the connection again from the app.', 'beltoft-services' ); ?></p>
	<?php endif; ?>
</main>
