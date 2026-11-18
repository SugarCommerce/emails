<?php
/**
 * Transport
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

/**
 * How a finished email leaves the site.
 *
 * `wp_mail()` unless somebody says otherwise -- which is what every SMTP
 * plugin on the site hooks into, so the default changes nothing. A host
 * that would rather hand its email to an API (Postmark, Resend, its own)
 * gives a transport instead, and the emails, their look and their settings
 * screen are none the wiser.
 *
 * One method to send and one to be named in a log. A transport must not
 * throw for a message that was refused: that is a normal outcome, and it
 * comes back as a Result.
 */
interface Transport {

	/**
	 * Hand a message over.
	 *
	 * @param Message $message The finished email.
	 *
	 * @return Result
	 */
	public function send( Message $message ): Result;

	/**
	 * A short name, for logs: `wp_mail`, `postmark`.
	 *
	 * @return string
	 */
	public function name(): string;
}
