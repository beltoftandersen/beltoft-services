/**
 * One place for feedback on My Account and the checkout fields: a status bar, a lock for the buttons of the
 * service that is working, and a styled question (dialog) that replaces window.confirm / alert / prompt.
 *
 *   var h = beltoftStatus.busy( 'Rebooting your server…', 'resource-id', button? );   // bar + lock, returns a handle
 *   beltoftStatus.done( h, 'Your server has restarted.' );                           // shown about 5 s, then fades
 *   beltoftStatus.fail( h, 'Failed: …', fieldElementOrSelector? );                   // stays until closed; the field gets the message too
 *   beltoftStatus.info( 'Copied.' );                                                  // like done, without a handle
 *   beltoftStatus.confirm( { title, text, confirm, cancel, danger } ).then( function ( yes ) {} );
 *   beltoftStatus.locked( scope );                                                    // true while a handle of that scope is busy
 *
 * Scope: the service's resource id (the container carries data-bs-scope="<id>"; a [data-resource] element is marked
 * automatically), or 'page' for the whole content area. Only the latest message is shown. No dependencies.
 */
( function () {
	'use strict';

	if ( window.beltoftStatus ) {
		return;
	}

	var config = window.beltoftStatusConfig || {};
	var strings = config.strings || {};
	var SELECTOR = 'button, input[type="submit"], input[type="button"], [data-bs-action]';
	var DONE_MS = 5000;
	var bar = null;
	var current = null;
	var timers = { done: 0, fade: 0 };
	var active = [];
	var recent = { el: null, at: 0 };
	var fieldNotes = [];

	function text( key, fallback ) {
		return strings[ key ] || fallback;
	}

	function reduced() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function make( tag, className, content ) {
		var el = document.createElement( tag );

		if ( className ) {
			el.className = className;
		}

		if ( undefined !== content ) {
			el.textContent = content;
		}

		return el;
	}

	// ---- the bar ----------------------------------------------------------------------------------------------

	function contentArea() {
		return document.querySelector( '.woocommerce-MyAccount-content' ) || document.querySelector( '[data-bs-scope="page"]' ) || document.querySelector( 'main' ) || document.body;
	}

	// Below the page's tabs when it has any, else at the top of the content area.
	function place() {
		var area = contentArea();
		var tabs = area.querySelectorAll( 'nav.beltoft-services-tabs, nav.beltoft-services-billing-tabs, .beltoft-services-billing-tabs, nav.beltoft-services-account-tabs' );
		var anchor = tabs.length ? tabs[ tabs.length - 1 ] : null;

		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( bar, anchor.nextSibling );
		} else if ( area.firstChild ) {
			area.insertBefore( bar, area.firstChild );
		} else {
			area.appendChild( bar );
		}
	}

	// The fixed site header covers the top of the viewport: the bar sticks right under it.
	function offset() {
		var top = 0;
		var nodes = document.querySelectorAll( 'header, #brx-header, .brx-header' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			var style = window.getComputedStyle( node );
			var box;

			if ( 'fixed' === style.position || 'sticky' === style.position ) {
				box = node.getBoundingClientRect();

				if ( box.top <= 1 && box.height < 240 ) {
					top = Math.max( top, box.bottom );
				}
			}
		} );

		if ( bar ) {
			bar.style.setProperty( '--bs-header-offset', Math.round( top ) + 'px' );
		}
	}

	function ensure() {
		if ( bar && document.body.contains( bar ) ) {
			return bar;
		}

		bar = make( 'div', 'bs-status' );
		bar.hidden = true;
		bar.setAttribute( 'data-bs-status', '' );
		bar.setAttribute( 'aria-live', 'polite' );
		bar.appendChild( make( 'span', 'bs-status__text' ) );

		var close = make( 'button', 'bs-status__close' );
		close.type = 'button';
		close.setAttribute( 'aria-label', text( 'close', 'Close' ) );
		close.setAttribute( 'data-bs-keep', '' );
		close.textContent = '×';
		close.addEventListener( 'click', function () {
			hide( true );
		} );
		bar.appendChild( close );

		place();
		offset();

		return bar;
	}

	function clearTimers() {
		window.clearTimeout( timers.done );
		window.clearTimeout( timers.fade );
		timers.done = 0;
		timers.fade = 0;
	}

	function hide( now ) {
		if ( ! bar ) {
			return;
		}

		clearTimers();

		if ( now || reduced() ) {
			bar.hidden = true;
			bar.classList.remove( 'is-fading' );
			current = null;

			return;
		}

		bar.classList.add( 'is-fading' );
		timers.fade = window.setTimeout( function () {
			bar.hidden = true;
			bar.classList.remove( 'is-fading' );
			current = null;
		}, 400 );
	}

	// kind: busy, done, fail, info. Only the newest message is shown.
	function show( kind, message, handle ) {
		var box = ensure();
		var label = box.querySelector( '.bs-status__text' );
		var close = box.querySelector( '.bs-status__close' );
		var prefix = 'fail' === kind ? text( 'failedPrefix', 'Failed: ' ) : '';

		clearTimers();
		box.classList.remove( 'is-fading', 'is-busy', 'is-done', 'is-fail', 'is-info' );
		box.classList.add( 'is-' + kind );
		box.setAttribute( 'data-bs-kind', kind );
		box.setAttribute( 'role', 'fail' === kind ? 'alert' : 'status' );
		box.setAttribute( 'aria-busy', 'busy' === kind ? 'true' : 'false' );
		box.setAttribute( 'aria-live', 'fail' === kind ? 'assertive' : 'polite' );
		label.textContent = prefix + message;
		close.hidden = 'fail' !== kind;
		box.hidden = false;
		current = handle || null;

		if ( 'done' === kind || 'info' === kind ) {
			timers.done = window.setTimeout( function () {
				hide( false );
			}, DONE_MS );
		}

		// A failure may be above the viewport on a phone: bring it into view.
		if ( 'fail' === kind && box.scrollIntoView && box.getBoundingClientRect().top < 0 ) {
			box.scrollIntoView( { block: 'center' } );
		}
	}

	// ---- the lock -----------------------------------------------------------------------------------------------

	function container( scope ) {
		var found;

		if ( ! scope || 'page' === scope ) {
			return document.querySelector( '[data-bs-scope="page"]' ) || contentArea();
		}

		found = document.querySelector( '[data-bs-scope="' + String( scope ).replace( /["\\]/g, '' ) + '"]' );

		return found || contentArea();
	}

	function lockable( el, area ) {
		return ! el.closest( 'dialog' ) && ! el.closest( '[data-bs-status]' ) && ! el.hasAttribute( 'data-bs-keep' ) && area.contains( el );
	}

	function lockButton( el, label ) {
		var n = el.beltoftLocks || 0;

		if ( ! n ) {
			el.beltoftWasDisabled = el.disabled || 'true' === el.getAttribute( 'aria-disabled' );
			el.beltoftTitle = el.getAttribute( 'title' );
			el.disabled = true;

			if ( 'BUTTON' !== el.tagName && 'INPUT' !== el.tagName ) {
				el.setAttribute( 'aria-disabled', 'true' );
				el.setAttribute( 'data-bs-locked', '' );
			}
		}

		el.setAttribute( 'title', label );
		el.beltoftLocks = n + 1;
	}

	function unlockButton( el ) {
		var n = ( el.beltoftLocks || 0 ) - 1;

		el.beltoftLocks = Math.max( 0, n );

		if ( el.beltoftLocks > 0 ) {
			return;
		}

		if ( ! el.beltoftWasDisabled ) {
			el.disabled = false;
			el.removeAttribute( 'aria-disabled' );
		}

		el.removeAttribute( 'data-bs-locked' );

		if ( null === el.beltoftTitle || undefined === el.beltoftTitle ) {
			el.removeAttribute( 'title' );
		} else {
			el.setAttribute( 'title', el.beltoftTitle );
		}
	}

	function chosen( button ) {
		if ( button && button.nodeType ) {
			return button;
		}

		if ( recent.el && Date.now() - recent.at < 1500 && document.body.contains( recent.el ) ) {
			return recent.el;
		}

		return document.activeElement && document.activeElement.matches && document.activeElement.matches( SELECTOR ) ? document.activeElement : null;
	}

	function release( handle ) {
		var index = active.indexOf( handle );

		if ( index < 0 || handle.released ) {
			return;
		}

		handle.released = true;
		active.splice( index, 1 );

		handle.locked.forEach( unlockButton );
		handle.locked = [];

		if ( handle.button ) {
			handle.button.removeAttribute( 'aria-busy' );
			handle.button.classList.remove( 'is-busy' );

			if ( undefined !== handle.button.beltoftLabel && handle.button.beltoftOwner === handle ) {
				handle.button.textContent = handle.button.beltoftLabel;
				handle.button.beltoftLabel = undefined;
				handle.button.beltoftOwner = null;
			}

			if ( ! handle.button.beltoftLocks && ! handle.beltoftButtonWasDisabled ) {
				handle.button.disabled = false;
			}
		}
	}

	function isLocked( scope ) {
		return active.some( function ( handle ) {
			return handle.scope === ( scope || 'page' );
		} );
	}

	// ---- field messages -------------------------------------------------------------------------------------------

	function resolveField( field ) {
		if ( ! field ) {
			return null;
		}

		if ( 'string' === typeof field ) {
			return document.querySelector( field );
		}

		return field.nodeType ? field : null;
	}

	function clearFields() {
		fieldNotes.forEach( function ( note ) {
			if ( note.node.parentNode ) {
				note.node.parentNode.removeChild( note.node );
			}

			note.field.removeAttribute( 'aria-invalid' );

			if ( note.describedby ) {
				note.field.setAttribute( 'aria-describedby', note.describedby );
			} else {
				note.field.removeAttribute( 'aria-describedby' );
			}
		} );

		fieldNotes = [];
	}

	function fieldNote( field, message ) {
		var node = make( 'p', 'bs-field-error', message );
		var anchor = field;
		var id = 'bs-field-error-' + ( fieldNotes.length + 1 ) + '-' + Date.now();
		var before = field.getAttribute( 'aria-describedby' );

		clearFields();
		node.id = id;
		node.setAttribute( 'data-bs-field-error', '' );

		// Right under the field; a field inside a label or a row of inputs gets it after the row.
		if ( anchor.nextSibling ) {
			anchor.parentNode.insertBefore( node, anchor.nextSibling );
		} else {
			anchor.parentNode.appendChild( node );
		}

		field.setAttribute( 'aria-invalid', 'true' );
		field.setAttribute( 'aria-describedby', before ? before + ' ' + id : id );
		fieldNotes.push( { node: node, field: field, describedby: before } );

		// The error goes when the customer changes the field.
		var gone = function () {
			field.removeEventListener( 'input', gone );
			field.removeEventListener( 'change', gone );
			clearFields();
		};

		field.addEventListener( 'input', gone );
		field.addEventListener( 'change', gone );
	}

	// ---- the API ----------------------------------------------------------------------------------------------

	function busy( message, scope, button ) {
		var key = scope || 'page';
		var area = container( key );
		var el = chosen( button );
		var handle = { scope: key, text: message, locked: [], button: null, released: false };
		var label = text( 'wait', 'Wait for %s to finish' ).replace( '%s', function () {
			return String( message ).replace( /[….]+$/, '' ).toLowerCase();
		} );

		clearFields();

		if ( el && ( area.contains( el ) || 'page' === key ) ) {
			handle.button = el;
			handle.beltoftButtonWasDisabled = el.disabled;
			el.disabled = true;
			el.setAttribute( 'aria-busy', 'true' );
			el.classList.add( 'is-busy' );
		}

		Array.prototype.forEach.call( area.querySelectorAll( SELECTOR ), function ( other ) {
			if ( other !== handle.button && lockable( other, area ) ) {
				lockButton( other, label );
				handle.locked.push( other );
			}
		} );

		active.push( handle );
		show( 'busy', message, handle );

		return handle;
	}

	function finish( handle, kind, message ) {
		if ( handle ) {
			release( handle );
		}

		// A newer request is still working: its busy line stays, a failure is the only thing that may replace it.
		if ( active.length && 'fail' !== kind ) {
			return;
		}

		show( kind, message, handle );
	}

	function done( handle, message ) {
		finish( handle, 'done', message );
	}

	function fail( handle, message, field ) {
		var target = resolveField( field );

		finish( handle, 'fail', message );

		if ( target ) {
			fieldNote( target, text( 'failedPrefix', 'Failed: ' ) + message );

			if ( target.focus ) {
				target.focus();
			}
		}
	}

	function info( message ) {
		show( 'info', message, null );
	}

	// ---- the question --------------------------------------------------------------------------------------------

	var dialog = null;

	function build() {
		var form;
		var buttons;

		dialog = make( 'dialog', 'beltoft-services-vm__dialog bs-dialog' );
		dialog.setAttribute( 'aria-labelledby', 'bs-dialog-title' );
		form = make( 'form', 'bs-dialog__form' );
		form.method = 'dialog';
		form.appendChild( make( 'h5', '', '' ) ).id = 'bs-dialog-title';
		form.appendChild( make( 'div', 'bs-dialog__body' ) );
		buttons = make( 'p', 'beltoft-services-vm__buttons' );

		var yes = make( 'button', 'button woocommerce-button bs-dialog__yes' );
		yes.type = 'submit';
		yes.value = 'yes';
		var no = make( 'button', 'button woocommerce-button bs-dialog__no' );
		no.type = 'button';
		buttons.appendChild( yes );
		buttons.appendChild( no );
		form.appendChild( buttons );
		dialog.appendChild( form );
		document.body.appendChild( dialog );

		no.addEventListener( 'click', function () {
			close( false );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			close( true );
		} );

		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			close( false );
		} );

		dialog.addEventListener( 'click', function ( event ) {
			// A click on the backdrop is a no.
			if ( event.target === dialog ) {
				close( false );
			}
		} );
	}

	var pending = null;

	function close( answer ) {
		var resolve = pending;

		if ( ! resolve ) {
			return;
		}

		pending = null;

		if ( dialog.close ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}

		resolve( answer );
	}

	// Resolves true for the confirm button, false for Cancel, Esc or the backdrop. The safe button has the focus.
	function confirm( options ) {
		var o = options || {};
		var body;
		var yes;
		var no;
		var heading;

		if ( ! dialog ) {
			build();
		}

		// A question is already open: the newer one waits for nothing, it answers no.
		if ( pending ) {
			return Promise.resolve( false );
		}

		heading = dialog.querySelector( 'h5' );
		body = dialog.querySelector( '.bs-dialog__body' );
		yes = dialog.querySelector( '.bs-dialog__yes' );
		no = dialog.querySelector( '.bs-dialog__no' );
		heading.textContent = o.title || '';
		heading.hidden = ! o.title;

		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		( Array.isArray( o.text ) ? o.text : ( o.text ? [ o.text ] : [] ) ).forEach( function ( line ) {
			body.appendChild( make( 'p', '', line ) );
		} );

		yes.textContent = o.confirm || text( 'ok', 'OK' );
		yes.classList.toggle( 'bs-danger', !! o.danger );
		no.textContent = o.cancel || text( 'cancel', 'Cancel' );
		no.hidden = false === o.cancel;

		return new Promise( function ( resolve ) {
			pending = resolve;

			if ( dialog.showModal ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}

			( false === o.cancel ? yes : no ).focus();
		} );
	}

	// ---- setup ----------------------------------------------------------------------------------------------------

	// Which button was pressed last: busy() uses it when a script does not name the button.
	document.addEventListener( 'click', function ( event ) {
		var el = event.target && event.target.closest ? event.target.closest( SELECTOR ) : null;

		if ( el ) {
			recent = { el: el, at: Date.now() };
		}
	}, true );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.submitter ) {
			recent = { el: event.submitter, at: Date.now() };
		}
	}, true );

	// A locked link-style action does nothing, however often it is clicked.
	document.addEventListener( 'click', function ( event ) {
		var el = event.target && event.target.closest ? event.target.closest( '[data-bs-locked], .is-busy' ) : null;

		if ( el && ( el.hasAttribute( 'data-bs-locked' ) || 'true' === el.getAttribute( 'aria-busy' ) ) && 'BUTTON' !== el.tagName ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );

	// A form that posts and reloads (credit code, address, account details, card): data-bs-working="Working…" shows the bar and locks the page's buttons on submit.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var label = form && form.getAttribute ? form.getAttribute( 'data-bs-working' ) : null;
		var submitter = event.submitter;

		if ( null === label || event.defaultPrevented ) {
			return;
		}

		// After the browser has read the form: a disabled submit button would not be sent.
		window.setTimeout( function () {
			if ( ! isLocked( 'page' ) ) {
				busy( label || text( 'working', 'Working…' ), 'page', submitter || null );
			}
		}, 0 );
	} );

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-resource]' ), function ( el ) {
			if ( ! el.hasAttribute( 'data-bs-scope' ) ) {
				el.setAttribute( 'data-bs-scope', el.getAttribute( 'data-resource' ) );
			}
		} );

		// Server-side notices after a reload (WooCommerce) are printed at the bar's place.
		if ( document.querySelector( '.woocommerce-MyAccount-content' ) ) {
			ensure();

			// WooCommerce's own notices after a reload sit right under the bar.
			var notices = contentArea().querySelector( '.woocommerce-notices-wrapper' );

			if ( notices && bar.parentNode && ! notices.contains( bar ) ) {
				bar.parentNode.insertBefore( notices, bar.nextSibling );
			}
			window.addEventListener( 'resize', offset );
			window.addEventListener( 'scroll', offset, { passive: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.beltoftStatus = {
		busy: busy,
		done: done,
		fail: fail,
		info: info,
		confirm: confirm,
		locked: isLocked,
		clearFields: clearFields,
		release: release
	};
}() );
