<?php
/**
 * Look Card
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Mailer;

use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Utils\Assets;
use SugarCommerce\Emails\Utils\Runtime;

/**
 * Where a site chooses what its emails look like, and sees it.
 *
 * A design picked from small pictures of each, a colour, a logo, where the
 * heading sits and what goes at the bottom -- and under them one of the
 * emails, drawn in whatever is on the card now. The preview follows the
 * controls without a save, because nobody can choose a colour from its name.
 *
 * What the choices mean when an email is built is Look. This is only the
 * card: its fields, its script and the one action the script calls.
 *
 * Configured with the mailer's `look_card`:
 *
 *     'look_card' => [
 *         'tab'           => 'emails',
 *         'section'       => 'look',
 *         'screen'        => 'myshop-settings',      // the ?page= it is on
 *         'endpoint'      => fn() => rest_url( ... ), // the field kit's action route
 *         'preview_email' => 'receipt',               // what is drawn under it
 *     ],
 */
final class LookCard {

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * The name of the action the card's preview calls.
	 *
	 * @return string
	 */
	public function action(): string {
		return (string) ( $this->config()['action'] ?? $this->mailer->hook( 'email_look_preview' ) );
	}

	/**
	 * The card.
	 *
	 * @return array<string, array<string, mixed>> Sections, by key.
	 */
	public function sections(): array {
		$config = $this->config();

		return [
			(string) $config['section'] => [
				'title'       => $this->mailer->label( 'look' ),
				'tab'         => (string) $config['tab'],
				'description' => $this->mailer->label( 'look_description' ),

				// Folded, like the emails under it: the tab is a short
				// list, and this is opened by whoever came to change it.
				'collapsed'   => true,
			],
		];
	}

	/**
	 * The card's controls.
	 *
	 * @return array<string, array<string, mixed>> Fields, by setting.
	 */
	public function fields(): array {
		$this->mailer->ready();

		$config  = $this->config();
		$store   = $this->mailer->store();
		$look    = $this->mailer->look();
		$footer  = $look->default_footer();
		$label   = $this->mailer->label( ... );
		$where   = [
			'tab'     => (string) $config['tab'],
			'section' => (string) $config['section'],
		];
		$heights = [];

		foreach ( array_keys( $look->heights() ) as $size ) {
			$heights[ $size ] = $label( 'logo_' . $size ) ?: ucfirst( (string) $size );
		}

		return [
			$store->key( 'template' )       => [
				'type'    => 'card_choice',
				'label'   => $label( 'design' ),
				...$where,
				'default' => 'default',

				// Seven designs: four and three, not two rows of three and
				// one left over on a row of its own.
				'columns' => 4,
				'options' => $this->designs(),
			],
			$store->key( 'color' )          => [
				'type'    => 'color',
				'label'   => $label( 'colour' ),
				...$where,
				'width'   => 'half',
				'default' => $look->colour(),
				'tooltip' => $label( 'colour_tip' ),
			],
			$store->key( 'align' )          => [
				'type'    => 'button_group',
				'label'   => $label( 'align' ),
				...$where,
				'width'   => 'half',
				'default' => 'center',
				'options' => [
					'left'   => $label( 'align_left' ),
					'center' => $label( 'align_center' ),
				],
			],
			$store->key( 'logo' )           => [
				'type'         => 'image',
				'label'        => $label( 'logo' ),
				...$where,
				'width'        => 'half',
				'choose_label' => $label( 'logo_choose' ),
				'tooltip'      => $label( 'logo_tip' ),
			],
			$store->key( 'logo_height' )    => [
				'type'    => 'button_group',
				'label'   => $label( 'logo_height' ),
				...$where,
				'width'   => 'half',
				'default' => 'medium',
				'options' => $heights,
			],

			// The footer is a switch and a box. On and empty -- which is
			// how every site starts -- it is the default footer, shown in
			// the box as what will be used.
			$store->key( 'footer_enabled' ) => [
				'type'    => 'toggle',
				'label'   => $label( 'footer_enabled' ),
				...$where,
				'default' => 1,
			],
			$store->key( 'footer' )         => [
				'type'        => 'textarea',
				'label'       => $label( 'footer' ),
				...$where,
				'rows'        => 3,
				'show_when'   => [ $store->key( 'footer_enabled' ) => 1 ],
				'placeholder' => '' !== $footer ? $footer : $label( 'footer_placeholder' ),
				'description' => $label( 'footer_description' ),
				'tooltip'     => $label( 'footer_tip' ),
			],

			// Nothing is stored for this one: it is where the script draws
			// an email in the look that is on the card. It is a field so
			// that it sits in the card, and so that it can carry the action
			// the script calls.
			$store->key( 'look_preview' )   => [
				'type'            => 'html',
				'label'           => $label( 'look_preview' ),
				...$where,
				'html'            => $this->frame(),
				'action_callback' => [ $this, 'preview' ],
				'action_names'    => [ 'run' => $this->action() ],
				'action_rich'     => [ 'look_footer' ],
			],
		];
	}

