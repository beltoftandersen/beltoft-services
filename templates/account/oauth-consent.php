<?php
/**
 * The page where a customer lets an app connect to their account.
 *
 * Override by copying to yourtheme/beltoft-services/account/oauth-consent.php.
 *
 * @package BeltoftServices
 *
 * @var array<string, mixed> $result Decision of Oauth\Server::authorize(): kind, status, client, host, verified, account, asks_actions, asks, notice.
 * @var array<string, string> $fields The request values to carry through the form.
 * @var string               $nonce  Nonce of the form.
 * @var string               $action Address the form posts to.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_kind = (string) $result['kind'];
?>
<main class="beltoft-services-oauth">
	<div class="beltoft-services-oauth__panel">
	<?php if ( 'consent' === $beltoft_services_kind ) : ?>
		<h1><?php
			/* translators: %s: name of the app, as the app gave it */
			echo esc_html( sprintf( __( 'Connect %s to your account', 'beltoft-services' ), (string) $result['client']['name'] ) );
		?></h1>
		<p class="beltoft-services-oauth__lead">
			<?php
			/* translators: 1: name of the app as the app gave it, 2: the website the app returns to */
			echo esc_html( sprintf( __( '"%1$s" wants to connect to your account. After you choose, you are sent back to %2$s.', 'beltoft-services' ), (string) $result['client']['name'], (string) $result['host'] ) );
			?>
		</p>
		<dl class="beltoft-services-oauth__facts">
			<div>
				<dt><?php esc_html_e( 'App', 'beltoft-services' ); ?></dt>
				<dd>
					<?php echo esc_html( (string) $result['client']['name'] ); ?>
					<span class="beltoft-services-oauth__<?php echo $result['verified'] ? 'verified' : 'unverified'; ?>">
						<?php echo esc_html( $result['verified'] ? __( 'Verified', 'beltoft-services' ) : __( 'Not verified: the name is chosen by the app. Continue only if you started this connection.', 'beltoft-services' ) ); ?>
					</span>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Returns to', 'beltoft-services' ); ?></dt>
				<dd><?php echo esc_html( (string) $result['host'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Your account', 'beltoft-services' ); ?></dt>
				<dd><?php echo esc_html( sprintf( '%1$s (%2$s)', (string) $result['account']['name'], (string) $result['account']['email'] ) ); ?></dd>
			</div>
		</dl>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php foreach ( $fields as $beltoft_services_key => $beltoft_services_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $beltoft_services_key ); ?>" value="<?php echo esc_attr( $beltoft_services_value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<fieldset class="beltoft-services-oauth__choices">
				<legend><?php esc_html_e( 'What the app may do', 'beltoft-services' ); ?></legend>
				<?php if ( ! empty( $result['notice'] ) && 'full_tick' === $result['notice'] ) : ?>
					<p class="beltoft-services-oauth__note"><?php esc_html_e( 'To give Full access, tick the box under it. Nothing was connected.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<?php
				$beltoft_services_asks = (string) ( $result['asks'] ?? ( ! empty( $result['asks_actions'] ) ? 'actions' : 'read' ) );
				?>
				<?php if ( 'actions' === $beltoft_services_asks ) : ?>
					<p class="beltoft-services-oauth__note"><?php esc_html_e( 'This app asks to run actions too. Choose that only if you want it.', 'beltoft-services' ); ?></p>
				<?php elseif ( 'full' === $beltoft_services_asks ) : ?>
					<p class="beltoft-services-oauth__note"><?php esc_html_e( 'This app asks for Full access. Choose that only if you want it.', 'beltoft-services' ); ?></p>
				<?php endif; ?>
				<label class="beltoft-services-oauth__choice">
					<input type="radio" name="level" value="read" checked>
					<span>
						<strong><?php esc_html_e( 'Read only', 'beltoft-services' ); ?></strong>
						<?php esc_html_e( 'Read your servers, databases, domains and DNS records, their state, usage, backups and snapshots, firewall and access lists, your orders, subscriptions, saved cards (brand and last digits only), account details, license keys and next payment. It cannot change anything.', 'beltoft-services' ); ?>
					</span>
				</label>
				<label class="beltoft-services-oauth__choice">
					<input type="radio" name="level" value="actions">
					<span>
						<strong><?php esc_html_e( 'Read and actions', 'beltoft-services' ); ?></strong>
						<?php esc_html_e( 'Also everyday changes: restart, start and stop a server, rename it, take a snapshot, change its firewall and database access list, set or remove DNS records, change name servers, turn auto-renew on or off, and ask for a new database password. Restoring, reinstalling, deleting, buying and showing passwords need Full access.', 'beltoft-services' ); ?>
					</span>
				</label>
				<label class="beltoft-services-oauth__choice">
					<input type="radio" name="level" value="full">
					<span>
						<strong><?php esc_html_e( 'Full access', 'beltoft-services' ); ?></strong>
						<?php esc_html_e( 'Everything above, plus abilities that need your yes in the chat each time: buy and renew services with your saved card, cancel services, restore or reinstall servers, roll back or delete snapshots, delete data, and show you passwords once. None of these is available yet: today Full access can do the same as Read and actions. When they are added you connect again to use them. Domain transfers, and the transfer code of a domain, are never possible through an app: they stay in your dashboard.', 'beltoft-services' ); ?>
					</span>
				</label>
				<label class="beltoft-services-oauth__choice beltoft-services-oauth__understand">
					<input type="checkbox" name="understand_full" value="1">
					<span><?php esc_html_e( 'For Full access: I understand that the app can spend money and delete data once I agree in the chat.', 'beltoft-services' ); ?></span>
				</label>
			</fieldset>
			<p class="beltoft-services-oauth__secret"><?php esc_html_e( 'Your account password is never shared. Without Full access the app sees no password or other secret at all.', 'beltoft-services' ); ?></p>
			<p class="beltoft-services-oauth__buttons">
				<button type="submit" name="decision" value="approve" class="button beltoft-services-oauth__allow"><?php esc_html_e( 'Allow', 'beltoft-services' ); ?></button>
				<button type="submit" name="decision" value="deny" class="button beltoft-services-oauth__cancel"><?php esc_html_e( 'Cancel', 'beltoft-services' ); ?></button>
			</p>
		</form>
		<p class="beltoft-services-oauth__foot"><?php esc_html_e( 'You can disconnect the app at any time under Account details, Security.', 'beltoft-services' ); ?></p>
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
	</div>
</main>
