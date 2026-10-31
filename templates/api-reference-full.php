<?php
/**
 * The public API reference as a full width docs app, printed by [beltoft_services_api_reference layout="full"].
 *
 * Override by copying to yourtheme/beltoft-services/api-reference-full.php.
 *
 * @package BeltoftServices
 *
 * @var string                              $base      Base address of the API, no trailing slash.
 * @var string                              $spec_url  Address of openapi.json.
 * @var int                                 $rate      Requests a token may make per minute (0 means no limit).
 * @var array<string, string>               $scopes    Level => label.
 * @var array<int, array<string, mixed>>    $ops       Operations.
 * @var array<string, array<string, mixed>> $groups    Operations grouped by resource (ops have id, short, tools, samples, sample, status_label).
 * @var array<string, string>               $levels    Levels of a connected app: key => label.
 * @var array<int, string>                  $errors    Status => meaning.
 * @var array<string, string>               $languages Language key => label.
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
		$out .= 1 === preg_match( '/^\{\w+\}$/', (string) $part ) ? '<span class="bsdocs__param">' . esc_html( (string) $part ) . '</span>' : esc_html( (string) $part );
	}

	return $out;
};

// One code block with a label and a Copy button. $lang is the language key of a sample, empty for other code.
$beltoft_services_code = static function ( string $label, string $code, string $what, string $lang = '' ): void {
	?>
	<div class="bsdocs__code"<?php echo '' !== $lang ? ' data-lang="' . esc_attr( $lang ) . '"' : ''; ?>>
		<div class="bsdocs__codebar">
			<span><?php echo esc_html( $label ); ?></span>
			<button type="button" class="bsdocs__copy" data-bsdocs-copy aria-label="<?php echo esc_attr( $what ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
		</div>
		<pre tabindex="0" aria-label="<?php echo esc_attr( $label ); ?>"><code><?php echo esc_html( $code ); ?></code></pre>
	</div>
	<?php
};

// The sample of one operation in every language, then the example reply.
$beltoft_services_samples = static function ( array $op ) use ( $languages, $beltoft_services_code ): void {
	/* translators: 1: language, 2: HTTP method, 3: path. */
	$what = __( 'Copy the %1$s example for %2$s %3$s', 'beltoft-services' );

	foreach ( $languages as $key => $label ) {
		$beltoft_services_code( $label, (string) ( $op['samples'][ $key ] ?? '' ), sprintf( $what, $label, $op['method'], $op['path'] ), $key );
	}

	if ( $op['sample'] ) {
		/* translators: 1: HTTP method, 2: path. */
		$beltoft_services_code( $op['status_label'], $op['sample'], sprintf( __( 'Copy the example reply of %1$s %2$s', 'beltoft-services' ), $op['method'], $op['path'] ) );
	} else {
		?>
		<p class="bsdocs__status"><?php echo esc_html( $op['status_label'] ); ?></p>
		<?php
	}
};

$beltoft_services_methods = array(
	'GET'    => 'get',
	'POST'   => 'post',
	'PUT'    => 'put',
	'DELETE' => 'delete',
);

// The list reply shown with the authentication examples.
$beltoft_services_first = null;

foreach ( $ops as $beltoft_services_candidate ) {
	if ( 'GET' === $beltoft_services_candidate['method'] && '/resources' === $beltoft_services_candidate['path'] ) {
		$beltoft_services_first = $beltoft_services_candidate;
		break;
	}
}

if ( null === $beltoft_services_first ) {
	foreach ( $groups as $beltoft_services_group ) {
		$beltoft_services_first = $beltoft_services_group['ops'][0] ?? null;
		break;
	}
}

if ( null !== $beltoft_services_first ) {
	foreach ( $groups as $beltoft_services_group ) {
		foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) {
			if ( $beltoft_services_op['method'] === $beltoft_services_first['method'] && $beltoft_services_op['path'] === $beltoft_services_first['path'] ) {
				$beltoft_services_first = $beltoft_services_op;
			}
		}
	}
}

