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
 * @var array<string, array<string, mixed>>     $groups   Operations grouped by resource: id, title, desc, ops (each with id, short, tools, curl, sample).
 * @var array<string, string>                   $levels   Levels of a connected app: key => label.
 * @var array<int, string>                      $errors   Status => meaning.
 * @var array{on: bool, url: string, tools: array<int, array{name: string, description: string, level: string}>} $mcp Use with Claude (MCP).
 */

defined( 'ABSPATH' ) || exit;

$beltoft_services_cls = 'beltoft-services-apiref__';

require BELTOFT_SERVICES_PATH . 'templates/parts/api-reference/helpers.php';

// A navy code panel with a Copy button. $label names the panel, $what says what is copied.
$beltoft_services_panel = static function ( string $label, string $code, string $what ): void {
	?>
	<div class="beltoft-services-apiref__panel">
		<div class="beltoft-services-apiref__panelbar">
			<span><?php echo esc_html( $label ); ?></span>
			<button type="button" class="beltoft-services-apiref__copy" data-apiref-copy data-copied="<?php esc_attr_e( 'Copied', 'beltoft-services' ); ?>" aria-label="<?php echo esc_attr( $what ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
		</div>
		<pre tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>"><code><?php echo esc_html( $code ); ?></code></pre>
	</div>
	<?php
};
?>
<div class="beltoft-services-apiref" data-apiref>
	<nav class="beltoft-services-apiref__nav" aria-label="<?php esc_attr_e( 'API reference sections', 'beltoft-services' ); ?>">
		<ul>
			<li class="beltoft-services-apiref__navgroup"><a href="#api-authentication"><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></a></li>
			<?php foreach ( $groups as $beltoft_services_group ) : ?>
				<li class="beltoft-services-apiref__navgroup">
					<a href="#<?php echo esc_attr( $beltoft_services_group['id'] ); ?>"><?php echo esc_html( $beltoft_services_group['title'] ); ?></a>
					<ul>
						<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
							<li><a href="#<?php echo esc_attr( $beltoft_services_op['id'] ); ?>" title="<?php echo esc_attr( $beltoft_services_op['method'] . ' ' . $beltoft_services_op['path'] ); ?>"><span class="beltoft-services-apiref__navmethod beltoft-services-apiref__navmethod--<?php echo esc_attr( $beltoft_services_methods[ $beltoft_services_op['method'] ] ?? 'get' ); ?>"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span> <span class="beltoft-services-apiref__navpath"><?php echo esc_html( $beltoft_services_op['short'] ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
			<li class="beltoft-services-apiref__navgroup"><a href="#api-errors"><?php esc_html_e( 'Errors and limits', 'beltoft-services' ); ?></a></li>
			<?php if ( $mcp['on'] ) : ?>
				<li class="beltoft-services-apiref__navgroup"><a href="#use-with-claude"><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></a></li>
			<?php endif; ?>
		</ul>
	</nav>

	<div class="beltoft-services-apiref__main">
	<section id="api-authentication" data-apiref-section>
		<h2><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></h2>
		<p class="beltoft-services-apiref__intro"><?php esc_html_e( 'The API works on your own services only. All requests use HTTPS and all replies are JSON.', 'beltoft-services' ); ?></p>
		<div class="beltoft-services-apiref__split">
			<div>
				<h3><?php esc_html_e( 'Personal token', 'beltoft-services' ); ?></h3>
				<p><?php esc_html_e( 'Create a token in My Account, Security. Send your username and the token as HTTP Basic authentication. A token has one access level: read only, or read and actions. A token is shown once and can be revoked at any time.', 'beltoft-services' ); ?></p>
				<h3><?php esc_html_e( 'OAuth for apps', 'beltoft-services' ); ?></h3>
				<p><?php esc_html_e( 'An app that acts for a customer uses OAuth 2.1 with the authorization code flow and PKCE (S256). Apps register themselves (RFC 7591). The customer chooses the access level when connecting. The addresses are in the OpenAPI document.', 'beltoft-services' ); ?></p>
				<p><a href="<?php echo esc_url( $spec_url ); ?>"><?php esc_html_e( 'OpenAPI 3.1 document (openapi.json)', 'beltoft-services' ); ?></a></p>
			</div>
			<div class="beltoft-services-apiref__codes">
				<?php
				$beltoft_services_panel( __( 'Base address', 'beltoft-services' ), $base, __( 'Copy the base address', 'beltoft-services' ) );
				$beltoft_services_panel( __( 'Request with a personal token', 'beltoft-services' ), 'curl -u "USERNAME:TOKEN" ' . $base . '/resources', __( 'Copy the curl example with a personal token', 'beltoft-services' ) );
				$beltoft_services_panel( __( 'Request with an OAuth access token', 'beltoft-services' ), 'curl -H "Authorization: Bearer ACCESS_TOKEN" ' . $base . '/resources', __( 'Copy the curl example with an OAuth access token', 'beltoft-services' ) );
				?>
			</div>
		</div>
	</section>

	<?php foreach ( $groups as $beltoft_services_group ) : ?>
		<section id="<?php echo esc_attr( $beltoft_services_group['id'] ); ?>" class="beltoft-services-apiref__group" data-apiref-section>
			<h2><?php echo esc_html( $beltoft_services_group['title'] ); ?></h2>
			<?php if ( $beltoft_services_group['desc'] ) : ?>
				<p class="beltoft-services-apiref__lead"><?php echo esc_html( $beltoft_services_group['desc'] ); ?></p>
			<?php endif; ?>
			<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
				<article id="<?php echo esc_attr( $beltoft_services_op['id'] ); ?>" class="beltoft-services-apiref__op" data-apiref-section data-path="<?php echo esc_attr( $beltoft_services_op['path'] ); ?>">
					<header class="beltoft-services-apiref__head">
						<h3>
							<span class="beltoft-services-apiref__method beltoft-services-apiref__method--<?php echo esc_attr( $beltoft_services_methods[ $beltoft_services_op['method'] ] ?? 'get' ); ?>"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span>
							<code class="beltoft-services-apiref__path"><?php echo $beltoft_services_path( $beltoft_services_op['path'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the helper escapes every part. ?></code>
						</h3>
						<p class="beltoft-services-apiref__summary"><?php echo esc_html( $beltoft_services_op['summary'] ); ?></p>
						<?php if ( $beltoft_services_op['tools'] ) : ?>
							<p class="beltoft-services-apiref__meta beltoft-services-apiref__tools">
								<?php
								esc_html_e( 'With Claude:', 'beltoft-services' );
								echo ' ';

								foreach ( $beltoft_services_op['tools'] as $beltoft_services_i => $beltoft_services_tool_name ) {
									echo $beltoft_services_i ? ', ' : '';
									echo '<span class="beltoft-services-apiref__tool">' . esc_html( $beltoft_services_tool_name ) . '</span>';
								}
								?>
							</p>
						<?php endif; ?>
					</header>
					<div class="beltoft-services-apiref__text">
						<p class="beltoft-services-apiref__meta">
							<?php
							/* translators: 1: access level, 2: HTTP status of a successful reply. */
							echo esc_html( sprintf( __( 'Access: %1$s. Success status: %2$d.', 'beltoft-services' ), $beltoft_services_level( $beltoft_services_op['scope'] ), $beltoft_services_op['status'] ) );
							?>
						</p>
<?php require BELTOFT_SERVICES_PATH . 'templates/parts/api-reference/op-tables.php'; ?>
					</div>
					<div class="beltoft-services-apiref__codes">
						<?php
						/* translators: 1: HTTP method, 2: path. */
						$beltoft_services_what = sprintf( __( 'Copy the curl example for %1$s %2$s', 'beltoft-services' ), $beltoft_services_op['method'], $beltoft_services_op['path'] );
						$beltoft_services_panel( __( 'curl', 'beltoft-services' ), $beltoft_services_op['curl'], $beltoft_services_what );

						if ( $beltoft_services_op['sample'] ) {
							/* translators: %d: HTTP status of a successful reply. */
							$beltoft_services_label = sprintf( __( 'Example reply, %d', 'beltoft-services' ), $beltoft_services_op['status'] );
							/* translators: 1: HTTP method, 2: path. */
							$beltoft_services_what = sprintf( __( 'Copy the example reply of %1$s %2$s', 'beltoft-services' ), $beltoft_services_op['method'], $beltoft_services_op['path'] );
							$beltoft_services_panel( $beltoft_services_label, $beltoft_services_op['sample'], $beltoft_services_what );
						}
						?>
					</div>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>
	<p class="beltoft-services-apiref__note"><?php esc_html_e( 'Passwords, console access, transfer codes and actions that destroy something, such as reinstall, restore and delete, are not part of the API. They are only available in the dashboard. Every change made with a token is written to the history of the service.', 'beltoft-services' ); ?></p>

	<section id="api-errors" data-apiref-section>
		<h2><?php esc_html_e( 'Errors and limits', 'beltoft-services' ); ?></h2>
		<div class="beltoft-services-apiref__split">
			<div>
				<p><?php esc_html_e( 'An error has an HTTP status and a JSON body with a code and a message, and the status again inside data.', 'beltoft-services' ); ?></p>
<?php require BELTOFT_SERVICES_PATH . 'templates/parts/api-reference/errors-table.php'; ?>
<?php require BELTOFT_SERVICES_PATH . 'templates/parts/api-reference/rate-note.php'; ?>
			</div>
			<div class="beltoft-services-apiref__codes">
				<?php $beltoft_services_panel( __( 'Error reply', 'beltoft-services' ), '{"code":"rest_forbidden","message":"...","data":{"status":403}}', __( 'Copy the example error reply', 'beltoft-services' ) ); ?>
			</div>
		</div>
	</section>

	<?php if ( $mcp['on'] ) : ?>
		<section id="use-with-claude" data-apiref-section>
			<h2><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></h2>
			<div class="beltoft-services-apiref__split">
				<div>
					<p><?php esc_html_e( 'Claude can work with your services through an MCP connector. You choose what Claude may do when you connect, and you can disconnect it in My Account, Security at any time.', 'beltoft-services' ); ?></p>
					<h3><?php esc_html_e( 'Set up', 'beltoft-services' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'In Claude, open Settings, then Connectors, then Add custom connector.', 'beltoft-services' ); ?></li>
						<li><?php esc_html_e( 'Paste the connector address.', 'beltoft-services' ); ?></li>
						<li><?php esc_html_e( 'Claude opens this site. Sign in if you are asked to.', 'beltoft-services' ); ?></li>
						<li><?php esc_html_e( 'Choose an access level and confirm.', 'beltoft-services' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Read and Actions access never show passwords and cannot restore, roll back, reinstall, delete or cancel. Full access can do those things, order a server or a database and show a password once, but only after Claude has shown you a summary and you have said yes in the chat. A password shown this way stays in the conversation. Domain transfers, transfer codes and the console are never possible through Claude, only in your dashboard. Every request Claude makes is written to the history of the service.', 'beltoft-services' ); ?></p>
				</div>
				<div class="beltoft-services-apiref__codes">
					<?php $beltoft_services_panel( __( 'Connector address', 'beltoft-services' ), $mcp['url'], __( 'Copy the connector address', 'beltoft-services' ) ); ?>
				</div>
			</div>
			<h3><?php esc_html_e( 'Tools', 'beltoft-services' ); ?></h3>
			<?php foreach ( $levels as $beltoft_services_key => $beltoft_services_label ) : ?>
				<?php
				$beltoft_services_rows = array_filter( $mcp['tools'], static fn( $tool ) => $tool['level'] === $beltoft_services_key );

				if ( ! $beltoft_services_rows ) {
					continue;
				}
				?>
<?php require BELTOFT_SERVICES_PATH . 'templates/parts/api-reference/tools-table.php'; ?>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>
	</div>
</div>
