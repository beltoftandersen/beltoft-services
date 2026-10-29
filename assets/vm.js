/**
 * My Account → Licenses: the virtual machine panel. Draws nothing itself; it wires the forms
 * (add or replace the SSH key, reinstall, restore) and the one-time password button that the server
 * printed. Posts to the same action route as the generic buttons, with the arguments in
 * {args: {...}}; the REST nonce is shared with account.js (kept fresh from each reply). The
 * password is only ever held in the page for as long as the customer looks at it. A confirmed action
 * (rollback, restore, reinstall, snapshot delete) closes its dialog, says at once on the card what is
 * happening, and after the page reloads at the end says that it is done (sessionStorage, shown once).
 * On a server page (templates/account/vm.php) it also makes the tab links switch the panes without a
 * page load (the address changes with them, so Back works, and the links are plain links without
 * script), asks before Reboot and Shut down in the same dialog, and copies the ssh command.
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var vm = window.beltoftServicesVm;

	if ( ! account || ! vm || ! window.fetch ) {
		return;
	}

	function post( panel, path, body ) {
		return window.fetch( account.root + panel.getAttribute( 'data-resource' ) + '/' + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': account.nonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response;
		} );
	}

	function notice( panel, text ) {
		// An open dialog shows its own messages; otherwise the panel's line (the dialog has no data-notice).
		var line = panel.querySelector( 'dialog[open] [data-dialog-notice]' ) || panel.querySelector( '[data-notice]' );

		if ( line ) {
			line.textContent = text;
		}
	}

	// The server's own refusal text is written for the customer; anything else gets a fixed line.
	function reply( panel, response, fallback ) {
		return response.json().then( function ( body ) {
			if ( 403 === response.status && body && 'rest_cookie_invalid_nonce' === body.code ) {
				notice( panel, vm.strings.expired );
			} else if ( 429 === response.status ) {
				notice( panel, vm.strings.tooMany );
			} else if ( 422 === response.status && body && 'string' === typeof body.message ) {
				notice( panel, body.message );
			} else {
				notice( panel, fallback );
			}
		}, function () {
			notice( panel, fallback );
		} );
	}

	// A clicked button shows it is working until the page reloads; reset() gives it back after an error.
	function busy( button ) {
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.classList.add( 'is-busy' );

		if ( undefined === button.beltoftLabel ) {
			button.beltoftLabel = button.textContent;
			button.textContent = vm.strings.working;
		}
	}

	function reset( button ) {
		button.disabled = false;
		button.removeAttribute( 'aria-busy' );
		button.classList.remove( 'is-busy' );

		if ( undefined !== button.beltoftLabel ) {
			button.textContent = button.beltoftLabel;
			button.beltoftLabel = undefined;
		}
	}

	// close() when the browser has dialogs, else the attribute the fallback opening set.
	function closeDialog( dialog ) {
		if ( dialog.close ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
	}

	// The safe button has the focus when a question opens: Enter or a stray Space does not confirm.
	function openDialog( dialog ) {
		var safe = dialog.querySelector( '[data-confirm-cancel], [data-snapshot-cancel]' );

		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}

		if ( safe ) {
			safe.focus();
		}
	}

	// The browser's storage may be off (private mode): then the done line is simply not shown.
	function store() {
		try {
			return window.sessionStorage;
		} catch ( e ) {
			return null;
		}
	}

	function key( panel ) {
		return 'beltoftServicesVm:' + panel.getAttribute( 'data-resource' );
	}

	function read( panel, name ) {
		var box = store();

		try {
			return box ? JSON.parse( box.getItem( key( panel ) + name ) ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function write( panel, name, value ) {
		var box = store();

		if ( box ) {
			try {
				if ( null === value ) {
					box.removeItem( key( panel ) + name );
				} else {
					box.setItem( key( panel ) + name, JSON.stringify( value ) );
				}
			} catch ( e ) { /* not kept */ }
		}
	}

	// What this tab asked for: the card's start and done lines (they name the snapshot), and the server's clock at the request.
	function recall( panel ) {
		var entry = read( panel, ':asked' );

		// Half an hour is longer than any of these takes.
		return entry && entry.info && Date.now() - entry.local < 1800000 ? entry : null;
	}

	// The card's own line, at the top of the server card: working (with the pulsing squares), done or not done.
	function flash( panel, text, kind ) {
		var line = panel.querySelector( '[data-flash]' );
		var power = panel.querySelector( '[data-power-line]' );
		var changed;

		if ( ! line ) {
			return;
		}

		changed = line.textContent !== text || line.className !== 'beltoft-services-vm__flash is-' + kind;
		line.textContent = text;
		line.className = 'beltoft-services-vm__flash is-' + kind;
		line.hidden = false;
		// The flash says what is happening: the progress sentence under it would say it twice, and the power is not "Running" while it works.
		panel.classList.toggle( 'has-working-flash', 'working' === kind );

		if ( 'working' === kind && power ) {
			power.textContent = vm.strings.busy;
		}

		// Looked at once, not on every poll.
		if ( changed && line.scrollIntoView ) {
			line.scrollIntoView( { block: 'nearest' } );
		}
	}

	// Plain replacement: a "$" in a snapshot's name stays as it is.
	function fill( text, name ) {
		return text.replace( '%s', function () {
			return name;
		} );
	}

	// What the card says for an action that is confirmed in a dialog: start and done lines, whether the server is running again, and the lines when the name is not known (another device).
	function describe( action, name ) {
		var s = vm.strings;

		switch ( action ) {
			case 'snapshot_rollback':
				return { action: action, start: fill( s.rollbackStart, name ), done: name ? fill( s.rollbackDone, name ) : s.rollbackDoneAnon, running: true };
			case 'restore':
				return { action: action, start: s.restoreStart, done: s.restoreDone, running: true };
			case 'reinstall':
				return { action: action, start: s.reinstallStart, done: s.reinstallDone, running: true, password: true };
			case 'snapshot_delete':
				return { action: action, start: fill( s.deleteStart, name ), done: name ? fill( s.deleteDone, name ) : s.deleteDoneAnon };
			case 'reboot':
				return { action: action, start: s.rebootStart, done: s.rebootDone, power: true };
			case 'shutdown':
				return { action: action, start: s.shutdownStart, done: s.shutdownDone, power: true };
			default:
				return null;
		}
	}

	// Shows the end of a confirmed action once, from the server's record of it: done only when the job said it was.
	function ended( panel, result, info ) {
		var card = panel.querySelector( '.beltoft-services-vm' );
		var text;

		write( panel, ':seen', result.at );

		if ( ! result.ok ) {
			flash( panel, result.text || vm.strings.failed, 'failed' );

			return;
		}

		text = info.done;

		if ( info.running && card && 'running' === card.getAttribute( 'data-power' ) ) {
			text += ' ' + vm.strings.running;
		}

		if ( info.password && panel.querySelector( '[data-vm-reveal]' ) ) {
			text += ' ' + vm.strings.newPassword;
		}

		flash( panel, text, 'done' );
	}

	// On every status of the card (account.js): working while it works, then done or not done. Works on a device that did not ask too, for ten minutes.
	function outcome( panel, data ) {
		var result = data.last_result;
		var asked = recall( panel );
		var info;
		// The first status of a page load that is already over is the page after the reload: the line is shown and used up. One that comes while the work was still on is shown too, and the reload that follows shows it again.
		var first = ! panel.beltoftStatusSeen;

		panel.beltoftStatusSeen = true;

		if ( data.progress && data.progress.active ) {
			if ( asked ) {
				flash( panel, asked.info.start, 'working' );
			}

			return;
		}

		info = result ? describe( result.action, '' ) : null;

		if ( ! info ) {
			return;
		}

		if ( asked ) {
			// The record has to be the answer to this request: the same action, from the server's clock at or after it.
			if ( asked.info.action === result.action && result.at >= asked.queued ) {
				if ( first ) {
					write( panel, ':asked', null );
				}

				ended( panel, result, asked.info );
			}

			return;
		}

		if ( data.now - result.at <= 600 && read( panel, ':seen' ) !== result.at ) {
			ended( panel, result, info );
		}
	}

	// A dialog that asks first. source: an element with the paragraphs (hidden, in the card), or text; name: the thing it is about.
	function confirmFirst( panel, options ) {
		var dialog = panel.querySelector( '[data-confirm-dialog]' );
		var body;
		var yes;
		var line;

		if ( ! dialog ) {
			return false;
		}

		// A request is on its way: nothing new is asked until it has answered.
		if ( panel.beltoftPending ) {
			return true;
		}

		body = dialog.querySelector( '[data-confirm-body]' );
		yes = dialog.querySelector( '[data-confirm-submit]' );

		dialog.querySelector( '[data-confirm-heading]' ).textContent = options.title;
		yes.textContent = options.label;
		reset( yes );
		dialog.querySelector( '[data-dialog-notice]' ).textContent = '';

		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		if ( options.name ) {
			line = document.createElement( 'p' );
			line.appendChild( document.createElement( 'strong' ) ).textContent = options.name;
			body.appendChild( line );
		}

		if ( options.source ) {
			Array.prototype.forEach.call( options.source.childNodes, function ( node ) {
				body.appendChild( node.cloneNode( true ) );
			} );
		} else if ( options.text ) {
			line = document.createElement( 'p' );
			line.textContent = options.text;
			body.appendChild( line );
		}

		dialog.beltoftRun = options.run;
		notice( panel, '' );
		openDialog( dialog );

		return true;
	}

	function send( panel, action, args, button, dialog, info ) {
		if ( panel.beltoftPending ) {
			return;
		}

		panel.beltoftPending = true;

		if ( button ) {
			busy( button );
		}

		post( panel, 'actions/' + encodeURIComponent( action ), { args: args } ).then( function ( response ) {
			if ( 202 === response.status ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					panel.beltoftPending = false;

					if ( dialog ) {
						// The button goes back to normal: the dialog is used again for the next action.
						if ( button ) {
							reset( button );
						}

						closeDialog( dialog );
					}

					if ( info ) {
						notice( panel, '' );
						flash( panel, info.start, 'working' );
						write( panel, ':asked', { info: info, queued: body && body.at ? body.at : 0, local: Date.now() } );
					} else {
						notice( panel, vm.strings.queued );
					}

					// The panel's status poll (account.js) shows the progress and reloads when it is over; a power action also looks a few times, as the card's own buttons do.
					panel.dispatchEvent( new window.CustomEvent( 'beltoft-services:queued', { detail: { settle: !! ( info && info.power ) } } ) );
				} );
			}

			panel.beltoftPending = false;

			if ( button ) {
				reset( button );
			}

			return reply( panel, response, vm.strings.failed );
		} ).catch( function () {
			panel.beltoftPending = false;

			if ( button ) {
				reset( button );
			}
			notice( panel, vm.strings.failed );
		} );
	}

	// A WooCommerce Subscriptions link (cancel, reactivate) is a GET that does its work and redirects: do it in the background, then show the card again.
	function subscriptionLink( panel, url, button ) {
		if ( panel.beltoftPending ) {
			return;
		}

		panel.beltoftPending = true;

		if ( button ) {
			busy( button );
		}

		window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'refused' );
			}

			window.location.reload();
		} ).catch( function () {
			panel.beltoftPending = false;

			if ( button ) {
				reset( button );
			}

			notice( panel, vm.strings.failed );
		} );
	}

	function submit( panel, form ) {
		var args = {};
		var action = form.getAttribute( 'data-action' );
		var button = form.querySelector( 'button[type="submit"]' );
		var source = form.querySelector( '[data-confirm-source]' );
		var dialog = form.closest( 'dialog' );
		var backup;

		// The question's own form: run what it was opened for.
		if ( 'confirm' === action ) {
			if ( dialog && dialog.beltoftRun ) {
				dialog.beltoftRun( button, dialog );
			}

			return;
		}

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( field.name ) {
				args[ field.name ] = field.value;
			}
		} );

		if ( ( 'reinstall' === action || 'restore' === action ) && source ) {
			backup = form.querySelector( 'select[name="volid"]' );

			if ( confirmFirst( panel, {
				title: source.getAttribute( 'data-confirm-title' ),
				label: source.getAttribute( 'data-confirm-yes' ),
				name: backup && backup.selectedIndex >= 0 ? backup.options[ backup.selectedIndex ].text : '',
				source: source,
				run: function ( yes, box ) {
					send( panel, action, args, yes, box, describe( action, '' ) );
				}
			} ) ) {
				return;
			}
		}

		backup = form.querySelector( '[data-snapshot-dialog-name]' );
		send( panel, action, args, button, dialog, describe( action, backup ? backup.textContent : '' ) );
	}

	function reveal( panel, button ) {
		var out = panel.querySelector( '[data-vm-password]' );

		button.disabled = true;

		post( panel, 'reveal', {} ).then( function ( response ) {
			if ( ! response.ok ) {
				return reply( panel, response, 409 === response.status ? vm.strings.gone : vm.strings.failed );
			}

			return response.json().then( function ( body ) {
				if ( out && body && 'string' === typeof body.password ) {
					out.textContent = body.password;
					out.hidden = false;
					button.hidden = true;
				}
			} );
		} ).catch( function () {
			button.disabled = false;
			notice( panel, vm.strings.failed );
		} );
	}

	// Reboot and Shut down ask first, in the page's one dialog.
	function askPower( panel, action ) {
		var s = vm.strings;

		if ( ! confirmFirst( panel, {
			title: s[ action + 'Title' ],
			label: s[ action + 'Yes' ],
			text: s[ action + 'Text' ],
			run: function ( yes, box ) {
				send( panel, action, {}, yes, box, describe( action, '' ) );
			}
		} ) ) {
			send( panel, action, {}, null, null, describe( action, '' ) );
		}
	}

	function copyText( text ) {
		var area;

		if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
			return window.navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();

			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( 'copy' ) );
				}
			} catch ( e ) {
				reject( e );
			}

			document.body.removeChild( area );
		} );
	}

	function copyCommand( panel, button ) {
		var label = button.textContent;

		copyText( button.getAttribute( 'data-copy' ) ).then( function () {
			button.textContent = button.getAttribute( 'data-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}, function () {
			notice( panel, vm.strings.failed );
		} );
	}

	// The tab of a page by its pane. Every pane stays in the page; only one is shown.
	function showTab( panel, slug ) {
		var found = false;

		Array.prototype.forEach.call( panel.querySelectorAll( '.beltoft-services-tab' ), function ( pane ) {
			var on = slug === pane.getAttribute( 'data-tab' );

			found = found || on;
			pane.hidden = ! on;
		} );

		if ( ! found ) {
			return false;
		}

		Array.prototype.forEach.call( panel.querySelectorAll( '.beltoft-services-tabs a[data-tab-link]' ), function ( link ) {
			if ( slug === link.getAttribute( 'data-tab-link' ) ) {
				link.setAttribute( 'aria-current', 'page' );
			} else {
				link.removeAttribute( 'aria-current' );
			}
		} );

		// The charts of a tab that was not shown at load are drawn when it first is.
		panel.dispatchEvent( new window.CustomEvent( 'beltoft-services:tab', { detail: { tab: slug } } ) );

		return true;
	}

	// Which tab the address says: the nav link that points at it, else the first.
	function tabFromLocation( panel ) {
		var links = panel.querySelectorAll( '.beltoft-services-tabs a[data-tab-link]' );
		var here = window.location.pathname + window.location.search;
		var found = links.length ? links[ 0 ].getAttribute( 'data-tab-link' ) : '';

		Array.prototype.forEach.call( links, function ( link ) {
			if ( link.pathname + link.search === here ) {
				found = link.getAttribute( 'data-tab-link' );
			}
		} );

		return found;
	}

	// A link to something inside a hidden pane (a disk's setup guide) opens its tab first.
	function revealHash( panel ) {
		var id = window.location.hash.slice( 1 );
		var target;
		var pane;

		try {
			id = decodeURIComponent( id );
		} catch ( e ) { /* a malformed hash is used as it is */ }

		target = id ? document.getElementById( id ) : null;
		pane = target && panel.contains( target ) ? target.closest( '.beltoft-services-tab' ) : null;

		if ( pane && pane.hidden ) {
			showTab( panel, pane.getAttribute( 'data-tab' ) );
		}
	}

	document.querySelectorAll( '.beltoft-services-panel[data-resource]' ).forEach( function ( panel ) {
		var line = panel.querySelector( '[data-flash]' );
		var progress = panel.querySelector( '[data-progress]' );

		// The card's line goes above the steps, so there is one message and then the steps.
		if ( line && progress && progress.parentNode ) {
			progress.parentNode.insertBefore( line, progress );
		}

		// Esc does not close a question while its request is on its way.
		Array.prototype.forEach.call( panel.querySelectorAll( 'dialog' ), function ( box ) {
			box.addEventListener( 'cancel', function ( event ) {
				if ( panel.beltoftPending ) {
					event.preventDefault();
				}
			} );
		} );

		panel.addEventListener( 'beltoft-services:status', function ( event ) {
			outcome( panel, event.detail || {} );
		} );

		// Copying needs the clipboard or the old copy command: without either the button stays hidden (the command is printed under Log in).
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-copy]' ), function ( button ) {
			button.hidden = false;
		} );

		if ( panel.querySelector( '.beltoft-services-tab' ) ) {
			revealHash( panel );
			window.addEventListener( 'hashchange', function () {
				revealHash( panel );
			} );
			window.addEventListener( 'popstate', function () {
				showTab( panel, tabFromLocation( panel ) );
			} );
		}

		panel.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '.beltoft-services-vm-form' );

			if ( form ) {
				event.preventDefault();
				submit( panel, form );
			}
		} );

		panel.addEventListener( 'click', function ( event ) {
			var roll = event.target.closest( '[data-snapshot-rollback]' );
			var cancel = event.target.closest( '[data-snapshot-cancel], [data-confirm-cancel]' );
			var drop = event.target.closest( '[data-snapshot-delete]' );
			var remove = event.target.closest( '[data-cancel-url]' );
			var undo = event.target.closest( '[data-undo-url]' );
			var dialog = panel.querySelector( '[data-snapshot-dialog]' );
			var button = event.target.closest( '[data-vm-reveal]' );
			var tab = event.target.closest( 'a[data-tab-link]' );
			var power = event.target.closest( '[data-power-action]' );
			var copy = event.target.closest( '[data-copy]' );
			var nav = panel.querySelector( '.beltoft-services-tabs' );
			var name;
			var source;

			if ( tab && 0 === event.button && ! ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) && window.history && window.history.pushState && showTab( panel, tab.getAttribute( 'data-tab-link' ) ) ) {
				event.preventDefault();
				window.history.pushState( { beltoftTab: tab.getAttribute( 'data-tab-link' ) }, '', tab.href );

				if ( nav && nav.getBoundingClientRect().top < 0 ) {
					nav.scrollIntoView();
				}

				return;
			}

			if ( power && ! power.disabled ) {
				askPower( panel, power.getAttribute( 'data-power-action' ) );

				return;
			}

			if ( copy ) {
				copyCommand( panel, copy );

				return;
			}

			if ( roll && dialog ) {
				dialog.querySelector( 'input[name="id"]' ).value = roll.getAttribute( 'data-snapshot-rollback' );
				dialog.querySelector( '[data-snapshot-dialog-name]' ).textContent = roll.getAttribute( 'data-snapshot-name' );
				dialog.querySelector( 'input[name="confirm"]' ).value = '';
				notice( panel, '' );
				dialog.querySelector( '[data-dialog-notice]' ).textContent = '';
				openDialog( dialog );

				return;
			}

			if ( drop && ! drop.disabled ) {
				name = drop.getAttribute( 'data-snapshot-name' ) || '';

				if ( ! confirmFirst( panel, {
					title: vm.strings.deleteTitle,
					label: vm.strings.deleteYes,
					name: name,
					text: vm.strings.snapshotDelete,
					run: function ( yes, box ) {
						send( panel, 'snapshot_delete', { id: drop.getAttribute( 'data-snapshot-delete' ) }, yes, box, describe( 'snapshot_delete', name ) );
					}
				} ) ) {
					send( panel, 'snapshot_delete', { id: drop.getAttribute( 'data-snapshot-delete' ) }, drop, null, describe( 'snapshot_delete', name ) );
				}

				return;
			}

			if ( remove ) {
				// The cancel address is only ever used from the dialog's button.
				source = document.getElementById( remove.getAttribute( 'data-confirm-open' ) );

				if ( source ) {
					confirmFirst( panel, {
						title: source.getAttribute( 'data-confirm-title' ),
						label: source.getAttribute( 'data-confirm-yes' ),
						source: source,
						run: function ( yes ) {
							subscriptionLink( panel, remove.getAttribute( 'data-cancel-url' ), yes );
						}
					} );
				}

				return;
			}

			if ( undo ) {
				subscriptionLink( panel, undo.getAttribute( 'data-undo-url' ), undo );

				return;
			}

			if ( cancel && ! panel.beltoftPending ) {
				closeDialog( cancel.closest( 'dialog' ) );

				return;
			}

			if ( button && ! button.disabled ) {
				reveal( panel, button );
			}
		} );
	} );
}() );
