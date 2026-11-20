<?php
/**
 * Rendering: the email as the HTML a mail client is sent.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Email;

use SugarCommerce\Emails\Render\Processor;
use SugarCommerce\Emails\Templates;
use SugarCommerce\Text\Display\Color;

/**
 * Rendering: the email as the HTML a mail client is sent.
 *
 * The content and its tags processed, wrapped in the chosen template with
 * the site's look, and made into a whole document.
 *
 * @since 1.0.0
 */
trait Rendering {

	/**
	 * The finished email.
	 *
	 * @return string
	 */
	public function html(): string {
		$processor = new Processor( $this->groups );

		// The subject takes the same tags as the body — `Your order from
		// {site_name}` is the first example in the README — and used to be
		// sent as typed, because only the body was filled in. It is a
		// header: what a tag returns goes in as words, on one line.
		$this->resolved_subject = self::one_line( $processor->plain( $this->subject, $this->data ) );

		// Kept before each run, because a run starts a fresh report.
		$problems = $processor->problems();
		$unknown  = $processor->unknown();

		$preheader = self::one_line( $processor->plain( $this->preheader, $this->data ) );
		$problems  = array_merge( $problems, $processor->problems() );
		$unknown   = array_merge( $unknown, $processor->unknown() );

		$body = $processor->process( $this->content, $this->data );

		$this->problems = array_merge( $problems, $processor->problems() );
		$this->unknown  = array_values( array_unique( array_merge( $unknown, $processor->unknown() ) ) );

		return $this->wrap( $body, $this->resolved_subject, $preheader );
	}

	/**
	 * The finished email, with samples where the real values would be.
	 *
	 * @return string
	 */
	public function preview(): string {
		$processor = new Processor( $this->groups );

		return $this->wrap(
			$processor->preview( $this->content ),
			self::one_line( $processor->preview( $this->subject ) ),
			self::one_line( wp_strip_all_tags( $processor->preview( $this->preheader ) ) )
		);
	}

	/**
	 * Put the body inside the visual template.
	 *
	 * @param string $body      The body, with its tags filled in.
	 * @param string $subject   The subject, with its tags filled in.
	 * @param string $preheader The inbox line, with its tags filled in.
	 *
	 * @return string
	 */
	private function wrap( string $body, string $subject, string $preheader = '' ): string {
		$shell = Templates::get( $this->template );

		$replacements = array_merge(
			self::site_context(),
			[
				'title'    => $subject,
				'subtitle' => '',
				'footer'   => '',
				'logo'     => '',
				'subject'  => $subject,
			],
			$this->context,
			[ 'content' => $body ]
		);

		$replacements = self::look( $replacements, $this->context );

		$filled = [];

		foreach ( $replacements as $key => $value ) {
			// Text goes in as text. The title sits inside an <h1>, and a
			// title built from a customer's name went in as whatever the
			// name had in it. The body, the logo and the footer are markup by
			// contract and go in as they are.
			$filled[ '{' . $key . '}' ] = in_array( $key, self::MARKUP, true )
				? (string) $value
				: esc_html( (string) $value );
		}

		// Anything the template asks for that nobody filled in goes, or it
		// shows up as `{colour_primary}` in the middle of the email. Read
		// out of the template alone and taken out in the same single pass
		// that puts the values in: a sweep of the finished email afterwards
		// also took `{these}` out of what a customer typed -- a name, a
		// note, a licence key -- and what a value says is never a tag.
		if ( preg_match_all( '/\{[a-z0-9_]+\}/i', $shell, $asked ) ) {
			foreach ( $asked[0] as $placeholder ) {
				$filled[ $placeholder ] ??= '';
			}
		}

		return self::document( strtr( $shell, $filled ), $preheader );
	}

	/**
	 * Say what language the email is in, which way it reads, and what the
	 * inbox should show after the subject.
	 *
	 * Done to whatever template was used -- the library's, a theme's copy,
	 * another plugin's -- so none of them has to remember. A template that
	 * already says its language is left to say it.
	 *
	 * @param string $html      The filled-in template.
	 * @param string $preheader The inbox line, or empty.
	 *
	 * @return string
	 */
	private static function document( string $html, string $preheader ): string {
		$lang = str_replace( '_', '-', function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '' );
		$dir  = function_exists( 'is_rtl' ) && is_rtl() ? 'rtl' : 'ltr';

		if ( 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $lang ) ) {
			$html = (string) preg_replace(
				'/<html(?![^>]*\blang=)([^>]*)>/i',
				sprintf( '<html lang="%s" dir="%s"$1>', esc_attr( $lang ), $dir ),
				$html,
				1
			);
		}

		if ( '' === $preheader ) {
			return $html;
		}

		// Hidden every way a mail client might show it, and followed by
		// spacing so the inbox does not run on into the email's first words.
		$hidden = sprintf(
			'<div style="display:none;font-size:1px;color:transparent;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">%s%s</div>',
			esc_html( $preheader ),
			str_repeat( '&#847;&zwnj;&nbsp;', 40 )
		);

		$with = (string) preg_replace( '/<body\b[^>]*>/i', '$0' . $hidden, $html, 1, $count );

		return $count > 0 ? $with : $hidden . $html;
	}

	/**
	 * The colours and the alignment a template draws with, made safe and
	 * made to agree with each other.
	 *
	 * A site gives one colour. The words on top of it and the links beside
	 * it follow from that unless the site said otherwise: white words on a
	 * pale header and a pale link on a white page are both a colour doing
	 * what it was told and an email nobody can read.
	 *
	 * Each of these goes into a `style` attribute, so each is checked for
	 * being what it claims to be and not merely escaped.
	 *
	 * @param array<string, mixed> $replacements Everything the template may ask for.
	 * @param array<string, mixed> $given        What this email was given itself.
	 *
	 * @return array<string, mixed>
	 */
	private static function look( array $replacements, array $given ): array {
		$primary = Color::hex( $replacements['color_primary'] ?? '', '#2271b1' );

		// Whether the colour is light is WCAG's call (Color::contrast); what
		// goes with a light one is the email's own: near-black words, not
		// pure black, and a link in the admin's near-black, which its
		// underline still marks as a link, rather than a pale one on white.
		$light = Color::is_light( $primary );

		$replacements['color_primary']    = $primary;
		$replacements['color_on_primary'] = Color::hex( $given['color_on_primary'] ?? '', $light ? '#111111' : '#ffffff' );
		$replacements['color_link']       = Color::hex( $given['color_link'] ?? '', $light ? '#1d2327' : $primary );

		$align = (string) ( $given['header_align'] ?? '' );

		$replacements['header_align'] = in_array( $align, [ 'left', 'center', 'right' ], true ) ? $align : 'center';

		return $replacements;
	}

	/**
	 * What every email knows about the site it came from.
	 *
	 * @return array<string, string>
	 */
	private static function site_context(): array {
		return [
			'site_name'     => (string) get_bloginfo( 'name' ),
			'site_url'      => home_url(),
			'admin_email'   => (string) get_option( 'admin_email' ),

			// The site's year, not the server's. A footer that says 2025 for
			// the first few hours of the new year is a small thing that looks
			// like nobody is home.
			'year'          => (string) current_time( 'Y' ),
			'date'          => (string) current_time( (string) get_option( 'date_format' ) ),
			'time'          => (string) current_time( (string) get_option( 'time_format' ) ),

			'color_primary' => '#2271b1',
		];
	}
}
