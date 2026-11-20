<?php
/**
 * Labels: the words the screens and errors use, in the plugin's own when it gave any.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Mailer;

/**
 * Labels: the words the screens and errors use, in the plugin's own when it gave any.
 *
 * @since 1.0.0
 */
trait Labels {

	/**
	 * Something the screen or an error says, as the plugin wrote it or as
	 * the library would.
	 *
	 * @param string $key Which.
	 *
	 * @return string
	 */
	public function label( string $key ): string {
		$given = ( (array) $this->config( 'labels', [] ) )[ $key ] ?? null;

		return is_string( $given ) ? $given : ( self::labels()[ $key ] ?? '' );
	}

	/**
	 * The words the library uses where a plugin has not given its own.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			// Errors.
			/* translators: %s: the name an email was registered with. */
			'unknown_email'      => __( 'There is no email called %s.', 'sugarcommerce' ),
			'no_record'          => __( 'What that email is about could not be found.', 'sugarcommerce' ),
			'no_recipient'       => __( 'There is no email address to send that to.', 'sugarcommerce' ),
			/* translators: 1: the name of an email context, e.g. "order". 2: a filter's name. */
			'unknown_context'    => __( 'There is no email context called "%1$s". Add it with the %2$s filter first.', 'sugarcommerce' ),
			'mail_failed'        => __( 'WordPress could not send the email.', 'sugarcommerce' ),

			// An email's panel.
			'enabled'            => __( 'Send this email', 'sugarcommerce' ),
			'send_to'            => __( 'Send to', 'sugarcommerce' ),
			'send_to_tip'        => __( 'Separate several addresses with commas. Left empty, it goes to your site’s email address.', 'sugarcommerce' ),
			'subject'            => __( 'Subject', 'sugarcommerce' ),
			'subject_tip'        => __( 'What they see in their inbox. Merge tags work here as well as in the body.', 'sugarcommerce' ),
			'heading'            => __( 'Heading', 'sugarcommerce' ),
			'heading_empty'      => __( 'The same as the subject', 'sugarcommerce' ),
			'heading_tip'        => __( 'A title shown above the wording. Leave it empty to use the subject.', 'sugarcommerce' ),
			'body'               => __( 'Body', 'sugarcommerce' ),
			'body_tip'           => __( 'The email itself. Add Tag puts in a detail that is filled in for each one sent, like the customer’s name.', 'sugarcommerce' ),
			'on'                 => __( 'On', 'sugarcommerce' ),
			'off'                => __( 'Off', 'sugarcommerce' ),
			'to_admin'           => __( 'To you', 'sugarcommerce' ),
			'to_customer'        => __( 'To the customer', 'sugarcommerce' ),
			'design'             => __( 'Design', 'sugarcommerce' ),
			'design_shared'      => __( 'The same as your other emails', 'sugarcommerce' ),
			'design_tip'         => __( 'Your emails share one design, chosen under Look at the top of this page. Pick another here only if this email should stand apart.', 'sugarcommerce' ),

			// Its buttons.
			'preview_note'       => __( 'This is how it will look, with sample details in place of real ones.', 'sugarcommerce' ),
			'no_test_address'    => __( 'Your account has no email address to send a test to.', 'sugarcommerce' ),
			/* translators: %s: an email address. */
			'sent_to'            => __( 'Sent to %s.', 'sugarcommerce' ),
			'test_subject'       => __( 'Test email', 'sugarcommerce' ),
			'preview_title'      => __( 'Email preview', 'sugarcommerce' ),

			// The Look card.
			'look'               => __( 'Look', 'sugarcommerce' ),
			'look_description'   => __( 'How your emails look: design, colour, logo and footer.', 'sugarcommerce' ),
			'colour'             => __( 'Your colour', 'sugarcommerce' ),
			'colour_tip'         => __( 'Used for the top of the email and for links. With a pale colour the words on top of it are made dark, so they can still be read.', 'sugarcommerce' ),
			'align'              => __( 'Heading and logo', 'sugarcommerce' ),
			'align_left'         => __( 'On the left', 'sugarcommerce' ),
			'align_center'       => __( 'In the middle', 'sugarcommerce' ),
			'logo'               => __( 'Logo', 'sugarcommerce' ),
			'logo_choose'        => __( 'Choose a Logo', 'sugarcommerce' ),
			'logo_tip'           => __( 'Shown at the top of every email. Leave it out and the email starts with its heading.', 'sugarcommerce' ),
			'logo_height'        => __( 'Logo size', 'sugarcommerce' ),
			'logo_small'         => __( 'Small', 'sugarcommerce' ),
			'logo_medium'        => __( 'Medium', 'sugarcommerce' ),
			'logo_large'         => __( 'Large', 'sugarcommerce' ),
			'footer_enabled'     => __( 'Show a footer', 'sugarcommerce' ),
			'footer'             => __( 'Footer text', 'sugarcommerce' ),
			'footer_placeholder' => __( 'Your business name and address', 'sugarcommerce' ),
			'footer_description' => __( 'Many countries require a business address on emails to customers.', 'sugarcommerce' ),
			'footer_tip'         => __( 'Small print under every email. Leave it empty to use the default, or type your own. You can use {site_name} and {year}.', 'sugarcommerce' ),
			'look_preview'       => __( 'How your emails will look', 'sugarcommerce' ),
			'look_drawing'       => __( 'Drawing a preview…', 'sugarcommerce' ),
			'look_failed'        => __( 'The preview could not be drawn. Try again in a moment.', 'sugarcommerce' ),
		];
	}
}
