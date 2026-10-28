<?php
/**
 * Reads a BIND zone file (as exported by most DNS providers) into record rows.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Domains;

defined( 'ABSPATH' ) || exit;

/**
 * Parsing only: the rows are not validated here, callers run Records::validate().
 */
final class ZoneFile {

	public const MAX_BYTES   = 102400;
	public const MAX_LINES   = 500;
	public const MAX_RECORDS = 200;

	private const TYPES = array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA' );

	/**
	 * Parses zone file text for a domain.
	 *
	 * @param string $text   Zone file text.
	 * @param string $domain Normalised ASCII domain the records belong to.
	 * @return array{records: array<int, array<string, mixed>>, skipped: array<int, array{line: int, text: string, reason: string}>}|\WP_Error
	 */
	public static function parse( string $text, string $domain ) {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			return self::too_large();
		}

		$domain  = strtolower( rtrim( $domain, '.' ) );
		$entries = self::entries( $text );

		if ( count( $entries ) > self::MAX_LINES ) {
			return self::too_large();
		}

		$origin   = $domain;
		$ttl      = 3600;
		$previous = null;
		$records  = array();
		$skipped  = array();

		foreach ( $entries as $entry ) {
			$tokens = $entry['tokens'];
			$skip   = static function ( string $reason ) use ( &$skipped, $entry ): void {
				$skipped[] = array(
					'line'   => $entry['line'],
					'text'   => $entry['text'],
					'reason' => $reason,
				);
			};

			if ( '$' === substr( $tokens[0]['t'], 0, 1 ) && ! $tokens[0]['q'] ) {
				$directive = strtoupper( $tokens[0]['t'] );
				if ( '$ORIGIN' === $directive && 2 === count( $tokens ) ) {
					$origin = self::absolute( $tokens[1]['t'], $origin );
				} elseif ( '$TTL' === $directive && 2 === count( $tokens ) && null !== self::seconds( $tokens[1]['t'] ) ) {
					$ttl = (int) self::seconds( $tokens[1]['t'] );
				} else {
					$skip( __( 'could not read this line', 'beltoft-services' ) );
				}
				continue;
			}

			if ( $entry['blank_owner'] ) {
				$owner = $previous;
			} else {
				$first = array_shift( $tokens );
				$owner = $first['q'] ? null : self::absolute( $first['t'], $origin );
			}

			$row_ttl = null;
			$seen_in = false;
			while ( $tokens && ! $tokens[0]['q'] ) {
				$word = $tokens[0]['t'];
				if ( null === $row_ttl && null !== self::seconds( $word ) ) {
					$row_ttl = self::seconds( $word );
				} elseif ( ! $seen_in && 'IN' === strtoupper( $word ) ) {
					$seen_in = true;
				} else {
					break;
				}
				array_shift( $tokens );
			}

			if ( null === $owner || ! $tokens || $tokens[0]['q'] ) {
				$skip( __( 'could not read this line', 'beltoft-services' ) );
				continue;
			}

			$previous = $owner;
			$type     = strtoupper( array_shift( $tokens )['t'] );
			$name     = self::relative( $owner, $domain );

			if ( null === $name ) {
				$skip( __( 'outside this domain', 'beltoft-services' ) );
				continue;
			}

			if ( 'SOA' === $type ) {
				$skip( __( 'managed by us', 'beltoft-services' ) );
				continue;
			}

			if ( 'NS' === $type && '@' === $name ) {
				$skip( __( 'replaced by our name servers', 'beltoft-services' ) );
				continue;
			}

			if ( ! in_array( $type, self::TYPES, true ) ) {
				$skip( __( 'type not supported', 'beltoft-services' ) );
				continue;
			}

			if ( 'TXT' === $type && DnsClaims::RECORD_NAME === $name ) {
				$skip( __( 'verification record', 'beltoft-services' ) );
				continue;
			}

			$row = self::row( $type, $name, $tokens, $origin );

			if ( null === $row ) {
				$skip( __( 'could not read this line', 'beltoft-services' ) );
				continue;
			}

			// Providers export "automatic" as 1: keep the value inside what a zone accepts.
			$row_ttl = null === $row_ttl ? $ttl : $row_ttl;
			// Cloudflare exports its "Auto" TTL as 1 (300 seconds there, because it proxies). Here an hour is
			// the better default: resolvers keep the answer longer, so fewer visitors wait for a fresh lookup.
			$row['ttl'] = 1 === (int) $row_ttl ? 3600 : max( 60, min( 86400, $row_ttl ) );

			if ( count( $records ) >= self::MAX_RECORDS ) {
				$skip( __( 'too many records', 'beltoft-services' ) );
				continue;
			}

			$records[] = $row;
		}

