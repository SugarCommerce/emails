<?php
/**
 * Templates
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

use SugarCommerce\Emails\Utils\Runtime;
use SugarCommerce\Text\Text;

/**
 * Class Templates
 *
 * Manages email templates loaded from files with theme override support.
 *
 * @since 1.0.0
 */
class Templates {

	/**
	 * Base templates directory
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static string $templates_dir = '';

	/**
	 * The folder in a theme that holds its copies of the templates.
	 *
	 * Set by the plugin using the library, so a theme overrides a shop's
	 * emails in the same folder as the shop's other templates
	 * (`my-shop/emails/`) rather than in one named after this library,
	 * which a theme author has no reason to know exists.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static string $theme_dir = 'sugarcommerce-emails';

	/**
	 * Template metadata cache
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static array $template_meta = [];

	/**
	 * Templates a plugin registered with a file of their own, by name.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private static array $files = [];

	/**
	 * Get template HTML
	 *
	 * Retrieves template with theme override support.
	 *
	 * @param string $template Template name
	 *
	 * @return string Template HTML with placeholders
	 * @since 1.0.0
	 */
	public static function get( string $template = 'default' ): string {
		$template = self::name( $template );

		// A template registered with a file of its own. It is neither in
		// the templates directory nor in the theme, and it is what the
		// plugin that registered it asked for by that name.
		$html = self::get_registered_template( $template );

		// Check theme override first
		if ( ! $html ) {
			$html = self::get_theme_template( $template );
		}

		if ( ! $html ) {
			// Load from plugin templates directory
			$file = self::get_templates_dir() . $template . '.html';
			if ( file_exists( $file ) ) {
				$html = file_get_contents( $file );
			}
		}

		// Fall back to default if not found
		if ( ! $html ) {
			$default = self::get_templates_dir() . 'default.html';
			$html    = file_exists( $default ) ? file_get_contents( $default ) : self::fallback_template();
		}

		/**
		 * Filter template HTML
		 *
		 * @param string $html     Template HTML
		 * @param string $template Template name
		 *
		 * @since 1.0.0
		 */
		return apply_filters( Runtime::hook( 'template' ), $html, $template );
	}

	/**
	 * A template name that can only name a file in a templates directory.
	 *
	 * The name went straight into a path, so `../../wp-config` was a
	 * template name and anything ending in .html anywhere on the disk could
	 * be read back as the shell of an email.
	 *
	 * @param string $template Template name, as given.
	 *
	 * @return string
	 */
	private static function name( string $template ): string {
		return sanitize_file_name( basename( $template ) );
	}

	/**
	 * Get templates directory path
	 *
	 * @return string Full path to templates directory
	 * @since  1.0.0
	 * @access private
	 */
	private static function get_templates_dir(): string {
		if ( empty( self::$templates_dir ) ) {
			self::$templates_dir = dirname( __DIR__, 1 ) . '/templates/';

		}

		return self::$templates_dir;
	}

	/**
	 * Set custom templates directory
	 *
	 * @param string $dir Templates directory path
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function set_templates_dir( string $dir ): void {
		self::$templates_dir = trailingslashit( $dir );
		self::$template_meta = []; // Clear cache
	}

	/**
	 * Set the folder in a theme that overrides the templates.
	 *
	 * Relative to the theme's directory, child theme first and then its
	 * parent. A path that would leave the theme is refused: it is a name
	 * for a folder inside one, not a place on the disk.
	 *
	 * @param string $dir Folder name, such as `my-shop/emails`.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function set_theme_dir( string $dir ): void {
		$dir = trim( str_replace( '\\', '/', $dir ), '/' );

		if ( '' === $dir || in_array( '..', explode( '/', $dir ), true ) ) {
			return;
		}

		self::$theme_dir = $dir;
	}

	/**
	 * The folder in a theme that overrides the templates.
	 *
	 * @return string Relative to the theme's directory, with no slashes at
	 *                either end.
	 * @since 1.0.0
	 */
	public static function get_theme_dir(): string {
		return self::$theme_dir;
	}

	/**
	 * Register a template that lives in a file of its own.
	 *
	 * For a design a plugin brings that is not in the templates directory:
	 * an add-on's letterhead, say. Asked for by name, the file's markup is
	 * used. A file that cannot be read is ignored and the name falls back
	 * as any unknown name does.
	 *
	 * @param string $template The name it is asked for by.
	 * @param string $file     The path of an .html file.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function register_file( string $template, string $file ): void {
		$template = self::name( $template );

		if ( '' === $template || '' === $file ) {
			return;
		}

		self::$files[ $template ] = $file;
	}

	/**
	 * Forget the templates registered with a file of their own.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function forget_files(): void {
		self::$files = [];
	}

	/**
	 * A registered template's markup.
	 *
	 * @param string $template Template name.
	 *
	 * @return string|false
	 * @since 1.0.0
	 */
	private static function get_registered_template( string $template ) {
		$file = self::$files[ $template ] ?? '';

		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file a plugin named, not a URL.
		$html = file_get_contents( $file );

		return is_string( $html ) && '' !== $html ? $html : false;
	}

