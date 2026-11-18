<?php
/**
 * Panels
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
 * Every registered email's editor, as fields for a settings screen.
 *
 * A panel per email, made from what the email was registered with, so
 * another plugin's email has the switch, the subject, the body with Add Tag,
 * the Preview and the Send a test the plugin's own have, and nobody edits
 * the settings screen to give it them.
 *
 * The fields are in the shape wp-register-setting-fields takes, with the
 * `email_editor` field wp-field-kit draws.
 */
final class Panels {

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * The panels, in the order the emails were registered.
	 *
	 * @return array<string, array<string, mixed>> Fields, by setting.
	 */
	public function fields(): array {
		$fields = [];

		foreach ( $this->mailer->registry()->emails() as $id => $email ) {
			if ( false === $email['panel'] ) {
				continue;
			}

			// An email with controls nobody else has -- how often, say --
			// draws its own.
			if ( true !== $email['panel'] && is_callable( $email['panel'] ) ) {
				$fields += (array) call_user_func( $email['panel'], $id, $email );

				continue;
			}

			$fields[ (string) $email['setting'] ] = $this->field( $id, $email );
		}

		return $fields;
	}

	/**
	 * An email's own wording, as its editor's starting point.
	 *
	 * @param string $id The email.
	 *
	 * @return array{subject: string, body: string}
	 */
	public function defaults( string $id ): array {
		$this->mailer->ready();

		$email = $this->mailer->registry()->get( $id ) ?? [];

		return [
			'subject' => (string) ( $email['subject'] ?? '' ),
			'body'    => (string) ( $email['body'] ?? '' ),
		];
	}

	/**
	 * Put an email's own wording into any part of its setting left blank.
	 *
	 * A blank subject or body is sent as the default, so a blank editor was
	 * showing something other than what would go out -- and gave nobody
	 * anything to start editing from. Filled in, the editor shows the email.
	 *
	 * Run it before the settings screen draws.
	 *
	 * @return void
	 */
	public function fill_blanks(): void {
		$this->mailer->ready();

		$store    = $this->mailer->store();
		$registry = $this->mailer->registry();

		foreach ( $registry->emails() as $id => $email ) {
			// One that draws its own panel, or has none, looks after itself.
			if ( true !== $email['panel'] ) {
				continue;
			}

			$setting = (string) $email['setting'];
			$stored  = $store->saved( $setting );

			// Never saved: the field's own default covers it.
			if ( null === $stored ) {
				continue;
			}

			$changed = false;

			foreach ( $this->defaults( $id ) as $part => $text ) {
				if ( '' === trim( wp_strip_all_tags( (string) ( $stored[ $part ] ?? '' ) ) ) && '' !== $text ) {
					$stored[ $part ] = $text;
					$changed         = true;
				}
			}

			// A body that is still what the email used to ship was put
			// there by this method and never edited. It is brought up to
			// the wording as it is now, so the editor shows what is sent.
			if ( $registry->untouched( $id, (string) ( $stored['body'] ?? '' ) ) ) {
				$stored['body'] = $this->defaults( $id )['body'];
				$changed        = true;
			}

			// A setting saved before panels had a switch has none in it,
			// and is sent. Writing it back through the screen would save
			// the missing switch as off, so it is written down as what it
			// has been all along.
			if ( $changed && ! array_key_exists( 'enabled', $stored ) ) {
				$stored['enabled'] = empty( $email['enabled'] ) ? 0 : 1;
			}

			if ( $changed ) {
				$store->set( $setting, $stored );
			}
		}
	}

	/**
	 * What a card's header says while it is folded, and that it is.
	 *
	 * A tab of emails is a list first: each email's name, whether it is
	 * switched on, who it goes to and a line saying when. The editor is
	 * inside, for the one somebody came to change -- every card open is a
	 * tab four screens long.
	 *
	 * For an email that draws its own panel, too, so it reads like the
	 * others.
	 *
	 * @param array<string, mixed> $email What the email was registered with:
	 *                                    `description` and `recipient`.
	 *
	 * @return array<string, mixed> Field configuration, to merge in.
	 */
	public function header( array $email ): array {
		$who = $email['recipient'] ?? 'customer';

		return [
			'collapsed'   => true,
			'summary'     => (string) ( $email['description'] ?? '' ),

			// Follows the card's own switch, so it says Off the moment the
			// switch is thrown and not only after a save.
			'status'      => [
				'field' => 'enabled',
				'on'    => $this->mailer->label( 'on' ),
				'off'   => $this->mailer->label( 'off' ),
			],

			// Only for the two it can be said of. An email with a
			// recipient worked out in code goes to whoever that says.
			'meta'        => 'admin' === $who
				? $this->mailer->label( 'to_admin' )
				: ( 'customer' === $who ? $this->mailer->label( 'to_customer' ) : '' ),

			// The Look card's footer travels with a Preview, and is lines
			// of text: sent as plain text it would arrive as one.
			'action_rich' => [ 'look_footer' ],
		];
	}