		return array(
			'records' => $records,
			'skipped' => $skipped,
		);
	}

	/**
	 * The error for input that is too big.
	 */
	private static function too_large(): \WP_Error {
		return new \WP_Error( 'zone_file_too_large', __( 'This zone file is too large. Remove records you do not need and try again.', 'beltoft-services' ), array( 'status' => 400 ) );
	}

	/**
	 * Splits the text into logical lines: comments removed, parentheses joined, quotes decoded.
	 *
	 * @param string $text Zone file text.
	 * @return array<int, array{line: int, blank_owner: bool, tokens: array<int, array{t: string, q: bool}>, text: string}>
	 */
	private static function entries( string $text ): array {
		$text    = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$text    = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$length  = strlen( $text );
		$entries = array();
		$tokens  = array();
		$word    = null;
		$depth   = 0;
		$line    = 1;
		$start   = 0;
		$first   = 1;
		$blank   = false;
		$at_line = true;

		$flush_word = static function () use ( &$tokens, &$word ): void {
			if ( null !== $word ) {
				$tokens[] = array(
					't' => $word,
					'q' => false,
				);
				$word     = null;
			}
		};

		for ( $i = 0; $i < $length; $i++ ) {
			$c = $text[ $i ];

			if ( $at_line ) {
				$at_line = false;
				$start   = $i;
				$first   = $line;
				$blank   = ' ' === $c || "\t" === $c;
			}

			if ( '"' === $c ) {
				$flush_word();
				$value = '';
				for ( ++$i; $i < $length && '"' !== $text[ $i ]; $i++ ) {
					if ( "\n" === $text[ $i ] ) {
						++$line;
					}
					if ( '\\' === $text[ $i ] && $i + 1 < $length ) {
						if ( preg_match( '/^\d{3}/', substr( $text, $i + 1, 3 ) ) ) {
							$value .= chr( (int) substr( $text, $i + 1, 3 ) % 256 );
							$i     += 3;
						} else {
							$value .= $text[ ++$i ];
						}
						continue;
					}
					$value .= $text[ $i ];
				}
				$tokens[] = array(
					't' => $value,
					'q' => true,
				);
				continue;
			}

			if ( ';' === $c ) {
				$flush_word();
				while ( $i + 1 < $length && "\n" !== $text[ $i + 1 ] ) {
					++$i;
				}
				continue;
			}

			if ( '(' === $c || ')' === $c ) {
				$flush_word();
				$depth = max( 0, $depth + ( '(' === $c ? 1 : -1 ) );
				continue;
			}

			if ( "\n" === $c ) {
				$flush_word();
				++$line;
				if ( 0 === $depth ) {
					if ( $tokens ) {
						$entries[] = self::entry( $first, $blank, $tokens, substr( $text, $start, $i - $start ) );
					}
					$tokens  = array();
					$at_line = true;
				}
				continue;
			}

			if ( ' ' === $c || "\t" === $c ) {
				$flush_word();
				continue;
			}

			$word = ( $word ?? '' ) . $c;
		}

		$flush_word();

		if ( $tokens ) {
			$entries[] = self::entry( $first, $blank, $tokens, substr( $text, $start ) );
		}

		return $entries;
	}

	/**
	 * One logical line.
	 *
	 * @param int                                          $line   First line number.
	 * @param bool                                         $blank  Starts with white space (no owner).
	 * @param array<int, array{t: string, q: bool}>        $tokens Tokens.
	 * @param string                                       $raw    Original text.
	 * @return array{line: int, blank_owner: bool, tokens: array<int, array{t: string, q: bool}>, text: string}
	 */
	private static function entry( int $line, bool $blank, array $tokens, string $raw ): array {
		$raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );

		return array(
			'line'        => $line,
			'blank_owner' => $blank,
			'tokens'      => $tokens,
			'text'        => function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 120 ) : substr( $raw, 0, 120 ),
		);
	}

	/**
	 * Seconds of a TTL word such as 300, 1h or 1d12h; null when the word is not a TTL.
	 *
	 * @param string $word Word.
	 */
	private static function seconds( string $word ): ?int {
		if ( ! preg_match( '/^(\d+[smhdw]?)+$/i', $word ) ) {
			return null;
		}

		$total = 0;
		$units = array(
			's' => 1,
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
			'w' => 604800,
		);
		preg_match_all( '/(\d+)([smhdw]?)/i', $word, $parts, PREG_SET_ORDER );

		foreach ( $parts as $part ) {
			$total += (int) $part[1] * ( $units[ strtolower( $part[2] ) ] ?? 1 );
		}

		return min( $total, PHP_INT_MAX >> 8 );
	}

	/**
	 * A name as a lower-case host name without the final dot.
	 *
	 * @param string $name   Name as written.
	 * @param string $origin Current origin.
	 */
	private static function absolute( string $name, string $origin ): string {
		$name = strtolower( $name );

		if ( '@' === $name ) {
			return $origin;
		}

		if ( str_ends_with( $name, '.' ) ) {
			return rtrim( $name, '.' );
		}

		return $name . '.' . $origin;
	}

	/**
	 * The name relative to the domain: @ for the domain, otherwise the label part, or the full
	 * name when it has more than one label (Records accepts both); null outside the domain.
	 *
	 * @param string $fqdn   Host name.
	 * @param string $domain Domain.
	 */
	private static function relative( string $fqdn, string $domain ): ?string {
		if ( $fqdn === $domain ) {
			return '@';
		}

		if ( ! str_ends_with( $fqdn, '.' . $domain ) ) {
			return null;
		}

		$part = substr( $fqdn, 0, -strlen( $domain ) - 1 );

		return substr_count( $part, '.' ) > 1 ? $fqdn : $part;
	}

	/**
	 * The data part of a record as a row, or null when it does not fit the type.
	 *
	 * @param string                                $type   Record type.
	 * @param string                                $name   Row name.
	 * @param array<int, array{t: string, q: bool}> $tokens Data tokens.
	 * @param string                                $origin Current origin.
	 * @return array<string, mixed>|null
	 */
	private static function row( string $type, string $name, array $tokens, string $origin ): ?array {
		$words = array_column( $tokens, 't' );
		$row   = array(
			'name' => $name,
			'type' => $type,
		);

		$number = static function ( string $value, int $max ): ?int {
			return ctype_digit( $value ) && (int) $value <= $max ? (int) $value : null;
		};

		switch ( $type ) {
			case 'A':
			case 'AAAA':
				if ( 1 !== count( $words ) ) {
					return null;
				}
				$row['value'] = $words[0];
				return $row;

			case 'CNAME':
				if ( 1 !== count( $words ) ) {
					return null;
				}
				$row['value'] = self::absolute( $words[0], $origin );
				return $row;

			case 'MX':
				$priority = 2 === count( $words ) ? $number( $words[0], 65535 ) : null;
				if ( null === $priority ) {
					return null;
				}
				$row['priority'] = $priority;
				$row['value']    = self::absolute( $words[1], $origin );
				return $row;

			case 'SRV':
				$nums = 4 === count( $words ) ? array( $number( $words[0], 65535 ), $number( $words[1], 65535 ), $number( $words[2], 65535 ) ) : array( null );
				if ( in_array( null, $nums, true ) ) {
					return null;
				}
				$row['priority'] = $nums[0];
				$row['weight']   = $nums[1];
				$row['port']     = $nums[2];
				$row['value']    = self::absolute( $words[3], $origin );
				return $row;

			case 'CAA':
				$flag = 3 === count( $words ) ? $number( $words[0], 255 ) : null;
				if ( null === $flag ) {
					return null;
				}
				$row['flag']  = $flag;
				$row['tag']   = strtolower( $words[1] );
				$row['value'] = $words[2];
				return $row;

			default: // TXT: the strings are joined.
				if ( ! $words ) {
					return null;
				}
				$row['value'] = implode( '', $words );
				return $row;
		}
	}
}
