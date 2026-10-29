/**
 * My Account → Licenses: the virtual machine panel. Draws nothing itself; it wires the forms
 * (add or replace the SSH key, reinstall, restore) and the one-time password button that the server
 * printed. Posts to the same action route as the generic buttons, with the arguments in
 * {args: {...}}; the REST nonce is shared with account.js (kept fresh from each reply). The
 * password is only ever held in the page for as long as the customer looks at it. A confirmed action
 * (rollback, restore, reinstall, snapshot delete) closes its dialog, says at once on the card what is
 * happening, and after the page reloads at the end says that it is done (sessionStorage, shown once).
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

	function openDialog( dialog ) {
		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
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

	function remember( panel, info ) {
		var box = store();

		if ( box ) {
			try {
				box.setItem( key( panel ), JSON.stringify( { info: info, at: Date.now() } ) );
			} catch ( e ) { /* not kept */ }
		}
	}

	function recall( panel ) {
		var box = store();
		var entry = null;

		try {
			entry = box ? JSON.parse( box.getItem( key( panel ) ) ) : null;
		} catch ( e ) {
			entry = null;
		}

		// Half an hour is longer than any of these takes.
		return entry && entry.info && Date.now() - entry.at < 1800000 ? entry.info : null;
	}

	function forget( panel ) {
		var box = store();

		if ( box ) {
			try {
				box.removeItem( key( panel ) );
			} catch ( e ) { /* nothing to remove */ }
		}
	}

	// The card's own line, at the top of the server card: working (with the pulsing squares) or done.
	function flash( panel, text, kind ) {
		var line = panel.querySelector( '[data-flash]' );

		if ( ! line ) {
			return;
		}

		line.textContent = text;
		line.className = 'beltoft-services-vm__flash is-' + kind;
		line.hidden = false;

		if ( line.scrollIntoView ) {
			line.scrollIntoView( { block: 'nearest' } );
		}
	}

	function fill( text, name ) {
		return text.replace( '%s', name );
	}

	// What the card says for an action that is confirmed in a dialog: start, done, and whether the server is running again.
	function describe( action, name ) {
		var s = vm.strings;

		switch ( action ) {
			case 'snapshot_rollback':
				return { start: fill( s.rollbackStart, name ), done: fill( s.rollbackDone, name ), running: true };
			case 'restore':
				return { start: s.restoreStart, done: s.restoreDone, running: true };
			case 'reinstall':
				return { start: s.reinstallStart, done: s.reinstallDone, running: true, password: true };
			case 'snapshot_delete':
				return { start: fill( s.deleteStart, name ), done: fill( s.deleteDone, name ) };
			default:
				return null;
		}
	}

	// At page load: the work the customer confirmed is still going (say so) or over (say it is done, once).
	function showOutcome( panel ) {
		var info = recall( panel );
		var block = panel.querySelector( '[data-progress]' );
		var phase = block ? block.getAttribute( 'data-phase' ) : 'idle';
		var card = panel.querySelector( '.beltoft-services-vm' );
		var text;

		if ( ! info ) {
			return;
		}

		if ( 'queued' === phase || 'running' === phase ) {
			flash( panel, info.start, 'working' );
			return;
		}

		forget( panel );

		if ( 'failed' === phase ) {
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

	// A dialog that asks first. source: an element with the paragraphs (hidden, in the card), or text; name: the thing it is about.
	function confirmFirst( panel, options ) {
		var dialog = panel.querySelector( '[data-confirm-dialog]' );
		var body;
		var yes;
		var line;

		if ( ! dialog ) {
			return false;
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
		if ( button ) {
			busy( button );
		}

		post( panel, 'actions/' + encodeURIComponent( action ), { args: args } ).then( function ( response ) {
			if ( 202 === response.status ) {
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
					remember( panel, info );
				} else {
					notice( panel, vm.strings.queued );
				}

				// The panel's status poll (account.js) shows the progress and reloads when it is over.
				panel.dispatchEvent( new window.CustomEvent( 'beltoft-services:queued' ) );
				return null;
			}

			if ( button ) {
				reset( button );
			}

			return reply( panel, response, vm.strings.failed );
		} ).catch( function () {
			if ( button ) {
				reset( button );
			}
			notice( panel, vm.strings.failed );
		} );
	}

	// A WooCommerce Subscriptions link (cancel, reactivate) is a GET that does its work and redirects: do it in the background, then show the card again.
	function subscriptionLink( panel, url, button ) {
		if ( button ) {
			busy( button );
		}

		window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'refused' );
			}

			window.location.reload();
		} ).catch( function () {
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

	document.querySelectorAll( '.beltoft-services-panel[data-resource]' ).forEach( function ( panel ) {
		showOutcome( panel );

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
			var remove = event.target.closest( '[data-confirm-url]' );
			var undo = event.target.closest( '[data-subscription-link]' );
			var dialog = panel.querySelector( '[data-snapshot-dialog]' );
			var button = event.target.closest( '[data-vm-reveal]' );
			var name;

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
				// Without the dialog the link keeps working as a link.
				if ( confirmFirst( panel, {
					title: document.getElementById( remove.getAttribute( 'data-confirm-open' ) ).getAttribute( 'data-confirm-title' ),
					label: document.getElementById( remove.getAttribute( 'data-confirm-open' ) ).getAttribute( 'data-confirm-yes' ),
					source: document.getElementById( remove.getAttribute( 'data-confirm-open' ) ),
					run: function ( yes ) {
						subscriptionLink( panel, remove.href, yes );
					}
				} ) ) {
					event.preventDefault();
				}

				return;
			}

			if ( undo ) {
				event.preventDefault();
				subscriptionLink( panel, undo.href, null );

				return;
			}

			if ( cancel ) {
				closeDialog( cancel.closest( 'dialog' ) );

				return;
			}

			if ( button && ! button.disabled ) {
				reveal( panel, button );
			}
		} );
	} );
}() );
