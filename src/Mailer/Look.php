<?php
/**
 * Look
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Text\Text;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Parts\Logo;
use SugarCommerce\Emails\Templates;

/**
 * The design, the colour, the logo and the footer of every email.
 *
 * One answer for all of them, read where an email is built: a send, a
 * preview and a test are drawn from the same things, so what the Look card's
 * preview shows is what is sent.
 *
 * The designs are the library's templates. Which of them a site is offered,
 * and any another plugin brings with a file of its own, is templates(). The
 * words drawn on the colour and the links beside it are worked out from the
 * colour, so a pale brand colour gets dark words on it and nobody has to be
 * told about contrast.
 */
final class Look {

	/**
	 * A look that has not been saved, while something is being drawn in it.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $trying = null;

	/**
	 * The design of the email that is being built now.
	 *
	 * @var string
	 */
	private string $drawing = '';

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * The designs an email can be drawn in.
	 *
	 * @return array<string, array{label: string, description: string, file: string}>
	 *         By name. `file` is empty for one of the library's own.
	 */
	public function templates(): array {
		$templates = $this->mailer->config( 'templates' );
		$templates = is_array( $templates ) ? $templates : self::library_templates();

		/**
		 * Filter the designs an email can be drawn in.
		 *
		 * Another plugin adds its own with the path of an .html file. The
		 * file is a whole email with `{content}` where the wording goes; it
		 * can also use `{title}`, `{logo}`, `{footer}`, `{color_primary}`,
		 * `{color_on_primary}`, `{color_link}` and `{header_align}`.
		 *
		 *     $templates['letterhead'] = [
		 *         'label'       => 'Letterhead',
		 *         'description' => 'Our headed paper.',
		 *         'file'        => __DIR__ . '/letterhead.html',
		 *     ];
		 *
		 * @param array<string, array<string, string>> $templates By name.
		 */
		$templates = (array) apply_filters( $this->mailer->hook( 'email_templates' ), $templates );
		$clean     = [];

		foreach ( $templates as $name => $template ) {
			$name = sanitize_key( (string) $name );

			if ( '' === $name || ! is_array( $template ) ) {
				continue;
			}

			$clean[ $name ] = [
				'label'       => (string) ( $template['label'] ?? Text::humanize( $name ) ),
				'description' => (string) ( $template['description'] ?? '' ),
				'file'        => (string) ( $template['file'] ?? '' ),
			];
		}

		return $clean;
	}

	/**
	 * Draw something in a look that has not been saved.
	 *
	 * For a preview: the look on the screen is used while `$draw` runs and
	 * is forgotten when it returns. Nothing is written anywhere.
	 *
	 * @param array<string, mixed> $look Any of `template`, `color`, `logo`
	 *                                   (an attachment id), `logo_height`,
	 *                                   `align`, `footer` and
	 *                                   `footer_enabled`; and for one
	 *                                   email's card, `own_template` and
	 *                                   `no_heading`.
	 * @param callable             $draw What to run.
	 *
	 * @return mixed Whatever `$draw` returned.
	 */
	public function trying( array $look, callable $draw ): mixed {
		$before       = $this->trying;
		$this->trying = $look;

		try {
			return $draw();
		} finally {
			$this->trying = $before;
		}
	}

	/**
	 * One thing about the look being tried, or null when nothing is.
	 *
	 * @param string $key Which.
	 *
	 * @return mixed
	 */
	public function tried( string $key ): mixed {
		return $this->trying[ $key ] ?? null;
	}

