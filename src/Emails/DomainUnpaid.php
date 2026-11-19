<?php
/** Customer email for a domain whose renewal was not paid: the reminders before and after the expiry, and the notices of the end. @package BeltoftServices */
namespace BeltoftServices\Emails;

use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * One email, seven notices (plan 2026-11-19-unpaid-domains.md section 3.2):
 * N1 the renewal could not be paid, N2 the same a week before the expiry, N3 the domain has expired, N4 and N5 the restore period ends soon,
 * N6 the renewal is paid, N7 the restore period is over. The notice and its facts come in the trigger data under `unpaid`; the subject and
 * heading follow the notice unless the shop owner saved their own. Plain and factual, and always the domain, the dates and what to do.
 */
final class DomainUnpaid extends ServiceEmail {

	use SavedOrDefaultTexts;

	protected const ID = 'beltoft_services_domain_unpaid';

	public const KINDS = array( 'N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7' );

	/**
	 * The facts of the current send.
	 *
	 * @var array<string, mixed>
	 */
	public array $facts = array();

	/**
	 * Sends the notice in $data['unpaid'].
	 *
	 * @param Resource             $r    Domain resource.
	 * @param array<string, mixed> $data Trigger data.
	 */
	public function trigger( Resource $r, array $data = array() ): bool {
		$this->facts = self::clean( is_array( $data['unpaid'] ?? null ) ? $data['unpaid'] : array() );

		return parent::trigger( $r, $data );
	}

	/**
	 * The facts with every key, as plain strings.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	public static function clean( array $facts ): array {
		$kind = in_array( $facts['kind'] ?? '', self::KINDS, true ) ? (string) $facts['kind'] : 'N1';
		$out  = array( 'kind' => $kind, 'days' => max( 0, (int) ( $facts['days'] ?? 0 ) ), 'fee_zero' => ! empty( $facts['fee_zero'] ) );

		foreach ( array( 'expiry_date', 'deadline', 'renewal_price', 'fee', 'total', 'vat', 'new_expiry' ) as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $facts[ $key ] ?? '' ) );
		}

		foreach ( array( 'pay_url', 'restore_url' ) as $key ) {
			$out[ $key ] = esc_url_raw( (string) ( $facts[ $key ] ?? '' ) );
		}

		return $out;
	}

	public function get_default_subject() {
		return self::titles( $this->facts, (string) ( $this->values['domain_name'] ?? '' ) )[0];
	}

	public function get_default_heading() {
		return self::titles( $this->facts, (string) ( $this->values['domain_name'] ?? '' ) )[1];
	}

	/**
	 * What the templates get beside the values: the facts, with every key.
	 *
	 * @param bool $plain_text Plain text.
	 * @return array<string, mixed>
	 */
	protected function template_args( bool $plain_text ): array {
		$facts = self::clean( $this->facts );

		return array_merge(
			parent::template_args( $plain_text ),
			array(
				'unpaid'       => $facts,
				'unpaid_lines' => self::lines( $facts, (string) ( $this->values['domain_name'] ?? '' ) ),
				'unpaid_link'  => self::link( $facts, (string) ( $this->values['account_url'] ?? '' ) ),
			)
		);
	}

