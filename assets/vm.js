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

	var bs = window.beltoftStatus;

	function scopeOf( panel ) {
		return panel.getAttribute( 'data-resource' );
	}

	// A message that something went wrong: in the status bar, and under the field when there is one.
	function problem( panel, handle, text, field ) {
		panel.beltoftPending = false;
		bs.fail( handle || panel.beltoftWork || null, text, field || null );
		panel.beltoftWork = null;
		panel.classList.remove( 'has-working-flash' );
	}

	// The server's own refusal text is written for the customer; anything else gets a fixed line.
	function reply( panel, response, fallback, handle, field ) {
		return response.json().then( function ( body ) {
			if ( 403 === response.status && body && 'rest_cookie_invalid_nonce' === body.code ) {
				problem( panel, handle, vm.strings.expired );
			} else if ( 429 === response.status ) {
				problem( panel, handle, vm.strings.tooMany );
			} else if ( 422 === response.status && body && 'string' === typeof body.message ) {
				problem( panel, handle, body.message, field );
			} else {
				problem( panel, handle, fallback );
			}
		}, function () {
			problem( panel, handle, fallback );
		} );
	}

	// A button of a closed question goes back to normal: the dialog is used again for the next action.
	function resetButton( button ) {
		if ( button ) {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.classList.remove( 'is-busy' );
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
		return entry && entry.info && Date.now() - entry.local < 600000 ? entry : null;
	}

	// What the card says, in the status bar: working (the lock stays on while it works), done or not done.
	function flash( panel, text, kind ) {
		var power = panel.querySelector( '[data-power-line]' );
		var work = panel.beltoftWork;

		// The bar says what is happening: the progress sentence under the head would say it twice, and the power is not "Running" while it works.
		panel.classList.toggle( 'has-working-flash', 'working' === kind );

		if ( 'working' === kind ) {
			if ( ! work || work.released || panel.beltoftWorkText !== text ) {
				if ( work && ! work.released ) {
					bs.release( work );
				}

				panel.beltoftWork = bs.busy( text, scopeOf( panel ), null );
				panel.beltoftWorkText = text;
			}

			if ( power ) {
				power.textContent = vm.strings.busy;
			}

			return;
		}

		panel.beltoftWork = null;
		panel.beltoftWorkText = '';

		if ( 'failed' === kind ) {
			bs.fail( work || null, text );
		} else {
			bs.done( work || null, text );
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
			case 'rename':
				return { action: action, start: s.renameStart, done: s.renameDone };
			case 'replace_ssh_key':
				return { action: action, start: s.keyStart, done: s.keyDone };
			case 'reset_password':
				return { action: action, start: s.resetStart, done: s.resetDone, password: true };
			case 'reboot':
				return { action: action, start: s.rebootStart, done: s.rebootDone, power: true };
			case 'shutdown':
				return { action: action, start: s.shutdownStart, done: s.shutdownDone, power: true };
			default:
				return null;
		}
	}

	// Shows the end of a confirmed action once, from the server's record of it: done only when the job said it was.
	function ended( panel, result, info, data ) {
		var card = panel.querySelector( '.beltoft-services-vm' );
		var text;

		write( panel, ':seen', result.at );

		if ( ! result.ok ) {
			flash( panel, result.text || vm.strings.failed, 'failed' );

			return;
		}

		// Never claim what the VM does not show: the server says when it is still running after the wait.
		if ( data && data.power_failed && 'shutdown' === result.action ) {
			flash( panel, vm.strings.shutdownStill, 'failed' );

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

	// On every status of the card (account.js): working while it works, then done or not done. Works on a device that did not ask too, for a minute: a later page load never shows an old result again.
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

		// The job is over but the VM has not changed power yet: still working, from the same status the badge is drawn from.
		if ( data.power_pending && result && result.ok ) {
			flash( panel, 'start' === result.action ? vm.strings.startStart : vm.strings.shutdownStart, 'working' );

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

				ended( panel, result, asked.info, data );
			}

			return;
		}

		if ( data.now - result.at <= 60 && read( panel, ':seen' ) !== result.at ) {
			ended( panel, result, info, data );
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
		resetButton( yes );

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
		openDialog( dialog );

		return true;
	}

	// One request per click: a busy panel does not start another. field: the input a refusal is shown under.
	function send( panel, action, args, button, dialog, info, field ) {
		var handle;

		if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		panel.beltoftPending = true;
		handle = bs.busy( info && info.start ? info.start : vm.strings.working, scopeOf( panel ), button || null );

		post( panel, 'actions/' + encodeURIComponent( action ), { args: args } ).then( function ( response ) {
			// An action that needs a purchase first (a reinstall to a paid app) answers with the checkout address.
			if ( 200 === response.status ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					if ( body && body.redirect ) {
						bs.info( body.message || '' );
						window.location.href = body.redirect;

						return;
					}

					resetButton( button );
					problem( panel, handle, vm.strings.failed );
				} );
			}

			if ( 202 === response.status ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					panel.beltoftPending = false;

					if ( dialog ) {
						resetButton( button );
						closeDialog( dialog );
					}

					// The bar keeps saying what is going on (and the buttons stay locked) until the work is over.
					panel.beltoftWork = handle;
					panel.beltoftWorkText = info ? info.start : '';

					if ( info ) {
						panel.classList.add( 'has-working-flash' );
						write( panel, ':asked', { info: info, queued: body && body.at ? body.at : 0, local: Date.now() } );
					} else {
						bs.release( handle );
						panel.beltoftWork = bs.busy( vm.strings.queued, scopeOf( panel ), null );
						panel.beltoftWorkText = vm.strings.queued;
					}

					// The panel's status poll (account.js) shows the progress and reloads when it is over; a power action also looks a few times, as the card's own buttons do.
					panel.dispatchEvent( new window.CustomEvent( 'beltoft-services:queued', { detail: { settle: !! ( info && info.power ) } } ) );
				} );
			}

			resetButton( button );

			return reply( panel, response, vm.strings.failed, handle, field );
		} ).catch( function () {
			resetButton( button );
			problem( panel, handle, vm.strings.failed );
		} );
	}

	// A WooCommerce Subscriptions link (cancel, reactivate) is a GET that does its work and redirects: do it in the background, then show the card again.
	function subscriptionLink( panel, url, button ) {
		var handle;

		if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		panel.beltoftPending = true;
		handle = bs.busy( vm.strings.working, scopeOf( panel ), button || null );

		window.fetch( url, { credentials: 'same-origin' } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'refused' );
			}

			window.location.reload();
		} ).catch( function () {
			resetButton( button );
			problem( panel, handle, vm.strings.failed );
		} );
	}

	// A pay as you go extra disk is changed through its own resource (never a subscription link): the answer is 202, then the page reloads.
	function diskCall( panel, id, path, body, button ) {
		var handle;

		if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		panel.beltoftPending = true;
		handle = bs.busy( vm.strings.working, scopeOf( panel ), button || null );

		window.fetch( account.root + id + '/' + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			if ( 202 === response.status ) {
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );

				return null;
			}

			resetButton( button );

			return reply( panel, response, vm.strings.failed, handle );
		} ).catch( function () {
			resetButton( button );
			problem( panel, handle, vm.strings.failed );
		} );
	}

	function submit( panel, form ) {
		var args = {};
		var action = form.getAttribute( 'data-action' );
		var button = form.querySelector( 'button[type="submit"]' );
		var source = form.querySelector( '[data-confirm-source]' );
		var dialog = form.closest( 'dialog' );
		var backup;
		var field = form.querySelector( 'input[name]:not([type="hidden"]), textarea[name]' );

		// The question's own form: run what it was opened for.
		if ( 'confirm' === action ) {
			if ( dialog && dialog.beltoftRun ) {
				dialog.beltoftRun( button, dialog );
			}

			return;
		}

		Array.prototype.forEach.call( form.elements, function ( field ) {
			// A group of radios (the system and app tiles) sends the checked one only.
			if ( field.name && ! field.disabled && ( 'radio' !== field.type || field.checked ) ) {
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
					send( panel, action, args, yes, box, describe( action, '' ), field );
				}
			} ) ) {
				return;
			}
		}

		backup = form.querySelector( '[data-snapshot-dialog-name]' );
		send( panel, action, args, button, dialog, describe( action, backup ? backup.textContent : '' ), field );
	}

	function reveal( panel, button ) {
		var out = panel.querySelector( '[data-vm-password]' );
		var handle;

		if ( bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		handle = bs.busy( vm.strings.revealStart, scopeOf( panel ), button );

		post( panel, 'reveal', {} ).then( function ( response ) {
			if ( ! response.ok ) {
				return reply( panel, response, 409 === response.status ? vm.strings.gone : vm.strings.failed, handle );
			}

			return response.json().then( function ( body ) {
				var copy = panel.querySelector( '[data-vm-password-copy]' );

				if ( out && body && 'string' === typeof body.password ) {
					out.textContent = body.password;
					out.hidden = false;
					button.hidden = true;

					// The password is only in the page now: a copy button next to it, through the same copy as the ssh command.
					if ( copy ) {
						copy.setAttribute( 'data-copy', body.password );
						copy.hidden = false;
					}

					bs.done( handle, vm.strings.revealDone );
				} else {
					bs.fail( handle, vm.strings.failed );
				}
			} );
		} ).catch( function () {
			bs.fail( handle, vm.strings.failed );
		} );
	}

	// Reboot and Shut down ask first, in the shared question.
	function askPower( panel, action ) {
		var s = vm.strings;

		if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		bs.confirm( { title: s[ action + 'Title' ], confirm: s[ action + 'Yes' ], text: s[ action + 'Text' ] } ).then( function ( yes ) {
			if ( yes ) {
				send( panel, action, {}, null, null, describe( action, '' ) );
			}
		} );
	}

	// Reset password changes the root password on the server: it asks first.
	function askReset( panel ) {
		var s = vm.strings;

		if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
			return;
		}

		bs.confirm( { title: s.resetTitle, confirm: s.resetYes, text: s.resetText, danger: true } ).then( function ( yes ) {
			if ( yes ) {
				send( panel, 'reset_password', {}, null, null, describe( 'reset_password', '' ) );
			}
		} );
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
			bs.fail( null, vm.strings.failed );
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

	// A link to something inside a hidden pane (a disk's setup guide, in the Disks tab) opens its tab first.
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
			var diskOp = event.target.closest( '[data-delete-disk], [data-grow-disk]' );
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

			if ( resetPw && ! resetPw.disabled ) {
				askReset( panel );

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
				openDialog( dialog );

				return;
			}

			if ( drop && ! drop.disabled ) {
				name = drop.getAttribute( 'data-snapshot-name' ) || '';

				if ( panel.beltoftPending || bs.locked( scopeOf( panel ) ) ) {
					return;
				}

				bs.confirm( { title: vm.strings.deleteTitle, confirm: vm.strings.deleteYes, text: [ name, vm.strings.snapshotDelete ].filter( Boolean ), danger: true } ).then( function ( yes ) {
					if ( yes ) {
						send( panel, 'snapshot_delete', { id: drop.getAttribute( 'data-snapshot-delete' ) }, null, null, describe( 'snapshot_delete', name ) );
					}
				} );

				return;
			}

			if ( diskOp ) {
				source = document.getElementById( diskOp.getAttribute( 'data-confirm-open' ) );

				if ( source ) {
					confirmFirst( panel, {
						title: source.getAttribute( 'data-confirm-title' ),
						label: source.getAttribute( 'data-confirm-yes' ),
						source: source,
						run: function ( yes ) {
							if ( diskOp.hasAttribute( 'data-delete-disk' ) ) {
								diskCall( panel, diskOp.getAttribute( 'data-delete-disk' ), 'delete', { confirm: 'DELETE' }, yes );
							} else {
								diskCall( panel, diskOp.getAttribute( 'data-grow-disk' ), 'grow', {}, yes );
							}
						}
					} );
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
