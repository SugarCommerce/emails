<?php
/**
 * WordPress stubs.
 *
 * @package SugarCommerce\Emails
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/srv/site/wp-content' );
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function re_reset_globals(): void {
	$GLOBALS['re_options'] = [ 'admin_email' => 'admin@example.test', 'date_format' => 'F j, Y', 'time_format' => 'g:i a' ];
	$GLOBALS['re_mail']    = [];
	$GLOBALS['re_sent']    = true;
	$GLOBALS['re_filters'] = [];
	$GLOBALS['re_actions'] = [];
	$GLOBALS['re_clock']   = [];
	$GLOBALS['re_hooks']   = [];
	$GLOBALS['re_fired']   = [];
	$GLOBALS['re_wrong']   = [];
	$GLOBALS['re_user']    = 'tester@example.test';
	$GLOBALS['re_http']    = [];
	$GLOBALS['re_respond'] = null;
	$GLOBALS['re_rtl']     = false;
	$GLOBALS['re_language'] = 'en-GB';
	$GLOBALS['re_mail_error'] = '';

	foreach ( [ 'SugarCommerce\\Emails\\Tags', 'SugarCommerce\\Emails\\Emails', 'SugarCommerce\\Emails\\Mailer' ] as $registry ) {
		if ( class_exists( $registry ) ) {
			$registry::forget();
		}
	}

	if ( class_exists( 'SugarCommerce\\Emails\\Templates' ) ) {
		SugarCommerce\Emails\Templates::forget_files();
	}

	if ( class_exists( 'SugarCommerce\\Emails\\Delivery' ) ) {
		SugarCommerce\Emails\Delivery::reset();
	}

	if ( class_exists( 'SugarCommerce\\Emails\\Render\\Components' ) ) {
		SugarCommerce\Emails\Render\Components::flush();
	}
}

re_reset_globals();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url, $protocols = null, $context = 'display' ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $content ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( trim( (string) $email ), FILTER_VALIDATE_EMAIL ) ?: '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default_value = false ) {
		return $GLOBALS['re_options'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return [ 'name' => 'Example Shop', 'charset' => 'UTF-8', 'language' => $GLOBALS['re_language'] ][ $show ] ?? '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/*
	 * Records what it was asked for. gmdate() and current_time() agree on a
	 * site running in UTC, which every test site is, so comparing the values
	 * cannot tell the two apart — and telling them apart is the whole
	 * question when the answer is a copyright year.
	 */
	function current_time( $type, $gmt = 0 ) {
		$GLOBALS['re_clock'][] = (string) $type;

		return 'timestamp' === $type ? time() : gmdate( (string) $type );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) ?? '';
		$text = strip_tags( $text );

		return trim( $remove_breaks ? (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $text, (int) $quote_style );
	}
}

/*
 * Core's, minus the accent and mime handling: the special characters go, a
 * run of dots collapses to one, and the ends are trimmed of dots and dashes.
 */
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$special = [ '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) ];

		$filename = str_replace( $special, '', (string) $filename );
		$filename = (string) preg_replace( '/\.{2,}/', '.', $filename );
		$filename = (string) preg_replace( '/[\r\n\t -]+/', '-', $filename );

		return trim( $filename, '.-_' );
	}
}

/*
 * A theme may override an email template. Nothing here has a theme, so these
 * point at a directory that does not exist and the shipped template wins.
 */
if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory() {
		return sys_get_temp_dir() . '/no-such-theme';
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() {
		return sys_get_temp_dir() . '/no-such-theme';
	}
}

/*
 * Core's locate_template(), without loading: the child theme, then the
 * parent (the theme-compat folder never holds an email).
 */