	/**
	 * The subject and heading of a notice.
	 *
	 * @param array<string, mixed> $facts  Facts.
	 * @param string               $domain The domain.
	 * @return array{0: string, 1: string}
	 */
	public static function titles( array $facts, string $domain ): array {
		$facts = self::clean( $facts );

		switch ( $facts['kind'] ) {
			case 'N2':
				/* translators: 1: domain name, 2: number of days. */
				return array( sprintf( _n( 'Your domain %1$s expires in %2$d day', 'Your domain %1$s expires in %2$d days', $facts['days'], 'beltoft-services' ), $domain, $facts['days'] ), __( 'Your domain expires soon', 'beltoft-services' ) );
			case 'N3':
				/* translators: %s: domain name. */
				return array( sprintf( __( 'Your domain %s has expired', 'beltoft-services' ), $domain ), __( 'Your domain has expired', 'beltoft-services' ) );
			case 'N4':
			case 'N5':
				/* translators: 1: domain name, 2: number of days. */
				return array( sprintf( _n( '%1$s can be restored for %2$d more day', '%1$s can be restored for %2$d more days', $facts['days'], 'beltoft-services' ), $domain, $facts['days'] ), __( 'Your domain can still be restored', 'beltoft-services' ) );
			case 'N6':
				/* translators: %s: domain name. */
				return array( sprintf( __( 'The renewal of %s is paid', 'beltoft-services' ), $domain ), __( 'Your domain is renewed', 'beltoft-services' ) );
			case 'N7':
				/* translators: %s: domain name. */
				return array( sprintf( __( 'Your domain %s is no longer registered by us', 'beltoft-services' ), $domain ), __( 'Your domain is no longer registered by us', 'beltoft-services' ) );
			default:
				/* translators: 1: domain name, 2: date. */
				return array( sprintf( __( 'Your domain %1$s must be renewed by %2$s', 'beltoft-services' ), $domain, $facts['expiry_date'] ), __( 'Your domain must be renewed', 'beltoft-services' ) );
		}
	}

	/**
	 * The paragraphs of a notice.
	 *
	 * @param array<string, mixed> $facts  Facts.
	 * @param string               $domain The domain.
	 * @return string[]
	 */
	public static function lines( array $facts, string $domain ): array {
		$f = self::clean( $facts );

		switch ( $f['kind'] ) {
			case 'N1':
			case 'N2':
				$lines = array(
					'N2' === $f['kind']
						/* translators: 1: domain name, 2: number of days, 3: date. */
						? sprintf( _n( 'We could not take payment for the renewal of %1$s. The domain expires in %2$d day, on %3$s. Until then it works as normal.', 'We could not take payment for the renewal of %1$s. The domain expires in %2$d days, on %3$s. Until then it works as normal.', $f['days'], 'beltoft-services' ), $domain, $f['days'], $f['expiry_date'] )
						/* translators: 1: domain name, 2: date. */
						: sprintf( __( 'We could not take payment for the renewal of %1$s. The domain expires on %2$s. Until then it works as normal.', 'beltoft-services' ), $domain, $f['expiry_date'] ),
					'' !== $f['renewal_price']
						/* translators: %s: price. */
						? sprintf( __( 'You can pay the renewal of %s here.', 'beltoft-services' ), $f['renewal_price'] )
						: __( 'You can pay the renewal here.', 'beltoft-services' ),
					/* translators: %s: date. */
					sprintf( __( 'If it is not paid, the domain stops working on %s.', 'beltoft-services' ), $f['expiry_date'] ),
				);

				return array_merge( $lines, self::after( $f ) );

			case 'N3':
			case 'N4':
			case 'N5':
				if ( 'N3' === $f['kind'] ) {
					/* translators: 1: domain name, 2: date. */
					$lines = array( sprintf( __( '%1$s expired on %2$s. The website and email on this domain do not work now.', 'beltoft-services' ), $domain, $f['expiry_date'] ) );
				} else {
					$lines = array(
						/* translators: 1: domain name, 2: date, 3: number of days, 4: date. */
						sprintf( _n( '%1$s expired on %2$s and can still be restored for %3$d more day, until %4$s. The website and email on this domain do not work now.', '%1$s expired on %2$s and can still be restored for %3$d more days, until %4$s. The website and email on this domain do not work now.', $f['days'], 'beltoft-services' ), $domain, $f['expiry_date'], $f['days'], $f['deadline'] ),
					);
				}

				if ( '' === $f['deadline'] ) {
					$lines[] = __( 'Contact us to find out if it can be restored.', 'beltoft-services' );

					return $lines;
				}

				if ( 'N3' === $f['kind'] ) {
					/* translators: %s: date. */
					$lines[] = sprintf( __( 'You can restore it until %s.', 'beltoft-services' ), $f['deadline'] );
				}

				if ( '' !== $f['total'] && '' !== $f['fee'] && ! $f['fee_zero'] ) {
					/* translators: 1: total price, 2: renewal price, 3: reactivation fee, 4: "including VAT" or "excluding VAT". */
					$lines[] = sprintf( __( 'The price is %1$s (%2$s renewal and %3$s reactivation fee), %4$s.', 'beltoft-services' ), $f['total'], $f['renewal_price'], $f['fee'], $f['vat'] );
				} elseif ( '' !== $f['total'] && $f['fee_zero'] ) {
					/* translators: 1: price, 2: "including VAT" or "excluding VAT". */
					$lines[] = sprintf( __( 'The price is %1$s, the renewal, with no reactivation fee, %2$s.', 'beltoft-services' ), $f['total'], $f['vat'] );
				} else {
					$lines[] = __( 'The price is the renewal plus a reactivation fee.', 'beltoft-services' );
				}

				$lines[] = __( 'Restore it here.', 'beltoft-services' );
				/* translators: %s: date. */
				$lines[]  = sprintf( __( 'After %s the domain is released and anyone can register it.', 'beltoft-services' ), $f['deadline'] );

				return $lines;

			case 'N6':
				return array(
					/* translators: 1: domain name, 2: date. */
					sprintf( __( 'The renewal of %1$s is paid. The domain is registered until %2$s.', 'beltoft-services' ), $domain, $f['new_expiry'] ),
				);

			default:
				return array(
					/* translators: 1: domain name, 2: date. */
					sprintf( __( 'The time to restore %1$s ended on %2$s. We no longer register the domain for you, and anyone can register it now.', 'beltoft-services' ), $domain, $f['deadline'] ),
					__( 'If you have questions, contact us.', 'beltoft-services' ),
				);
		}
	}

