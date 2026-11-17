/* Checkout: the domain tiles of an order form (hosting address, email domain).
 *
 * The tiles are real radio inputs. This script shows the panel of the chosen tile and runs the Register and Transfer
 * panels: it searches with the public domain search route, adds a domain with the same cart route as the search page,
 * posts the chosen domain in a hidden value, and then asks the checkout for one refresh (update_checkout). It never
 * blocks the page: only the clicked button is disabled while its request runs. */
( function () {
	'use strict';

	var config = window.beltoftServicesOrderDomain;

	if ( ! config ) {
		return;
	}

	var text = config.text;
	var tax = 'excl';

	function el( tag, className, content ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( content ) {
			node.textContent = content;
		}

		return node;
	}

	function fill( template, value ) {
		return String( template ).replace( '%s', value );
	}

	function lineOf( node ) {
		return node.closest( '[data-od-line]' );
	}

	function panelOf( line, kind ) {
		return line.querySelector( '[data-od-panel="' + kind + '"]' );
	}

	// The order summary reads the choice from the posted form. WooCommerce aborts a refresh still running, so the last one wins.
	function refresh() {
		if ( window.jQuery && window.jQuery( 'form.checkout' ).length ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	function normalise( value ) {
		return String( value ).trim().toLowerCase().replace( /^https?:\/\//, '' ).replace( /^www\./, '' ).replace( /[/?#].*$/, '' );
	}

	function message( box, content, alert ) {
		box.replaceChildren();

		if ( content ) {
			var note = el( 'p', 'beltoft-services-od-note', content );

			if ( alert ) {
				note.setAttribute( 'role', 'alert' );
			}

			box.appendChild( note );
		}
	}

	function loader( box, content ) {
		box.replaceChildren( el( 'p', 'beltoft-services-od-note', content ) );
		box.setAttribute( 'aria-busy', 'true' );
	}

	function working( button, on, label ) {
		if ( on ) {
			button.dataset.label = button.textContent;
		}

		button.disabled = on;
		button.textContent = on ? label : ( button.dataset.label || button.textContent );
	}

	function json( url, options ) {
		return window.fetch( url, options ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} );
	}

	function check( name ) {
		return json( config.check + '?name=' + encodeURIComponent( name ) + ( config.tax ? '&tax=' + config.tax : '' ), { credentials: 'same-origin' } ).then( function ( answer ) {
			if ( ! answer.ok ) {
				throw new Error( answer.data.message || text.searchFailed );
			}

			tax = 'incl' === answer.data.tax ? 'incl' : 'excl';

			return answer.data;
		} );
	}

	function add( domain, mode, code ) {
		return json( config.cart, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WC-Nonce': config.nonce, 'X-WP-Nonce': config.restNonce },
			body: JSON.stringify( { domain: domain, mode: mode, auth_code: code || '', dns: '' } )
		} ).then( function ( answer ) {
			if ( ! answer.ok ) {
				throw new Error( answer.data.message || text.addFailed );
			}

			return answer.data;
		} );
	}

	// The domain this order holds: the hidden value, and the chosen box of the matching panel. The other panel keeps its form.
	function setChosen( line, domain, display, mode ) {
		var value = line.querySelector( '[data-od-value]' );

		if ( value ) {
			value.value = domain;
		}

		[ 'register', 'transfer' ].forEach( function ( kind ) {
			var panel = panelOf( line, kind );

			if ( ! panel ) {
				return;
			}

			var chosen = panel.querySelector( '[data-od-chosen]' );
			var form = panel.querySelector( '[data-od-form]' );
			var mine = domain && kind === mode;

			chosen.hidden = ! mine;
			form.hidden = !! mine;

			if ( mine ) {
				chosen.querySelector( '[data-od-chosen-text]' ).textContent = fill( 'transfer' === kind ? text.transferAdded : text.registerAdded, display );
			}
		} );

		heldNote( line );
	}

	// A domain the order holds while another tile is chosen: it stays in the order and is paid for, so the customer is told and can take it out.
	function heldNote( line ) {
		var value = line.querySelector( '[data-od-value]' );
		var domain = value ? value.value : '';
		var picked = line.querySelector( '[data-od-tiles] input[type="radio"]:checked' );
		var tiles = line.querySelector( '[data-od-tiles]' );
		var note = line.querySelector( '[data-od-held]' );
		var mode = '';

		[ 'register', 'transfer' ].forEach( function ( kind ) {
			var panel = panelOf( line, kind );
			var chosen = panel ? panel.querySelector( '[data-od-chosen]' ) : null;

			if ( chosen && ! chosen.hidden ) {
				mode = kind;
			}
		} );

		if ( ! domain || ! mode || ! picked || picked.value === mode || ! tiles ) {
			if ( note ) {
				note.hidden = true;
			}

			return;
		}

		if ( ! note ) {
			note = el( 'div', 'beltoft-services-od-chosen beltoft-services-od-held' );
			note.setAttribute( 'data-od-held', '' );
			note.appendChild( el( 'p', 'beltoft-services-od-chosen__text' ) );

			var remove = el( 'button', 'beltoft-services-od-link', text.remove );

			remove.type = 'button';
			remove.setAttribute( 'data-od-remove', '' );
			note.appendChild( remove );
			tiles.insertAdjacentElement( 'afterend', note );
		}

		note.querySelector( 'p' ).textContent = fill( 'transfer' === mode ? text.heldTransfer : text.heldRegister, domain );
		note.hidden = false;
	}

	function pickTile( line, kind ) {
		var radio = line.querySelector( '[data-od-tiles] input[type="radio"][value="' + kind + '"]' );

		if ( radio ) {
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function added( line, domain, display, mode ) {
		setChosen( line, domain, display, mode );
		pickTile( line, mode );
		refresh();
	}

	function priceHtml( item ) {
		var safe = el( 'span', '', item.price_text || '' );

		return safe.innerHTML;
	}

	// One result row of the register search.
	function registerRow( line, panel, item ) {
		var row = el( 'div', 'beltoft-services-od-row beltoft-services-od-row--' + item.status );
		var info = el( 'div', 'beltoft-services-od-row__info' );

		info.appendChild( el( 'strong', 'beltoft-services-od-row__name', item.display || item.domain ) );

		var states = { free: text.free, taken: text.taken, premium: text.premium, not_sold: text.notSold };

		info.appendChild( el( 'span', 'beltoft-services-od-row__state', states[ item.status ] || text.unknown ) );
		row.appendChild( info );

		if ( 'free' === item.status && null !== item.price && undefined !== item.price ) {
			var price = el( 'span', 'beltoft-services-od-row__price' );

			price.innerHTML = fill( text.perYear, priceHtml( item ) );
			row.appendChild( price );
		}

		if ( 'free' === item.status ) {
			var button = el( 'button', 'beltoft-services-od-button', text.add );

			button.type = 'button';
			button.setAttribute( 'aria-label', fill( text.addLabel, item.display || item.domain ) );
			button.addEventListener( 'click', function () {
				var results = panel.querySelector( '[data-od-results]' );

				working( button, true, text.adding );
				add( item.domain, 'register', '' ).then( function () {
					message( results, '' );
					added( line, item.domain, item.display || item.domain, 'register' );
				} ).catch( function ( error ) {
					working( button, false );
					message( results, error.message, true );
				} );
			} );
			row.appendChild( button );
		} else if ( 'taken' === item.status ) {
			var link = el( 'button', 'beltoft-services-od-link', text.transferInstead );

			link.type = 'button';
			link.addEventListener( 'click', function () {
				var other = panelOf( line, 'transfer' );

				if ( ! other ) {
					return;
				}

				other.querySelector( '[data-od-input]' ).value = item.domain;
				pickTile( line, 'transfer' );
				other.querySelector( '[data-od-go]' ).click();
			} );
			row.appendChild( link );
		}

		return row;
	}

	function search( line, panel ) {
		var results = panel.querySelector( '[data-od-results]' );
		var go = panel.querySelector( '[data-od-go]' );
		var typed = panel.querySelector( '[data-od-input]' ).value.trim();

		if ( ! typed ) {
			panel.querySelector( '[data-od-input]' ).focus();

			return;
		}

		loader( results, text.searching );
		go.disabled = true;
		check( typed ).then( function ( data ) {
			var typedExtension = typed.indexOf( '.' ) > 0;
			var shown = ( data.results || [] ).filter( function ( item, index ) {
				return 'not_sold' !== item.status || ( typedExtension && 0 === index );
			} );

			results.replaceChildren();

			if ( ! shown.length ) {
				message( results, text.noResults );

				return;
			}

			if ( shown.some( function ( item ) { return 'free' === item.status; } ) ) {
				results.appendChild( el( 'p', 'beltoft-services-od-note', 'incl' === tax ? text.priceInclVat : text.priceExclVat ) );
			}

			shown.forEach( function ( item ) {
				results.appendChild( registerRow( line, panel, item ) );
			} );
		} ).catch( function ( error ) {
			message( results, error.message, true );
		} ).then( function () {
			results.removeAttribute( 'aria-busy' );
			go.disabled = false;
		} );
	}

	// The transfer step after the domain is checked: its price and the transfer code.
	function transferForm( line, panel, item ) {
		var results = panel.querySelector( '[data-od-results]' );
		var card = el( 'div', 'beltoft-services-od-row beltoft-services-od-row--taken' );
		var info = el( 'div', 'beltoft-services-od-row__info' );

		results.replaceChildren( card );
		info.appendChild( el( 'strong', 'beltoft-services-od-row__name', item.display || item.domain ) );
		card.appendChild( info );

		if ( 'taken' !== item.status ) {
			info.appendChild( el( 'span', 'beltoft-services-od-row__state', 'free' === item.status ? text.notRegistered : ( 'premium' === item.status ? text.noTransfer : text.notSold ) ) );

			if ( 'free' === item.status ) {
				var instead = el( 'button', 'beltoft-services-od-link', text.registerInstead );

				instead.type = 'button';
				instead.addEventListener( 'click', function () {
					var other = panelOf( line, 'register' );

					if ( ! other ) {
						return;
					}

					other.querySelector( '[data-od-input]' ).value = item.domain;
					pickTile( line, 'register' );
					other.querySelector( '[data-od-go]' ).click();
				} );
				card.appendChild( instead );
			}

			return;
		}

		info.appendChild( el( 'span', 'beltoft-services-od-row__state', text.registered ) );

		var price = el( 'span', 'beltoft-services-od-row__price' );

		if ( item.free_transfer ) {
			price.textContent = text.freeTransfer;
			info.appendChild( el( 'span', 'beltoft-services-od-row__state', text.freeTransferNote ) );
		} else if ( null !== item.price && undefined !== item.price ) {
			price.innerHTML = fill( text.perYear, priceHtml( item ) );
			info.appendChild( el( 'span', 'beltoft-services-od-row__state', text.paidTransferNote ) );
		}

		card.appendChild( price );

		var form = el( 'div', 'beltoft-services-od-code' );
		var code = el( 'input', '' );

		code.type = 'text';
		code.autocomplete = 'off';
		code.spellcheck = false;
		code.setAttribute( 'autocapitalize', 'none' );
		code.setAttribute( 'aria-label', fill( text.codeLabel, item.display || item.domain ) );
		code.placeholder = text.codePlaceholder;

		var button = el( 'button', 'beltoft-services-od-button', text.addTransfer );

		button.type = 'button';
		form.append( code, button );
		results.appendChild( form );

		function go() {
			var value = code.value.trim();

			if ( ! value ) {
				code.focus();

				return;
			}

			working( button, true, text.adding );
			add( item.domain, 'transfer', value ).then( function () {
				message( results, '' );
				added( line, item.domain, item.display || item.domain, 'transfer' );
			} ).catch( function ( error ) {
				working( button, false );
				results.appendChild( el( 'p', 'beltoft-services-od-note', error.message ) ).setAttribute( 'role', 'alert' );
			} );
		}

		button.addEventListener( 'click', go );
		code.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				go();
			}
		} );
		code.focus();
	}

	function transfer( line, panel ) {
		var results = panel.querySelector( '[data-od-results]' );
		var go = panel.querySelector( '[data-od-go]' );
		var wanted = normalise( panel.querySelector( '[data-od-input]' ).value );

		if ( ! wanted ) {
			panel.querySelector( '[data-od-input]' ).focus();

			return;
		}

		if ( wanted.indexOf( '.' ) < 1 ) {
			message( results, text.fullDomain, true );

			return;
		}

		loader( results, text.checking );
		go.disabled = true;
		check( wanted ).then( function ( data ) {
			var rows = data.results || [];
			var item = rows.filter( function ( row ) { return row.domain === wanted; } )[ 0 ] || rows[ 0 ];

			if ( ! item ) {
				message( results, text.searchFailed, true );

				return;
			}

			transferForm( line, panel, item );
		} ).catch( function ( error ) {
			message( results, error.message, true );
		} ).then( function () {
			results.removeAttribute( 'aria-busy' );
			go.disabled = false;
		} );
	}

	// The chosen tile shows its panel.
	function show( line ) {
		var picked = line.querySelector( '[data-od-tiles] input[type="radio"]:checked' );
		var mode = picked ? picked.value : '';

		line.querySelectorAll( '[data-od-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-od-panel' ) !== mode;
		} );

		heldNote( line );
	}

	document.querySelectorAll( '[data-od-line]' ).forEach( heldNote );

	// The phone row of the checkout is printed when the page loads. A domain added in the page needs a phone number for the
	// registrar: when the refresh says so and the row is not there, the page loads once more (the site keeps what was typed).
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', function () {
			var marker = document.querySelector( '.beltoft-services-od-phone' );

			if ( ! marker || '1' !== marker.getAttribute( 'data-od-phone' ) || document.getElementById( 'billing_phone' ) ) {
				return;
			}

			try {
				if ( Date.now() - Number( window.sessionStorage.getItem( 'beltoftOdReload' ) || 0 ) < 60000 ) {
					return;
				}

				window.sessionStorage.setItem( 'beltoftOdReload', String( Date.now() ) );
			} catch ( e ) {
				return;
			}

			window.location.reload();
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches && event.target.matches( '[data-od-tiles] input[type="radio"]' ) && lineOf( event.target ) ) {
			show( lineOf( event.target ) );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest ? event.target.closest( '[data-od-go], [data-od-remove]' ) : null;
		var panel = target ? target.closest( '[data-od-panel]' ) : null;
		var held = target ? target.closest( '[data-od-held]' ) : null;

		if ( ! target || ( ! panel && ! held ) || ! lineOf( panel || held ) ) {
			return;
		}

		var line = lineOf( panel || held );
		var kind = panel ? panel.getAttribute( 'data-od-panel' ) : '';

		if ( target.hasAttribute( 'data-od-go' ) ) {
			if ( 'transfer' === kind ) {
				transfer( line, panel );
			} else {
				search( line, panel );
			}

			return;
		}

		var value = line.querySelector( '[data-od-value]' );
		var domain = value ? value.value : '';

		working( target, true, text.removing );
		window.jQuery.ajax( {
			url: config.remove,
			method: 'POST',
			dataType: 'json',
			data: { nonce: config.removeNonce, domain: domain }
		} ).done( function () {
			setChosen( line, '', '', '' );
			refresh();
		} ).fail( function () {
			var box = ( panel || line.querySelector( '[data-od-panel]:not([hidden])' ) || line ).querySelector( '[data-od-results]' );

			if ( box ) {
				message( box, text.removeFailed, true );
			}
		} ).always( function () {
			working( target, false );
		} );
	} );

	// Enter in these inputs searches; it must never send the checkout.
	document.addEventListener( 'keydown', function ( event ) {
		var input = event.target && event.target.matches ? event.target : null;

		if ( 'Enter' !== event.key || ! input || ! input.matches( '[data-od-input]' ) ) {
			return;
		}

		event.preventDefault();
		input.closest( '[data-od-panel]' ).querySelector( '[data-od-go]' ).click();
	} );
}() );
