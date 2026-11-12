<?php
/**
 * The one guide for a customer who has to add DNS records at another provider.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Hosting (point a domain to a website), mail (make email work) and any other feature that asks for records feed this class
 * a model and it draws the guide: a heading that says what the records achieve, numbered steps, the records grouped
 * "Needed" and "Recommended" with a Copy button on every name and value, a status per record, notes for the cases a
 * customer trips on, and "Check now". When everything needed is found it collapses to one line.
 *
 * The class draws and describes; it never looks anything up and never decides when a record counts as found. The feature
 * keeps its own check and hands the stored result in (`status` of each record).
 *
 * Model keys:
 * - id (string): unique on the page. domain (string): the domain, ASCII. title, why (strings): what this achieves and why
 *   nothing works until it is done. provider (string): the DNS provider guessed from the name servers, '' when unknown.
 * - steps (string[]): replaces the three standard steps (a zone of ours, where nothing is added by hand).
 * - records: list of arrays with type, name (full name), value, priority (int|null, MX), required (bool), purpose (string),
 *   status (ok, missing, unchecked, wrong, conflict, or none when nothing of ours looks for the record), seen (string[]: what is there now), wrong_text (sprintf format with
 *   %s for the first seen value, optional), reason (string, optional), note (string, optional), merged (string, optional: a
 *   value to use instead, with its own Copy button), key (string, optional).
 * - notes (string[]): lines for special cases, shown only because the caller found they apply.
 * - done_text (string): the one line shown when everything needed is found.
 * - check: null for no button, or array(label, attrs, class): the button that starts a check (the feature's own script handles
 *   it). checked (int timestamp, 0 never), interval (seconds between automatic checks, 0 for none), days (how long it keeps
 *   checking, 0 for no limit).
 * - attrs (array): extra attributes of the section (a value of '' prints the bare attribute), class (string).
 */
final class DnsRecordsGuide {

	/** Script handle of the shared Copy behaviour (assets/dns-guide.js). */
	public const SCRIPT = 'beltoft-services-dns-guide';

	/** Longest part of another value shown in a status. */
	public const SEEN_LENGTH = 60;

	/**
	 * Loads the script of the Copy buttons and the stylesheet that holds the guide's rules (assets/account.css).
	 */
	public static function enqueue(): void {
		\BeltoftServices\Account\Panel::style();
		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/dns-guide.js', BELTOFT_SERVICES_FILE ), array( \BeltoftServices\Status::API_HANDLE ), \BeltoftServices\Assets::version( 'assets/dns-guide.js' ), true );
	}

	/**
	 * The name as a provider's form wants it: the part in front of the domain, "@" for the domain itself.
	 *
	 * @param string $name   Full record name.
	 * @param string $domain The domain.
	 */
	public static function label( string $name, string $domain ): string {
		if ( $name === $domain ) {
			return '@';
		}

		return str_ends_with( $name, '.' . $domain ) ? substr( $name, 0, -1 - strlen( $domain ) ) : $name;
	}

	/**
	 * The words for one proof record, the same wherever it is asked.
	 */
	public static function proof_purpose(): string {
		return __( 'Shows that the domain is yours. One record proves it for all your services here, so you add it once.', 'beltoft-services' );
	}

	/**
	 * The status of a record in words, and its kind (for the colour).
	 *
	 * @param array<string, mixed> $rec Record.
	 * @return array{0: string, 1: string} Kind (ok, missing, wrong, unchecked) and text.
	 */
	public static function state( array $rec ): array {
		$status = (string) ( $rec['status'] ?? 'unchecked' );
		$seen   = array_values( array_filter( array_map( 'strval', (array) ( $rec['seen'] ?? array() ) ), static fn( $v ) => '' !== $v ) );

		// A record nothing of ours looks for (a certificate authority's validation record): no status is shown.
		if ( 'none' === $status ) {
			return array( 'none', '' );
		}

		if ( 'ok' === $status ) {
			return array( 'ok', __( 'Found', 'beltoft-services' ) );
		}

		if ( 'unchecked' === $status ) {
			return array( 'unchecked', __( 'Not checked yet', 'beltoft-services' ) );
		}

		if ( array() !== $seen && in_array( $status, array( 'wrong', 'conflict', 'missing' ), true ) ) {
			$shown = strlen( $seen[0] ) > self::SEEN_LENGTH ? substr( $seen[0], 0, self::SEEN_LENGTH ) . '...' : $seen[0];
			$text  = (string) ( $rec['wrong_text'] ?? '' );

			return array( 'wrong', '' !== $text ? sprintf( $text, $shown ) : sprintf( /* translators: %s: the start of the value that is there now. */ __( 'Another value is there now: %s', 'beltoft-services' ), $shown ) );
		}

		if ( 'conflict' === $status || 'wrong' === $status ) {
			return array( 'wrong', __( 'Another value is there now', 'beltoft-services' ) );
		}

		return array( 'missing', __( 'Not found yet', 'beltoft-services' ) );
	}

