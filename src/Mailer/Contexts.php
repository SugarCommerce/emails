<?php
/**
 * Contexts
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Emails\Mailer;

/**
 * The kinds of record an email is written about, and how each is found.
 *
 * Every merge tag in a group is handed the same thing. Order tags read an
 * order and licence tags a licence, and mixing them would mean each tag
 * guessing which it had been given -- so an email says what it is about, and
 * that decides both the tags its editor offers and the record its tags are
 * handed.
 *
 * A context is three things:
 *
 *     'order' => [
 *         'group'  => 'myshop',               // where its tags are kept
 *         'load'   => 'myshop_get_order',     // an id in, the record out
 *         'latest' => 'myshop_latest_order',  // an id for a test, or 0
 *     ],
 *
 * `group` is the mailer's own group when left out. The list is filtered with
 * `{prefix}_email_contexts`, so another plugin with records of its own adds
 * one and its emails and tags name it.
 */
final class Contexts {

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * Every context, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$contexts = [];

		foreach ( (array) $this->mailer->config( 'contexts', [] ) as $name => $context ) {
			if ( is_array( $context ) ) {
				$contexts[ (string) $name ] = $context + [ 'group' => $this->mailer->group() ];
			}
		}

		/**
		 * Filter the kinds of thing an email can be about.
		 *
		 *     $contexts['booking'] = [
		 *         'group'  => 'myaddon_booking',
		 *         'load'   => 'myaddon_get_booking',
		 *         'latest' => 'myaddon_latest_booking_id',
		 *     ];
		 *
		 * @param array<string, array<string, mixed>> $contexts By name.
		 */
		return (array) apply_filters( $this->mailer->hook( 'email_contexts' ), $contexts );
	}

	/**
	 * The context an email or a tag is about when it does not say.
	 *
	 * @return string
	 */
	public function default(): string {
		$named = (string) $this->mailer->config( 'default_context', '' );

		if ( '' !== $named ) {
			return $named;
		}

		return (string) ( array_key_first( (array) $this->mailer->config( 'contexts', [] ) ) ?? '' );
	}

	/**
	 * One context, or null for a name nobody registered.
	 *
	 * @param string $context Its name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $context ): ?array {
		$found = $this->all()[ $context ] ?? null;

		return is_array( $found ) && '' !== (string) ( $found['group'] ?? '' ) ? $found : null;
	}

	/**
	 * The group a context's merge tags are kept in.
	 *
	 * @param string $context Its name.
	 *
	 * @return string Empty for a context nobody registered.
	 */
	public function group( string $context ): string {
		return (string) ( $this->get( $context )['group'] ?? '' );
	}

	/**
	 * The record an email is about, from whatever the sender passed.
	 *
	 * A row is taken as it is, so the tags of one email share the row it
	 * was sent with and do not each look it up again. An id -- or nothing,
	 * for a context that has an answer to nothing -- is handed to the
	 * context to find.
	 *
	 * @param string $context The context.
	 * @param mixed  $data    A row, an id, or nothing.
	 *
	 * @return mixed The record, or null when there is none.
	 */
	public function load( string $context, mixed $data ): mixed {
		if ( is_object( $data ) || ( is_array( $data ) && [] !== $data ) ) {
			return $data;
		}

		$found = $this->get( $context );

		if ( null === $found || ! is_callable( $found['load'] ?? null ) ) {
			return null;
		}

		$record = call_user_func( $found['load'], is_numeric( $data ) ? (int) $data : $data );

		return empty( $record ) ? null : $record;
	}

	/**
	 * Something real for a test email to be about, when there is anything.
	 *
	 * @param string $context The context.
	 *
	 * @return int An id, or 0.
	 */
	public function latest( string $context ): int {
		$found = $this->get( $context );

		return null !== $found && is_callable( $found['latest'] ?? null )
			? (int) call_user_func( $found['latest'] )
			: 0;
	}
}
