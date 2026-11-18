# Emails

Named transactional emails with merge tags, HTML that survives Outlook, and a settings screen where the site owner
rewords them.

## What it does

A plugin that sends a receipt needs things nobody enjoys writing: merge tags a shop owner can put in the subject line,
HTML that renders the same in Gmail and Outlook, a way to preview the result without placing a test order, and
somewhere for the owner to change the wording. This registers tags and emails by name, fills the tags in from whatever
the email is about, builds the body from components, and hands the finished email to `wp_mail()` -- or to Postmark,
Resend or any API you give it.

## Features

* Register a merge tag with a sample, so a preview shows something real
* Register an email by name and send it about one order, licence or booking
* Let the owner reword, switch off and redirect each email, and keep one look for all of them
* Preview and send a test of what is on the screen, before anything is saved
* Build the body from buttons, tables and order lines, not a table layout kept by hand
* Send through an API instead of `wp_mail()`, or send everything to one inbox on staging
* Refuse to send an email whose tags could not all be filled in

## Installation

```bash
composer require sugarcommerce/emails
```

## Quick start

```php
use SugarCommerce\Emails\Emails;
use SugarCommerce\Emails\Tags;

Tags::add( 'shop', 'customer_name', [
	'label'    => __( 'Customer name', 'my-plugin' ),
	'callback' => fn( $order ) => $order->billing_name,
	'preview'  => 'Jane Doe',
] );

Emails::add( 'shop', 'receipt', [
	'subject'   => __( 'Your order from {site_name}', 'my-plugin' ),
	'preheader' => __( 'Thanks for your order.', 'my-plugin' ),
	'content'   => __( 'Thanks, {customer_name}.', 'my-plugin' ),
] );

Emails::send( 'shop', 'receipt', [ 'to' => $order->email, 'data' => $order ] );
```

`send()` returns `true` or a `WP_Error`, and refuses an email with a tag that failed: a receipt with a blank where the
total should be is worse than none. `Emails::preview()` draws it with the samples in.

## Tags

A tag's `type` says how what its callback returns goes in. `text`, the default, is escaped -- a billing name with
markup in it reads as the name. `html` goes in as it is. A component's name draws that component from what the
callback returns. Tags work in the subject and the preheader as well as the body, as plain words there. A tag nobody
registered is taken out and listed by `$email->unknown()`. `Tags::for_editor( [ 'shop' ] )` is the list an
`email_editor` field's Add Tag button takes.

## Components

Ten, by type: `button` (`text`, `url`, `background`), `alert` (`message`, `type`, optional bold `title`), `table`,
`key_value_list`, `order_items`, `downloads_list`, `code_block`, `coupon`, `divider` and `spacer`.

```php
Tags::add( 'shop', 'items', [
	'type'     => 'order_items',
	'callback' => fn( $order ) => [ 'items' => $order->lines, 'tax' => $order->tax, 'currency' => $order->currency ],
] );
```

`order_items` takes integer amounts in the smallest unit (written out by your `format` callable, by
`sugarcommerce/money` when installed, or as `EUR 49.99`) or strings already written out, and every word in it can be
replaced through `labels`. Add your own with the `Runtime::hook( 'components' )` filter (a class
implementing `Interfaces\Component`).

## Building one by hand

```php
Email::make()
	->to( $customer->email )
	->subject( __( 'Your sign-in link', 'my-plugin' ) )
	->content( '<p>' . esc_html( $link ) . '</p>' )
	->template( 'plain' )
	->reply_to( 'help@example.com' )
	->send();
```

`->text()` replaces the plain-text part, which is otherwise worked out from the HTML with every link kept. The
document says the site's language and direction (`lang`, `dir="rtl"`) whatever template drew it.

## Templates and theme overrides

Seven designs ship: `default`, `soft`, `mono`, `notification`, `plain`, `dark`, `invoice`. A theme overrides one with a
file of the same name in its own folder -- `sugarcommerce-emails/` unless the plugin names another, child theme first:

```php
\SugarCommerce\Emails\Templates::set_theme_dir( 'my-shop/emails' );
```

## Editable emails: the Mailer

A `Mailer` is what a plugin puts round its emails so the owner can change them: the wording, a switch, where a note to
the site goes, one look for all of them, and a settings panel per email with Preview and Send a test.

```php
$mailer = Mailer::register( 'myshop', [
	'option_name' => 'myshop_settings',
	'contexts'    => [ 'order' => [ 'load' => 'myshop_get_order', 'latest' => 'myshop_latest_order_id' ] ],
	'tags'        => fn( Mailer $m ) => $m->register_tag( 'customer_name', [ 'callback' => fn( $order ) => $order->name, 'sample' => 'Jane Doe' ] ),
	'emails'      => fn( Mailer $m ) => $m->register_email( 'receipt', [ 'subject' => 'Your order {order_number}', 'body' => "Hello {customer_name},\n\nThanks." ] ),
] );

$mailer->send( 'receipt', $order_id );
```

Built-in tags and emails are registered on `init` (or by whoever asks first); another plugin adds its own on
`myshop_register_email_tags` and `myshop_register_emails`, or earlier -- an early registration waits and replaces a
built-in of the same name. Every hook is named from the mailer's `prefix`.