if ( ! function_exists( 'locate_template' ) ) {
	function locate_template( $template_names, $load = false, $load_once = true, $args = array() ) {
		foreach ( (array) $template_names as $template_name ) {
			if ( ! $template_name ) {
				continue;
			}

			if ( file_exists( get_stylesheet_directory() . '/' . $template_name ) ) {
				return get_stylesheet_directory() . '/' . $template_name;
			}

			if ( file_exists( get_template_directory() . '/' . $template_name ) ) {
				return get_template_directory() . '/' . $template_name;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['re_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['re_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['re_actions'][] = $hook;
		$GLOBALS['re_fired'][]   = [ $hook, $args ];

		foreach ( $GLOBALS['re_hooks'][ $hook ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['re_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['re_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	/*
	 * Core's, reduced to what these tests read: blocks split on a blank
	 * line, each wrapped in a paragraph unless it is already a block.
	 */
	function wpautop( $text, $br = true ) {
		$text = trim( str_replace( [ "\r\n", "\r" ], "\n", (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		$out = '';

		foreach ( preg_split( '/\n\s*\n/', $text ) as $block ) {
			$block = trim( $block );
			$out  .= preg_match( '@^<(p|h[1-6]|div|table|ul|ol)[\s>]@i', $block ) ? $block . "\n" : '<p>' . $block . "</p>\n";
		}

		return $out;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) [ 'user_email' => $GLOBALS['re_user'] ];
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
		return 'https://example.test/uploads/' . (int) $id . '-' . $size . '.png';
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['re_wrong'][] = [ $function_name, $message ];
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $classname, $fallback = '' ) {
		$clean = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $classname );

		return '' === $clean ? $fallback : $clean;
	}
}

if ( ! function_exists( 'network_home_url' ) ) {
	function network_home_url( $path = '' ) {
		return 'https://www.example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		$GLOBALS['re_mail'][] = compact( 'to', 'subject', 'message', 'headers', 'attachments' );

		// What wp_mail() does: PHPMailer emptied, then handed to
		// phpmailer_init, and wp_mail_failed when it would not go.
		$phpmailer = (object) [ 'AltBody' => '' ];
		do_action( 'phpmailer_init', $phpmailer );
		$GLOBALS['re_mail'][ count( $GLOBALS['re_mail'] ) - 1 ]['alt'] = $phpmailer->AltBody;

		if ( ! $GLOBALS['re_sent'] && '' !== $GLOBALS['re_mail_error'] ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $GLOBALS['re_mail_error'] ) );
		}

		return (bool) $GLOBALS['re_sent'];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The parts of WP_Error this library uses.
	 */
	class WP_Error {

		/**
		 * The messages, by code.
		 *
		 * @var array<string, string>
		 */
		private array $errors = [];

		/**
		 * The data, by code.
		 *
		 * @var array<string, mixed>
		 */
		private array $data = [];

		/**
		 * Build one.
		 *
		 * @param string $code    Its code.
		 * @param string $message Its message.
		 * @param mixed  $data    Anything else worth carrying.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( '' !== $code ) {
				$this->errors[ $code ] = $message;
				$this->data[ $code ]   = $data;
			}
		}

		/**
		 * The first code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return (string) array_key_first( $this->errors );
		}

		/**
		 * The first message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return (string) ( $this->errors[ $this->get_error_code() ] ?? '' );
		}

		/**
		 * What was carried with it.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data[ $this->get_error_code() ] ?? null;
		}
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['re_hooks'][ $hook ] ?? [] as $index => $added ) {
			if ( $added === $callback ) {
				unset( $GLOBALS['re_hooks'][ $hook ][ $index ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl() {
		return (bool) $GLOBALS['re_rtl'];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/*
	 * Records the request and answers with whatever the test put in
	 * re_respond: a WP_Error, or [ status, body ].
	 */
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['re_http'][] = [ 'url' => $url, 'args' => $args ];

		$answer = $GLOBALS['re_respond'] ?? [ 200, '{}' ];

		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		return [
			'response' => [ 'code' => $answer[0] ],
			'body'     => $answer[1],
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

		return [ 'ext' => $ext, 'type' => [ 'pdf' => 'application/pdf', 'txt' => 'text/plain' ][ $ext ] ?? false ];
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

/*
 * What SugarCommerce\Plugin\Assets calls: it registers, then enqueues the
 * handle, and a test reads what was registered.
 */
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $path ) );
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['re_enqueued'][] = [ 'style', $handle, $src, $ver ];

		return true;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['re_enqueued'][] = [ 'script', $handle, $src, $ver ];

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {}
}