	/**
	 * What the site chose on the Look card, or would have by default.
	 *
	 * @return array{template: string, color: string, logo: int, logo_height: string, align: string, footer: string, footer_enabled: bool}
	 */
	public function chosen(): array {
		$store = $this->mailer->store();
		$saved = $store->all();
		$read  = static fn( string $part, mixed $fallback ): mixed => $saved[ $store->key( $part ) ] ?? $fallback;

		$look = [
			'template'       => $read( 'template', '' ),
			'color'          => $read( 'color', '' ),
			'logo'           => $read( 'logo', 0 ),
			'logo_height'    => $read( 'logo_height', '' ),
			'align'          => $read( 'align', '' ),
			'footer'         => $read( 'footer', '' ),

			// On until somebody turns it off. A site that saved its
			// settings before there was a switch has nothing here, and its
			// emails keep the footer they have had.
			'footer_enabled' => $read( 'footer_enabled', true ),
		];

		if ( null !== $this->trying ) {
			$look = array_merge( $look, array_intersect_key( $this->trying, $look ) );
		}

		$template = sanitize_key( (string) $look['template'] );
		$color    = strtolower( trim( (string) $look['color'] ) );
		$heights  = $this->heights();
		$height   = is_scalar( $look['logo_height'] ) ? (string) $look['logo_height'] : '';
		$logo     = is_numeric( $look['logo'] ) ? max( 0, (int) $look['logo'] ) : 0;

		return [
			'template'       => isset( $this->templates()[ $template ] ) ? $template : 'default',

			// Six hex digits or it is not a colour: it goes into a style
			// attribute as it is.
			'color'          => 1 === preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : $this->colour(),
			'logo'           => $logo,
			'logo_height'    => isset( $heights[ $height ] ) ? $height : 'medium',
			'align'          => in_array( $look['align'], [ 'left', 'center' ], true ) ? (string) $look['align'] : 'center',
			'footer'         => trim( (string) $look['footer'] ),
			'footer_enabled' => (bool) filter_var( $look['footer_enabled'], FILTER_VALIDATE_BOOLEAN ),
		];
	}

	/**
	 * The look of one email, as it is about to be drawn.
	 *
	 * @param string $id The email, or empty for the look on its own.
	 *
	 * @return array{template: string, color: string, logo_url: string, logo_height: int, align: string, footer: string}
	 *         `footer` is plain text, a line to a line.
	 */
	public function brand( string $id = '' ): array {
		$chosen = $this->chosen();
		$email  = '' === $id ? null : $this->mailer->registry()->get( $id );
		$saved  = null === $email ? null : $this->mailer->store()->saved( (string) $email['setting'] );

		// An email's own design: what the site picked on its card, then
		// what it was registered with, then the site's design.
		$own = sanitize_key( (string) ( $this->tried( 'own_template' ) ?? $saved['template'] ?? '' ) );
		$own = '' !== $own ? $own : sanitize_key( (string) ( $email['template'] ?? '' ) );

		$heights = $this->heights();
		$brand   = [
			'template'    => isset( $this->templates()[ $own ] ) ? $own : $chosen['template'],
			'color'       => $chosen['color'],
			'logo_url'    => $chosen['logo'] > 0 ? (string) wp_get_attachment_image_url( $chosen['logo'], 'medium' ) : '',
			'logo_height' => (int) ( $heights[ $chosen['logo_height'] ] ?? 48 ),
			'align'       => $chosen['align'],
			'footer'      => $this->footer( $chosen ),
		];

		/**
		 * Filter the look of an email as it is about to be drawn.
		 *
		 * @param array<string, mixed> $brand `template`, `color` (six-digit
		 *                                    hex), `logo_url`, `logo_height`
		 *                                    (pixels), `align` (`left` or
		 *                                    `center`) and `footer` (plain
		 *                                    text, a line to a line).
		 * @param string               $id    The email, or empty for the
		 *                                    look on its own.
		 */
		$filtered = (array) apply_filters( $this->mailer->hook( 'email_brand' ), $brand, $id );

		// A filter that drops a key gets the site's own back for it.
		return $filtered + $brand;
	}

