<?php
/**
 * The public API reference, printed by the shortcode [beltoft_services_api_reference].
 *
 * Override by copying to yourtheme/beltoft-services/api-reference.php.
 *
 * @package BeltoftServices
 *
 * @var string                                  $base     Base address of the API, no trailing slash.
 * @var string                                  $spec_url Address of openapi.json.
 * @var int                                     $rate     Requests a token may make per minute (0 means no limit).
 * @var array<string, string>                   $scopes   Level => label.
 * @var array<int, array<string, mixed>>        $ops      Operations: method, path, summary, scope, params, reply, status, errors.
 * @var array<int, string>                      $errors   Status => meaning.
 * @var array{on: bool, url: string, tools: array<int, array{name: string, description: string, level: string}>} $mcp Use with Claude (MCP).
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_level = static function ( string $level ) use ( $scopes ): string {
	return 'none' === $level ? __( 'None, public', 'beltoft-services' ) : (string) ( $scopes[ $level ] ?? $level );
};
?>
<div class="beltoft-services-apiref">
	<section id="api-authentication">
		<h2><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></h2>
		<p><?php esc_html_e( 'The API works on your own services only. All requests use HTTPS and all replies are JSON.', 'beltoft-services' ); ?></p>
		<h3><?php esc_html_e( 'Personal token', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'Create a token in My Account, Security. Send your username and the token as HTTP Basic authentication. A token has one access level: read only, or read and actions. A token is shown once and can be revoked at any time.', 'beltoft-services' ); ?></p>
		<pre class="beltoft-services-apiref__code"><code><?php echo esc_html( 'curl -u "USERNAME:TOKEN" ' . $base . '/resources' ); ?></code></pre>
		<h3><?php esc_html_e( 'OAuth for apps', 'beltoft-services' ); ?></h3>
		<p><?php esc_html_e( 'An app that acts for a customer uses OAuth 2.1 with the authorization code flow and PKCE (S256). Apps register themselves (RFC 7591). The customer chooses the access level when connecting. The addresses are in the OpenAPI document.', 'beltoft-services' ); ?></p>
		<h3><?php esc_html_e( 'Base address', 'beltoft-services' ); ?></h3>
		<pre class="beltoft-services-apiref__code"><code><?php echo esc_html( $base ); ?></code></pre>
		<p>
			<a href="<?php echo esc_url( $spec_url ); ?>"><?php esc_html_e( 'OpenAPI 3.1 document (openapi.json)', 'beltoft-services' ); ?></a>
		</p>
	</section>

	<section id="api-requests">
		<h2><?php esc_html_e( 'Requests', 'beltoft-services' ); ?></h2>
		<p><?php esc_html_e( 'The id of a service is in the address of its page in My Account. The access level is the lowest level that may make the request.', 'beltoft-services' ); ?></p>
		<?php foreach ( $ops as $beltoft_services_op ) : ?>
			<article class="beltoft-services-apiref__op">
				<h3><span class="beltoft-services-apiref__method"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span> <code><?php echo esc_html( $beltoft_services_op['path'] ); ?></code></h3>
				<p><?php echo esc_html( $beltoft_services_op['summary'] ); ?></p>
				<p class="beltoft-services-apiref__meta">
					<?php
					/* translators: 1: access level, 2: HTTP status of a successful reply. */
					echo esc_html( sprintf( __( 'Access: %1$s. Success status: %2$d.', 'beltoft-services' ), $beltoft_services_level( $beltoft_services_op['scope'] ), $beltoft_services_op['status'] ) );
					?>
				</p>
				<?php if ( $beltoft_services_op['params'] ) : ?>
					<div class="beltoft-services-apiref__scroll"><table>
						<caption><?php esc_html_e( 'Parameters', 'beltoft-services' ); ?></caption>
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'In', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Required', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $beltoft_services_op['params'] as $beltoft_services_param ) : ?>
							<tr>
								<td><code><?php echo esc_html( $beltoft_services_param['name'] ); ?></code></td>
								<td><?php echo esc_html( $beltoft_services_param['in'] ); ?></td>
								<td><?php echo esc_html( $beltoft_services_param['type'] ); ?></td>
								<td><?php echo esc_html( $beltoft_services_param['required'] ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ) ); ?></td>
								<td><?php echo esc_html( $beltoft_services_param['desc'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
				<?php if ( $beltoft_services_op['reply'] ) : ?>
					<div class="beltoft-services-apiref__scroll"><table>
						<caption><?php esc_html_e( 'Reply fields', 'beltoft-services' ); ?></caption>
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Field', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $beltoft_services_op['reply'] as $beltoft_services_field ) : ?>
							<tr>
								<td><code><?php echo esc_html( $beltoft_services_field['name'] ); ?></code></td>
								<td><?php echo esc_html( $beltoft_services_field['type'] ); ?></td>
								<td><?php echo esc_html( $beltoft_services_field['desc'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
				<?php if ( $beltoft_services_op['errors'] ) : ?>
					<p class="beltoft-services-apiref__meta"><?php echo esc_html( sprintf( /* translators: %s: list of HTTP statuses. */ __( 'Error statuses: %s.', 'beltoft-services' ), implode( ', ', $beltoft_services_op['errors'] ) ) ); ?></p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
		<p><?php esc_html_e( 'Passwords, console access, transfer codes and actions that destroy something, such as reinstall, restore and delete, are not part of the API. They are only available in the dashboard. Every change made with a token is written to the history of the service.', 'beltoft-services' ); ?></p>
	</section>

	<section id="api-errors">
		<h2><?php esc_html_e( 'Errors and limits', 'beltoft-services' ); ?></h2>
		<p><?php esc_html_e( 'An error has an HTTP status and a JSON body with a code and a message, and the status again inside data.', 'beltoft-services' ); ?></p>
		<pre class="beltoft-services-apiref__code"><code><?php echo esc_html( '{"code":"rest_forbidden","message":"...","data":{"status":403}}' ); ?></code></pre>
		<div class="beltoft-services-apiref__scroll"><table>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Status', 'beltoft-services' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'beltoft-services' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $errors as $beltoft_services_status => $beltoft_services_meaning ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $beltoft_services_status ); ?></code></td>
					<td><?php echo esc_html( $beltoft_services_meaning ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<p>
			<?php
			echo esc_html(
				$rate > 0
					/* translators: %d: requests per minute. */
					? sprintf( __( 'Rate limit: %d requests a minute for each token. Some requests have lower limits of their own. A reply of 429 means wait a minute and try again.', 'beltoft-services' ), $rate )
					: __( 'Some requests have rate limits of their own. A reply of 429 means wait a minute and try again.', 'beltoft-services' )
			);
			?>
		</p>
	</section>

	<?php if ( $mcp['on'] ) : ?>
		<section id="use-with-claude">
			<h2><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></h2>
			<p><?php esc_html_e( 'Claude can work with your services through an MCP connector. You choose what Claude may do when you connect, and you can disconnect it in My Account, Security at any time.', 'beltoft-services' ); ?></p>
			<h3><?php esc_html_e( 'Connector address', 'beltoft-services' ); ?></h3>
			<pre class="beltoft-services-apiref__code"><code><?php echo esc_html( $mcp['url'] ); ?></code></pre>
			<h3><?php esc_html_e( 'Set up', 'beltoft-services' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'In Claude, open Settings, then Connectors, then Add custom connector.', 'beltoft-services' ); ?></li>
				<li><?php esc_html_e( 'Paste the connector address above.', 'beltoft-services' ); ?></li>
				<li><?php esc_html_e( 'Claude opens this site. Sign in if you are asked to.', 'beltoft-services' ); ?></li>
				<li><?php esc_html_e( 'Choose an access level and confirm.', 'beltoft-services' ); ?></li>
			</ol>
			<h3><?php esc_html_e( 'Access levels', 'beltoft-services' ); ?></h3>
			<ul>
				<?php foreach ( $scopes as $beltoft_services_key => $beltoft_services_label ) : ?>
					<li><?php echo esc_html( $beltoft_services_label ); ?> <code><?php echo esc_html( $beltoft_services_key ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<h3><?php esc_html_e( 'Tools', 'beltoft-services' ); ?></h3>
			<div class="beltoft-services-apiref__scroll"><table>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Tool', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it does', 'beltoft-services' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Access', 'beltoft-services' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $mcp['tools'] as $beltoft_services_tool ) : ?>
					<tr>
						<td><code><?php echo esc_html( $beltoft_services_tool['name'] ); ?></code></td>
						<td><?php echo esc_html( $beltoft_services_tool['description'] ); ?></td>
						<td><?php echo esc_html( $beltoft_services_level( $beltoft_services_tool['level'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p><?php esc_html_e( 'Claude never sees passwords. Domain transfers are never possible through Claude. Restoring, reinstalling and deleting are only possible in your dashboard. Every request Claude makes is written to the history of the service.', 'beltoft-services' ); ?></p>
		</section>
	<?php endif; ?>
</div>
