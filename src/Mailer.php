<?php
/**
 * Mailer
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails;

use Closure;
use SugarCommerce\Emails\Mailer\Actions;
use SugarCommerce\Emails\Mailer\Contexts;
use SugarCommerce\Emails\Mailer\Look;
use SugarCommerce\Emails\Mailer\LookCard;
use SugarCommerce\Emails\Mailer\Panels;
use SugarCommerce\Emails\Mailer\Registry;
use SugarCommerce\Emails\Mailer\Sender;
use SugarCommerce\Emails\Mailer\Store;
use SugarCommerce\Emails\Traits\Mailer\Labels;
use SugarCommerce\Emails\Traits\Mailer\Parts;
use SugarCommerce\Emails\Traits\Mailer\Registered;

/**
 * A plugin's emails, editable by whoever runs the site.
 *
 * `Emails` and `Tags` build an email from a default subject and body. This is
 * what a plugin puts round them so the site owner can change them: the
 * wording each email is sent with, a switch to turn one off, where a note to
 * the site goes, what every email looks like, and a settings panel per email
 * with Preview and Send a test that work from what is on the screen, saved or
 * not.
 *
 * Everything it stores is under keys the plugin names, in the option the
 * plugin keeps its settings in, so a plugin that wrote these settings itself
 * before can hand them over without anybody losing what they wrote. And every
 * hook it fires is named from the plugin's prefix, because two plugins can
 * each bundle a copy.
 *
 *     $mailer = Mailer::register( 'myshop', [
 *         'option_name' => 'myshop_settings',
 *         'contexts'    => [
 *             'order' => [ 'load' => 'myshop_get_order', 'latest' => 'myshop_latest_order_id' ],
 *         ],
 *         'emails'      => static function ( Mailer $mailer ): void {
 *             $mailer->register_email( 'receipt', [ ... ] );
 *         },
 *     ] );
 *
 *     $mailer->send( 'receipt', $order_id );
 *
 * The parts each do one job and are reached from here: `store()` reads and
 * writes the settings, `registry()` keeps what was registered, `contexts()`
 * says what an email can be about, `sender()` sends, `look()` is the design,
 * colour, logo and footer, `panels()` and `look_card()` are the settings
 * screen, and `actions()` are its Preview and Send a test buttons.
 */
final class Mailer {

	use Labels;
	use Parts;
	use Registered;

	/**
	 * Everything a mailer can be told, and what it assumes when it is not.
	 *
	 * @var array<string, mixed>
	 */
	private const array DEFAULTS = [
		// What its hooks are named from: `{prefix}_send_email` and the rest.
		// The id when left out.
		'prefix'          => '',

		// The group its emails are registered in with Emails, and the tag
		// group of a context that does not name one. The id when left out.
		'group'           => '',

		// Where its settings live. One option holding an array, unless
		// `settings`, `option` and `update` say otherwise.
		'option_name'     => '',

		// Callables in place of the option: every setting as stored, one
		// setting as the settings screen reads it, and writing one.
		'settings'        => null,
		'option'          => null,
		'update'          => null,

		// Each key is this and a name: `email_receipt`, `email_template`.
		'key_prefix'      => 'email_',

		// Any key by its part, in place of the prefixed one. See Store.
		'keys'            => [],

		// What an email can be about. See Contexts.
		'contexts'        => [],
		'default_context' => '',

		// Who a `customer` email goes to when the record has no `email`.
		// Given the record, returns an address.
		'customer_email'  => null,

		// Run by ready(), in this order and around the two actions: the
		// built-in tags, then the built-in emails.
		'tags'            => null,
		'emails'          => null,

		// The `init` priority ready() runs at. False to call it yourself.
		'priority'        => 15,

		// The designs offered, by name: `label`, `description`, and `file`
		// for one that is not among the library's templates. Null for the
		// library's own.
		'templates'       => null,
		'colour'          => '#2271b1',
		'logo_heights'    => [
			'small'  => 32,
			'medium' => 48,
			'large'  => 72,
		],

		// The footer of a site that has not written one, as plain text.
		'default_footer'  => null,

		// Fills tags into the footer a site wrote: given the text, returns
		// it. `{year}` and `{site_name}` when left out.
		'fill_footer'     => null,

		// Where each email's panel goes on the settings screen.
		'panel'           => [
			'tab'     => 'emails',
			'section' => 'transactional',
		],

		// The Look card. See LookCard.
		'look_card'       => [],

		// A tag heading listed after all the others in Add Tag.
		'last_tag_group'  => '',

		// Any of the words shown, in place of the library's. See label().
		'labels'          => [],

		// `templates`, `last_tag_group` and `labels` can each be a closure
		// returning the value instead, called the first time it is needed:
		// a plugin that registers its mailer before `init` cannot translate
		// its words yet. See self::LATE.

		// The plugin's own wrappers for `register_tag` and `register_email`,
		// by method, so a mistake is reported under the name that was
		// called.
		'functions'       => [],

		// How its emails leave the site: a Transport, or a callable returning
		// one. Null for the one everything goes through -- wp_mail(), unless
		// Delivery::use() said otherwise.
		'transport'       => null,
	];

	/**
	 * The settings that can be given as a closure, to be worked out when they
	 * are first read rather than when the mailer is registered.
	 *
	 * @var array<int, string>
	 */
	private const array LATE = [ 'templates', 'last_tag_group', 'labels' ];

