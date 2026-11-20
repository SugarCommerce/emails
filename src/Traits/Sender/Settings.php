<?php
/**
 * Settings: whether an email is on, and its saved subject, heading and body.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Sender;

use SugarCommerce\Emails\Address;

/**
 * Settings: whether an email is on, and its saved subject, heading and body.
 *
 * @since 1.0.0
 */
trait Settings {

	/**
	 * Whether the site has an email switched on.
	 *
	 * What the switch on its panel was saved as; until it has been saved,
	 * what the email was registered with.
	 *
	 * @param string $id The email.
	 *
	 * @return bool
	 */
	public function enabled( string $id ): bool {
		$email = $this->mailer->registry()->get( $id );

		if ( null === $email ) {
			return false;
		}

		$saved = $this->mailer->store()->saved( (string) $email['setting'] );

		return null !== $saved && array_key_exists( 'enabled', $saved )
			? (bool) $saved['enabled']
			: (bool) $email['enabled'];
	}

	/**
	 * What the site owner has written for an email, as Emails reads it.
	 *
	 * Without `enabled`, which Emails would act on by quietly not sending.
	 * Whether an email goes is decided in send(), so that a test can still
	 * be sent from one that is switched off.
	 *
	 * @param string $id The email.
	 *
	 * @return array<string, mixed> `template` and `context` always; `to`,
	 *                              `subject` and `content` when written.
	 */
	public function stored( string $id ): array {
		$email = $this->mailer->registry()->get( $id );

		if ( null === $email ) {
			return [];
		}

		// The look first: the design the email is drawn in and the colour,
		// logo and footer the design is filled in with. The same for every
		// way an email is built, so a preview is what is sent.
		$stored = $this->mailer->look()->settings( $id );
		$saved  = $this->mailer->store()->saved( (string) $email['setting'] );

		if ( null === $saved ) {
			return $stored;
		}

		// Several addresses, separated by commas, which is what the box
		// says beside it -- and each may be written `Name <address>`, whose
		// name may have a comma of its own inside quotes.
		$to = array_filter( array_map( static fn( Address $address ): string => sanitize_email( $address->email ), Address::list( (string) ( $saved['recipient'] ?? '' ) ) ) );

		if ( [] !== $to ) {
			$stored['to'] = array_values( $to );
		}

		if ( '' !== trim( (string) ( $saved['subject'] ?? '' ) ) ) {
			$stored['subject'] = (string) $saved['subject'];
		}

		// The editor keeps a body the way WordPress keeps a post, without
		// its paragraphs; wpautop() puts them back.
		//
		// Unless it is wording the email shipped with earlier and nobody
		// has touched: then it was never the site's own, and the email's
		// wording as it is now is what goes. See Registry::untouched().
		if ( '' !== trim( (string) ( $saved['body'] ?? '' ) ) && ! $this->mailer->registry()->untouched( $id, (string) $saved['body'] ) ) {
			$stored['content'] = wpautop( (string) $saved['body'] );
		}

		// The heading is the template's title, which sits above the body.
		// Unless a preview is of a card whose heading has just been
		// emptied: then the saved one is not what is on the screen.
		if ( '' !== trim( (string) ( $saved['heading'] ?? '' ) ) && ! $this->mailer->look()->tried( 'no_heading' ) ) {
			$stored['context']['title'] = (string) $saved['heading'];
		}

		return $stored;
	}

	/**
	 * The subject and body an email would be sent with now.
	 *
	 * @param string $id The email.
	 *
	 * @return array{subject: string, body: string}
	 */
	public function wording( string $id ): array {
		$email  = $this->mailer->registry()->get( $id ) ?? [];
		$stored = $this->stored( $id );

		return [
			'subject' => (string) ( $stored['subject'] ?? $email['subject'] ?? '' ),
			'body'    => (string) ( $stored['content'] ?? $email['body'] ?? '' ),
		];
	}
}
