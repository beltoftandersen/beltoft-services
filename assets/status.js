/**
 * One place for feedback on My Account and the checkout fields: a state line, a lock for the buttons of the
 * service that is working, and a styled question (dialog) that replaces window.confirm / alert / prompt.
 *
 *   var h = beltoftStatus.busy( 'Rebooting your server…', 'resource-id', button? );   // state line + lock, returns a handle
 *   beltoftStatus.done( h, 'Your server has restarted.' );                           // shown about 5 s, then fades
 *   beltoftStatus.fail( h, 'Failed: …', fieldElementOrSelector? );                   // stays until closed; the field is marked invalid and focused, the text is shown once, here
 *   beltoftStatus.info( 'Copied.' );                                                  // like done, without a handle
 *   beltoftStatus.confirm( { title, text, confirm, cancel, danger } ).then( function ( yes ) {} );
 *   beltoftStatus.locked( scope );                                                    // true while a handle of that scope is busy
 *
 * Every page has a state line ([data-bs-inline]): in the head of a server or database page it follows the state indicator,
 * on any other page it is the line under the title row (PageHead), and it falls back to one at the top of the content.
 * Busy, done, fail and info show there, and it goes back to what it said (the description) after a done or an info.
 * The line has a fixed height: long text is cut with an ellipsis (the title holds all of it), so nothing on the page moves.
 * The same busy, done and fail text is also shown right under the clicked button's group (.bs-note), where the customer looks.
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
	var FADE_MS = 150;
	var current = null;
	var timers = { done: 0, fade: 0 };
	var active = [];
	var recent = { el: null, at: 0 };
	var fieldNotes = [];
	var candidates = null;
	var headerTop = 0;

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

	function contentArea() {
		return document.querySelector( '.woocommerce-MyAccount-content' ) || document.querySelector( '[data-bs-scope="page"]' ) || document.querySelector( 'form.woocommerce-checkout' ) || document.querySelector( '.woocommerce-cart-form, .woocommerce' ) || document.querySelector( 'main' ) || document.body;
	}

	// The fixed site header covers the top of the viewport: a field that gets the focus must land below it
	// (fields use --bs-overlay-clear as scroll-margin-top).
	function offset() {
		var top = 0;
		var wide = window.innerWidth * 0.5;

		if ( ! candidates ) {
			candidates = [];
			Array.prototype.forEach.call( document.body.querySelectorAll( 'header, section, nav, div' ), function ( node ) {
				var style = window.getComputedStyle( node );

				if ( ( 'fixed' === style.position || 'sticky' === style.position ) && 'auto' !== style.top && node.offsetWidth >= wide && node.offsetHeight < 240 ) {
					candidates.push( node );
				}
			} );
		}

		candidates.forEach( function ( node ) {
			var style = window.getComputedStyle( node );

			if ( 'none' !== style.display && ( 'fixed' === style.position || 'sticky' === style.position ) ) {
				top = Math.max( top, ( parseFloat( style.top ) || 0 ) + node.offsetHeight );
			}
		} );

		headerTop = Math.round( top );
		document.documentElement.style.setProperty( '--bs-header-offset', headerTop + 'px' );
		document.documentElement.style.setProperty( '--bs-overlay-clear', ( headerTop + 16 ) + 'px' );
	}

	function clearTimers() {
		window.clearTimeout( timers.done );
		window.clearTimeout( timers.fade );
		timers.done = 0;
		timers.fade = 0;
	}

	// ---- the state line --------------------------------------------------------------------------------------------

	// The line of the page: the one in a server or database head, else the one printed under the page's title row. A page that
	// has none (a checkout step, a page of another plugin) gets one at the top of its content, made when it is first needed.
	function inlineTarget() {
		var found = document.querySelector( '.beltoft-services-page__head [data-bs-inline]' ) || document.querySelector( '[data-bs-inline]' );
		var line;
		var area;

		if ( found && document.body.contains( found ) ) {
			return found;
		}

		area = contentArea();
		line = make( 'div', 'bs-pagehead is-empty' );
		line.appendChild( make( 'span', 'bs-inline' ) ).setAttribute( 'data-bs-inline', '' );
		line.firstChild.setAttribute( 'role', 'status' );
		line.firstChild.setAttribute( 'aria-live', 'polite' );
		area.insertBefore( line, area.firstChild );

		return line.firstChild;
	}

	// The state indicator in front of the line (Running, Stopped, or the state text of a service that is not ready).
	function indicatorOf( target ) {
		var row = target.parentNode;

		return row ? ( row.querySelector( '[data-power-line]:not([hidden])' ) || row.querySelector( '[data-badge]:not([hidden])' ) ) : null;
	}

	function inlineParts( target ) {
		var label = target.querySelector( '.bs-inline__text' );
		var close = target.querySelector( '.bs-inline__close' );

		if ( ! label ) {
			label = make( 'span', 'bs-inline__text', target.textContent.trim() );
			target.textContent = '';
			target.appendChild( label );
		}

		if ( ! close ) {
			close = make( 'button', 'bs-inline__close' );
			close.type = 'button';
			close.hidden = true;
			close.setAttribute( 'aria-label', text( 'close', 'Close' ) );
			close.setAttribute( 'data-bs-keep', '' );
			close.textContent = '×';
			close.addEventListener( 'click', function () {
				inlineHide( target, true );
			} );
			target.appendChild( close );
		}

		if ( undefined === target.beltoftDefault ) {
			target.beltoftDefault = label.textContent;
		}

		return { label: label, close: close };
	}

	// While work is going on the indicator says Busy; it goes back to what it said, unless something else has changed it since.
	function indicate( target, on ) {
		var el = indicatorOf( target );

		if ( on ) {
			if ( el && undefined === target.beltoftIndicated ) {
				target.beltoftIndicated = { el: el, was: el.textContent };
				el.textContent = text( 'busyState', 'Busy' );
			}

			return;
		}

		if ( target.beltoftIndicated ) {
			if ( target.beltoftIndicated.el.textContent === text( 'busyState', 'Busy' ) ) {
				target.beltoftIndicated.el.textContent = target.beltoftIndicated.was;
			}

			target.beltoftIndicated = undefined;
		}
	}

	// A page line without a description is closed while idle; it opens for a message and closes again after it.
	function live( target, on ) {
		var head = target.parentNode;

		if ( head && head.classList && head.classList.contains( 'bs-pagehead' ) ) {
			head.classList.toggle( 'is-live', on );
		}
	}

	function inlineReset( target ) {
		var parts = inlineParts( target );

		clearTimers();
		target.classList.remove( 'is-fading', 'is-done', 'is-fail', 'is-info' );
		parts.label.classList.remove( 'is-busy' );
		target.removeAttribute( 'data-bs-kind' );
		target.setAttribute( 'role', 'status' );
		target.setAttribute( 'aria-live', 'polite' );
		target.setAttribute( 'aria-busy', 'false' );
		parts.label.textContent = target.beltoftDefault;

		if ( target.beltoftDefault ) {
			parts.label.setAttribute( 'title', target.beltoftDefault );
		} else {
			parts.label.removeAttribute( 'title' );
		}

		parts.close.hidden = true;
		indicate( target, false );
		live( target, false );
		current = null;
	}

	function inlineHide( target, now ) {
		clearTimers();

		if ( now || reduced() ) {
			inlineReset( target );

			return;
		}

		target.classList.add( 'is-fading' );
		timers.fade = window.setTimeout( function () {
			inlineReset( target );
		}, FADE_MS );
	}

	function inlineShow( target, kind, message, handle ) {
		var parts = inlineParts( target );
		var full = ( 'fail' === kind ? text( 'failedPrefix', 'Failed: ' ) : '' ) + message;

		clearTimers();
		target.classList.remove( 'is-fading', 'is-done', 'is-fail', 'is-info' );
		live( target, true );

		// The three squares of work in progress are the ones every busy element has (.is-busy::before), on the text.
		parts.label.classList.toggle( 'is-busy', 'busy' === kind );

		if ( 'busy' !== kind ) {
			target.classList.add( 'is-' + kind );
		}

		target.setAttribute( 'data-bs-kind', kind );
		target.setAttribute( 'role', 'fail' === kind ? 'alert' : 'status' );
		target.setAttribute( 'aria-busy', 'busy' === kind ? 'true' : 'false' );
		target.setAttribute( 'aria-live', 'fail' === kind ? 'assertive' : 'polite' );
		parts.label.textContent = full;
		parts.label.setAttribute( 'title', full );
		parts.close.hidden = 'fail' !== kind;
		indicate( target, 'busy' === kind );
		current = handle || null;

		if ( 'done' === kind || 'info' === kind ) {
			timers.done = window.setTimeout( function () {
				inlineHide( target, false );
			}, DONE_MS );
		}
	}

	// kind: busy, done, fail, info. Only the newest message is shown.
	function show( kind, message, handle ) {
		inlineShow( inlineTarget(), kind, message, handle );
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

			el.setAttribute( 'data-bs-locked', '' );

			if ( 'BUTTON' !== el.tagName && 'INPUT' !== el.tagName ) {
				el.setAttribute( 'aria-disabled', 'true' );
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
			note.field.removeAttribute( 'aria-invalid' );

			if ( note.describedby ) {
				note.field.setAttribute( 'aria-describedby', note.describedby );
			} else {
				note.field.removeAttribute( 'aria-describedby' );
			}
		} );

		fieldNotes = [];
	}

	// A failed field is marked invalid and points at the state line, which holds the one copy of the message. The text is never
	// printed next to the field: an error is shown once, at the top.
	function fieldNote( field ) {
		var line = inlineTarget();
		var before = field.getAttribute( 'aria-describedby' );

		clearFields();
		field.setAttribute( 'aria-invalid', 'true' );

		if ( line ) {
			if ( ! line.id ) {
				line.id = 'bs-status-line';
			}

			field.setAttribute( 'aria-describedby', before ? before + ' ' + line.id : line.id );
		}

		fieldNotes.push( { field: field, describedby: before } );

		// The mark goes when the customer changes the field.
		var gone = function () {
			field.removeEventListener( 'input', gone );
			field.removeEventListener( 'change', gone );
			clearFields();
		};

		field.addEventListener( 'input', gone );
		field.addEventListener( 'change', gone );
	}

	// ---- the note next to the button -----------------------------------------------------------------------------
	// Busy, done and fail are also said right where the customer clicked: a line directly under the button's group, so it
	// is seen on a phone too. Busy has a spinner, done a check mark and stays until the next action there, fail says why.

	var NOTE_ANCHOR = '[data-bs-note-anchor], .bs-action, .bs-item__actions, .bs-actions, .beltoft-hosting-buttons, .beltoft-services-panel__actions, .beltoft-services-vm__actions, .beltoft-services-db__form p';

	function noteFor( button ) {
		if ( ! button || ! button.closest || button.closest( 'dialog' ) ) {
			return null;
		}

		var anchor = button.closest( NOTE_ANCHOR ) || button;
		var note = anchor.nextElementSibling;

		if ( ! note || ! note.classList || ! note.classList.contains( 'bs-note' ) ) {
			note = make( 'p', 'bs-note' );
			note.appendChild( make( 'span', 'bs-note__icon' ) ).setAttribute( 'aria-hidden', 'true' );
			note.appendChild( make( 'span', 'bs-note__text' ) );
			anchor.insertAdjacentElement( 'afterend', note );
		}

		return note;
	}

	function noteSay( note, kind, message ) {
		if ( ! note ) {
			return;
		}

		note.className = 'bs-note bs-note--' + kind;
		note.setAttribute( 'role', 'fail' === kind ? 'alert' : 'status' );
		note.querySelector( '.bs-note__text' ).textContent = String( message || '' );
		note.hidden = '' === String( message || '' );

		var box = note.getBoundingClientRect ? note.getBoundingClientRect() : null;

		if ( box && ( box.bottom > window.innerHeight || box.top < headerTop ) ) {
			note.scrollIntoView( { block: 'nearest', behavior: reduced() ? 'auto' : 'smooth' } );
		}
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

		// One message, where the customer clicked: the note under the button. The state line at the top is only for work
		// that no button started (owner 2026-11-13: one loader, not two).
		handle.note = noteFor( handle.button );

		if ( handle.note ) {
			noteSay( handle.note, 'busy', message );
		} else {
			show( 'busy', message, handle );
		}

		return handle;
	}

	function finish( handle, kind, message ) {
		if ( handle ) {
			release( handle );
			noteSay( handle.note, kind, message );
		}

		// The note under the button already says it.
		if ( handle && handle.note ) {
			return;
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

		// The message is under the button, or at the top: bring it into view when it is not.
		var line = handle && handle.note ? handle.note : inlineTarget();

		if ( line && line.getBoundingClientRect ) {
			var seen = line.getBoundingClientRect();

			if ( seen.top < headerTop || seen.bottom > window.innerHeight ) {
				line.scrollIntoView( { block: 'center' } );
			}
		}

		if ( target ) {
			fieldNote( target );

			if ( target.focus ) {
				target.focus( { preventScroll: true } );
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

	// A form that posts and reloads (credit code, address, account details, card): data-bs-working="Working…" shows the state line and locks the page's buttons on submit.
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

		// A page with a state line in its head does not need the one under the title row.
		if ( document.querySelector( '.beltoft-services-page__head [data-bs-inline]' ) ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.bs-pagehead' ), function ( line ) {
				if ( line.parentNode ) {
					line.parentNode.removeChild( line );
				}
			} );
		}

		Array.prototype.forEach.call( document.querySelectorAll( '[data-bs-inline]' ), inlineParts );
		offset();
		window.addEventListener( 'resize', function () {
			candidates = null;
			offset();
		} );
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

	// What the last post did arrives in the page as hidden [data-bs-flash] lines (Account\Flash, the credit page, the API tokens page):
	// the status bar says it, once. A failure may name its field (data-bs-field): the field is marked and focused, the text is not repeated.
	function flash() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-bs-flash]' ), function ( node ) {
			var kind = node.getAttribute( 'data-bs-flash' );

			node.removeAttribute( 'data-bs-flash' );

			if ( 'fail' === kind ) {
				fail( null, node.textContent, node.getAttribute( 'data-bs-field' ) || undefined );
			} else {
				info( node.textContent );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', flash );
	} else {
		flash();
	}
}() );
