<?php
/**
 * The page where a customer lets an app connect to their account.
 *
 * Override by copying to yourtheme/beltoft-services/account/oauth-consent.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $result Decision of Oauth\Server::authorize(): kind, status, client, host, verified, account, asks_actions.
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
			/* translators: 1: name of the app as the app gave it, 2: the website the app returns to */
			echo esc_html( sprintf( __( 'An app called "%1$s" wants to connect to your account. After you choose, you are sent back to %2$s.', 'beltoft-services' ), (string) $result['client']['name'], (string) $result['host'] ) );
			?>
			<strong>
				<?php echo esc_html( $result['verified'] ? __( 'Verified.', 'beltoft-services' ) : __( 'Not verified: the name is chosen by the app. Continue only if you started this connection.', 'beltoft-services' ) ); ?>
			</strong>
		</p>
		<p>
			<?php
			/* translators: %s: name of the app, as the app gave it */
			echo esc_html( sprintf( __( '%s wants to read your servers, databases, domains and DNS.', 'beltoft-services' ), (string) $result['client']['name'] ) );
			?>
		</p>
		<p>
			<?php
			/* translators: 1: name of the account, 2: email address */
			echo esc_html( sprintf( __( 'You are signed in as %1$s (%2$s).', 'beltoft-services' ), (string) $result['account']['name'], (string) $result['account']['email'] ) );
			?>
		</p>
		<p><?php esc_html_e( 'What each choice allows:', 'beltoft-services' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Read only: the app can read your servers, databases, domains and DNS records, their state and usage, and your next payment. It cannot change anything.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Read and actions: also restart, start and stop a server, take a snapshot, set or remove DNS records and ask for a new database password. Restoring, reinstalling and deleting are never allowed.', 'beltoft-services' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'The app never sees your password or any other secret.', 'beltoft-services' ); ?></p>
		<?php if ( ! empty( $result['asks_actions'] ) ) : ?>
			<p><?php esc_html_e( 'This app asks to run actions too. Tick the box below only if you want that.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php foreach ( $fields as $beltoft_services_key => $beltoft_services_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $beltoft_services_key ); ?>" value="<?php echo esc_attr( $beltoft_services_value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<label>
					<input type="checkbox" name="allow_actions" value="1">
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
