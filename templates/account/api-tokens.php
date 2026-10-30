<?php
/**
 * Account details, Security: personal API tokens and the API guide.
 *
 * Override by copying to yourtheme/beltoft-services/account/api-tokens.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>>                $tokens   The customer's tokens.
 * @var array<string, string>                           $scopes   Scope => label.
 * @var array{ok: bool, message: string, password: string}|null $result What the last post did.
 * @var string                                          $nonce    Nonce of the forms.
 * @var string                                          $field    Name of the action field.
 * @var string                                          $base     Base address of the API, with a trailing slash.
 * @var string                                          $username The customer's username.
 * @var int                                             $max      Tokens allowed.
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_fmt = (string) get_option( 'date_format' );
?>
<section class="beltoft-services-api" id="api-tokens">
	<h3><?php esc_html_e( 'Security', 'beltoft-services' ); ?></h3>
	<h4><?php esc_html_e( 'API tokens', 'beltoft-services' ); ?></h4>
	<p><?php esc_html_e( 'A token lets a script or a tool use your account over the API without your password. Anyone with a token can do what its access allows, so keep it private. It is shown once, and you can revoke it at any time.', 'beltoft-services' ); ?></p>

	<?php if ( is_array( $result ) && '' !== $result['message'] ) : ?>
		<p class="beltoft-services-api__notice<?php echo $result['ok'] ? '' : ' is-error'; ?>" role="status"><?php echo esc_html( $result['message'] ); ?></p>
	<?php endif; ?>
	<?php if ( is_array( $result ) && '' !== $result['password'] ) : ?>
		<p><code class="beltoft-services-api__secret"><?php echo esc_html( $result['password'] ); ?></code></p>
	<?php endif; ?>

	<?php if ( $tokens ) : ?>
		<table class="beltoft-services-api__tokens">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
					<th><?php esc_html_e( 'Access', 'beltoft-services' ); ?></th>
					<th><?php esc_html_e( 'Created', 'beltoft-services' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'beltoft-services' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $tokens as $beltoft_services_token ) : ?>
				<tr>
					<td><?php echo esc_html( $beltoft_services_token['name'] ); ?></td>
					<td><?php echo esc_html( $scopes[ $beltoft_services_token['scope'] ] ?? '' ); ?></td>
					<td><?php echo esc_html( wp_date( $beltoft_services_fmt, $beltoft_services_token['created'] ) ); ?></td>
					<td><?php echo esc_html( $beltoft_services_token['last_used'] ? wp_date( $beltoft_services_fmt, $beltoft_services_token['last_used'] ) : __( 'Never', 'beltoft-services' ) ); ?></td>
					<td>
						<form method="post">
							<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="revoke">
							<input type="hidden" name="token_uuid" value="<?php echo esc_attr( $beltoft_services_token['uuid'] ); ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Revoke', 'beltoft-services' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'You have no tokens.', 'beltoft-services' ); ?></p>
	<?php endif; ?>

	<?php if ( count( $tokens ) < $max ) : ?>
		<form method="post" class="beltoft-services-api__new">
			<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="create">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<label for="beltoft-token-name"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></label>
				<input type="text" id="beltoft-token-name" name="token_name" maxlength="100" required>
			</p>
			<p>
				<label for="beltoft-token-scope"><?php esc_html_e( 'Access', 'beltoft-services' ); ?></label>
				<select id="beltoft-token-scope" name="token_scope">
					<?php foreach ( $scopes as $beltoft_services_key => $beltoft_services_label ) : ?>
						<option value="<?php echo esc_attr( $beltoft_services_key ); ?>"><?php echo esc_html( $beltoft_services_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Create token', 'beltoft-services' ); ?></button></p>
		</form>
	<?php endif; ?>

	<details class="beltoft-services-api__docs">
		<summary><?php esc_html_e( 'How to use the API', 'beltoft-services' ); ?></summary>
		<p><?php esc_html_e( 'Send your username and token as HTTP Basic authentication over HTTPS. All replies are JSON. The examples use 123 for the id of a service; the ids are in the address of each service page.', 'beltoft-services' ); ?></p>
		<pre><code><?php echo esc_html( 'curl -u "' . $username . ':YOUR TOKEN" ' . $base . 'resources/123' ); ?></code></pre>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Request', 'beltoft-services' ); ?></th>
					<th><?php esc_html_e( 'What it does', 'beltoft-services' ); ?></th>
					<th><?php esc_html_e( 'Access', 'beltoft-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>GET resources/123</code></td><td><?php esc_html_e( 'Status of a service.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Read', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>GET resources/123/metrics</code></td><td><?php esc_html_e( 'Usage figures of a server.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Read', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>GET usage</code></td><td><?php esc_html_e( 'Current usage of your servers.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Read', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>GET resources/123/dns-records</code></td><td><?php esc_html_e( 'The DNS records of a domain.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Read', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>GET domains/check</code></td><td><?php esc_html_e( 'Whether a domain name is available.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Read', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>POST resources/123/actions/reboot</code></td><td><?php esc_html_e( 'Asks for an action on a service, for example start, shutdown or reboot. The reply is 202 and the work runs in the background.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Actions', 'beltoft-services' ); ?></td></tr>
				<tr><td><code>PUT or DELETE resources/123/dns-records/A/www</code></td><td><?php esc_html_e( 'Sets or removes one DNS record set.', 'beltoft-services' ); ?></td><td><?php esc_html_e( 'Actions', 'beltoft-services' ); ?></td></tr>
			</tbody>
		</table>
		<pre><code><?php echo esc_html( 'curl -u "' . $username . ':YOUR TOKEN" -X POST ' . $base . 'resources/123/actions/reboot' ); ?></code></pre>
		<p><?php esc_html_e( 'Every change made with a token is written to the history of the service. Passwords, console access and actions that destroy something, such as reinstall, restore and delete, are only available in your dashboard. Requests are limited per token; a reply of 429 means wait a minute.', 'beltoft-services' ); ?></p>
	</details>
</section>
