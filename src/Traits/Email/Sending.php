<?php
/**
 * Sending: what is wrong with it, the message it makes, and handing that to the transport.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Email;

use SugarCommerce\Emails\Delivery;
use SugarCommerce\Emails\Models\Message;
use SugarCommerce\Emails\Models\Result;
use SugarCommerce\Emails\Utils\Runtime;
use WP_Error;

/**
 * Sending: what is wrong with it, the message it makes, and handing that to the transport.
 *
 * @since 1.0.0
 */
trait Sending {

	/**
	 * What went wrong filling the tags in.
	 *
	 * @return array<string, string>
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * The tags the last build met that nobody registered.
	 *
	 * Taken out rather than sent, and not a failure -- an email may mention
	 * a tag from a group it did not name -- but the usual cause is a typo
	 * and the usual symptom a gap in a sentence. Worth asserting empty
	 * across every email in a test.
	 *
	 * @return string[]
	 */
	public function unknown(): array {
		return $this->unknown;
	}

	/**
	 * How the last send went: the provider's message id, or why not and
	 * whether trying again could help. Null before a send.
	 *
	 * @return Result|null
	 */
	public function result(): ?Result {
		return $this->result;
	}

	/**
	 * The finished email as a transport is handed it.
	 *
	 * For a queue that sends later, or a test that wants to look. Builds it,
	 * so problems() and unknown() are about this build.
	 *
	 * @return Message
	 */
	public function message(): Message {
		$html = $this->html();

		return new Message(
			to: $this->to,
			subject: $this->resolved_subject,
			html: $html,
			text: $this->text,
			from_email: $this->from[0],
			from_name: $this->from[1],
			reply_to: $this->reply[0],
			reply_to_name: $this->reply[1],
			cc: $this->cc,
			bcc: $this->bcc,
			headers: $this->headers,
			attachments: $this->attachments,
			metadata: $this->metadata,
			tag: $this->tag,
		);
	}

	/**
	 * Send it.
	 *
	 * @return true|WP_Error
	 */
	public function send(): true|WP_Error {
		$this->result = null;

		if ( [] === $this->to ) {
			return new WP_Error( 'no_recipient', __( 'The email has nobody to go to.', 'sugarcommerce' ) );
		}

		$message = $this->message();

		// A tag that could not be filled in leaves a gap in a sentence, and
		// the sentence is usually the one with the amount in it.
		if ( [] !== $this->problems ) {
			return new WP_Error(
				'tags_failed',
				sprintf(
					/* translators: %s: what went wrong, one per tag. */
					__( 'The email was not sent because some of it could not be filled in: %s', 'sugarcommerce' ),
					implode( ' ', $this->problems )
				),
				[ 'problems' => $this->problems ]
			);
		}

		$this->result = Delivery::send( $message, $this->transport );

		/**
		 * Fires after an email has been handed over.
		 *
		 * @param bool     $sent    Whether it went.
		 * @param string[] $to      Who to.
		 * @param string   $subject What it says it is about.
		 * @param Result   $result  How it went, in full.
		 */
		do_action( Runtime::hook( 'sent' ), $this->result->sent, $this->to, $this->resolved_subject, $this->result );

		return $this->result->to_wp();
	}
}
