<?php
/**
 * The parts: the store, the registry, the contexts, the sender, the look, the panels, the actions, the Look card and the transport.
 *
 * @package     SugarCommerce\Emails
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Emails\Traits\Mailer;

use SugarCommerce\Emails\Mailer;
use SugarCommerce\Emails\Mailer\Actions;
use SugarCommerce\Emails\Mailer\Contexts;
use SugarCommerce\Emails\Mailer\Look;
use SugarCommerce\Emails\Mailer\LookCard;
use SugarCommerce\Emails\Mailer\Panels;
use SugarCommerce\Emails\Mailer\Registry;
use SugarCommerce\Emails\Mailer\Sender;
use SugarCommerce\Emails\Mailer\Store;
use SugarCommerce\Emails\Transport;

/**
 * The parts: the store, the registry, the contexts, the sender, the look, the panels, the actions, the Look card and the transport.
 *
 * Each made when the mailer is, and shared.
 *
 * @since 1.0.0
 */
trait Parts {

	/**
	 * The parts.
	 *
	 * @return Store
	 */
	public function store(): Store {
		return $this->store;
	}

	/**
	 * @return Registry
	 */
	public function registry(): Registry {
		return $this->registry;
	}

	/**
	 * @return Contexts
	 */
	public function contexts(): Contexts {
		return $this->contexts;
	}

	/**
	 * @return Sender
	 */
	public function sender(): Sender {
		return $this->sender;
	}

	/**
	 * @return Look
	 */
	public function look(): Look {
		return $this->look;
	}

	/**
	 * @return Panels
	 */
	public function panels(): Panels {
		return $this->panels;
	}

	/**
	 * @return Actions
	 */
	public function actions(): Actions {
		return $this->actions;
	}

	/**
	 * @return LookCard
	 */
	public function look_card(): LookCard {
		return $this->look_card;
	}

	/**
	 * How its emails leave the site.
	 *
	 * @return Transport|null Null for the one everything goes through.
	 */
	public function transport(): ?Transport {
		$given     = $this->config( 'transport' );
		$transport = $given instanceof Transport ? $given : ( is_callable( $given ) ? call_user_func( $given, $this ) : null );

		/**
		 * Filter how this mailer's emails leave the site.
		 *
		 * Return a Transport to send them through an API -- Postmark,
		 * Resend, your own -- and the emails, their look and their
		 * settings screen stay as they are.
		 *
		 * @param Transport|null $transport Null for the usual.
		 * @param Mailer         $mailer    This mailer.
		 */
		$filtered = apply_filters( $this->hook( 'email_transport' ), $transport instanceof Transport ? $transport : null, $this );

		return $filtered instanceof Transport ? $filtered : null;
	}
}
