<?php
/**
 * The business email driver: one resource is one email domain on a mailcow server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Mail;

use BeltoftServices\Alerts;
use BeltoftServices\Drivers\Driver;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Provision: check the name (usable, not reserved, not taken), prove the customer holds it (a zone of ours for them,
 * or the _beltoft-verify TXT token), add the domain with the plan's limits, make its DKIM key, and place the DNS
 * records (MailDns). Pause and resume switch the domain and its mailboxes off and on. A plan change raises the limits
 * and never lowers them. Delete removes mailboxes, aliases and the domain, and runs only when Retention or an admin
 * asks for it (deletion_mode is off by default). Mailboxes and aliases are Mailboxes and Aliases, reached through MailRoutes and the account
 * page (MailCard); the driver has no queued customer actions.
 */
final class MailDriver implements Driver {

	public const ID   = 'mail';
	public const KIND = 'mail';

	/** Days a cancelled email domain is kept before it can be deleted, when the product sets no period. */
	public const DEFAULT_RETENTION_DAYS = 30;

	/** The DKIM key size asked for. */
	public const DKIM_BITS = 2048;

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Business email', 'beltoft-services' );
	}

	public function kind(): string {
		return self::KIND;
	}

	public function unit_label( int $used, int $max ): string {
		/* translators: 1: email domains used, 2: email domains allowed */
		return sprintf( _n( '%1$d of %2$d email domain', '%1$d of %2$d email domains', $max, 'beltoft-services' ), $used, $max );
	}

	/**
	 * Mailboxes, the size of each, aliases and domains: all four are plan limits of a variation.
	 */
	public function plan_fields(): array {
		return array(
			'mailboxes' => array( 'number', __( 'Mailboxes', 'beltoft-services' ), __( 'How many mailboxes one email domain may have.', 'beltoft-services' ), 1, true ),
			'quota_mb'  => array( 'number', __( 'Size of each mailbox (MB)', 'beltoft-services' ), __( 'The storage of every mailbox, in MB (5 GB is 5120).', 'beltoft-services' ), 5120, true ),
			'aliases'   => array( 'number', __( 'Aliases', 'beltoft-services' ), __( 'How many aliases one email domain may have.', 'beltoft-services' ), 20, true ),
			'domains'   => array( 'number', __( 'Email domains', 'beltoft-services' ), __( 'How many email domains the plan allows. Each domain is one service.', 'beltoft-services' ), 1, true ),
		);
	}

	/**
	 * The default retention of email products: 30 days unless the product sets its own. An explicit 0 still means never.
	 *
	 * @param mixed $days       Days so far.
	 * @param mixed $product_id Product or variation id.
	 * @return mixed
	 */
	public static function default_retention( $days, $product_id ) {
		$driver = \BeltoftServices\Product::driver_for( (int) $product_id );

		return $driver && self::ID === $driver->id() ? self::DEFAULT_RETENTION_DAYS : $days;
	}

	/**
	 * Plan values as whole limits, each at least 1.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array{mailboxes:int,quota_mb:int,aliases:int,domains:int}
	 */
	public static function limits_from( array $plan ): array {
		return array(
			'mailboxes' => max( 1, (int) ( $plan['mailboxes'] ?? 1 ) ),
			'quota_mb'  => max( 1, (int) ( $plan['quota_mb'] ?? 5120 ) ),
			'aliases'   => max( 1, (int) ( $plan['aliases'] ?? 20 ) ),
			'domains'   => max( 1, (int) ( $plan['domains'] ?? 1 ) ),
		);
	}

	/**
	 * The domain attributes mailcow takes for these limits. The domain's total storage holds every mailbox at full size.
	 *
	 * @param array{mailboxes:int,quota_mb:int,aliases:int,domains:int} $limits Limits.
	 * @return array<string, string>
	 */
	public static function domain_attrs( array $limits ): array {
		return array(
			'mailboxes' => (string) $limits['mailboxes'],
			'aliases'   => (string) $limits['aliases'],
			'defquota'  => (string) $limits['quota_mb'],
			'maxquota'  => (string) $limits['quota_mb'],
			'quota'     => (string) ( $limits['mailboxes'] * $limits['quota_mb'] ),
		);
	}

	public function provision( Resource $r ): StepResult {
		if ( '' === MailSettings::host() ) {
			/* Admin text, not shown to customers. */
			return StepResult::retry( 3600, 'Set the mail host name under Settings, Services, Email.' );
		}

		$name = MailDomain::normalise( $r->get( MailDomain::META_DOMAIN ) );

		if ( null === $name ) {
			return StepResult::fail( 'The order did not contain a valid domain.' );
		}

		if ( MailDomain::reserved( $name ) ) {
			return StepResult::fail( 'This domain cannot be used for email here.' );
		}

		if ( MailDomain::taken( $name, $r->id() ) ) {
			return StepResult::fail( 'This domain already has email here.' );
		}

		$steps = (array) $r->get( 'steps' );

		if ( ! in_array( 'proof', $steps, true ) ) {
			$proof = $this->prove( $r, $name );

			if ( $proof instanceof StepResult ) {
				return $proof;
			}

			$steps[] = 'proof';

			if ( ! $r->set( array( 'steps' => $steps, 'mail_mode' => $proof ) ) ) {
				return StepResult::retry( 60, 'The proof could not be saved.' );
			}
		}

		$client = MailClients::for();
		$limits = self::limits_from( (array) $r->get( 'plan' ) );

		if ( ! in_array( 'domain', $steps, true ) ) {
			$added = $client->add_domain( array_merge( array( 'domain' => $name, 'active' => '1', 'backupmx' => '0', 'relay_all_recipients' => '0', 'description' => $this->marker( $r ) ), self::domain_attrs( $limits ) ) );

			if ( is_wp_error( $added ) ) {
				$known = $this->adopt( $r, $client, $name, $added );

				if ( null !== $known ) {
					return $known;
				}
			}

			$steps[] = 'domain';

			if ( ! $r->set( array( 'steps' => $steps, 'mail_limits' => $limits ) ) ) {
				return StepResult::retry( 60, 'The domain status could not be saved.' );
			}
		}

		if ( ! in_array( 'rl', $steps, true ) ) {
			$limited = $this->domain_rate( $client, $name );

			if ( is_wp_error( $limited ) ) {
				return $this->from_error( $limited );
			}

			$steps[] = 'rl';

			if ( ! $r->set( array( 'steps' => $steps ) ) ) {
				return StepResult::retry( 60, 'The domain send limit status could not be saved.' );
			}
		}

		if ( ! in_array( 'dkim', $steps, true ) ) {
			$key = $client->get_dkim( $name );

			if ( null === $key ) {
				$made = $client->add_dkim( $name, MailDns::SELECTOR, self::DKIM_BITS );

				if ( is_wp_error( $made ) && 'mailcow_exists' !== $made->get_error_code() ) {
					return $this->from_error( $made );
				}

				$key = $client->get_dkim( $name );
			}

			if ( is_wp_error( $key ) ) {
				return $this->from_error( $key );
			}

			if ( null === $key ) {
				return StepResult::retry( 60, 'The DKIM key is not there yet.' );
			}

			$steps[] = 'dkim';

			if ( ! $r->set( array( 'steps' => $steps, 'mail_dkim' => (string) $key['dkim_txt'] ) ) ) {
				return StepResult::retry( 60, 'The DKIM key could not be saved.' );
			}
		}

		$fresh = Resource::find( $r->id() ) ?? $r;
		$rows  = MailDns::apply( $fresh );

		if ( is_wp_error( $rows ) ) {
			return StepResult::retry( 300, $rows->get_error_message() );
		}

		$meta = array_merge( MailDns::meta_for( $rows ), array( 'steps' => array_merge( $steps, array( 'dns' ) ) ) );

		if ( 'waiting' === $meta['mail_dns'] ) {
			MailDns::schedule( $r->id(), MailDns::INTERVAL );
		}

		return StepResult::done( $meta, 'Email domain ready' );
	}

	/**
	 * Proves the customer holds the domain.
	 *
	 * @param Resource $r    Resource.
	 * @param string   $name Domain.
	 * @return string|StepResult 'ours' or 'external' when proven, else the result to return.
	 */
	private function prove( Resource $r, string $name ) {
		if ( MailDomain::on_our_servers( $name, (int) ( $r->license()['user_id'] ?? 0 ) ) ) {
			return 'ours';
		}

		$token = MailDomain::token( $r );

		if ( '' === $token ) {
			return StepResult::retry( 60, 'The proof token could not be saved.' );
		}

		$until = (int) $r->get( 'mail_proof_until' );
		$until = $until > 0 ? $until : time() + MailDns::WAIT_DAYS * DAY_IN_SECONDS;
		$check = MailDomain::check_proof( $name, $token );

		if ( 'found' === $check['status'] ) {
			return 'external';
		}

		if ( 'support' === $check['status'] && 'not_delegated' !== $check['reason'] ) {
			return StepResult::fail( 'The domain cannot be verified: ' . $check['reason'] . '.' );
		}

		if ( time() >= $until ) {
			return StepResult::fail( 'The ownership proof for the domain was not found in time.' );
		}

		return StepResult::wait( MailDns::INTERVAL, 'Waiting for the ownership proof (' . ( '' !== $check['reason'] ? $check['reason'] : $check['status'] ) . ').', $until, array( 'mail_proof_until' => $until, 'mail_mode' => 'external' ) );
	}

	/**
	 * add/domain was refused. "Exists" is fine only when this service made the domain earlier (its marker is in the
	 * description): a domain that was there before is somebody else's and is never taken over.
	 *
	 * @param Resource      $r      Resource.
	 * @param MailcowClient $client Client.
	 * @param string        $name   Domain.
	 * @param \WP_Error     $error  The refusal.
	 * @return StepResult|null The result to return, or null to go on.
	 */
	private function adopt( Resource $r, MailcowClient $client, string $name, \WP_Error $error ): ?StepResult {
		if ( 'mailcow_exists' !== $error->get_error_code() ) {
			return $this->from_error( $error );
		}

		$have = $client->get_domain( $name );

		if ( is_wp_error( $have ) ) {
			return $this->from_error( $have );
		}

		if ( is_array( $have ) && $this->marker( $r ) === (string) ( $have['description'] ?? '' ) ) {
			return null;
		}

		if ( ! $r->get( 'mail_foreign_alerted' ) ) {
			Alerts::admin( $r, 'The domain already exists on the mail server and was not created by this service. It was left alone.' );
		}

		return StepResult::fail( 'The domain already exists on the mail server.', array( 'mail_foreign_alerted' => true ) );
	}

	/**
	 * The text this service writes in the description of a domain it made.
	 *
	 * @param Resource $r Resource.
	 */
	private function marker( Resource $r ): string {
		return 'beltoft-services:' . $r->get( 'uuid' );
	}

	/**
	 * Sets the sending limit of the whole domain (setting mail_rl_minute, per minute); nothing when it is 0.
	 *
	 * @param MailcowClient $client Client.
	 * @param string        $domain Domain.
	 * @return \WP_Error|null
	 */
	private function domain_rate( MailcowClient $client, string $domain ): ?\WP_Error {
		$limit = MailSettings::domain_rate_limit();

		if ( null === $limit ) {
			return null;
		}

		$set = $client->set_domain_rate_limit( $domain, $limit[0], $limit[1] );

		return is_wp_error( $set ) ? $set : null;
	}

	/**
	 * A client error as a step result: no answer means try again, a refusal is final.
	 *
	 * @param \WP_Error $e Error.
	 */
	private function from_error( \WP_Error $e ): StepResult {
		return in_array( $e->get_error_code(), array( 'mailcow_transport', 'mailcow_http' ), true )
			? StepResult::retry( 300, $e->get_error_message() )
			: StepResult::fail( $e->get_error_message() );
	}

	/**
	 * Has the domain been made on the mail server?
	 *
	 * @param Resource $r Resource.
	 */
	private function created( Resource $r ): bool {
		return in_array( 'domain', (array) $r->get( 'steps' ), true );
	}

	public function pause( Resource $r ): StepResult {
		if ( ! $this->created( $r ) ) {
			return StepResult::done();
		}

		$client = MailClients::for();
		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$off    = $client->edit_domain( $domain, array( 'active' => '0' ) );

		if ( is_wp_error( $off ) ) {
			return StepResult::retry( 300, $off->get_error_message() );
		}

		$boxes = $client->list_mailboxes( $domain );

		if ( is_wp_error( $boxes ) ) {
			return StepResult::retry( 300, $boxes->get_error_message() );
		}

		$paused = array_values( array_map( 'strval', (array) $r->get( 'mail_paused_boxes' ) ) );

		foreach ( $boxes as $box ) {
			$email = strtolower( (string) ( $box['username'] ?? '' ) );

			if ( '' === $email || '0' === (string) ( $box['active'] ?? '1' ) ) {
				continue;
			}

			$set = $client->edit_mailbox( $email, array( 'active' => '0' ) );

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 300, $set->get_error_message() );
			}

			// Kept as it happens: a retry must not forget the mailboxes this pause already switched off.
			$paused[] = $email;

			if ( ! $r->set( array( 'mail_paused_boxes' => array_values( array_unique( $paused ) ) ) ) ) {
				return StepResult::retry( 60, 'The paused mailboxes could not be saved.' );
			}
		}

		return StepResult::done( array( 'mail_paused_boxes' => array_values( array_unique( $paused ) ) ) );
	}

	public function resume( Resource $r ): StepResult {
		if ( ! $this->created( $r ) ) {
			return $this->provision( $r );
		}

		$client = MailClients::for();
		$domain = (string) $r->get( MailDomain::META_DOMAIN );
		$on     = $client->edit_domain( $domain, array( 'active' => '1' ) );

		if ( is_wp_error( $on ) ) {
			return StepResult::retry( 300, $on->get_error_message() );
		}

		foreach ( array_values( array_map( 'strval', (array) $r->get( 'mail_paused_boxes' ) ) ) as $email ) {
			$box = $client->get_mailbox( $email );

			// A mailbox deleted while paused is not brought back.
			if ( ! is_array( $box ) ) {
				continue;
			}

			$set = $client->edit_mailbox( $email, array( 'active' => '1' ) );

			if ( is_wp_error( $set ) ) {
				return StepResult::retry( 300, $set->get_error_message() );
			}
		}

		return StepResult::done( array( 'mail_paused_boxes' => array() ) );
	}

	/**
	 * Upgrade only: each limit becomes the larger of what is applied and what the plan now says. A mailbox that has the
	 * old size gets the new one.
	 */
	public function update_plan( Resource $r ): StepResult {
		if ( ! $this->created( $r ) ) {
			return StepResult::done();
		}

		$client  = MailClients::for();
		$domain  = (string) $r->get( MailDomain::META_DOMAIN );
		$limited = $this->domain_rate( $client, $domain );

		if ( is_wp_error( $limited ) ) {
			return StepResult::retry( 300, $limited->get_error_message() );
		}

		$plan    = self::limits_from( (array) $r->get( 'plan' ) );
		$applied = is_array( $r->get( 'mail_limits' ) ) && array() !== $r->get( 'mail_limits' ) ? self::limits_from( (array) $r->get( 'mail_limits' ) ) : $plan;
		$next    = array();

		foreach ( $applied as $key => $value ) {
			$next[ $key ] = max( $value, $plan[ $key ] );
		}

		if ( $next === $applied ) {
			return StepResult::done( array( 'mail_limits' => $applied ), $plan === $applied ? '' : 'Email plan change was lower: limits kept' );
		}

		$edit = $client->edit_domain( $domain, self::domain_attrs( $next ) );

		if ( is_wp_error( $edit ) ) {
			return StepResult::retry( 300, $edit->get_error_message() );
		}

		if ( $next['quota_mb'] > $applied['quota_mb'] ) {
			$boxes = $client->list_mailboxes( $domain );

			if ( is_wp_error( $boxes ) ) {
				return StepResult::retry( 300, $boxes->get_error_message() );
			}

			foreach ( $boxes as $box ) {
				if ( (int) round( (int) ( $box['quota'] ?? 0 ) / 1048576 ) === $applied['quota_mb'] ) {
					$set = $client->edit_mailbox( (string) $box['username'], array( 'quota' => (string) $next['quota_mb'] ) );

					if ( is_wp_error( $set ) ) {
						return StepResult::retry( 300, $set->get_error_message() );
					}
				}
			}
		}

		return StepResult::done( array( 'mail_limits' => $next ), 'Email plan updated' );
	}

	public function delete( Resource $r ): StepResult {
		$domain = (string) $r->get( MailDomain::META_DOMAIN );

		if ( ! $this->created( $r ) || '' === $domain ) {
			MailAccess::purge_all( $r );

			return StepResult::done();
		}

		$client = MailClients::for();
		$boxes  = $client->list_mailboxes( $domain );

		if ( is_wp_error( $boxes ) ) {
			return StepResult::retry( 300, $boxes->get_error_message() );
		}

		foreach ( $boxes as $box ) {
			$gone = $client->delete_mailbox( (string) $box['username'] );

			if ( is_wp_error( $gone ) ) {
				return StepResult::retry( 300, $gone->get_error_message() );
			}
		}

		$aliases = $client->list_aliases( $domain );

		if ( is_wp_error( $aliases ) ) {
			return StepResult::retry( 300, $aliases->get_error_message() );
		}

		foreach ( $aliases as $alias ) {
			$gone = $client->delete_alias( (int) $alias['id'] );

			if ( is_wp_error( $gone ) ) {
				return StepResult::retry( 300, $gone->get_error_message() );
			}
		}

		$removed = $client->delete_domain( $domain );

		if ( is_wp_error( $removed ) && 'domain_invalid' !== (string) ( ( (array) $removed->get_error_data() )['key'] ?? '' ) ) {
			return StepResult::retry( 300, $removed->get_error_message() );
		}

		MailAccess::purge_all( $r );

		return StepResult::done( array(), 'Email domain deleted' );
	}

	public function status( Resource $r ): ?array {
		return array(
			'domain' => (string) $r->get( MailDomain::META_DOMAIN ),
			'mode'   => (string) $r->get( 'mail_mode' ),
			'dns'    => (string) $r->get( 'mail_dns' ),
		);
	}

	public function customer_actions( Resource $r ): array {
		return array();
	}

	public function act( Resource $r, string $action, array $args ): StepResult {
		return StepResult::fail( 'This email action is not available.' );
	}

	public function render_account( Resource $r ): void {
		MailCard::render( $r );
	}

	public function render_admin( Resource $r ): void {
		$limits = MailGuard::limits( $r );
		$rows   = array(
			__( 'Domain', 'beltoft-services' )    => (string) $r->get( MailDomain::META_DOMAIN ),
			__( 'DNS', 'beltoft-services' )       => (string) $r->get( 'mail_mode' ) . ' / ' . (string) $r->get( 'mail_dns' ),
			__( 'Mailboxes', 'beltoft-services' ) => (string) $limits['mailboxes'],
			__( 'Size (MB)', 'beltoft-services' ) => (string) $limits['quota_mb'],
			__( 'Aliases', 'beltoft-services' )   => (string) $limits['aliases'],
		);

		echo '<h3>' . esc_html__( 'Business email', 'beltoft-services' ) . '</h3><table class="widefat striped"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
