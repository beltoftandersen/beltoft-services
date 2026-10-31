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

$beltoft_services_level = static function ( string $level ) use ( $scopes ): string {
	return 'none' === $level ? __( 'None, public', 'beltoft-services' ) : (string) ( $scopes[ $level ] ?? $level );
};

// A path with its {parameters} wrapped, so that they can be coloured.
$beltoft_services_path = static function ( string $path ): string {
	$parts = preg_split( '/(\{\w+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = '';

	foreach ( (array) $parts as $part ) {
		$out .= 1 === preg_match( '/^\{\w+\}$/', (string) $part ) ? '<span class="beltoft-services-apiref__param">' . esc_html( (string) $part ) . '</span>' : esc_html( (string) $part );
	}

	return $out;
};

// A navy code panel with a Copy button. $label names the panel, $what says what is copied.
$beltoft_services_panel = static function ( string $label, string $code, string $what ): void {
	?>
	<div class="beltoft-services-apiref__panel">
		<div class="beltoft-services-apiref__panelbar">
			<span><?php echo esc_html( $label ); ?></span>
			<button type="button" class="beltoft-services-apiref__copy" data-apiref-copy aria-label="<?php echo esc_attr( $what ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
		</div>
		<pre tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>"><code><?php echo esc_html( $code ); ?></code></pre>
	</div>
	<?php
};

$beltoft_services_methods = array(
	'GET'    => 'get',
	'POST'   => 'post',
	'PUT'    => 'put',
	'DELETE' => 'delete',
);
?>
<div class="beltoft-services-apiref" data-apiref>
	<nav class="beltoft-services-apiref__nav" aria-label="<?php esc_attr_e( 'API reference sections', 'beltoft-services' ); ?>">
		<ul>
			<li><a href="#api-authentication"><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></a></li>
			<?php foreach ( $groups as $beltoft_services_group ) : ?>
				<li class="beltoft-services-apiref__navgroup">
					<a href="#<?php echo esc_attr( $beltoft_services_group['id'] ); ?>"><?php echo esc_html( $beltoft_services_group['title'] ); ?></a>
					<ul>
						<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
							<li><a href="#<?php echo esc_attr( $beltoft_services_op['id'] ); ?>"><span class="beltoft-services-apiref__navmethod beltoft-services-apiref__navmethod--<?php echo esc_attr( $beltoft_services_methods[ $beltoft_services_op['method'] ] ?? 'get' ); ?>"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span> <span class="beltoft-services-apiref__navpath"><?php echo esc_html( $beltoft_services_op['short'] ); ?></span></a></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
			<li><a href="#api-errors"><?php esc_html_e( 'Errors and limits', 'beltoft-services' ); ?></a></li>
			<?php if ( $mcp['on'] ) : ?>
				<li><a href="#use-with-claude"><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></a></li>
			<?php endif; ?>
		</ul>
	</nav>

	<div class="beltoft-services-apiref__main">
	<section id="api-authentication" data-apiref-section>
		<h2><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></h2>
		<p><?php esc_html_e( 'The API works on your own services only. All requests use HTTPS and all replies are JSON.', 'beltoft-services' ); ?></p>
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
						<?php if ( $beltoft_services_op['params'] ) : ?>
							<table class="beltoft-services-apiref__table">
								<caption><?php esc_html_e( 'Parameters', 'beltoft-services' ); ?></caption>
								<thead><tr>
									<th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Required', 'beltoft-services' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th>
								</tr></thead>
								<tbody>
								<?php foreach ( $beltoft_services_op['params'] as $beltoft_services_param ) : ?>
									<tr>
										<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><code<?php echo 'path' === $beltoft_services_param['in'] ? ' class="beltoft-services-apiref__param"' : ''; ?>><?php echo esc_html( $beltoft_services_param['name'] ); ?></code></td>
										<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['type'] . ', ' . $beltoft_services_param['in'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Required', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['required'] ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ) ); ?></td>
										<td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['desc'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<?php if ( $beltoft_services_op['reply'] ) : ?>
							<table class="beltoft-services-apiref__table">
								<caption><?php esc_html_e( 'Reply fields', 'beltoft-services' ); ?></caption>
								<thead><tr>
									<th scope="col"><?php esc_html_e( 'Field', 'beltoft-services' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th>
								</tr></thead>
								<tbody>
								<?php foreach ( $beltoft_services_op['reply'] as $beltoft_services_field ) : ?>
									<tr>
										<td data-label="<?php esc_attr_e( 'Field', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_field['name'] ); ?></code></td>
										<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['type'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['desc'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<?php if ( $beltoft_services_op['errors'] ) : ?>
							<p class="beltoft-services-apiref__meta"><?php echo esc_html( sprintf( /* translators: %s: list of HTTP statuses. */ __( 'Error statuses: %s.', 'beltoft-services' ), implode( ', ', $beltoft_services_op['errors'] ) ) ); ?></p>
						<?php endif; ?>
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
				<table class="beltoft-services-apiref__table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Status', 'beltoft-services' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Meaning', 'beltoft-services' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $errors as $beltoft_services_status => $beltoft_services_meaning ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Status', 'beltoft-services' ); ?>"><code><?php echo esc_html( (string) $beltoft_services_status ); ?></code></td>
							<td data-label="<?php esc_attr_e( 'Meaning', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_meaning ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
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
					<p><?php esc_html_e( 'Claude never sees passwords. Domain transfers are never possible through Claude. Restoring, reinstalling and deleting are only possible in your dashboard. Every request Claude makes is written to the history of the service.', 'beltoft-services' ); ?></p>
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
				<table class="beltoft-services-apiref__table beltoft-services-apiref__tools">
					<caption><?php echo esc_html( $beltoft_services_label ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Tool', 'beltoft-services' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it does', 'beltoft-services' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $beltoft_services_rows as $beltoft_services_tool ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Tool', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_tool['name'] ); ?></code></td>
							<td data-label="<?php esc_attr_e( 'What it does', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_tool['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>
	</div>
</div>
