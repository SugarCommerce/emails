<?php
/**
 * Actions
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Emails\Delivery;
use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Models\Message;
use WP_Error;

/**
 * The Preview and Send a test buttons on an email's panel.
 *
 * Both work from what is in the editor now, saved or not: the point of
 * either is to see a change before making it. And both work on an email that
 * is switched off, which is when somebody is most likely to be getting it
 * ready.
 *
 * Each returns a callable given the editor's inputs and returning what the
 * settings screen shows: `success`, `message`, and for a preview `data.html`.
 */
final class Actions {

	/**
	 * The look's parts, as a card posts them: each under `look_` and its
	 * name.
	 *
	 * @var string[]
	 */
	public const array LOOK = [ 'template', 'color', 'logo', 'logo_height', 'align', 'footer', 'footer_enabled' ];

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * What the Preview button under an email's editor runs.
	 *
	 * @param string $id The email.
	 *
	 * @return callable
	 */
	public function preview( string $id ): callable {
		$mailer = $this->mailer;

		return function ( array $payload ) use ( $id, $mailer ): array {
			$mailer->ready();

			$html = $mailer->look()->trying(
				$this->look( $payload ),
				fn () => Emails::preview( $mailer->group(), $id, $this->overrides( $payload ) )
			);

			if ( is_wp_error( $html ) ) {
				return [
					'success' => false,
					'message' => $html->get_error_message(),
				];
			}

			return [
				'success' => true,
				'message' => $mailer->label( 'preview_note' ),
				'data'    => [ 'html' => $this->frame( (string) $html ) ],
			];
		};
	}

	/**
	 * What the Send a test button runs.
	 *
	 * To whoever pressed it. About the most recent record the email can be
	 * about, when there is one, so the tags are filled in with something
	 * real; with the sample details a preview uses when there is not.
	 *
	 * @param string $id The email.
	 *
	 * @return callable
	 */
	public function test( string $id ): callable {
		$mailer = $this->mailer;

		return function ( array $payload ) use ( $id, $mailer ): array {
			$mailer->ready();

			$to = (string) wp_get_current_user()->user_email;

			if ( ! is_email( $to ) ) {
				return [
					'success' => false,
					'message' => $mailer->label( 'no_test_address' ),
				];
			}

			$overrides = $this->overrides( $payload ) + [ 'to' => $to ];
			$email     = $mailer->registry()->get( $id );
			$about     = null === $email ? 0 : $mailer->contexts()->latest( (string) $email['context'] );

			$sent = $mailer->look()->trying(
				$this->look( $payload ),
				fn () => $about > 0
					? $mailer->sender()->deliver( $id, $overrides + [ 'data' => $about ] )
					: $this->send_sample( $id, $to, $overrides )
			);

			if ( is_wp_error( $sent ) ) {
				return [
					'success' => false,
					'message' => $sent->get_error_message(),
				];
			}

			return [
				'success' => true,
				'message' => sprintf( $mailer->label( 'sent_to' ), $to ),
			];
		};
	}

	/**
	 * The frame a preview is shown in.
	 *
	 * A frame of its own, so the email's styles stay in the email and the
	 * admin's stay out of it.
	 *
	 * Escaped whole, entities and all. esc_attr() leaves an entity it finds
	 * alone, so the &lt; of a customer's name written as text came out of
	 * the attribute as < and the preview drew markup the email would not.
	 *
	 * Sandboxed, without scripts or the admin's origin: an email can carry
	 * HTML a tag or a pasted body put there, and a preview is no place for
	 * it to run as the signed-in admin. Links still open, in a new tab.
	 *
	 * @param string $html The email, a whole page of markup.
	 *
	 * @return string
	 */
	public function frame( string $html ): string {
		return sprintf(
			'<iframe class="sugarcommerce-emails-preview" title="%1$s" sandbox="allow-popups allow-popups-to-escape-sandbox" style="width:100%%;height:600px;border:1px solid #dcdcde;border-radius:4px;background:#fff" srcdoc="%2$s"></iframe>',
			esc_attr( $this->mailer->label( 'preview_title' ) ),
			htmlspecialchars( $html, ENT_QUOTES, 'UTF-8', true )
		);
	}

