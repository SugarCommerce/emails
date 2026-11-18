/**
 * The Look card of sugarcommerce/emails.
 *
 * Three small jobs, all so that what is on the screen is what is drawn,
 * and a fourth so the folded card says what is in it.
 * The email under the card is redrawn whenever one of the card's controls
 * changes, without a save. The little pictures of each design take the
 * colour that is in the colour box. And every email card's Preview and
 * Send a test are sent the Look card's controls with their own, so an email
 * is previewed in the look on the screen and not in the one last saved.
 *
 * What it needs is on the preview's element, put there by LookCard: the
 * action route, its nonce, the action's name, and which setting each
 * control saves under, as `setting:look_part` pairs.
 */
( function () {
	'use strict';

	const frame = document.querySelector( '.sugarcommerce-emails-look-preview' );

	if ( ! frame ) {
		return;
	}

	const config = frame.dataset;

	// The card's controls, by the setting each saves under, and the name
	// each travels under in a request.
	const PARTS = {};

	( config.parts || '' ).split( ',' ).forEach( function ( pair ) {
		const at = pair.lastIndexOf( ':' );

		if ( at > 0 ) {
			PARTS[ pair.slice( 0, at ) ] = pair.slice( at + 1 );
		}
	} );

	/**
	 * One control's value: the chosen radio of a group, or the box itself.
	 *
	 * @param {string} setting The setting it saves under.
	 * @return {string|null} Its value, or null when it is not on the page.
	 */
	function read( setting ) {
		const inputs = document.querySelectorAll(
			'[name$="[' + setting + ']"]'
		);

		if ( ! inputs.length ) {
			return null;
		}

		let value = '';

		inputs.forEach( function ( input ) {
			if ( 'radio' === input.type || 'checkbox' === input.type ) {
				if ( input.checked ) {
					value = input.value;
				}

				return;
			}

			value = input.value;
		} );

		return value;
	}

	/**
	 * The look that is on the card now.
	 *
	 * @return {Object} Each control, under the name it travels by.
	 */
	function look() {
		const values = {};

		Object.keys( PARTS ).forEach( function ( setting ) {
			const value = read( setting );

			if ( null !== value ) {
				values[ PARTS[ setting ] ] = value;
			}
		} );

		return values;
	}

	// Every email card's Preview and Send a test: add the look to what the
	// card is about to send.
	document.addEventListener( 'fieldkit:action-payload', function ( event ) {
		if ( ! event.detail || ! event.detail.payload ) {
			return;
		}

		Object.assign( event.detail.payload, look() );
	} );

	if ( ! config.endpoint ) {
		return;
	}

	const card = frame.closest( '.field-kit__settings-card' ) || document;
	let timer = 0;
	let asked = 0;
	let last = '';

	/**
	 * Say what is in the card while it is folded.
	 *
	 * A folded card shows its title and nothing else: its description is
	 * inside the fold. The email cards under it each say a line about
	 * themselves, so this one does too, with the description it already
	 * has. Open, the description itself is showing and the line steps
	 * aside.
	 */
	function caption() {
		const summary = card.querySelector( ':scope > summary' );
		const description = card.querySelector(
			':scope > .field-kit__settings-card-description'
		);

		if (
			! summary ||
			! description ||
			summary.querySelector( '.sugarcommerce-emails-card-caption' )
		) {
			return;
		}

		const words = document.createElement( 'span' );
		const line = document.createElement( 'span' );

		words.className = 'sugarcommerce-emails-card-words';
		line.className = 'sugarcommerce-emails-card-caption';
		line.textContent = description.textContent.trim();

		while ( summary.firstChild ) {
			words.appendChild( summary.firstChild );
		}

		words.appendChild( line );
		summary.appendChild( words );
	}

	/**
	 * Give the pictures of each design the colour in the colour box.
	 */
	function paint() {
		const colour = read( config.colour || '' ) || '';

		if ( /^#[0-9a-f]{6}$/i.test( colour ) ) {
			card.style.setProperty( '--sugarcommerce-emails-look-colour', colour );

			// Dark words on a pale colour, as the email itself will have.
			const red = parseInt( colour.slice( 1, 3 ), 16 );
			const green = parseInt( colour.slice( 3, 5 ), 16 );
			const blue = parseInt( colour.slice( 5, 7 ), 16 );
			const pale = ( red * 299 + green * 587 + blue * 114 ) / 1000 > 150;

			card.style.setProperty(
				'--sugarcommerce-emails-look-on-colour',
				pale ? '#111111' : '#ffffff'
			);
		}
	}

	/**
	 * Draw the email in the look on the card.
	 */
	function draw() {
		// Folded away, the card has nobody looking at it. It is drawn
		// when it is opened.
		if ( false === card.open ) {
			return;
		}

		const values = look();
		const key = JSON.stringify( values );

		// Nothing changed that the email would show.
		if ( key === last ) {
			return;
		}

		last = key;

		const mine = ++asked;

		frame.classList.add( 'is-drawing' );

		window
			.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( {
					action: config.action,
					payload: values,
				} ),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				// An answer to a question that has since been asked again.
				if ( mine !== asked ) {
					return;
				}

				if (
					result &&
					result.success &&
					result.data &&
					result.data.html
				) {
					// Markup the library built on the server: one <iframe>
					// holding the email, escaped whole into its srcdoc.
					frame.innerHTML = result.data.html;
				} else {
					fail( result && result.message );
				}
			} )
			.catch( function () {
				if ( mine === asked ) {
					fail( '' );
				}
			} )
			.finally( function () {
				if ( mine === asked ) {
					frame.classList.remove( 'is-drawing' );
				}
			} );
	}

	/**
	 * Say the email could not be drawn.
	 *
	 * @param {string} message Why, when the server said.
	 */
	function fail( message ) {
		const note = document.createElement( 'p' );

		note.className = 'sugarcommerce-emails-look-preview__status';
		note.textContent = message || config.failed || '';

		frame.replaceChildren( note );

		// So the next change asks again, the same look included.
		last = '';
	}

	/**
	 * A control changed: repaint now, redraw once the typing has stopped.
	 */
	function changed() {
		paint();

		window.clearTimeout( timer );
		timer = window.setTimeout( draw, 350 );
	}

	card.addEventListener( 'input', changed );
	card.addEventListener( 'change', changed );

	// The colour box is WordPress's picker, which says it has changed with
	// a jQuery event and to nobody else; and the logo is chosen in the
	// media window, which writes the box without saying anything at all.
	if ( window.jQuery ) {
		window
			.jQuery( card )
			.on( 'change irischange wpcolorpickerchange', 'input', changed );
	}

	// The card is a <details>: draw the email when it is opened.
	card.addEventListener( 'toggle', draw );

	new window.MutationObserver( changed ).observe( card, {
		subtree: true,
		attributes: true,
		attributeFilter: [ 'value' ],
	} );

	caption();
	paint();
	draw();
} )();
