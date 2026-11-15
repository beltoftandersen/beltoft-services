/**
 * One place for feedback on My Account and the checkout fields: a progress line (a thin line and a short text), a lock for
 * the buttons of the service that is working, and a styled question (dialog) that replaces window.confirm / alert / prompt.
 *
 *   var h = beltoftStatus.busy( 'Rebooting your server…', 'resource-id', button?, { lock: false }? ); // progress line + lock, returns a handle
 *   beltoftStatus.progress( h, fraction | { step, of } | progressPayload, message? );             // makes the line determinate: "(step n of m)"
 *   beltoftStatus.done( h, 'Your server has restarted.' );                           // shown about 5 s, then fades away
 *   beltoftStatus.fail( h, 'Failed: …', fieldElementOrSelector? );                   // stays until closed; the field is marked invalid and focused, the text is shown once, here
 *   beltoftStatus.info( 'Copied.' );                                                  // like done, without a handle
 *   beltoftStatus.confirm( { title, text, confirm, cancel, danger } ).then( function ( yes ) {} );
 *   beltoftStatus.locked( scope );                                                    // true while a handle of that scope is busy
 *
 * The line is shown in ONE place (owner 2026-11-15). On a wide screen it sits where the page's state line ([data-bs-inline]) is, at
 * the top (in the head of a server or database page, else under the title row, else at the top of the content), and takes the place
 * of the description while it shows. On a phone (max-width 767px, the phone breakpoint of account.css) it is fixed to the bottom of
 * the screen, above the browser's toolbar, so it never moves anything. Busy is a moving line (indeterminate); progress() fills it
 * for work whose steps are known. Done and info stay about 5 s and fade away; fail stays until closed. A failed field is only marked
 * (aria-invalid) and focused, the text is not printed next to it. Nothing is shown under the clicked button.
 *
 * progress( h, value, message? ): value is a fraction from 0 to 1, { step: n, of: m }, or a payload of Progress (its `steps` with
 * the status done, current or failed, as assets/progress.js draws them). The text is the message when given, else the text the line
 * has, followed by "(step n of m)" when the steps are known. Only the work that is shown moves the line: a released handle is ignored.
 * busy( ..., { lock: false } ) shows the line without locking any button (for a page that locks its own).
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
	var FADE_MS = 300;
	var PHONE = '(max-width: 767px)';
	var current = null;
	var timers = { done: 0, fade: 0 };
	var loader = null;
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

	// ---- the progress line -----------------------------------------------------------------------------------------

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
		area.insertBefore( line, area.firstChild );

		return line.firstChild;
	}

	// The state indicator in front of the line (Running, Stopped, or the state text of a service that is not ready).
	function indicatorOf( target ) {
		var row = target.parentNode;

		return row ? ( row.querySelector( '[data-power-line]:not([hidden])' ) || row.querySelector( '[data-badge]:not([hidden])' ) ) : null;
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

	function phone() {
		return !! ( window.matchMedia && window.matchMedia( PHONE ).matches );
	}

	// The one element: made once and kept (a live region that stays in the page is announced reliably). It is moved to the
	// state line on a wide screen and to the body on a phone, where it is fixed to the bottom and takes no room.
	function loaderParts() {
		var el;
		var bar;

		if ( loader ) {
			return loader;
		}

		el = make( 'div', 'bs-loader' );
		el.id = 'bs-status-line';
		el.hidden = true;
		el.setAttribute( 'data-bs-loader', '' );
		el.setAttribute( 'role', 'status' );
		el.setAttribute( 'aria-live', 'polite' );
		el.setAttribute( 'aria-atomic', 'true' );

		loader = { el: el, host: null, text: make( 'span', 'bs-loader__text' ), close: make( 'button', 'bs-loader__close' ), fill: make( 'i' ), base: '', stem: '' };
		loader.close.type = 'button';
		loader.close.hidden = true;
		loader.close.setAttribute( 'aria-label', text( 'close', 'Close' ) );
		loader.close.setAttribute( 'data-bs-keep', '' );
		loader.close.textContent = '×';
		loader.close.addEventListener( 'click', function () {
			hide( true );
		} );

		bar = make( 'span', 'bs-loader__bar' );
		bar.setAttribute( 'aria-hidden', 'true' );
		bar.appendChild( loader.fill );

		el.appendChild( loader.text );
		el.appendChild( loader.close );
		el.appendChild( bar );

		return loader;
	}

	function place( host ) {
		var parts = loaderParts();
		var where = phone() ? document.body : host;

		if ( parts.el.parentNode !== where ) {
			where.appendChild( parts.el );
		}

		parts.host = host;
	}

	// null: the moving line; 0 to 1: that much of the line.
	function setFraction( fraction ) {
		var parts = loaderParts();

		parts.el.classList.toggle( 'is-determinate', null !== fraction );
		parts.fill.style.width = null === fraction ? '' : Math.round( fraction * 1000 ) / 10 + '%';
	}

	// Wide screen: while the line shows and its place at the top has scrolled out of view, it sticks under the fixed site header.
	// The state line keeps the height the line had, so nothing in the page moves.
	function unstick() {
		var parts = loaderParts();

		parts.el.classList.remove( 'is-stuck' );
		parts.el.style.left = '';
		parts.el.style.width = '';

		if ( parts.host ) {
			parts.host.style.minHeight = '';
		}
	}

	function stick() {
		var parts = loaderParts();
		var host = parts.host;
		var box;

		if ( ! host || parts.el.hidden || phone() ) {
			unstick();

			return;
		}

		box = host.getBoundingClientRect();

		if ( box.top < headerTop && ! parts.el.classList.contains( 'is-stuck' ) ) {
			host.style.minHeight = parts.el.offsetHeight + 'px';
			parts.el.style.left = box.left + 'px';
			parts.el.style.width = box.width + 'px';
			parts.el.classList.add( 'is-stuck' );
		} else if ( box.top >= headerTop && parts.el.classList.contains( 'is-stuck' ) ) {
			unstick();
		}
	}

	function release_host( host ) {
		if ( host ) {
			host.classList.remove( 'has-loader' );
			host.removeAttribute( 'data-bs-kind' );
			indicate( host, false );
			live( host, false );
		}
	}

	function reset() {
		var parts = loaderParts();

		clearTimers();
		parts.el.hidden = true;
		parts.el.className = 'bs-loader';
		parts.el.setAttribute( 'role', 'status' );
		parts.el.setAttribute( 'aria-live', 'polite' );
		parts.el.setAttribute( 'aria-busy', 'false' );
		parts.text.textContent = '';
		parts.text.removeAttribute( 'title' );
		parts.close.hidden = true;
		parts.base = '';
		parts.stem = '';
		setFraction( null );
		unstick();
		release_host( parts.host );
		current = null;
	}

	function hide( now ) {
		var parts = loaderParts();

		clearTimers();

		if ( now || reduced() || parts.el.hidden ) {
			reset();

			return;
		}

		parts.el.classList.add( 'is-fading' );
		timers.fade = window.setTimeout( reset, FADE_MS );
	}

	// kind: busy, done, fail, info. Only the newest message is shown.
	function show( kind, message, handle ) {
		var host = inlineTarget();
		var parts = loaderParts();
		var full = ( 'fail' === kind ? text( 'failedPrefix', 'Failed: ' ) : '' ) + message;

		clearTimers();

		// Shown in another place than before (the page changed): the old place is cleaned.
		if ( parts.host && parts.host !== host ) {
			release_host( parts.host );
		}

		place( host );
		parts.el.className = 'bs-loader is-' + kind;
		parts.el.hidden = false;
		parts.el.setAttribute( 'role', 'fail' === kind ? 'alert' : 'status' );
		parts.el.setAttribute( 'aria-busy', 'busy' === kind ? 'true' : 'false' );
		parts.el.setAttribute( 'aria-live', 'fail' === kind ? 'assertive' : 'polite' );
		setFraction( 'done' === kind ? 1 : null );

		if ( parts.text.textContent !== full ) {
			parts.text.textContent = full;
		}

		parts.text.setAttribute( 'title', full );
		parts.close.hidden = 'fail' !== kind;

		// A wide screen: the line takes the place of the description. A phone: nothing in the page changes, the line is overlaid.
		if ( phone() ) {
			release_host( host );
		} else {
			host.classList.add( 'has-loader' );
			host.setAttribute( 'data-bs-kind', kind );
			live( host, true );
			indicate( host, 'busy' === kind );
		}

		parts.base = full;
		parts.stem = '';
		current = handle || null;
		stick();

		if ( 'done' === kind || 'info' === kind ) {
			timers.done = window.setTimeout( function () {
				hide( false );
			}, DONE_MS );
		}
	}

	// How far along, from what the caller knows: a number, { step, of }, or a Progress payload with its steps.
	function measure( value ) {
		var step = 0;
		var of = 0;
		var done = 0;
		var i;

		if ( 'number' === typeof value ) {
			return isFinite( value ) ? { fraction: Math.min( 1, Math.max( 0, value ) ), step: 0, of: 0 } : null;
		}

		if ( ! value || 'object' !== typeof value ) {
			return null;
		}

		if ( Array.isArray( value.steps ) && value.steps.length ) {
			of = value.steps.length;

			for ( i = 0; i < of; i++ ) {
				if ( 'done' === value.steps[ i ].status ) {
					done += 1;
				}

				if ( ! step && ( 'current' === value.steps[ i ].status || 'failed' === value.steps[ i ].status ) ) {
					step = i + 1;
				}
			}

			step = step || Math.min( of, done + 1 );
		} else if ( value.of > 0 ) {
			of = Math.floor( value.of );
			step = Math.min( of, Math.max( 1, Math.floor( value.step || 1 ) ) );
		} else {
			return null;
		}

		// The step being worked on does not count as done; a little of the line is always drawn.
		return { fraction: Math.max( 0.06, ( step - 1 ) / of ), step: step, of: of };
	}

	function progress( handle, value, message ) {
		var parts = loaderParts();
		var found = measure( value );
		var base;
		var full;

		if ( ! found || parts.el.hidden || ! parts.el.classList.contains( 'is-busy' ) ) {
			return;
		}

		// Only the work that is shown moves the line: a released or an older handle does not.
		if ( handle && ( handle.released || ( current && current !== handle ) ) ) {
			return;
		}

		base = message ? String( message ) : ( parts.stem || parts.base );
		parts.stem = base;
		full = base;

		if ( found.of ) {
			full = base.replace( /[….]+$/, '' ) + ' (' + text( 'stepOf', 'step %1$d of %2$d' ).replace( '%1$d', found.step ).replace( '%2$d', found.of ) + ')';
		}

		setFraction( found.fraction );

		if ( parts.text.textContent !== full ) {
			parts.text.textContent = full;
			parts.text.setAttribute( 'title', full );
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

	// A failed field is marked invalid and points at the progress line, which holds the one copy of the message. The text is never
	// printed next to the field: an error is shown once.
	function fieldNote( field ) {
		var line = loaderParts().el;
		var before = field.getAttribute( 'aria-describedby' );

		clearFields();
		field.setAttribute( 'aria-invalid', 'true' );
		field.setAttribute( 'aria-describedby', before ? before + ' ' + line.id : line.id );

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

	// ---- the API ----------------------------------------------------------------------------------------------

	// options.lock false: only the progress line, no button is locked (a page that locks its own buttons, such as hosting).
	function busy( message, scope, button, options ) {
		var key = scope || 'page';
		var area = container( key );
		var el = chosen( button );
		var lock = ! ( options && false === options.lock );
		var handle = { scope: key, text: message, locked: [], button: null, released: false };
		var label = text( 'wait', 'Wait for %s to finish' ).replace( '%s', function () {
			return String( message ).replace( /[….]+$/, '' ).toLowerCase();
		} );

		clearFields();

		if ( lock ) {
			if ( el && ( area.contains( el ) || 'page' === key ) ) {
				handle.button = el;
				handle.beltoftButtonWasDisabled = el.disabled;
				el.disabled = true;
				el.setAttribute( 'aria-busy', 'true' );
			}

			Array.prototype.forEach.call( area.querySelectorAll( SELECTOR ), function ( other ) {
				if ( other !== handle.button && lockable( other, area ) ) {
					lockButton( other, label );
					handle.locked.push( other );
				}
			} );

			active.push( handle );
		}

		// One loader, in one place (owner 2026-11-15): the clicked button is only disabled, the progress line says what is going on.
		show( 'busy', message, handle );

		return handle;
	}

	function finish( handle, kind, message ) {
		if ( handle ) {
			release( handle );
			handle.released = true;
		}

		// A newer request is still working: its busy line stays, a failure is the only thing that may replace it.
		if ( active.length && 'fail' !== kind ) {
			return;
		}

		// Nothing to say (done with an empty text): the line of this work just goes, there is nothing to read.
		if ( '' === String( message || '' ) && 'fail' !== kind ) {
			if ( handle && current === handle ) {
				hide( true );
			}

			return;
		}

		show( kind, message, handle );
	}

	function done( handle, message ) {
		finish( handle, 'done', message );
	}

	function fail( handle, message, field ) {
		var target = resolveField( field );
		var line;
		var seen;

		finish( handle, 'fail', message );

		// On a wide screen the line is at the top: bring it into view when it is not. On a phone it is fixed to the screen.
		line = loaderParts().el;

		if ( ! phone() && line.getBoundingClientRect ) {
			seen = line.getBoundingClientRect();

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

	// Escape closes a failure that stays on the screen (the close button is the same, for a pointer).
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && loader && ! loader.el.hidden && loader.el.classList.contains( 'is-fail' ) && ! ( dialog && dialog.open ) ) {
			hide( true );
		}
	} );

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

		offset();
		window.addEventListener( 'scroll', function () {
			if ( loader && ! loader.el.hidden ) {
				stick();
			}
		}, { passive: true } );
		window.addEventListener( 'resize', function () {
			candidates = null;
			offset();

			// The line moves between the state line and the bottom of the screen when the window crosses the phone breakpoint.
			if ( loader && ! loader.el.hidden && loader.host ) {
				unstick();
				place( loader.host );
				stick();
			}
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
		progress: progress,
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