	/**
	 * Get theme template override
	 *
	 * Checks for custom templates in theme directories.
	 *
	 * @param string $template Template name
	 *
	 * @return string|false Template HTML or false if not found
	 * @since  1.0.0
	 * @access private
	 */
	private static function get_theme_template( string $template ) {
		// Core's lookup: the child theme, then the parent. Only finding the
		// file is core's; reading it as markup rather than loading it as
		// PHP, the folder the plugin names, and falling back to the
		// library's own template (in get()) are what core has no part in.
		$file = locate_template( self::$theme_dir . '/' . $template . '.html' );

		if ( '' === $file ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the theme, not a URL.
		return file_get_contents( $file );
	}

	/**
	 * Get available templates
	 *
	 * Returns list of all available template names.
	 *
	 * @return array Template names
	 * @since 1.0.0
	 */
	public static function get_available_templates(): array {
		$templates = [];

		// Get plugin templates
		$dir = self::get_templates_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*.html' );
			foreach ( $files as $file ) {
				$templates[] = basename( $file, '.html' );
			}
		}

		// Check for theme templates
		$theme_dir = get_stylesheet_directory() . '/' . self::$theme_dir . '/';
		if ( is_dir( $theme_dir ) ) {
			$files = glob( $theme_dir . '*.html' );
			foreach ( $files as $file ) {
				$name = basename( $file, '.html' );
				if ( ! in_array( $name, $templates, true ) ) {
					$templates[] = $name;
				}
			}
		}

		return $templates;
	}

	/**
	 * Get template metadata
	 *
	 * Extracts template description from HTML comments.
	 *
	 * @param string $template Template name
	 *
	 * @return array Template metadata
	 * @since 1.0.0
	 */
	public static function get_template_meta( string $template ): array {
		$template = self::name( $template );

		if ( isset( self::$template_meta[ $template ] ) ) {
			return self::$template_meta[ $template ];
		}

		$defaults = [
			'name'        => Text::humanize( $template ),
			'description' => '',
			'supports'    => [],
		];

		// Try to get template content
		$file = self::get_templates_dir() . $template . '.html';
		if ( ! file_exists( $file ) ) {
			return $defaults;
		}

		$content = file_get_contents( $file );

		// Extract metadata from HTML comments
		if ( preg_match( '/<!--\s*Template:\s*(.+?)\s*-->/i', $content, $matches ) ) {
			$defaults['name'] = trim( $matches[1] );
		}

		if ( preg_match( '/<!--\s*Description:\s*(.+?)\s*-->/i', $content, $matches ) ) {
			$defaults['description'] = trim( $matches[1] );
		}

		if ( preg_match( '/<!--\s*Supports:\s*(.+?)\s*-->/i', $content, $matches ) ) {
			$defaults['supports'] = array_map( 'trim', explode( ',', $matches[1] ) );
		}

		self::$template_meta[ $template ] = $defaults;

		return $defaults;
	}

	/**
	 * Validate template exists
	 *
	 * @param string $template Template name
	 *
	 * @return bool True if exists
	 * @since 1.0.0
	 */
	public static function template_exists( string $template ): bool {
		return in_array( $template, self::get_available_templates(), true );
	}

	/**
	 * Get template options for select fields.
	 *
	 * Returns an associative array of template slug => label
	 * suitable for use as dropdown options in settings UIs.
	 *
	 * @return array Associative array of template_slug => Template Label.
	 * @since 1.0.0
	 */
	public static function get_options(): array {
		$options   = [];
		$templates = self::get_available_templates();

		foreach ( $templates as $slug ) {
			$meta             = self::get_template_meta( $slug );
			$options[ $slug ] = $meta['name'];
		}

		if ( empty( $options ) ) {
			$options['default'] = __( 'Default', 'sugarcommerce' );
		}

		/**
		 * Filter the available email template options.
		 *
		 * @param array $options Associative array of template_slug => label.
		 *
		 * @since 1.0.0
		 *
		 */
		return apply_filters( Runtime::hook( 'template_options' ), $options );
	}

	/**
	 * Minimal fallback template
	 *
	 * Used when no template files are found.
	 *
	 * @return string Basic HTML template
	 * @since  1.0.0
	 * @access private
	 */
	private static function fallback_template(): string {
		return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{subject}</title>
</head>
<body style="margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
<div style="max-width: 600px; margin: 0 auto; background: white; padding: 40px;">
<h1 style="margin: 0 0 20px; color: #111;">{title}</h1>
{content}
{footer}
</div>
</body>
</html>';
	}
}
