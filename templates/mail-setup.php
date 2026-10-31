<?php
/**
 * The mail program setup guide. Override in yourtheme/beltoft-services/mail-setup.php.
 *
 * @package BeltoftServices
 *
 * @var string             $host    Mail host name.
 * @var string             $webmail Webmail address, '' when none.
 * @var int                $imap    IMAP port.
 * @var int                $pop     POP3 port.
 * @var array<int, int>    $smtp    SMTP ports: SSL/TLS, STARTTLS.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_host = '<strong>' . esc_html( $host ) . '</strong>';
$beltoft_services_clients = array(
	'iphone'      => __( 'iPhone and iPad', 'beltoft-services' ),
	'mac'         => __( 'Mac Mail', 'beltoft-services' ),
	'outlook'     => __( 'Outlook', 'beltoft-services' ),
	'thunderbird' => __( 'Thunderbird', 'beltoft-services' ),
	'gmail'       => __( 'Gmail app', 'beltoft-services' ),
	'samsung'     => __( 'Samsung Email', 'beltoft-services' ),
);
?>
<div class="beltoft-services-mailsetup">
	<h2 id="bsms-settings"><?php esc_html_e( 'Settings for every app', 'beltoft-services' ); ?></h2>
	<div class="beltoft-services-mailsetup__scroll">
		<table>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Setting', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Server', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Port', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Security', 'beltoft-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Incoming mail, IMAP', 'beltoft-services' ); ?></td>
					<td><code><?php echo esc_html( $host ); ?></code></td>
					<td><?php echo (int) $imap; ?></td>
					<td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Outgoing mail, SMTP', 'beltoft-services' ); ?></td>
					<td><code><?php echo esc_html( $host ); ?></code></td>
					<td><?php echo (int) $smtp[0]; ?></td>
					<td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Outgoing mail, SMTP (other option)', 'beltoft-services' ); ?></td>
					<td><code><?php echo esc_html( $host ); ?></code></td>
					<td><?php echo (int) $smtp[1]; ?></td>
					<td><?php esc_html_e( 'STARTTLS', 'beltoft-services' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Incoming mail, POP3', 'beltoft-services' ); ?></td>
					<td><code><?php echo esc_html( $host ); ?></code></td>
					<td><?php echo (int) $pop; ?></td>
					<td><?php esc_html_e( 'SSL/TLS', 'beltoft-services' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<ul>
		<li><?php esc_html_e( 'User name: the full email address, for example info@your-domain.', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'Password: the mailbox password from your account. It is shown once when you make the mailbox. If you lost it, set a new one in your account.', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'Use IMAP if you read your mail on more than one device. POP3 downloads mail to one device and can remove it from the server.', 'beltoft-services' ); ?></li>
		<li><?php esc_html_e( 'The same user name and password are used for incoming and outgoing mail.', 'beltoft-services' ); ?></li>
		<?php if ( '' !== $webmail ) : ?>
			<li>
				<?php esc_html_e( 'Webmail:', 'beltoft-services' ); ?>
				<a href="<?php echo esc_url( $webmail ); ?>" rel="noopener noreferrer"><?php echo esc_html( $webmail ); ?></a>
			</li>
		<?php endif; ?>
	</ul>

	<p>
		<?php esc_html_e( 'Thunderbird, Thunderbird for Android and Outlook can usually find these settings themselves from your email address. Apple Mail, the Gmail app and Samsung Email cannot, so enter the settings above by hand. If an app does not find the settings, enter them by hand too.', 'beltoft-services' ); ?>
	</p>

	<nav aria-label="<?php esc_attr_e( 'Mail apps', 'beltoft-services' ); ?>">
		<p><?php esc_html_e( 'Jump to your app:', 'beltoft-services' ); ?></p>
		<ul class="beltoft-services-mailsetup__jump">
			<?php foreach ( $beltoft_services_clients as $beltoft_services_id => $beltoft_services_label ) : ?>
				<li><a href="#bsms-<?php echo esc_attr( $beltoft_services_id ); ?>"><?php echo esc_html( $beltoft_services_label ); ?></a></li>
			<?php endforeach; ?>
			<li><a href="#bsms-faq"><?php esc_html_e( 'Questions', 'beltoft-services' ); ?></a></li>
		</ul>
	</nav>

	<section id="bsms-iphone">
		<h2><?php echo esc_html( $beltoft_services_clients['iphone'] ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Open Settings, then Apps, then Mail, then Mail Accounts, and tap Add Account. On iOS 18 and older the path is Settings, Mail, Accounts.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Tap Add Other Account, then Mail Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your name, your email address, the mailbox password and a description, then tap Next.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'If Mail does not find the settings, choose IMAP.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: %s: mail host name */ __( 'Under Incoming Mail Server and Outgoing Mail Server enter %s as the host name, your full email address as the user name and the mailbox password.', 'beltoft-services' ), $beltoft_services_host ), array( 'strong' => array() ) ); ?></li>
			<li><?php esc_html_e( 'Tap Next, then Save. If Mail asks for ports, use the ones in the table above.', 'beltoft-services' ); ?></li>
		</ol>
	</section>

	<section id="bsms-mac">
		<h2><?php echo esc_html( $beltoft_services_clients['mac'] ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Open Mail and choose Mail, then Add Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Choose Other Mail Account at the bottom of the list and click Continue.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your name, your email address and the mailbox password, then click Sign In.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: %s: mail host name */ __( 'When Mail cannot set the account up itself, choose IMAP as the account type and enter %s as both the incoming and the outgoing mail server. Check the user name and password, then click Sign In.', 'beltoft-services' ), $beltoft_services_host ), array( 'strong' => array() ) ); ?></li>
			<li><?php esc_html_e( 'Choose the apps to use the account with, for example Mail only, and click Done.', 'beltoft-services' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'If you picked the wrong provider at first, remove that account in Mail, then add the account again.', 'beltoft-services' ); ?></p>
	</section>

	<section id="bsms-outlook">
		<h2><?php echo esc_html( $beltoft_services_clients['outlook'] ); ?></h2>

		<h3><?php esc_html_e( 'New Outlook for Windows', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Open Settings, then Accounts, then Email accounts, and choose Add account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and choose Continue.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'If Outlook does not find the settings, choose the option for another or IMAP account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter the mailbox password and, when asked, the servers and ports from the table above.', 'beltoft-services' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Classic Outlook for Windows', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Choose File, then Add Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address, open Advanced options, tick Let me set up my account manually and choose Connect.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Choose IMAP.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: IMAP port, 3: SMTP port */ __( 'Enter %1$s with port %2$d for incoming mail and %1$s with port %3$d for outgoing mail, both with SSL/TLS. Then enter the mailbox password and choose Connect.', 'beltoft-services' ), $beltoft_services_host, (int) $imap, (int) $smtp[0] ), array( 'strong' => array() ) ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Outlook for Mac', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Open Outlook, then Settings, then Accounts, and choose Add Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and choose Continue.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'If Outlook asks for the account type, choose IMAP and enter the servers and ports from the table above, with the mailbox password.', 'beltoft-services' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Outlook on iPhone and Android', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Tap your picture at the top left, then the settings icon, then Add Email Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and tap Continue.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'If Outlook does not find the account, choose the option for another account at the top right and then IMAP.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: IMAP port, 3: SMTP port */ __( 'Enter your user name and password. If the connection fails, open Advanced Settings. Outlook wants the port after the host name, separated by a colon: %1$s:%2$d for incoming mail and %1$s:%3$d for outgoing mail.', 'beltoft-services' ), $beltoft_services_host, (int) $imap, (int) $smtp[0] ), array( 'strong' => array() ) ); ?></li>
		</ol>

		<p><?php esc_html_e( 'Windows Mail and Calendar, the app that came with Windows 10 and 11, no longer works. Use Outlook or Thunderbird instead.', 'beltoft-services' ); ?></p>
	</section>

	<section id="bsms-thunderbird">
		<h2><?php echo esc_html( $beltoft_services_clients['thunderbird'] ); ?></h2>

		<h3><?php esc_html_e( 'Thunderbird on a computer', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Open the menu and choose Add Account, then Email. In older versions choose Account Settings, then Account Actions, then Add Mail Account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your name, your email address and the mailbox password, then choose Continue.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Thunderbird looks up the settings. Choose IMAP and then Done.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: %s: mail host name */ __( 'If it cannot find them, choose Configure manually and enter %s for both servers, with the ports from the table above, SSL/TLS, the authentication Normal password and your full email address as the user name.', 'beltoft-services' ), $beltoft_services_host ), array( 'strong' => array() ) ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Thunderbird for Android', 'beltoft-services' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Open the app menu, choose Settings and then Add account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and the mailbox password and tap Next.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'If the app does not find the settings, continue to the manual setup and choose IMAP.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: %s: mail host name */ __( 'Enter %s as the incoming and the outgoing server, SSL/TLS for both, the ports from the table above, the authentication Password and your full email address as the user name.', 'beltoft-services' ), $beltoft_services_host ), array( 'strong' => array() ) ); ?></li>
		</ol>
	</section>

	<section id="bsms-gmail">
		<h2><?php echo esc_html( $beltoft_services_clients['gmail'] ); ?></h2>
		<p><?php esc_html_e( 'The Gmail app on Android, iPhone and iPad can read and send mail from another address over IMAP.', 'beltoft-services' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Tap your picture at the top right and choose Add another account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Choose Other. On some versions the choice is called Other (IMAP) or Personal (IMAP).', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and tap Next. Choose IMAP if asked.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: IMAP port */ __( 'Enter the mailbox password. For incoming mail enter %1$s with port %2$d and SSL/TLS.', 'beltoft-services' ), $beltoft_services_host, (int) $imap ), array( 'strong' => array() ) ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: SMTP port */ __( 'For outgoing mail enter %1$s with port %2$d and SSL/TLS, and turn on Require sign-in with your full email address and the same password.', 'beltoft-services' ), $beltoft_services_host, (int) $smtp[0] ), array( 'strong' => array() ) ); ?></li>
			<li><?php esc_html_e( 'Choose your sync options and finish.', 'beltoft-services' ); ?></li>
		</ol>
	</section>

	<section id="bsms-samsung">
		<h2><?php echo esc_html( $beltoft_services_clients['samsung'] ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Open Samsung Email. The first time it asks you to add an account. Later, open the menu, then Settings, then Add account.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Enter your email address and the mailbox password, then choose Manual setup.', 'beltoft-services' ); ?></li>
			<li><?php esc_html_e( 'Choose IMAP account.', 'beltoft-services' ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: IMAP port */ __( 'For incoming mail enter %1$s as the IMAP server, SSL or SSL/TLS as the security type and port %2$d. The user name is your full email address.', 'beltoft-services' ), $beltoft_services_host, (int) $imap ), array( 'strong' => array() ) ); ?></li>
			<li><?php echo wp_kses( sprintf( /* translators: 1: mail host name, 2: SMTP port */ __( 'For outgoing mail enter %1$s as the SMTP server, SSL or SSL/TLS and port %2$d, and turn on Require sign-in.', 'beltoft-services' ), $beltoft_services_host, (int) $smtp[0] ), array( 'strong' => array() ) ); ?></li>
			<li><?php esc_html_e( 'Choose Sign in.', 'beltoft-services' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Menu names can differ a little between Samsung devices and versions.', 'beltoft-services' ); ?></p>
	</section>

	<section id="bsms-faq">
		<h2><?php esc_html_e( 'Questions', 'beltoft-services' ); ?></h2>

		<h3><?php esc_html_e( 'IMAP or POP3?', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Use IMAP. Your mail stays on the server and every device shows the same mailbox. POP3 downloads mail to one device and is only for a single computer that keeps its own copy.', 'beltoft-services' ); ?></p>

		<h3><?php esc_html_e( 'Which ports should I use?', 'beltoft-services' ); ?></h3>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: IMAP port, 2: SMTP SSL port, 3: SMTP STARTTLS port, 4: POP3 port */
					__( 'IMAP %1$d with SSL/TLS and SMTP %2$d with SSL/TLS. If an app only offers STARTTLS for outgoing mail, use port %3$d. POP3 uses port %4$d with SSL/TLS.', 'beltoft-services' ),
					(int) $imap,
					(int) $smtp[0],
					(int) $smtp[1],
					(int) $pop
				)
			);
			?>
		</p>

		<h3><?php esc_html_e( 'My app says the certificate is not trusted', 'beltoft-services' ); ?></h3>
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: mail host name */
					__( 'Use %s as the server name, not mail.your-domain. The certificate is made for that name only. Do not accept a certificate for any other name.', 'beltoft-services' ),
					$beltoft_services_host
				),
				array( 'strong' => array() )
			);
			?>
		</p>

		<h3><?php esc_html_e( 'Can I read this mailbox in Gmail on the web?', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Not by fetching it. From 2026 Gmail on the web no longer lets new users fetch mail from other accounts. Use the Gmail app, which still works with other addresses over IMAP, or another mail app.', 'beltoft-services' ); ?></p>

		<h3><?php esc_html_e( 'Can I use several devices?', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Yes. Add the mailbox to each device with IMAP. They all use the same user name and password and show the same mail.', 'beltoft-services' ); ?></p>

		<h3><?php esc_html_e( 'Is there webmail?', 'beltoft-services' ); ?></h3>
		<p>
			<?php if ( '' !== $webmail ) : ?>
				<?php esc_html_e( 'Yes, you can read and send mail in your browser:', 'beltoft-services' ); ?>
				<a href="<?php echo esc_url( $webmail ); ?>" rel="noopener noreferrer"><?php echo esc_html( $webmail ); ?></a>
			<?php else : ?>
				<?php esc_html_e( 'Use any of the apps above.', 'beltoft-services' ); ?>
			<?php endif; ?>
		</p>

		<h3><?php esc_html_e( 'I lost the password', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Set a new password for the mailbox in your account. We cannot send passwords by email or read the old one.', 'beltoft-services' ); ?></p>
	</section>
</div>