	/**
	 * Every mailer, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $mailers = [];

	/**
	 * Its id.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Its configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Whether ready() has run.
	 *
	 * @var bool
	 */
	private bool $ready = false;

	/**
	 * The parts.
	 */
	private Store $store;
	private Registry $registry;
	private Contexts $contexts;
	private Sender $sender;
	private Look $look;
	private Panels $panels;
	private Actions $actions;
	private LookCard $look_card;

	/**
	 * Set one up.
	 *
	 * Registering an id again replaces the mailer that had it.
	 *
	 * @param string               $id     Its id: the plugin, usually.
	 * @param array<string, mixed> $config See self::DEFAULTS.
	 *
	 * @return self
	 */
	public static function register( string $id, array $config = [] ): self {
		$mailer = new self( sanitize_key( $id ), $config );

		self::$mailers[ $mailer->id ] = $mailer;

		if ( false !== $mailer->config['priority'] && function_exists( 'add_action' ) ) {
			add_action( 'init', [ $mailer, 'ready' ], (int) $mailer->config['priority'] );
		}

		if ( [] !== $mailer->config['look_card'] && function_exists( 'add_action' ) ) {
			add_action( 'admin_enqueue_scripts', [ $mailer->look_card, 'assets' ] );
		}

		return $mailer;
	}

	/**
	 * One mailer, or null.
	 *
	 * @param string $id Its id.
	 *
	 * @return self|null
	 */
	public static function get( string $id ): ?self {
		return self::$mailers[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Forget one mailer, or all of them.
	 *
	 * @param string $id Its id, or empty for all.
	 *
	 * @return void
	 */
	public static function forget( string $id = '' ): void {
		if ( '' === $id ) {
			self::$mailers = [];

			return;
		}

		unset( self::$mailers[ sanitize_key( $id ) ] );
	}

	/**
	 * The address WordPress sends from when nobody has said otherwise.
	 *
	 * `wordpress@` the site's host, without a `www.`, worked out the way
	 * wp_mail() works it out. It is what an empty From address box means,
	 * so it is what the box can show as its placeholder.
	 *
	 * @return string
	 */
	public static function default_address(): string {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );

		return 'wordpress@' . ( str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host );
	}

	/**
	 * Construct.
	 *
	 * @param string               $id     Its id.
	 * @param array<string, mixed> $config Its configuration.
	 */
	private function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = array_merge( self::DEFAULTS, $config );

		foreach ( [ 'prefix', 'group' ] as $name ) {
			$this->config[ $name ] = '' === (string) $this->config[ $name ] ? $id : (string) $this->config[ $name ];
		}

		if ( '' === (string) $this->config['option_name'] ) {
			$this->config['option_name'] = $id . '_settings';
		}

		$this->store     = new Store( $this );
		$this->registry  = new Registry( $this );
		$this->contexts  = new Contexts( $this );
		$this->sender    = new Sender( $this );
		$this->look      = new Look( $this );
		$this->panels    = new Panels( $this );
		$this->actions   = new Actions( $this );
		$this->look_card = new LookCard( $this );
	}

	/**
	 * Its id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The group its emails are registered in with Emails.
	 *
	 * @return string
	 */
	public function group(): string {
		return (string) $this->config['group'];
	}

	/**
	 * A hook's name: the prefix, then what it is for.
	 *
	 * @param string $suffix What it is for, such as `send_email`.
	 *
	 * @return string
	 */
	public function hook( string $suffix ): string {
		return $this->config['prefix'] . '_' . $suffix;
	}

	/**
	 * One configuration value.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback Returned when it is not set.
	 *
	 * @return mixed
	 */
	public function config( string $key, mixed $fallback = null ): mixed {
		if ( in_array( $key, self::LATE, true ) && ( $this->config[ $key ] ?? null ) instanceof Closure ) {
			$this->config[ $key ] = call_user_func( $this->config[ $key ], $this );
		}

		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Register the built-in tags and emails, and let others add theirs.
	 *
	 * Once, whoever asks first. A settings screen is often put together
	 * before `init` would run this, and needs the tags and the default
	 * wording to draw its editors -- so everything that needs them asks,
	 * and the hook that asks again later finds it done.
	 *
	 * From here a registration goes straight in. Until here one is kept,
	 * and added at the end: after the plugin's own, so that another
	 * plugin's tag of the same name is the one that is used.
	 *
	 * @return void
	 */
	public function ready(): void {
		if ( $this->ready ) {
			return;
		}

		$this->ready = true;

		$this->registry->open();

		if ( is_callable( $this->config['tags'] ) ) {
			call_user_func( $this->config['tags'], $this );
		}

		/**
		 * Fires when the built-in merge tags have been registered.
		 *
		 * Where another plugin adds its own, with register_tag().
		 *
		 * @param Mailer $mailer This mailer.
		 */
		do_action( $this->hook( 'register_email_tags' ), $this );

		if ( is_callable( $this->config['emails'] ) ) {
			call_user_func( $this->config['emails'], $this );
		}

		/**
		 * Fires once the built-in emails are registered.
		 *
		 * Where another plugin registers its own, with register_email().
		 *
		 * @param string $group The group they are registered in with Emails.
		 * @param Mailer $mailer This mailer.
		 */
		do_action( $this->hook( 'register_emails' ), $this->group(), $this );

		$this->registry->flush();
	}

	/**
	 * Whether ready() has run.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return $this->ready;
	}
}