	/**
	 * Is everything needed found? At least one needed record, and every needed record found.
	 *
	 * @param array<string, mixed> $model Model.
	 */
	public static function done( array $model ): bool {
		$needed = array_filter( (array) ( $model['records'] ?? array() ), static fn( $rec ) => ! empty( $rec['required'] ) );

		return array() !== $needed && array() === array_filter( $needed, static fn( $rec ) => 'ok' !== ( $rec['status'] ?? '' ) );
	}

	/**
	 * Prints the guide.
	 *
	 * @param array<string, mixed> $model Model (see the class comment).
	 */
	public static function render( array $model ): void {
		echo self::html( $model ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped where it is built.
	}

	/**
	 * The guide as HTML.
	 *
	 * @param array<string, mixed> $model Model (see the class comment).
	 */
	public static function html( array $model ): string {
		self::enqueue();

		$domain  = (string) ( $model['domain'] ?? '' );
		$shown   = HostName::display( $domain );
		$records = array_values( array_filter( (array) ( $model['records'] ?? array() ), 'is_array' ) );
		$done    = self::done( $model );
		$attrs   = (array) ( $model['attrs'] ?? array() );
		$extra   = '';

		foreach ( $attrs as $name => $value ) {
			$extra .= ' ' . esc_attr( (string) $name ) . ( '' === (string) $value ? '' : '="' . esc_attr( (string) $value ) . '"' );
		}

		$out  = '<section class="beltoft-dnsg ' . esc_attr( (string) ( $model['class'] ?? '' ) ) . '" data-dnsg="' . esc_attr( (string) ( $model['id'] ?? $domain ) ) . '" data-state="' . ( $done ? 'done' : 'open' ) . '"' . $extra . ' aria-label="' . esc_attr( sprintf( /* translators: %s: domain name. */ __( 'DNS for %s', 'beltoft-services' ), $shown ) ) . '">';
		$out .= '<h5 class="beltoft-dnsg__title">' . esc_html( (string) ( $model['title'] ?? '' ) ) . '</h5>';

		if ( $done ) {
			$out .= '<p class="beltoft-dnsg__done" role="status">' . esc_html( (string) ( $model['done_text'] ?? '' ) ) . '</p>';
			$out .= self::group( __( 'Recommended', 'beltoft-services' ), array_filter( $records, static fn( $rec ) => empty( $rec['required'] ) && 'ok' !== ( $rec['status'] ?? '' ) ), $domain );
			$out .= self::check_line( $model, false );

			return $out . '</section>';
		}

		if ( '' !== (string) ( $model['why'] ?? '' ) ) {
			$out .= '<p class="beltoft-dnsg__why">' . esc_html( (string) $model['why'] ) . '</p>';
		}

		$out .= self::steps( $model, $shown );
		$out .= self::group( __( 'Needed', 'beltoft-services' ), array_filter( $records, static fn( $rec ) => ! empty( $rec['required'] ) ), $domain );
		$out .= self::group( __( 'Recommended', 'beltoft-services' ), array_filter( $records, static fn( $rec ) => empty( $rec['required'] ) ), $domain );

		$notes = array_values( array_filter( array_map( 'strval', (array) ( $model['notes'] ?? array() ) ), static fn( $n ) => '' !== $n ) );

		if ( array() !== array_filter( $records, static fn( $rec ) => '@' === self::label( (string) ( $rec['name'] ?? '' ), $domain ) ) ) {
			$notes[] = sprintf( /* translators: 1: "@", 2: domain name. */ __( 'About the name %1$s: it means the domain itself. Some providers want %1$s, some want the field left empty, and some want %2$s written out.', 'beltoft-services' ), '@', $shown );
		}

		if ( array() !== $notes ) {
			$out .= '<ul class="beltoft-dnsg__notes">';

			foreach ( $notes as $note ) {
				$out .= '<li>' . esc_html( $note ) . '</li>';
			}

			$out .= '</ul>';
		}

		return $out . self::check_line( $model, true ) . '</section>';
	}

	/**
	 * The numbered steps.
	 *
	 * @param array<string, mixed> $model Model.
	 * @param string               $shown The domain as shown.
	 */
	private static function steps( array $model, string $shown ): string {
		$has_check = ! empty( $model['check'] );

		if ( ! empty( $model['steps'] ) ) {
			$steps = array_map( 'strval', (array) $model['steps'] );
		} else {
			$first = sprintf( /* translators: %s: domain name. */ __( 'Log in where the DNS of %s is managed. That is usually where you bought the domain.', 'beltoft-services' ), $shown );

			if ( '' !== (string) ( $model['provider'] ?? '' ) ) {
				$first .= ' ' . sprintf( /* translators: %s: name of a DNS provider. */ __( 'For this domain it looks like %s.', 'beltoft-services' ), (string) $model['provider'] );
			}

			$steps = array( $first, __( 'Add the records below. Copy each name and value as it is.', 'beltoft-services' ) );

			if ( $has_check ) {
				$steps[] = __( 'Come back here and press Check now.', 'beltoft-services' );
			}
		}

		$out = '<ol class="beltoft-dnsg__steps">';

		foreach ( $steps as $step ) {
			$out .= '<li>' . esc_html( $step ) . '</li>';
		}

		return $out . '</ol>';
	}

	/**
	 * One group of records under its heading; nothing when it is empty.
	 *
	 * @param string                           $heading Heading.
	 * @param array<int, array<string, mixed>> $records Records.
	 * @param string                           $domain  The domain.
	 */
	private static function group( string $heading, array $records, string $domain ): string {
		if ( array() === $records ) {
			return '';
		}

		$out = '<h6 class="beltoft-dnsg__group">' . esc_html( $heading ) . '</h6><ul class="beltoft-dnsg__records">';

		foreach ( $records as $rec ) {
			$out .= self::record( $rec, $domain );
		}

		return $out . '</ul>';
	}

	/**
	 * One record: a block with labelled lines (it is the same on a phone, nothing scrolls sideways).
	 *
	 * @param array<string, mixed> $rec    Record.
	 * @param string               $domain The domain.
	 */
	private static function record( array $rec, string $domain ): string {
		$full  = (string) ( $rec['name'] ?? '' );
		$label = (string) ( $rec['label'] ?? self::label( $full, $domain ) );
		$value = (string) ( $rec['value'] ?? '' );
		list( $kind, $text ) = self::state( $rec );

		$out  = '<li class="beltoft-dnsg__rec" data-key="' . esc_attr( (string) ( $rec['key'] ?? '' ) ) . '" data-status="' . esc_attr( $kind ) . '">';
		$out .= '<div class="beltoft-dnsg__head"><span class="beltoft-dnsg__type">' . esc_html( (string) ( $rec['type'] ?? '' ) ) . '</span>';

		if ( '' !== (string) ( $rec['purpose'] ?? '' ) ) {
			$out .= '<span class="beltoft-dnsg__purpose">' . esc_html( (string) $rec['purpose'] ) . '</span>';
		}

		$out .= ( 'none' === $kind ? '' : '<span class="beltoft-dnsg__state is-' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>' ) . '</div>';
		$out .= '<dl class="beltoft-dnsg__fields">';
		$out .= '<div><dt>' . esc_html__( 'Name', 'beltoft-services' ) . '</dt><dd><code>' . esc_html( $label ) . '</code> ' . self::copy( $label, __( 'Copy name', 'beltoft-services' ) );

		if ( $full !== $label ) {
			$out .= '<span class="beltoft-dnsg__full">' . esc_html( sprintf( /* translators: %s: the full name of a DNS record. */ __( 'Full name: %s', 'beltoft-services' ), $full ) ) . '</span>';
		}

		$out .= '</dd></div>';

		if ( isset( $rec['priority'] ) && null !== $rec['priority'] && '' !== (string) $rec['priority'] ) {
			$out .= '<div><dt>' . esc_html__( 'Priority', 'beltoft-services' ) . '</dt><dd><code>' . esc_html( (string) $rec['priority'] ) . '</code> ' . self::copy( (string) $rec['priority'], __( 'Copy priority', 'beltoft-services' ) ) . '</dd></div>';
		}

		$out .= '<div><dt>' . esc_html__( 'Value', 'beltoft-services' ) . '</dt><dd><code class="beltoft-dnsg__value">' . esc_html( $value ) . '</code> ' . self::copy( $value, __( 'Copy value', 'beltoft-services' ) ) . '</dd></div>';

		if ( '' !== (string) ( $rec['merged'] ?? '' ) ) {
			$out .= '<div><dt>' . esc_html__( 'Use this instead', 'beltoft-services' ) . '</dt><dd><code class="beltoft-dnsg__value">' . esc_html( (string) $rec['merged'] ) . '</code> ' . self::copy( (string) $rec['merged'], __( 'Copy merged value', 'beltoft-services' ) ) . '</dd></div>';
		}

		$out .= '</dl>';

		if ( '' !== (string) ( $rec['reason'] ?? '' ) ) {
			$out .= '<p class="beltoft-dnsg__note">' . esc_html( (string) $rec['reason'] ) . '</p>';
		}

		if ( '' !== (string) ( $rec['note'] ?? '' ) ) {
			$out .= '<p class="beltoft-dnsg__note">' . esc_html( (string) $rec['note'] ) . '</p>';
		}

		return $out . '</li>';
	}

	/**
	 * A Copy button that copies the whole text it holds, however long.
	 *
	 * @param string $text  The text to copy.
	 * @param string $label Accessible name.
	 */
	private static function copy( string $text, string $label ): string {
		return '<button type="button" class="beltoft-dnsg__copy" data-dns-copy="' . esc_attr( $text ) . '" data-copied="' . esc_attr__( 'Copied', 'beltoft-services' ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html__( 'Copy', 'beltoft-services' ) . '</button>';
	}

	/**
	 * "Check now", when it was last done and how often it is done by itself.
	 *
	 * @param array<string, mixed> $model Model.
	 * @param bool                 $open  The guide is open (the button shows).
	 */
	private static function check_line( array $model, bool $open ): string {
		$check    = (array) ( $model['check'] ?? array() );
		$checked  = (int) ( $model['checked'] ?? 0 );
		$interval = (int) ( $model['interval'] ?? 0 );
		$days     = (int) ( $model['days'] ?? 0 );
		$out      = '';

		if ( $open && array() !== $check ) {
			$attrs = '';

			foreach ( (array) ( $check['attrs'] ?? array() ) as $name => $value ) {
				$attrs .= ' ' . esc_attr( (string) $name ) . '="' . esc_attr( (string) $value ) . '"';
			}

			$out .= '<button type="button" class="' . esc_attr( (string) ( $check['class'] ?? 'button woocommerce-button' ) ) . '"' . $attrs . '>' . esc_html( (string) ( $check['label'] ?? __( 'Check now', 'beltoft-services' ) ) ) . '</button>';
		}

		$when = '';

		if ( $checked > 0 ) {
			$when .= sprintf( /* translators: %s: date and time. */ __( 'Last checked %s.', 'beltoft-services' ), wp_date( 'j F Y, H:i', $checked ) ) . ' ';
		}

		if ( $open && $interval > 0 ) {
			$minutes = max( 1, (int) round( $interval / MINUTE_IN_SECONDS ) );
			$when   .= $days > 0
				? sprintf( /* translators: 1: minutes between checks, 2: days the checks go on. */ _n( 'We also check by ourselves every %1$d minutes for %2$d day.', 'We also check by ourselves every %1$d minutes for %2$d days.', $days, 'beltoft-services' ), $minutes, $days )
				: sprintf( /* translators: %d: minutes between checks. */ __( 'We also check by ourselves every %d minutes.', 'beltoft-services' ), $minutes );
		}

		if ( '' === trim( $when ) && '' === $out ) {
			return '';
		}

		return '<div class="beltoft-dnsg__check">' . $out . ( '' !== trim( $when ) ? '<span class="beltoft-dnsg__when">' . esc_html( trim( $when ) ) . '</span>' : '' ) . '</div>';
	}
}