	/**
	 * The control for an email's own design.
	 *
	 * @return array<string, mixed>
	 */
	public function design_part(): array {
		return [
			'type'    => 'select',
			'label'   => $this->mailer->label( 'design' ),
			'default' => '',
			'options' => [ '' => $this->mailer->label( 'design_shared' ) ] + array_map(
				static fn( array $template ): string => $template['label'],
				$this->mailer->look()->templates()
			),
			'tooltip' => $this->mailer->label( 'design_tip' ),
		];
	}

	/**
	 * The tags an editor offers, in the shape the editor wants them: what
	 * each is called, how it is written, and the line that says what it is.
	 *
	 * @param string $context What kind of email the editor is for.
	 *
	 * @return array<int, array{name: string, tag: string, description: string, group: string}>
	 */
	public function editor_tags( string $context = '' ): array {
		$tags = array_map(
			static fn( array $tag ): array => [
				'name'        => (string) ( $tag['label'] ?? '' ),
				'tag'         => (string) ( $tag['placeholder'] ?? '' ),
				'description' => (string) ( $tag['description'] ?? '' ),

				// The heading the list puts it under. An editor that does
				// not draw headings ignores it, and the order below still
				// keeps each group together.
				'group'       => (string) ( $tag['group'] ?? '' ),
			],
			$this->mailer->tags( $context )
		);

		// One heading last, whenever its tags were registered: the tags in
		// every email's list -- the site's own name and address -- and not
		// what an email is about, which is what somebody opens the list for.
		$last = (string) $this->mailer->config( 'last_tag_group', '' );

		if ( '' === $last ) {
			return array_values( $tags );
		}

		return array_merge(
			array_values( array_filter( $tags, static fn( array $tag ): bool => $last !== $tag['group'] ) ),
			array_values( array_filter( $tags, static fn( array $tag ): bool => $last === $tag['group'] ) )
		);
	}

	/**
	 * The editor for one email.
	 *
	 * Starts from the email's own wording, so there is something to edit and
	 * what is on screen is what would be sent. Offers the merge tags that
	 * email can use, a preview with sample details in it, and a test sent to
	 * whoever asks.
	 *
	 * @param string               $id    The email.
	 * @param array<string, mixed> $email What it was registered with.
	 *
	 * @return array<string, mixed>
	 */
	private function field( string $id, array $email ): array {
		$to_site = 'admin' === $email['recipient'];
		$tags    = $this->editor_tags( (string) $email['context'] );
		$panel   = (array) $this->mailer->config( 'panel', [] );

		$default = [ 'enabled' => empty( $email['enabled'] ) ? 0 : 1 ];
		$parts   = [
			'enabled' => [
				'type'    => 'toggle',
				'label'   => $this->mailer->label( 'enabled' ),
				'default' => $default['enabled'],
			],
		];

		// Only an email to the site can be pointed somewhere else. One to
		// a customer goes to the customer, and a box that did nothing
		// would be worse than no box.
		if ( $to_site ) {
			$default['recipient'] = '';
			$parts['recipient']   = [
				'type'        => 'text',
				'label'       => $this->mailer->label( 'send_to' ),
				'placeholder' => $this->mailer->sender()->admin_email(),
				'class'       => 'regular-text',
				'tooltip'     => $this->mailer->label( 'send_to_tip' ),
			];
		}

		$default += [
			'subject' => (string) $email['subject'],
			'heading' => '',
			'body'    => (string) $email['body'],
		];

		$parts += [
			'subject' => [
				'type'        => 'text',
				'label'       => $this->mailer->label( 'subject' ),
				'class'       => 'large-text',

				// The wording it is sent with while the box is empty.
				'placeholder' => (string) $email['subject'],
				'tooltip'     => $this->mailer->label( 'subject_tip' ),
			],
			'heading' => [
				'type'        => 'text',
				'label'       => $this->mailer->label( 'heading' ),
				'class'       => 'large-text',
				'placeholder' => $this->mailer->label( 'heading_empty' ),
				'tooltip'     => $this->mailer->label( 'heading_tip' ),
			],
			'body'    => [
				'type'    => 'wysiwyg',
				'label'   => $this->mailer->label( 'body' ),
				'tags'    => $tags,

				// In pixels, because the card starts folded and an editor
				// that is out of sight cannot measure its own rows.
				'height'  => 260,
				'tooltip' => $this->mailer->label( 'body_tip' ),
			],
		];

		// Which design, for the email that should not look like the
		// others: a note to the site in something plainer, say. Last and
		// quiet, because most sites want all their emails to match.
		$default['template'] = '';
		$parts['template']   = $this->design_part();

		return [
			'type'    => 'email_editor',
			'label'   => (string) $email['label'],
			'tab'     => (string) ( $panel['tab'] ?? 'emails' ),
			'section' => (string) ( $panel['section'] ?? 'transactional' ),
			'default' => $default,
			'fields'  => $parts,
			...$this->header( $email ),

			// A list, not a group name. Given the name of the group the
			// editor finds nothing to offer and draws no Add Tag button.
			'tags'    => $tags,
			'actions' => [
				'preview' => $this->mailer->actions()->preview( $id ),
				'test'    => $this->mailer->actions()->test( $id ),
			],
		];
	}
}
