<?php
/**
 * Emails and tags: registering them, sending one, and listing them for the screens.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Mailer;

use WP_Error;

/**
 * Emails and tags: registering them, sending one, and listing them for the screens.
 *
 * @since 1.0.0
 */
trait Registered {

	/**
	 * Register a merge tag, now or once the plugin's own are in place.
	 *
	 * @param string               $tag  The tag, without its braces.
	 * @param array<string, mixed> $args See Registry::tag().
	 *
	 * @return bool
	 */
	public function register_tag( string $tag, array $args ): bool {
		return $this->registry->tag( $tag, $args );
	}

	/**
	 * Register an email, now or once the plugin's own are in place.
	 *
	 * @param string               $id   Its name.
	 * @param array<string, mixed> $args See Registry::email().
	 *
	 * @return bool
	 */
	public function register_email( string $id, array $args ): bool {
		return $this->registry->email( $id, $args );
	}

	/**
	 * Take an email away again.
	 *
	 * @param string $id The email.
	 *
	 * @return bool Whether there was one.
	 */
	public function unregister_email( string $id ): bool {
		return $this->registry->forget( $id );
	}

	/**
	 * Send a registered email about one record.
	 *
	 * @param string               $id     The email.
	 * @param mixed                $record What it is about: a row or an id.
	 * @param array<string, mixed> $args   `to`, `subject`, `body`.
	 *
	 * @return true|WP_Error
	 */
	public function send( string $id, mixed $record, array $args = [] ): true|WP_Error {
		return $this->sender->send( $id, $record, $args );
	}

	/**
	 * The emails there are, each with what it would be sent with now.
	 *
	 * @return array<string, array<string, mixed>> By name. Each has `id`,
	 *         `label`, `description`, `context`, `recipient` (`customer`,
	 *         `admin` or `custom`), `enabled`, `setting`, `subject`, `body`.
	 */
	public function emails(): array {
		$this->ready();

		$emails = [];

		foreach ( $this->registry->emails() as $id => $email ) {
			$wording = $this->sender->wording( $id );

			$emails[ $id ] = [
				'id'          => $id,
				'label'       => (string) $email['label'],
				'description' => (string) $email['description'],
				'context'     => (string) $email['context'],
				'recipient'   => is_string( $email['recipient'] ) ? $email['recipient'] : 'custom',
				'enabled'     => $this->sender->enabled( $id ),
				'setting'     => (string) $email['setting'],
				'subject'     => $wording['subject'],
				'body'        => $wording['body'],
			];
		}

		/**
		 * Filter the list of emails.
		 *
		 * What is read from this list, by a screen or an agent. Adding to
		 * it does not make an email: register_email() does.
		 *
		 * @param array<string, array<string, mixed>> $emails By name.
		 */
		return (array) apply_filters( $this->hook( 'emails' ), $emails );
	}

	/**
	 * The merge tags one kind of email can use.
	 *
	 * @param string $context A context's name; the default one when empty.
	 *
	 * @return array<int, array{tag: string, placeholder: string, label: string, description: string, html: bool, group: string}>
	 */
	public function tags( string $context = '' ): array {
		$this->ready();

		$context = '' === $context ? $this->contexts->default() : $context;

		/**
		 * Filter the list of merge tags one kind of email can use.
		 *
		 * What is read from this list, by a screen or an agent. Adding to
		 * it does not make a tag: register_tag() does.
		 *
		 * @param array<int, array<string, mixed>> $tags    The tags.
		 * @param string                           $context What kind of email.
		 */
		return (array) apply_filters( $this->hook( 'email_tags' ), $this->registry->tags( $context ), $context );
	}

	/**
	 * The settings screen's panels: one for every registered email.
	 *
	 * @return array<string, array<string, mixed>> Fields, by setting.
	 */
	public function fields(): array {
		$this->ready();

		return $this->panels->fields();
	}
}