**Saved wording and the switch.** Each panel saves `enabled`, `subject`, `heading`, `body`, `template` and, for an
email to the site, `recipient` under `{key_prefix}{email}` (`email_receipt`). A blank part is sent as the default; an
email switched off returns `true` without sending. Register the old body under `earlier` and a saved copy nobody
edited is replaced by the new wording.

**Recipients.** `customer` (the record's `email`, then `customer_email`), `admin` (the panel's Send to box, then the
`admin_email` key, then the site's address) or a callable. Everything comes from `from_name` / `from_address`, with a
Reply-To from `reply_to` when it is elsewhere; empty is the site's name at `wordpress@` its domain.

**Contexts.** An email names what it is about, which decides the tags its editor offers and the record they are given.
Add one with `{prefix}_email_contexts`.

**The look.** Design, colour, logo and size, heading alignment and footer, saved once and drawn into every send,
preview and test. `$mailer->look()->settings()` gives an email built by hand the same look. Another plugin adds a design
through `{prefix}_email_templates` and changes one email's look through `{prefix}_email_brand`.

**The settings screen.** `$mailer->fields()` is one `email_editor` field per email for wp-register-setting-fields and
wp-field-kit; `$mailer->look_card()->sections()` and `->fields()` are the Look card, whose preview redraws as the
controls change. Call `$mailer->panels()->fill_blanks()` before the screen draws. Every word can be replaced through
`labels`; `labels`, `templates` and `last_tag_group` can be closures, read late enough to translate.

**Keeping a plugin's stored keys.** Name the option (or give `settings`, `option` and `update` callables), the
`key_prefix`, and any key that does not follow it through `keys` -- `reply_to`, `admin_email` and `business_name` have
none until given one. `$mailer->store()->keys()` lists every key read or written.

## Sending through something other than wp_mail()

```php
use SugarCommerce\Emails\Delivery;
use SugarCommerce\Emails\Transports\{Memory, Postmark, Resend};

Delivery::use( new Postmark( $token, 'Shop <shop@example.com>' ) );          // everything this plugin sends
Mailer::register( 'myshop', [ 'transport' => new Resend( $key ) ] );        // one mailer, tests included
Email::make()->via( new Memory() );                                         // one email
Delivery::redirect( 'dev@example.com' );                                    // staging: one inbox, nobody else
```

A transport implements `Transport` (`send( Message ): Result`, `name()`). `WpMail` is the default and adds the
plain-text part through PHPMailer; `Postmark` and `Resend` post through the WordPress HTTP API; `Memory` keeps messages
for tests. A `Result` carries the provider's message id, or why it failed and whether trying again could help --
`$email->result()`, and the `Runtime::hook( 'delivered' )` action. The `{prefix}_email_transport` filter and the build's own
`transport` and `redirect` filters change them from outside.

## Moving from arraypress/wp-*

`ArrayPress\RegisterEmails\X` is now `SugarCommerce\Emails\X` unless the table says otherwise; every class, method,
configuration key, option key and `{prefix}_` hook of the Mailer keeps its name. Text domain `arraypress` is now
`sugarcommerce`.

| Old | New |
|---|---|
| `register_email_tag( $group, $name, $config )` | `Tags::add( $group, $name, $config )` |
| `register_email( $group, $name, $config )` | `Emails::add( $group, $name, $config )` |
| `send_registered_email( $group, $name, $overrides )` | `Emails::send( $group, $name, $overrides )` |
| `preview_registered_email( $group, $name, $overrides )` | `Emails::preview( $group, $name, $overrides )` |
| `compose_email()` | `Email::make()` |
| `email_tags_for_editor( $groups )` | `Tags::for_editor( (array) $groups )` |
| filters `register_emails_template`, `register_emails_template_options` | `Runtime::hook( 'template' )`, `Runtime::hook( 'template_options' )` (`emails_template` unprefixed, `{plugin}_emails_template` in a Strauss build) |
| default theme folder `register-emails/` | `sugarcommerce-emails/` (`Templates::set_theme_dir()` unchanged) |
| CSS classes `register-emails-*` | `sugarcommerce-emails-*` |
| `Sender::headers()`, `wp_mail()` in Send a test | unchanged, and the test now goes through the mailer's transport |
| `Email` headers kept as lines | kept apart (`Email::message()`); a second `from()` replaces the first |
| `ArrayPress\FormatUtils\Text::humanize()` labels | `SugarCommerce\Text\Text::humanize()` |
| `order_items` via wp-money's `format_money()` | `format` callable, `sugarcommerce/money`, or strings; translated words |
| `activity_log`, `event_details`, `info_box`, `product_list`, `progress_bar`, `raw_html`, `reward_balance`, `shipping_tracker`, `stats_grid`, `subscription_status`, `testimonial` | removed: nothing used them. `info_box` is `alert` with a `title`; `raw_html` is a tag of type `html` |

From the retired SaaS packages: `mailer`'s transports (`Transport`, `SendResult` as `Result`, `Postmark`, `Resend`,
`Memory`, the staging redirect, the plain-text part) and `email-templates`' preheader, `lang`/`dir`, reported unresolved
tags, pre-formatted amounts and the notice's bold title. Not carried over: the SES, SMTP, Cloudflare and log
transports and the log viewer (an SMTP plugin already sits behind `wp_mail()`; any other provider is one small
`Transport`), the MIME builder (PHPMailer's job here), and email-templates' own renderer, layouts, palettes and
front-matter files (the Mailer's look, the seven designs and gettext do those jobs in WordPress).

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
