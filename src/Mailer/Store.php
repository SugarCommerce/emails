<?php
/**
 * Store
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
 * Where a mailer's settings are kept, and under which names.
 *
 * Every name is the plugin's: its key prefix and a part (`email_` and
 * `template` is `email_template`), or a key it gave for that part. Those
 * names are what a site has its wording saved under, so they are worked out
 * in this one place and nowhere else, and keys() lists every one of them.
 *
 * By default the settings are one option holding an array, which is what
 * most plugins keep. A plugin whose settings screen reads and writes them
 * some other way -- encrypted fields, its own filters -- hands over the
 * callables that do it.
 */
final class Store {

	/**
	 * The parts whose key is the prefix and a name, by part.
	 *
	 * @var array<string, string>
	 */
	public const array PREFIXED = [
		'template'       => 'template',
		'color'          => 'brand_color',
		'logo'           => 'logo',
		'logo_height'    => 'logo_height',
		'align'          => 'header_align',
		'footer'         => 'footer',
		'footer_enabled' => 'footer_enabled',
		'from_name'      => 'from_name',
		'from_address'   => 'from_address',

		// Not stored: the place on the Look card its preview is drawn.
		'look_preview'   => 'look_preview',
	];

	/**
	 * The parts that have no key until the plugin gives one.
	 *
	 * Where a reply goes, where a note to the site goes when nobody typed
	 * an address on its panel, and the business's name, which a logo is
	 * described by. Most plugins keep these with their general settings and
	 * not with their emails.
	 *
	 * @var string[]
	 */
	public const array OPTIONAL = [ 'reply_to', 'admin_email', 'business_name' ];

	/**
	 * Construct.
	 *
	 * @param Mailer $mailer The mailer.
	 */
	public function __construct( private Mailer $mailer ) {}

	/**
	 * The key one part is kept under.
	 *
	 * @param string $part A key of self::PREFIXED, or of self::OPTIONAL.
	 *
	 * @return string Empty for an optional part the plugin gave no key.
	 */
	public function key( string $part ): string {
		$given = $this->mailer->config( 'keys', [] )[ $part ] ?? null;

		if ( is_string( $given ) ) {
			return $given;
		}

		return isset( self::PREFIXED[ $part ] ) ? $this->prefix() . self::PREFIXED[ $part ] : '';
	}

	/**
	 * The key an email's own settings are kept under, unless it was
	 * registered with one of its own.
	 *
	 * @param string $id The email.
	 *
	 * @return string
	 */
	public function email_key( string $id ): string {
		return $this->prefix() . $id;
	}

	/**
	 * Every key, by part, and every email's.
	 *
	 * @return array{parts: array<string, string>, emails: array<string, string>}
	 */
	public function keys(): array {
		$parts = [];

		foreach ( array_merge( array_keys( self::PREFIXED ), self::OPTIONAL ) as $part ) {
			$parts[ $part ] = $this->key( $part );
		}

		return [
			'parts'  => $parts,
			'emails' => array_map(
				static fn( array $email ): string => (string) $email['setting'],
				$this->mailer->registry()->emails()
			),
		];
	}

	/**
	 * Every setting, as stored.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$read = $this->mailer->config( 'settings' );
		$all  = is_callable( $read )
			? call_user_func( $read )
			: get_option( (string) $this->mailer->config( 'option_name' ), [] );

		return is_array( $all ) ? $all : [];
	}

	/**
	 * One setting, as the settings screen reads it.
	 *
	 * @param string $key      The key. Empty reads as never saved.
	 * @param mixed  $fallback What a setting with no value is.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		if ( '' === $key ) {
			return $fallback;
		}

		$read = $this->mailer->config( 'option' );

		return is_callable( $read )
			? call_user_func( $read, $key, $fallback )
			: ( $this->all()[ $key ] ?? $fallback );
	}

	/**
	 * Change one setting.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value Its new value.
	 *
	 * @return bool
	 */
	public function set( string $key, mixed $value ): bool {
		if ( '' === $key ) {
			return false;
		}

		$write = $this->mailer->config( 'update' );

		if ( is_callable( $write ) ) {
			return (bool) call_user_func( $write, $key, $value );
		}

		$all         = $this->all();
		$all[ $key ] = $value;

		return update_option( (string) $this->mailer->config( 'option_name' ), $all );
	}

	/**
	 * An email's setting exactly as it was saved, or null when it never was.
	 *
	 * Not through the settings screen, which answers only for fields it
	 * has: an email with no panel, or one registered after the screen was
	 * put together, is not one, and would read as never saved.
	 *
	 * A part missing from it was never saved. A site that reworded an
	 * email before its panel had a switch has no `enabled` here, and that
	 * is how Sender::enabled() knows nobody turned it off.
	 *
	 * @param string $setting The setting.
	 *
	 * @return array<string, mixed>|null
	 */
	public function saved( string $setting ): ?array {
		$saved = $this->all()[ $setting ] ?? null;

		return is_array( $saved ) ? $saved : null;
	}

	/**
	 * The key prefix.
	 *
	 * @return string
	 */
	private function prefix(): string {
		return (string) $this->mailer->config( 'key_prefix', '' );
	}
}