	/**
	 * What an email is handed: its design, and what the design is filled
	 * in with.
	 *
	 * Also for an email built by hand -- a sign-in link, a note written to
	 * one customer -- so it looks like the others:
	 *
	 *     $look = $mailer->look()->settings();
	 *     Email::make()->template( $look['template'] )->context( $look['context'] );
	 *
	 * @param string $id The email, or empty for the look on its own.
	 *
	 * @return array{template: string, context: array<string, string>}
	 */
	public function settings( string $id = '' ): array {
		$brand = $this->brand( $id );
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $brand['footer'] ) ), 'strlen' );
		$file  = $this->templates()[ (string) $brand['template'] ]['file'] ?? '';

		// A design another plugin brought with a file of its own: the
		// library reads designs from its own folder and the theme's, and
		// this is neither.
		if ( '' !== $file ) {
			Templates::register_file( (string) $brand['template'], $file );
		}

		// Every email is built by asking this first -- a send, a preview, a
		// test -- so this is where the design of the one being built is
		// noted. See self::drawing().
		$this->drawing = (string) $brand['template'];

		$store = $this->mailer->store();

		return [
			'template' => (string) $brand['template'],
			'context'  => [
				'color_primary' => (string) $brand['color'],
				'header_align'  => (string) $brand['align'],
				'logo'          => Logo::render(
					[
						'url'    => (string) $brand['logo_url'],
						'alt'    => (string) $store->get( $store->key( 'business_name' ), get_bloginfo( 'name' ) ),
						'height' => (int) $brand['logo_height'],
					]
				),

				// Text, a line to a line. What a site types here is not
				// markup and is not treated as any.
				'footer'        => implode( '<br />', array_map( 'esc_html', $lines ) ),
			],
		];
	}

	/**
	 * The design of the email that is being built now.
	 *
	 * A merge tag is handed the record and nothing else, and one may need
	 * to know more: a list of what was bought can be a short list in every
	 * design but an invoice, where it is the invoice. An email asks
	 * self::settings() for its look immediately before its tags are filled
	 * in, so the design asked for last is the design of the email a tag is
	 * being filled in for.
	 *
	 * @return string A design's name, or empty before any email is built.
	 */
	public function drawing(): string {
		return $this->drawing;
	}

	/**
	 * The footer of a site that has not written one.
	 *
	 * @return string Plain text, a line to a line.
	 */
	public function default_footer(): string {
		$given = $this->mailer->config( 'default_footer' );

		return is_callable( $given ) ? (string) call_user_func( $given ) : '';
	}

	/**
	 * The colour emails go out in until the site picks one.
	 *
	 * @return string
	 */
	public function colour(): string {
		$colour = strtolower( (string) $this->mailer->config( 'colour', '#2271b1' ) );

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $colour ) ? $colour : '#2271b1';
	}

	/**
	 * How tall a logo is drawn: the names on the card, and the pixels.
	 *
	 * @return array<string, int>
	 */
	public function heights(): array {
		return array_map( 'intval', (array) $this->mailer->config( 'logo_heights', [] ) );
	}

	/**
	 * What goes at the bottom, from what the site chose.
	 *
	 * Nothing when the footer is switched off. Otherwise what was typed,
	 * with its tags filled in, and, while the box is empty, the default.
	 *
	 * @param array<string, mixed> $chosen From self::chosen().
	 *
	 * @return string Plain text, a line to a line.
	 */
	private function footer( array $chosen ): string {
		if ( empty( $chosen['footer_enabled'] ) ) {
			return '';
		}

		if ( '' === (string) $chosen['footer'] ) {
			return $this->default_footer();
		}

		$fill = $this->mailer->config( 'fill_footer' );

		if ( is_callable( $fill ) ) {
			return (string) call_user_func( $fill, (string) $chosen['footer'] );
		}

		// One pass, so a site name with `{braces}` in it goes in as it is,
		// and a tag the footer names that nothing fills in goes.
		$footer = (string) $chosen['footer'];
		$fill   = [];

		if ( preg_match_all( '/\{[a-z0-9_]+\}/i', $footer, $asked ) ) {
			$fill = array_fill_keys( $asked[0], '' );
		}

		return strtr(
			$footer,
			array_merge(
				$fill,
				[
					'{year}'      => (string) current_time( 'Y' ),
					'{site_name}' => (string) get_bloginfo( 'name' ),
				]
			)
		);
	}

	/**
	 * The library's own designs, as a site is offered them.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	private static function library_templates(): array {
		return [
			'default'      => [
				'label'       => __( 'Banner', 'sugarcommerce' ),
				'description' => __( 'A band of your colour across the top.', 'sugarcommerce' ),
			],
			'soft'         => [
				'label'       => __( 'Soft', 'sugarcommerce' ),
				'description' => __( 'A gentle tinted card with rounded corners.', 'sugarcommerce' ),
			],
			'mono'         => [
				'label'       => __( 'Black and white', 'sugarcommerce' ),
				'description' => __( 'A black heading and nothing else.', 'sugarcommerce' ),
			],
			'notification' => [
				'label'       => __( 'Compact', 'sugarcommerce' ),
				'description' => __( 'A small framed note. Good for short emails.', 'sugarcommerce' ),
			],
			'plain'        => [
				'label'       => __( 'Plain', 'sugarcommerce' ),
				'description' => __( 'Like a letter somebody typed.', 'sugarcommerce' ),
			],
			'dark'         => [
				'label'       => __( 'Dark', 'sugarcommerce' ),
				'description' => __( 'Light words on a dark card.', 'sugarcommerce' ),
			],
			'invoice'      => [
				'label'       => __( 'Invoice', 'sugarcommerce' ),
				'description' => __( 'A plain page laid out like an invoice.', 'sugarcommerce' ),
			],
		];
	}
}