	/**
	 * Draw an email in the look that is on the card.
	 *
	 * With sample details, and with the email's wording as it was last
	 * saved: this card is about how an email is dressed, and the email's
	 * own card is where its wording is tried.
	 *
	 * @param array<string, mixed> $payload The card's controls, each under
	 *                                      a `look_` name.
	 *
	 * @return array<string, mixed>
	 */
	public function preview( array $payload ): array {
		$this->mailer->ready();

		$actions = $this->mailer->actions();
		$html    = $this->mailer->look()->trying(
			$actions->look( $payload ),
			fn () => Emails::preview( $this->mailer->group(), $this->preview_email() )
		);

		if ( is_wp_error( $html ) ) {
			return [
				'success' => false,
				'message' => $html->get_error_message(),
			];
		}

		return [
			'success' => true,
			'data'    => [ 'html' => $actions->frame( (string) $html ) ],
		];
	}

	/**
	 * Load the card's script and stylesheet on the settings screen.
	 *
	 * @return void
	 */
	public function assets(): void {
		if ( ! $this->on_screen() ) {
			return;
		}

		Assets::style( Runtime::handle( 'look-card' ), 'css/look-card.css' );
		Assets::script( Runtime::handle( 'look-card' ), 'js/look-card.js' );
	}

	/**
	 * The email drawn under the card.
	 *
	 * @return string The one named, or the first registered.
	 */
	public function preview_email(): string {
		$named = (string) ( $this->config()['preview_email'] ?? '' );

		return '' !== $named ? $named : (string) array_key_first( $this->mailer->registry()->emails() );
	}

	/**
	 * Where the script draws the email, and what it needs to ask for one.
	 *
	 * What it needs is on the element and not in a global, so two plugins
	 * each with a card do not share one, and the parts the card's controls
	 * are saved under -- the plugin's keys -- travel with it.
	 *
	 * @return string
	 */
	private function frame(): string {
		$store    = $this->mailer->store();
		$endpoint = $this->config()['endpoint'] ?? '';
		$endpoint = is_callable( $endpoint ) ? call_user_func( $endpoint ) : $endpoint;
		$parts    = [];

		foreach ( Actions::LOOK as $part ) {
			$parts[] = $store->key( $part ) . ':look_' . $part;
		}

		return sprintf(
			'<div class="sugarcommerce-emails-look-preview" aria-live="polite" data-endpoint="%1$s" data-nonce="%2$s" data-action="%3$s" data-parts="%4$s" data-colour="%5$s" data-failed="%6$s"><p class="sugarcommerce-emails-look-preview__status">%7$s</p></div>',
			esc_url( (string) $endpoint ),
			esc_attr( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '' ),
			esc_attr( $this->action() ),
			esc_attr( implode( ',', $parts ) ),
			esc_attr( $store->key( 'color' ) ),
			esc_attr( $this->mailer->label( 'look_failed' ) ),
			esc_html( $this->mailer->label( 'look_drawing' ) )
		);
	}

	/**
	 * The designs, as the cards they are chosen from: a name, a line about
	 * each and a small picture of it.
	 *
	 * @return array<string, array<string, string>> By name.
	 */
	private function designs(): array {
		$designs = [];

		foreach ( $this->mailer->look()->templates() as $name => $template ) {
			$designs[ $name ] = [
				'label'       => $template['label'],
				'description' => $template['description'],
				'preview'     => self::thumbnail( $name ),
			];
		}

		return $designs;
	}

	/**
	 * A small picture of a design, for its card.
	 *
	 * Spans the stylesheet draws: a top, a heading and three lines of
	 * wording, arranged as that design arranges them and in the colour on
	 * the card. A design another plugin brought is drawn as a plain page.
	 *
	 * @param string $name The design.
	 *
	 * @return string
	 */
	public static function thumbnail( string $name ): string {
		return sprintf(
			'<span class="sugarcommerce-emails-look sugarcommerce-emails-look--%s">' .
			'<span class="sugarcommerce-emails-look__page">' .
			'<span class="sugarcommerce-emails-look__top"><span class="sugarcommerce-emails-look__title"></span></span>' .
			'<span class="sugarcommerce-emails-look__body">' .
			'<span class="sugarcommerce-emails-look__line"></span>' .
			'<span class="sugarcommerce-emails-look__line"></span>' .
			'<span class="sugarcommerce-emails-look__line sugarcommerce-emails-look__line--link"></span>' .
			'</span></span></span>',
			esc_attr( sanitize_html_class( $name ) )
		);
	}

	/**
	 * Whether this is the screen the card is on.
	 *
	 * @return bool
	 */
	private function on_screen(): bool {
		$screen = $this->config()['screen'] ?? '';

		if ( is_callable( $screen ) ) {
			return (bool) call_user_func( $screen );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen this is; nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== (string) $screen && (string) $screen === $page;
	}

	/**
	 * The card's configuration, with what it assumes.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return array_merge(
			[
				'tab'     => 'emails',
				'section' => 'look',
			],
			(array) $this->mailer->config( 'look_card', [] )
		);
	}
}