	/**
	 * What an editor posted, as the changes to make to the email.
	 *
	 * Whatever is in the card now is what is drawn, saved or not: the
	 * subject, the heading and the body. A part left empty is drawn as it
	 * would be sent -- from what was saved, and the email's own wording
	 * where nothing was. Nothing here is written anywhere.
	 *
	 * What a preview is handed straight from the editor never went through
	 * the settings screen, which is what puts the paragraphs back in a
	 * saved body -- so they are put back here.
	 *
	 * @param array<string, mixed> $payload The editor's inputs.
	 *
	 * @return array<string, mixed> Any of `subject`, `content`, `context`.
	 */
	public function overrides( array $payload ): array {
		$overrides = [];

		$subject = trim( sanitize_text_field( (string) ( $payload['subject'] ?? '' ) ) );
		$heading = trim( sanitize_text_field( (string) ( $payload['heading'] ?? '' ) ) );
		$body    = trim( wp_kses_post( (string) ( $payload['body'] ?? '' ) ) );

		if ( '' !== $subject ) {
			$overrides['subject'] = $subject;
		}

		if ( '' !== $heading ) {
			$overrides['context'] = [ 'title' => $heading ];
		}

		if ( '' !== $body ) {
			$overrides['content'] = wpautop( $body );
		}

		return $overrides;
	}

	/**
	 * The look on the screen, from what a button posted with its card.
	 *
	 * The Look card's script sends the card's controls with every Preview
	 * and Send a test, each under a `look_` name, so a card is previewed in
	 * the design and colour that are on the screen and not in the ones last
	 * saved. A request without them -- another screen, a test -- tries
	 * nothing, and the saved look is used.
	 *
	 * @param array<string, mixed> $payload The inputs.
	 *
	 * @return array<string, mixed> For Look::trying().
	 */
	public function look( array $payload ): array {
		$look = [];

		foreach ( self::LOOK as $part ) {
			if ( array_key_exists( 'look_' . $part, $payload ) ) {
				$look[ $part ] = is_scalar( $payload[ 'look_' . $part ] ) ? (string) $payload[ 'look_' . $part ] : '';
			}
		}

		// The card's own design, when it has the control for one.
		if ( array_key_exists( 'template', $payload ) ) {
			$look['own_template'] = sanitize_key( (string) $payload['template'] );
		}

		// A heading that is on the card and empty: the saved one is not
		// what is on the screen.
		if ( array_key_exists( 'heading', $payload ) && '' === trim( (string) $payload['heading'] ) ) {
			$look['no_heading'] = true;
		}

		return $look;
	}

	/**
	 * Send the preview itself, for a site with nothing yet to write about.
	 *
	 * @param string               $id        The email.
	 * @param string               $to        Who to.
	 * @param array<string, mixed> $overrides What the editor holds.
	 *
	 * @return true|WP_Error
	 */
	private function send_sample( string $id, string $to, array $overrides ): true|WP_Error {
		$html = Emails::preview( $this->mailer->group(), $id, $overrides );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$sender  = $this->mailer->sender()->identity();
		$message = new Message(
			to: [ $to ],
			subject: '' !== (string) ( $overrides['subject'] ?? '' ) ? (string) $overrides['subject'] : $this->mailer->label( 'test_subject' ),
			html: (string) $html,
			from_email: $sender['address'],
			from_name: $sender['name'],
			reply_to: $sender['reply_to'],
		);

		// The same road as the email itself: through the mailer's transport,
		// so a test of an email sent through an API is sent through it too.
		$result = Delivery::send( $message, $this->mailer->transport() );

		return $result->sent ? true : new WP_Error( $this->mailer->hook( 'test_failed' ), '' !== $result->error ? $result->error : $this->mailer->label( 'mail_failed' ) );
	}
}