// The navigation: sections that are not endpoints, then the resource groups.
$beltoft_services_nav = array(
	array(
		'id'    => 'api-intro',
		'title' => __( 'Introduction', 'beltoft-services' ),
		'ops'   => array(),
	),
	array(
		'id'    => 'api-authentication',
		'title' => __( 'Authentication', 'beltoft-services' ),
		'ops'   => array(),
	),
	array(
		'id'    => 'api-errors',
		'title' => __( 'Errors and limits', 'beltoft-services' ),
		'ops'   => array(),
	),
);

foreach ( $groups as $beltoft_services_group ) {
	$beltoft_services_nav[] = $beltoft_services_group;
}

if ( $mcp['on'] ) {
	$beltoft_services_nav[] = array(
		'id'    => 'use-with-claude',
		'title' => __( 'Use with Claude (MCP)', 'beltoft-services' ),
		'ops'   => array(),
		'tools' => wp_list_pluck( $mcp['tools'], 'name' ),
	);
}
?>
<div class="bsdocs" data-bsdocs data-lang="curl">
	<aside class="bsdocs__side">
		<div class="bsdocs__bar">
			<label class="bsdocs__sr" for="bsdocs-search"><?php esc_html_e( 'Search the reference', 'beltoft-services' ); ?></label>
			<input id="bsdocs-search" class="bsdocs__search" type="search" placeholder="<?php esc_attr_e( 'Search the reference', 'beltoft-services' ); ?>" autocomplete="off" data-bsdocs-search>
			<label class="bsdocs__sr" for="bsdocs-jump"><?php esc_html_e( 'Jump to', 'beltoft-services' ); ?></label>
			<select id="bsdocs-jump" class="bsdocs__jump" data-bsdocs-jump>
				<option value=""><?php esc_html_e( 'Jump to', 'beltoft-services' ); ?></option>
				<?php foreach ( $beltoft_services_nav as $beltoft_services_group ) : ?>
					<?php if ( $beltoft_services_group['ops'] ) : ?>
						<optgroup label="<?php echo esc_attr( $beltoft_services_group['title'] ); ?>">
							<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
								<option value="<?php echo esc_attr( $beltoft_services_op['id'] ); ?>"><?php echo esc_html( $beltoft_services_op['method'] . ' ' . $beltoft_services_op['path'] ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php else : ?>
						<option value="<?php echo esc_attr( $beltoft_services_group['id'] ); ?>"><?php echo esc_html( $beltoft_services_group['title'] ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
		</div>
		<nav class="bsdocs__nav" aria-label="<?php esc_attr_e( 'API reference sections', 'beltoft-services' ); ?>" data-bsdocs-nav>
			<ul>
				<?php foreach ( $beltoft_services_nav as $beltoft_services_group ) : ?>
					<?php
					$beltoft_services_hay = strtolower( $beltoft_services_group['title'] . ' ' . implode( ' ', (array) ( $beltoft_services_group['tools'] ?? array() ) ) );
					?>
					<li class="bsdocs__group" data-group="<?php echo esc_attr( strtolower( $beltoft_services_group['title'] ) ); ?>" data-search="<?php echo esc_attr( $beltoft_services_hay ); ?>">
						<a class="bsdocs__grouplink" href="#<?php echo esc_attr( $beltoft_services_group['id'] ); ?>"><?php echo esc_html( $beltoft_services_group['title'] ); ?></a>
						<?php if ( $beltoft_services_group['ops'] ) : ?>
							<ul>
								<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
									<li data-search="<?php echo esc_attr( strtolower( $beltoft_services_op['method'] . ' ' . $beltoft_services_op['path'] . ' ' . $beltoft_services_op['summary'] . ' ' . implode( ' ', $beltoft_services_op['tools'] ) ) ); ?>">
										<a href="#<?php echo esc_attr( $beltoft_services_op['id'] ); ?>" title="<?php echo esc_attr( $beltoft_services_op['method'] . ' ' . $beltoft_services_op['path'] ); ?>"><span class="bsdocs__navmethod bsdocs__navmethod--<?php echo esc_attr( $beltoft_services_methods[ $beltoft_services_op['method'] ] ?? 'get' ); ?>"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span> <span class="bsdocs__navpath"><?php echo esc_html( $beltoft_services_op['short'] ); ?></span></a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="bsdocs__empty" data-bsdocs-empty hidden><?php esc_html_e( 'Nothing matches your search.', 'beltoft-services' ); ?></p>
		</nav>
	</aside>

	<div class="bsdocs__content">
		<div class="bsdocs__tabs" data-bsdocs-tabs>
			<div class="bsdocs__tabsbar" role="group" aria-label="<?php esc_attr_e( 'Language of the examples', 'beltoft-services' ); ?>">
				<?php foreach ( $languages as $beltoft_services_key => $beltoft_services_label ) : ?>
					<button type="button" class="bsdocs__tab" data-bsdocs-lang="<?php echo esc_attr( $beltoft_services_key ); ?>" aria-pressed="<?php echo 'curl' === $beltoft_services_key ? 'true' : 'false'; ?>"><?php echo esc_html( $beltoft_services_label ); ?></button>
				<?php endforeach; ?>
			</div>
		</div>

		<section id="api-intro" class="bsdocs__row" data-bsdocs-section>
			<div class="bsdocs__text">
				<h2><?php esc_html_e( 'API reference', 'beltoft-services' ); ?></h2>
				<div class="bsdocs__base">
					<code><?php echo esc_html( $base ); ?></code>
					<button type="button" class="bsdocs__copy bsdocs__copy--light" data-bsdocs-copy aria-label="<?php esc_attr_e( 'Copy the base address', 'beltoft-services' ); ?>"><?php esc_html_e( 'Copy', 'beltoft-services' ); ?></button>
				</div>
				<p><?php esc_html_e( 'The API works on your own services only. All requests use HTTPS and all replies are JSON. Send your username and a personal token as HTTP Basic authentication.', 'beltoft-services' ); ?> <a href="#api-authentication"><?php esc_html_e( 'How authentication works', 'beltoft-services' ); ?></a></p>
			</div>
			<div class="bsdocs__samples"></div>
		</section>

		<section id="api-authentication" class="bsdocs__row" data-bsdocs-section>
			<div class="bsdocs__text">
				<h2><?php esc_html_e( 'Authentication', 'beltoft-services' ); ?></h2>
				<h3><?php esc_html_e( 'Personal token', 'beltoft-services' ); ?></h3>
				<p><?php esc_html_e( 'Create a token in My Account, Security. Send your username and the token as HTTP Basic authentication. A token has one access level: read only, or read and actions. A token is shown once and can be revoked at any time.', 'beltoft-services' ); ?></p>
				<h3><?php esc_html_e( 'OAuth for apps', 'beltoft-services' ); ?></h3>
				<p><?php esc_html_e( 'An app that acts for a customer uses OAuth 2.1 with the authorization code flow and PKCE (S256). Apps register themselves (RFC 7591). The customer chooses the access level when connecting. The addresses are in the OpenAPI document.', 'beltoft-services' ); ?></p>
				<p><a href="<?php echo esc_url( $spec_url ); ?>"><?php esc_html_e( 'OpenAPI 3.1 document (openapi.json)', 'beltoft-services' ); ?></a></p>
			</div>
			<div class="bsdocs__samples">
				<?php
				if ( null !== $beltoft_services_first ) {
					$beltoft_services_samples( $beltoft_services_first );
				}

				$beltoft_services_code( __( 'OAuth access token', 'beltoft-services' ), 'curl -H "Authorization: Bearer ACCESS_TOKEN" ' . $base . '/resources', __( 'Copy the curl example with an OAuth access token', 'beltoft-services' ) );
				?>
			</div>
		</section>

		<section id="api-errors" class="bsdocs__row" data-bsdocs-section>
			<div class="bsdocs__text">
				<h2><?php esc_html_e( 'Errors and limits', 'beltoft-services' ); ?></h2>
				<p><?php esc_html_e( 'An error has an HTTP status and a JSON body with a code and a message, and the status again inside data.', 'beltoft-services' ); ?></p>
				<table class="bsdocs__table">
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
			<div class="bsdocs__samples">
				<?php $beltoft_services_code( __( 'Error reply', 'beltoft-services' ), '{"code":"rest_forbidden","message":"...","data":{"status":403}}', __( 'Copy the example error reply', 'beltoft-services' ) ); ?>
			</div>
		</section>

		<?php foreach ( $groups as $beltoft_services_group ) : ?>
			<section id="<?php echo esc_attr( $beltoft_services_group['id'] ); ?>" class="bsdocs__group-section" data-bsdocs-section>
				<div class="bsdocs__row bsdocs__row--title">
					<div class="bsdocs__text">
						<h2><?php echo esc_html( $beltoft_services_group['title'] ); ?></h2>
						<?php if ( $beltoft_services_group['desc'] ) : ?>
							<p class="bsdocs__lead"><?php echo esc_html( $beltoft_services_group['desc'] ); ?></p>
						<?php endif; ?>
					</div>
					<div class="bsdocs__samples"></div>
				</div>
				<?php foreach ( $beltoft_services_group['ops'] as $beltoft_services_op ) : ?>
					<article id="<?php echo esc_attr( $beltoft_services_op['id'] ); ?>" class="bsdocs__row bsdocs__op" data-bsdocs-section data-path="<?php echo esc_attr( $beltoft_services_op['path'] ); ?>">
						<div class="bsdocs__text">
							<h3>
								<span class="bsdocs__method bsdocs__method--<?php echo esc_attr( $beltoft_services_methods[ $beltoft_services_op['method'] ] ?? 'get' ); ?>"><?php echo esc_html( $beltoft_services_op['method'] ); ?></span>
								<code class="bsdocs__path"><?php echo $beltoft_services_path( $beltoft_services_op['path'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the helper escapes every part. ?></code>
							</h3>
							<p class="bsdocs__summary"><?php echo esc_html( $beltoft_services_op['summary'] ); ?></p>
							<?php if ( $beltoft_services_op['tools'] ) : ?>
								<p class="bsdocs__meta">
									<?php
									esc_html_e( 'With Claude:', 'beltoft-services' );
									echo ' ';

									foreach ( $beltoft_services_op['tools'] as $beltoft_services_i => $beltoft_services_tool_name ) {
										echo $beltoft_services_i ? ', ' : '';
										echo '<span class="bsdocs__tool">' . esc_html( $beltoft_services_tool_name ) . '</span>';
									}
									?>
								</p>
							<?php endif; ?>
							<p class="bsdocs__meta">
								<?php
								/* translators: 1: access level, 2: HTTP status of a successful reply. */
								echo esc_html( sprintf( __( 'Access: %1$s. Success status: %2$d.', 'beltoft-services' ), $beltoft_services_level( $beltoft_services_op['scope'] ), $beltoft_services_op['status'] ) );
								?>
							</p>
							<?php if ( $beltoft_services_op['params'] ) : ?>
								<?php $beltoft_services_desc = '' !== implode( '', array_column( $beltoft_services_op['params'], 'desc' ) ); ?>
								<table class="bsdocs__table">
									<caption><?php esc_html_e( 'Parameters', 'beltoft-services' ); ?></caption>
									<thead><tr>
										<th scope="col"><?php esc_html_e( 'Name', 'beltoft-services' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Required', 'beltoft-services' ); ?></th>
										<?php if ( $beltoft_services_desc ) : ?><th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th><?php endif; ?>
									</tr></thead>
									<tbody>
									<?php foreach ( $beltoft_services_op['params'] as $beltoft_services_param ) : ?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Name', 'beltoft-services' ); ?>"><code<?php echo 'path' === $beltoft_services_param['in'] ? ' class="bsdocs__param"' : ''; ?>><?php echo esc_html( $beltoft_services_param['name'] ); ?></code></td>
											<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['type'] . ', ' . $beltoft_services_param['in'] ); ?></td>
											<td data-label="<?php esc_attr_e( 'Required', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['required'] ? __( 'Yes', 'beltoft-services' ) : __( 'No', 'beltoft-services' ) ); ?></td>
											<?php if ( $beltoft_services_desc ) : ?><td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_param['desc'] ); ?></td><?php endif; ?>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
							<?php if ( $beltoft_services_op['reply'] ) : ?>
								<?php $beltoft_services_desc = '' !== implode( '', array_column( $beltoft_services_op['reply'], 'desc' ) ); ?>
								<table class="bsdocs__table">
									<caption><?php esc_html_e( 'Reply fields', 'beltoft-services' ); ?></caption>
									<thead><tr>
										<th scope="col"><?php esc_html_e( 'Field', 'beltoft-services' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Type', 'beltoft-services' ); ?></th>
										<?php if ( $beltoft_services_desc ) : ?><th scope="col"><?php esc_html_e( 'Description', 'beltoft-services' ); ?></th><?php endif; ?>
									</tr></thead>
									<tbody>
									<?php foreach ( $beltoft_services_op['reply'] as $beltoft_services_field ) : ?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Field', 'beltoft-services' ); ?>"><code><?php echo esc_html( $beltoft_services_field['name'] ); ?></code></td>
											<td data-label="<?php esc_attr_e( 'Type', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['type'] ); ?></td>
											<?php if ( $beltoft_services_desc ) : ?><td data-label="<?php esc_attr_e( 'Description', 'beltoft-services' ); ?>"><?php echo esc_html( $beltoft_services_field['desc'] ); ?></td><?php endif; ?>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
							<?php if ( $beltoft_services_op['errors'] ) : ?>
								<p class="bsdocs__meta"><?php echo esc_html( sprintf( /* translators: %s: list of HTTP statuses. */ __( 'Error statuses: %s.', 'beltoft-services' ), implode( ', ', $beltoft_services_op['errors'] ) ) ); ?></p>
							<?php endif; ?>
						</div>
						<div class="bsdocs__samples">
							<?php $beltoft_services_samples( $beltoft_services_op ); ?>
						</div>
					</article>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>

		<section class="bsdocs__row" aria-label="<?php esc_attr_e( 'Not part of the API', 'beltoft-services' ); ?>">
			<div class="bsdocs__text">
				<p class="bsdocs__lead"><?php esc_html_e( 'Passwords, console access, transfer codes and actions that destroy something, such as reinstall, restore and delete, are not part of the API. They are only available in the dashboard. Every change made with a token is written to the history of the service.', 'beltoft-services' ); ?></p>
			</div>
			<div class="bsdocs__samples"></div>
		</section>

		<?php if ( $mcp['on'] ) : ?>
			<section id="use-with-claude" class="bsdocs__group-section" data-bsdocs-section>
				<div class="bsdocs__row">
					<div class="bsdocs__text">
						<h2><?php esc_html_e( 'Use with Claude (MCP)', 'beltoft-services' ); ?></h2>
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
					<div class="bsdocs__samples">
						<?php $beltoft_services_code( __( 'Connector address', 'beltoft-services' ), $mcp['url'], __( 'Copy the connector address', 'beltoft-services' ) ); ?>
					</div>
				</div>
				<div class="bsdocs__row">
					<div class="bsdocs__text">
						<h3><?php esc_html_e( 'Tools', 'beltoft-services' ); ?></h3>
						<?php foreach ( $levels as $beltoft_services_key => $beltoft_services_label ) : ?>
							<?php
							$beltoft_services_rows = array_filter( $mcp['tools'], static fn( $tool ) => $tool['level'] === $beltoft_services_key );

							if ( ! $beltoft_services_rows ) {
								continue;
							}
							?>
							<table class="bsdocs__table bsdocs__tools">
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
					</div>
					<div class="bsdocs__samples"></div>
				</div>
			</section>
		<?php endif; ?>
	</div>
</div>
