<?php
/**
 * API Transport Plumbing
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Transports;

use SugarCommerce\Emails\Address;
use SugarCommerce\Emails\Result;

/**
 * One JSON POST through WordPress's HTTP API, and a consistent reading of
 * what came back.
 *
 * The reading matters more than the request. Nothing served, a 429 or a 5xx
 * is the provider's trouble and worth another go; any other 4xx -- a wrong
 * key, an address it refused -- fails the same way every time, and trying
 * again only puts off finding out.
 */
trait Api {

	/**
	 * POST JSON and turn the answer into a Result.
	 *
	 * @param string                $url       The endpoint.
	 * @param array<string, mixed>  $payload   The request body.
	 * @param array<string, string> $headers   Headers of the provider's own.
	 * @param int                   $timeout   Seconds.
	 * @param string                $id_key    Where the answer keeps the message id.
	 * @param string                $error_key Where it keeps the reason.
	 *
	 * @return Result
	 */
	private function post( string $url, array $payload, array $headers, int $timeout, string $id_key, string $error_key = 'message' ): Result {
		foreach ( $headers as $name => $value ) {
			// The values are keys from configuration. A line break pasted in
			// with one would start a header of its own: refused rather than
			// sent, and without repeating the key.
			if ( 1 === preg_match( '/[\r\n\x00]/', $name . $value ) ) {
				return Result::failed( __( 'A setting for the mail provider has a line break in it. Check the API key.', 'sugarcommerce' ) );
			}
		}

		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		if ( false === $body ) {
			return Result::failed( __( 'The email could not be put into a form the mail provider takes.', 'sugarcommerce' ) );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array_merge(
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					$headers
				),
				'body'        => $body,
			]
		);

		// Nothing was served, so nothing was refused.
		if ( is_wp_error( $response ) ) {
			return Result::failed( $response->get_error_message(), 0, true );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : [];

		if ( $status >= 200 && $status < 300 ) {
			return Result::ok( self::words( $decoded[ $id_key ] ?? $decoded['id'] ?? '' ), $status );
		}

		$error = self::words( $decoded[ $error_key ] ?? $decoded['message'] ?? $decoded['error'] ?? '' );

		return Result::failed( '' !== $error ? $error : 'HTTP ' . $status, $status, 0 === $status || 429 === $status || $status >= 500 );
	}

	/**
	 * A provider's value as words.
	 *
	 * Providers disagree on shapes: an id is a string or a number, a reason
	 * is a string, an object with a `message`, or a list of those.
	 *
	 * @param mixed $value Decoded JSON.
	 *
	 * @return string
	 */
	private static function words( mixed $value ): string {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( array_key_exists( 'message', $value ) ) {
			return self::words( $value['message'] );
		}

		return implode( '; ', array_filter( array_map( [ self::class, 'words' ], array_values( $value ) ), 'strlen' ) );
	}

	/**
	 * The sender a transport was set up with, as the API needs it written.
	 *
	 * `Shop, Inc. <shop@example.com>` typed into a setting is quoted, so the
	 * comma does not make it two senders. What does not parse goes as it
	 * is, for the API to say what is wrong with it.
	 *
	 * @param string $from As given.
	 *
	 * @return string
	 */
	private static function sender( string $from ): string {
		return Address::try_parse( $from )?->header() ?? $from;
	}

	/**
	 * The attachments as an API takes them: a name, the bytes in base64 and
	 * what kind of file it is.
	 *
	 * Read now, at send time. A file that cannot be read is left out.
	 *
	 * @param string[] $paths The files.
	 *
	 * @return array<int, array{name: string, content: string, type: string}>
	 */
	private static function files( array $paths ): array {
		$files = [];

		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file the plugin attached.
			$bytes = file_get_contents( $path );

			if ( false === $bytes ) {
				continue;
			}

			$type = function_exists( 'wp_check_filetype' ) ? (string) ( wp_check_filetype( $path )['type'] ?? '' ) : '';

			$files[] = [
				'name'    => basename( $path ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An attachment, as the API takes it.
				'content' => base64_encode( $bytes ),
				'type'    => '' !== $type ? $type : 'application/octet-stream',
			];
		}

		return $files;
	}
}
