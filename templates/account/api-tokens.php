<?php
/**
 * My Account, Security: personal API tokens and the API guide.
 *
 * Override by copying to yourtheme/beltoft-services/account/api-tokens.php.
 *
 * @package BeltoftServices
 *
 * @var array<int, array<string, mixed>>                $tokens   The customer's tokens.
 * @var array<string, string>                           $scopes   Scope => label.
 * @var array<int, array{at: int, text: string}>|null      $audit    The last actions of connected apps on things that have no service history.
 * @var array<string, string>                           $levels   Level => label of a connected app (read, actions, full).
 * @var array{ok: bool, message: string, password: string}|null $result What the last post did.
 * @var string                                          $nonce    Nonce of the forms.
 * @var string                                          $field    Name of the action field.
 * @var string                                          $base     Base address of the API, with a trailing slash.
 * @var string                                          $username The customer's username.
 * @var int                                             $max      Tokens allowed.
 * @var string                                          $docs_url Address of the public API documentation page, empty when not set.
 * @var array{on: bool, allowed: bool, url: string, apps: array<int, array<string, mixed>>} $mcp Use with Claude (MCP).
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_fmt = (string) get_option( 'date_format' );
?>
<section class="beltoft-services-api" id="api-tokens">
	<h3><?php esc_html_e( 'API tokens', 'beltoft-services' ); ?></h3>
	<p><?php esc_html_e( 'A token lets a script or a tool use your account over the API without your password. Anyone with a token can do what its access allows, so keep it private. It is shown once, and you can revoke it at any time.', 'beltoft-services' ); ?></p>

	<?php if ( is_array( $result ) && '' !== $result['message'] ) : ?>
		<p class="beltoft-services-api__notice<?php echo $result['ok'] ? '' : ' is-error'; ?>" role="status"><?php echo esc_html( $result['message'] ); ?></p>
	<?php endif; ?>
	<?php if ( is_array( $result ) && '' !== $result['password'] ) : ?>
		<div class="beltoft-services-api__block">
			<code class="beltoft-services-api__secret"><?php echo esc_html( $result['password'] ); ?></code>
			<button type="button" class="button woocommerce-button beltoft-services-vm__copy" data-api-copy="<?php echo esc_attr( $result['password'] ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $tokens ) : ?>
		<table class="beltoft-services-api__tokens shop_table shop_table_responsive">
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
					<td data-title="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_token['name'] ); ?></td>
					<td data-title="<?php esc_attr_e( 'Access', 'beltoft-services' ); ?>"><?php echo esc_html( $scopes[ $beltoft_services_token['scope'] ] ?? '' ); ?></td>
					<td data-title="<?php esc_attr_e( 'Created', 'beltoft-services' ); ?>"><?php echo esc_html( wp_date( $beltoft_services_fmt, $beltoft_services_token['created'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Last used', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_token['last_used'] ? wp_date( $beltoft_services_fmt, $beltoft_services_token['last_used'] ) : __( 'Never', 'beltoft-services' ) ); ?></td>
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
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide brxe-woocommerce-form-field">
				<label for="beltoft-token-name"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></label>
				<span class="woocommerce-input-wrapper"><input type="text" class="woocommerce-Input input-text" id="beltoft-token-name" name="token_name" maxlength="100" placeholder=" " required></span>
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide brxe-woocommerce-form-field">
				<label for="beltoft-token-scope"><?php esc_html_e( 'Access', 'beltoft-services' ); ?></label>
				<span class="woocommerce-input-wrapper">
					<select id="beltoft-token-scope" name="token_scope">
						<?php foreach ( $scopes as $beltoft_services_key => $beltoft_services_label ) : ?>
							<option value="<?php echo esc_attr( $beltoft_services_key ); ?>"><?php echo esc_html( $beltoft_services_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Create token', 'beltoft-services' ); ?></button></p>
		</form>
	<?php endif; ?>

	<?php if ( '' !== (string) ( $docs_url ?? '' ) ) : ?>
		<p class="beltoft-services-api__docs-link">
			<?php esc_html_e( 'Send your username and the token as HTTP Basic authentication over HTTPS. All replies are JSON.', 'beltoft-services' ); ?>
			<?php esc_html_e( 'Every request, parameter and reply is described in the API documentation.', 'beltoft-services' ); ?>
			<a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Read the API documentation', 'beltoft-services' ); ?></a>
		</p>
	<?php else : ?>
	<details class="beltoft-services-api__docs">
		<summary><?php esc_html_e( 'How to use the API', 'beltoft-services' ); ?></summary>
		<p><?php esc_html_e( 'Send your username and token as HTTP Basic authentication over HTTPS. All replies are JSON. The examples use 123 for the id of a service; the ids are in the address of each service page.', 'beltoft-services' ); ?></p>
		<pre><code><?php echo esc_html( 'curl -u "' . $username . ':YOUR TOKEN" ' . $base . 'resources/123' ); ?></code></pre>
		<div class="beltoft-services-api__scroll"><table>
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
		</table></div>
		<pre><code><?php echo esc_html( 'curl -u "' . $username . ':YOUR TOKEN" -X POST ' . $base . 'resources/123/actions/reboot' ); ?></code></pre>
		<p><?php esc_html_e( 'Every change made with a token is written to the history of the service. Passwords, console access and actions that destroy something, such as reinstall, restore and delete, are only available in your dashboard. Requests are limited per token; a reply of 429 means wait a minute.', 'beltoft-services' ); ?></p>
	</details>
	<?php endif; ?>
	<?php if ( $mcp['on'] ) : ?>
		<h3 id="use-with-claude"><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Connect Claude to your account to ask about your servers, databases, domains and DNS in a chat. You choose what Claude may do when you connect, and you can disconnect it here at any time.', 'beltoft-services' ); ?></p>

		<form method="post" class="beltoft-services-api__switch">
			<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="mcp_switch">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="mcp_allow" value="<?php echo $mcp['allowed'] ? '0' : '1'; ?>">
			<p>
				<?php echo esc_html( $mcp['allowed'] ? __( 'Allow Claude (MCP) access: on.', 'beltoft-services' ) : __( 'Allow Claude (MCP) access: off.', 'beltoft-services' ) ); ?>
				<button type="submit" class="button"><?php echo esc_html( $mcp['allowed'] ? __( 'Turn off', 'beltoft-services' ) : __( 'Turn on', 'beltoft-services' ) ); ?></button>
			</p>
		</form>

		<?php if ( $mcp['apps'] ) : ?>
			<table class="beltoft-services-api__tokens shop_table shop_table_responsive">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connected app', 'beltoft-services' ); ?></th>
						<th><?php esc_html_e( 'Access', 'beltoft-services' ); ?></th>
						<th><?php esc_html_e( 'Connected', 'beltoft-services' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'beltoft-services' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $mcp['apps'] as $beltoft_services_app ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Connected app', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_app['name'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Access', 'beltoft-services' ); ?>"><?php echo esc_html( ( $levels ?? $scopes )[ $beltoft_services_app['scope'] ] ?? '' ); ?><?php if ( ! empty( $beltoft_services_app['stale'] ) ) : ?> <?php esc_html_e( '(works as Read and actions: reconnect to use full access)', 'beltoft-services' ); ?><?php endif; ?></td>
						<td data-title="<?php esc_attr_e( 'Connected', 'beltoft-services' ); ?>"><?php echo esc_html( wp_date( $beltoft_services_fmt, $beltoft_services_app['connected'] ) ); ?></td>
						<td data-title="<?php esc_attr_e( 'Last used', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_app['last_used'] ? wp_date( $beltoft_services_fmt, $beltoft_services_app['last_used'] ) : __( 'Never', 'beltoft-services' ) ); ?></td>
						<td>
							<form method="post">
								<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="disconnect">
								<input type="hidden" name="app_family" value="<?php echo esc_attr( $beltoft_services_app['family'] ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'beltoft-services' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php esc_html_e( 'To change what an app may do, disconnect it and connect it again.', 'beltoft-services' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'No app is connected.', 'beltoft-services' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $audit ) ) : ?>
			<h4><?php esc_html_e( 'Recent actions by connected apps', 'beltoft-services' ); ?></h4>
			<ul class="beltoft-services-api__audit">
			<?php foreach ( $audit as $beltoft_services_line ) : ?>
					<li><?php echo esc_html( wp_date( $beltoft_services_fmt, (int) $beltoft_services_line['at'] ) . ': ' . $beltoft_services_line['text'] ); ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<details class="beltoft-services-api__docs">
			<summary><?php esc_html_e( 'How to connect Claude', 'beltoft-services' ); ?></summary>
			<ol>
				<li><?php esc_html_e( 'In Claude, open Settings, then Connectors, then Add custom connector.', 'beltoft-services' ); ?></li>
				<li><?php esc_html_e( 'Paste this address as the connector URL:', 'beltoft-services' ); ?>
					<pre><code><?php echo esc_html( $mcp['url'] ); ?></code></pre>
				</li>
				<li><?php esc_html_e( 'Claude opens this site. Sign in if you are asked to, then choose what Claude may do: read only, or also run actions such as restart, snapshots and DNS changes.', 'beltoft-services' ); ?></li>
				<li><?php esc_html_e( 'Ask Claude about your services, for example which of your servers is busiest.', 'beltoft-services' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Claude can read your servers, databases, domains and DNS, and with actions allowed restart or stop a server, take a snapshot and change DNS records. It never sees passwords. Restoring, reinstalling and deleting are only possible in your dashboard. Every request Claude makes is written to the history of the service.', 'beltoft-services' ); ?></p>
			<p><?php esc_html_e( 'A personal token from above also works with any tool that speaks MCP over HTTP: send it as HTTP Basic authentication to the same address.', 'beltoft-services' ); ?></p>
		</details>
	<?php endif; ?>
</section>