	/**
	 * What happens after the expiry, for the notices sent before it (N1 and N2).
	 *
	 * @param array<string, mixed> $f Facts.
	 * @return string[]
	 */
	private static function after( array $f ): array {
		if ( '' === $f['deadline'] ) {
			return array();
		}

		if ( $f['fee_zero'] && '' !== $f['renewal_price'] ) {
			/* translators: 1: date, 2: renewal price. */
			$restore = sprintf( __( 'After that you can still restore it until %1$s, at the price of the renewal (%2$s), with no reactivation fee.', 'beltoft-services' ), $f['deadline'], $f['renewal_price'] );
		} elseif ( '' !== $f['fee'] && '' !== $f['renewal_price'] && ! $f['fee_zero'] ) {
			/* translators: 1: date, 2: renewal price, 3: reactivation fee. */
			$restore = sprintf( __( 'After that you can still restore it until %1$s, at a cost of %2$s plus a reactivation fee of %3$s.', 'beltoft-services' ), $f['deadline'], $f['renewal_price'], $f['fee'] );
		} else {
			/* translators: %s: date. */
			$restore = sprintf( __( 'After that you can still restore it until %s, at the price of the renewal plus a reactivation fee.', 'beltoft-services' ), $f['deadline'] );
		}

		/* translators: %s: date. */
		return array( $restore, sprintf( __( 'After %s the domain is released and anyone can register it.', 'beltoft-services' ), $f['deadline'] ) );
	}

	/**
	 * The link at the end: its label and address ('' address: no link).
	 *
	 * @param array<string, mixed> $facts       Facts.
	 * @param string               $account_url The domain in the account.
	 * @return array{0: string, 1: string}
	 */
	public static function link( array $facts, string $account_url ): array {
		$f = self::clean( $facts );

		if ( in_array( $f['kind'], array( 'N1', 'N2' ), true ) ) {
			return array( __( 'Pay the renewal', 'beltoft-services' ), '' !== $f['pay_url'] ? $f['pay_url'] : $account_url );
		}

		if ( in_array( $f['kind'], array( 'N3', 'N4', 'N5' ), true ) ) {
			return array( __( 'Restore the domain', 'beltoft-services' ), '' !== $f['restore_url'] ? $f['restore_url'] : $account_url );
		}

		if ( 'N6' === $f['kind'] ) {
			return array( __( 'View your domain in your account', 'beltoft-services' ), $account_url );
		}

		return array( '', '' );
	}
}
