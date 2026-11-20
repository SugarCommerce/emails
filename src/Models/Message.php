<?php
/**
 * Message
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Models;

/**
 * An email that is finished and ready to leave: what a transport is handed.
 *
 * Everything is already filled in and checked by the time one of these is
 * made -- the tags, the template, the header values without line breaks --
 * so a transport only has to say it to whoever delivers it. `wp_mail()`
 * takes header lines; an API takes the same things as fields. Both are
 * here, so neither has to parse the other's.
 *
 * Attachments are paths, as wp_mail() takes them. A transport that sends
 * through an API reads the files itself.
 */
final class Message {

	/**
	 * The plain-text part, worked out from the HTML when none was given.
	 *
	 * @var string
	 */
	public readonly string $text;

	/**
	 * Construct.
	 *
	 * @param string[]              $to            Who it goes to.
	 * @param string                $subject       What it is about, on one line.
	 * @param string                $html          The whole HTML document.
	 * @param string                $text          The plain-text part. Empty
	 *                                             to work it out from the HTML.
	 * @param string                $from_email    Who it is from, or empty for
	 *                                             whoever the transport sends as.
	 * @param string                $from_name     Their name.
	 * @param string                $reply_to      Where a reply goes, or empty.
	 * @param string                $reply_to_name Their name.
	 * @param string[]              $cc            Who else gets it.
	 * @param string[]              $bcc           Who else gets it, quietly.
	 * @param array<string, string> $headers       Headers of the sender's own, by name.
	 * @param string[]              $attachments   Paths of files to attach.
	 * @param array<string, string> $metadata      For the provider to send back
	 *                                             with a bounce or a complaint:
	 *                                             `order_id => 1234`. Postmark's
	 *                                             Metadata, Resend's tags.
	 * @param string                $tag           What kind of email it is, for
	 *                                             the provider's reports:
	 *                                             `receipt`. Postmark's Tag.
	 */
	public function __construct(
		public readonly array $to,
		public readonly string $subject,
		public readonly string $html,
		string $text = '',
		public readonly string $from_email = '',
		public readonly string $from_name = '',
		public readonly string $reply_to = '',
		public readonly string $reply_to_name = '',
		public readonly array $cc = [],
		public readonly array $bcc = [],
		public readonly array $headers = [],
		public readonly array $attachments = [],
		public readonly array $metadata = [],
		public readonly string $tag = '',
	) {
		$this->text = '' !== $text ? $text : self::text_of( $html );
	}

	/**
	 * The same message with some of it changed.
	 *
	 * @param array<string, mixed> $changes By constructor argument: `to`,
	 *                                      `subject`, `headers` and so on.
	 *
	 * @return self
	 */
	public function with( array $changes ): self {
		$now = get_object_vars( $this );

		// Worked out from the HTML unless it was given, so a change to the
		// HTML brings a new one with it.
		if ( array_key_exists( 'html', $changes ) && ! array_key_exists( 'text', $changes ) ) {
			$now['text'] = '';
		}

		return new self( ...array_merge( $now, $changes ) );
	}

	/**
	 * The headers as wp_mail() takes them, one line each.
	 *
	 * @param string $charset The character set the HTML is in.
	 *
	 * @return string[]
	 */
	public function lines( string $charset = 'UTF-8' ): array {
		$lines = [ 'Content-Type: text/html; charset=' . $charset ];

		// Not quoted: wp_mail() reads the name off these lines itself, and
		// PHPMailer quotes and encodes it. See Address::for_wp_mail().
		if ( '' !== $this->from_email ) {
			$lines[] = 'From: ' . Address::format_for_wp_mail( $this->from_email, $this->from_name, false );
		}

		if ( '' !== $this->reply_to ) {
			$lines[] = 'Reply-To: ' . Address::format_for_wp_mail( $this->reply_to, $this->reply_to_name );
		}

		foreach ( $this->cc as $address ) {
			$lines[] = 'Cc: ' . $address;
		}

		foreach ( $this->bcc as $address ) {
			$lines[] = 'Bcc: ' . $address;
		}

		foreach ( $this->headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}

	/**
	 * Somebody's address as a header writes it: `"Name" <address>`, or the
	 * address alone.
	 *
	 * The name is quoted, so `Acme, Inc.` stays one sender for an API that
	 * reads the header -- unquoted, Postmark and Resend read a comma as the
	 * start of a second address and refuse the email.
	 *
	 * @param string $email The address.
	 * @param string $name  Their name.
	 *
	 * @return string
	 */
	public static function address( string $email, string $name = '' ): string {
		return Address::format( $email, $name );
	}

	/**
	 * What an HTML email says, as plain text.
	 *
	 * The text part a mail client shows when it will not show HTML, and
	 * that a spam filter expects to find beside it. Rough, and better than
	 * none: links keep where they go -- `Download (https://...)` -- which is
	 * the part stripping the tags loses, and losing it guts an email whose
	 * whole point is a download link. Parentheses and not angle brackets,
	 * which the next step would take for a tag.
	 *
	 * @param string $html The email.
	 *
	 * @return string
	 */
	public static function text_of( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$text = (string) preg_replace( '@<(head|style|script|title)\b[^>]*>.*?</\1>@is', '', $html );
		$text = (string) preg_replace( '@<!--.*?-->@s', '', $text );

		// What the email hides, the preheader among it: it is for the inbox
		// list, and in a text client it would read as the first line twice.
		$text = (string) preg_replace( '@<(div|span)\b[^>]*display:\s*none[^>]*>.*?</\1>@is', '', $text );

		// A link whose words are its address says it once.
		$text = (string) preg_replace_callback(
			'/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
			static function ( array $link ): string {
				$words = trim( strip_tags( $link[2] ) );

				if ( '' === $words || html_entity_decode( $words, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === html_entity_decode( $link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) {
					return $link[1];
				}

				return $words . ' (' . $link[1] . ')';
			},
			$text
		);

		$text = (string) preg_replace( '@<(br|/p|/div|/tr|/h[1-6]|/li|/table)\b[^>]*>@i', "\n", $text );

		// Cells side by side, with room between them.
		$text = (string) preg_replace( '@</t[dh]>@i', ' ', $text );
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}
}
