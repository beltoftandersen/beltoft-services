/**
 * My Account: the hosting panel.
 *
 * Buttons and fields of the panel carry data-hosting-action and data-hosting-field attributes. A click sends one
 * request to the hosting REST routes with the wp_rest nonce that the generic panel script already holds
 * (window.beltoftServicesAccount), refreshed from every response's X-WP-Nonce header, and then reloads the page to
 * show the new state. The WordPress sign-in link opens in a new tab. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var config = window.beltoftHosting || { strings: {} };
	var text = config.strings || {};
	var S = window.beltoftStatus;

	if ( ! account || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	// What happened goes to the status bar at the top of the page, like every other form of My Account; nothing is printed in the panel.
	function say( panel, message ) {
		if ( message && S ) {
			S.info( message );
		}
	}

	function fail( message ) {
		if ( message && S ) {
			S.fail( null, message );
		}
	}

	// A field of the block (website panel, form) the button is in.
	function field( scope, name ) {
		var el = scope.querySelector( '[data-hosting-field="' + name + '"]' );

		return el ? String( el.value || '' ).trim() : '';
	}

	function format( template, values ) {
		var i = 0;

		return String( template || '' ).replace( /%(?:(\d)\$)?s/g, function ( all, n ) {
			var value = values[ n ? parseInt( n, 10 ) - 1 : i ];

			i += n ? 0 : 1;

			return undefined === value ? '' : value;
		} );
	}

	function call( method, path, body ) {
		return window.beltoftApi.call( account.root + path, { method: method, body: body, holder: account } );
	}

	// A request that waits for the customer's answer in the account area's own dialog (window.beltoftStatus.confirm).
	function ask( question, button, request, danger ) {
		return { ask: question, label: button.textContent, danger: danger, request: request };
	}

	function confirmed( question, label, danger ) {
		if ( S && S.confirm ) {
			return S.confirm( { text: question, confirm: label, danger: danger } );
		}

		return Promise.resolve( window.confirm( question ) );
	}

	// The request for each action: [ method, path, body ] or null when the input is not complete.
	function plan( panel, button, id ) {
		var base = id + '/hosting';
		var slug = button.getAttribute( 'data-slug' ) || '';
		var scope = button.closest( '[data-hosting-scope]' ) || panel;

		switch ( button.getAttribute( 'data-hosting-action' ) ) {
			case 'login':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/login', {} ];
			case 'password':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/password', {} ];
			case 'php_save':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/php', { php: field( scope, 'php_version' ) } ];
			case 'set_main':
				var chosen = scope.querySelector( 'input[type="radio"]:checked' );

				return chosen ? [ 'POST', base + '/domains/' + encodeURIComponent( chosen.value ) + '/main', {} ] : null;
			case 'remove_domain':
				return ask( text.confirmRemoveDomain || '', button, [ 'DELETE', base + '/domains/' + encodeURIComponent( button.getAttribute( 'data-host' ) || '' ), undefined ], true );
			case 'files_password':
				return '' === field( scope, 'code' ) ? null : [ 'POST', base + '/files/password', { code: field( scope, 'code' ), password: field( scope, 'sftp_password' ) } ];
			case 'files_key':
				return '' === field( scope, 'code' ) || '' === field( scope, 'ssh_key' ) ? null : [ 'POST', base + '/files/keys', { code: field( scope, 'code' ), key: field( scope, 'ssh_key' ) } ];
			case 'remove_key':
				return ask( text.confirmRemoveKey || '', button, [ 'DELETE', base + '/files/keys?fingerprint=' + encodeURIComponent( button.getAttribute( 'data-fingerprint' ) || '' ), undefined ], true );
			case 'dns_check':
				return [ 'POST', base + '/domains/' + encodeURIComponent( button.getAttribute( 'data-host' ) || '' ) + '/check', {} ];
			case 'php_rollback':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/php/rollback', {} ];
			case 'cache':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/cache', {} ];
			case 'remove_site':
				var typed = window.prompt( text.confirm || '' );

				return null === typed ? null : [ 'DELETE', base + '/sites/' + encodeURIComponent( slug ) + '?confirm=' + encodeURIComponent( typed ), undefined ];
			case 'add_site':
				return [ 'POST', base + '/sites', { slug: '', label: field( scope, 'label' ), php: field( scope, 'php' ) } ];
			case 'add_domain':
				return '' === field( scope, 'host' ) ? null : [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/domains', { host: field( scope, 'host' ) } ];
			case 'add_pack':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/addons', { kind: 'mail_pack', target: button.getAttribute( 'data-mailbox' ) || '', confirm: parseInt( button.getAttribute( 'data-cents' ) || '0', 10 ) } ], false );
			case 'remove_pack':
				return ask( text.confirmPack || '', button, [ 'DELETE', base + '/addons?target=' + encodeURIComponent( button.getAttribute( 'data-mailbox' ) || '' ), undefined ], true );
			case 'enable_mail':
				return [ 'POST', base + '/mail', { domain: field( scope, 'mail_domain' ) } ];
			case 'backup_now':
				return [ 'POST', base + '/backups', {} ];
			case 'restore':
				var scope = button.getAttribute( 'data-scope' ) || '';

				return ask( text.confirmRestore || '', button, [ 'POST', base + '/backups/restore', { scope: scope, volid: button.getAttribute( 'data-volid' ) || '', slug: field( scope, 'restore_slug' ) } ], false );
			case 'undo':
				return ask( text.confirmUndo || '', button, [ 'POST', base + '/backups/undo', {} ], false );
			case 'check_item':
				return [ 'POST', base + '/checklist', { item: button.getAttribute( 'data-item' ) || '' } ];
			case 'dismiss_checklist':
				return [ 'POST', base + '/checklist', { dismiss: true } ];
		}

		return null;
	}

	// The answer of an add-on purchase: paid with the saved card (go on), or a link to pay the order first.
	function pay( panel, bought, next ) {
		if ( bought.ok && bought.data && 'paid' === bought.data.status ) {
			next();
		} else if ( bought.ok && bought.data && 'pending' === bought.data.status ) {
			say( panel, bought.data.message || '' );
		} else if ( bought.ok && bought.data && bought.data.pay_url ) {
			window.location.href = bought.data.pay_url;
		} else {
			fail( ( bought.data && bought.data.message ) || text.failed || '' );
		}
	}

	function setHidden( el, hidden ) {
		if ( el ) {
			el.hidden = hidden;
		}
	}

	function expanded( opener, open ) {
		if ( opener ) {
			opener.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	// A form that opens takes the focus in its first field; one that closes gives it back to the button that opened it.
	function disclosed( opener, form, open ) {
		expanded( opener, open );

		if ( open && form ) {
			var first = form.querySelector( 'input:not([type="hidden"]), select, textarea' );

			if ( first ) {
				first.focus();
			}
		} else if ( ! open && opener && ! opener.hidden ) {
			opener.focus();
		}
	}

	// The fields an action cannot do without. The first empty one is marked and takes the focus.
	var NEEDS = { add_domain: [ 'host' ], files_password: [ 'code' ], files_key: [ 'ssh_key', 'code' ] };

	function firstEmpty( scope, action ) {
		var names = NEEDS[ action ] || [];

		for ( var i = 0; i < names.length; i++ ) {
			var el = scope.querySelector( '[data-hosting-field="' + names[ i ] + '"]' );

			if ( el && '' === String( el.value || '' ).trim() ) {
				return el;
			}
		}

		return null;
	}

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && event.target.hasAttribute && event.target.hasAttribute( 'data-hosting-field' ) ) {
			event.target.removeAttribute( 'aria-invalid' );
		}
	} );

	// Enter in a field does what the button beside it does; Escape closes an opened form.
	document.addEventListener( 'keydown', function ( event ) {
		var el = event.target;

		if ( ! el || ! el.closest || ! el.closest( '.beltoft-hosting' ) ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			var open = el.closest( '[data-php-form], [data-files-form]' );
			var close = open ? open.querySelector( '[data-hosting-action="php_cancel"], [data-hosting-action="files_close"]' ) : null;

			if ( close ) {
				event.preventDefault();
				close.click();
			}

			return;
		}

		if ( 'Enter' !== event.key || 'INPUT' !== el.tagName || /^(radio|checkbox|hidden)$/.test( el.type ) ) {
			return;
		}

		var group = el.closest( '[data-files-form]' ) || el.closest( '[data-php-form]' ) || el.closest( '.beltoft-hosting-formrow' );
		var go = group ? ( group.querySelector( '.beltoft-hosting-btn--primary[data-hosting-action]' ) || group.querySelector( 'button[data-hosting-action]:not([data-hosting-action="send_code"]):not([data-hosting-action="files_close"]):not([data-hosting-action="php_cancel"])' ) ) : null;

		if ( go && ! go.disabled ) {
			event.preventDefault();
			go.click();
		}
	} );

	// Actions that only show, hide or copy something, or ask for the emailed code. True when the click was one of them.
	function local( panel, button, id, action ) {
		var scope = button.closest( '[data-hosting-scope]' ) || panel;

		switch ( action ) {
			case 'php_edit':
			case 'php_cancel':
				var phpOpener = scope.querySelector( '[data-hosting-action="php_edit"]' );

				setHidden( scope.querySelector( '[data-php-form]' ), 'php_cancel' === action );
				setHidden( phpOpener, 'php_edit' === action );
				disclosed( phpOpener, scope.querySelector( '[data-php-form]' ), 'php_edit' === action );
				return true;
			case 'files_open':
				Array.prototype.forEach.call( scope.querySelectorAll( '[data-files-form]' ), function ( form ) {
					var mine = form.getAttribute( 'data-files-form' ) === button.getAttribute( 'data-target' );

					setHidden( form, ! mine );

					if ( ! mine ) {
						expanded( scope.querySelector( '[data-hosting-action="files_open"][data-target="' + form.getAttribute( 'data-files-form' ) + '"]' ), false );
					}
				} );
				setHidden( scope.querySelector( '[data-files-reveal]' ), true );
				disclosed( button, scope.querySelector( '[data-files-form="' + button.getAttribute( 'data-target' ) + '"]' ), true );
				return true;
			case 'files_close':
				var closing = button.closest( '[data-files-form]' );

				setHidden( closing, true );
				disclosed( closing ? scope.querySelector( '[data-hosting-action="files_open"][data-target="' + closing.getAttribute( 'data-files-form' ) + '"]' ) : null, closing, false );
				return true;
			case 'copy':
				copy( button.getAttribute( 'data-copy' ) || '' );
				return true;
			case 'copy_secret':
				copy( ( scope.querySelector( '[data-files-secret]' ) || {} ).textContent || '' );
				return true;
			case 'send_code':
				button.disabled = true;
				window.beltoftApi.call( account.root + 'step-up/send', { method: 'POST', body: { purpose: 'hosting_files' }, holder: account } ).then( function ( result ) {
					button.disabled = false;

					if ( result.ok ) {
						say( panel, text.codeSent || '' );
					} else {
						fail( ( result.data && result.data.message ) || text.failed || '' );
					}
				}, function () {
					button.disabled = false;
					fail( text.failed || '' );
				} );
				return true;
		}

		return false;
	}

	function copy( value ) {
		if ( ! value ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( function () {
				say( null, text.copied || '' );
			}, function () {} );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-hosting-action]' ) : null;
		var panel = button ? button.closest( '.beltoft-hosting' ) : null;

		if ( ! button || ! panel ) {
			return;
		}

		var id = panel.getAttribute( 'data-hosting-resource' );
		var action = button.getAttribute( 'data-hosting-action' );

		if ( local( panel, button, id, action ) ) {
			return;
		}

		var request = plan( panel, button, id );

		if ( ! request ) {
			var missing = firstEmpty( button.closest( '[data-hosting-scope]' ) || panel, action );

			if ( missing ) {
				missing.setAttribute( 'aria-invalid', 'true' );
				missing.focus();
			}

			return;
		}

		if ( request.ask ) {
			var next = request.request;

			confirmed( request.ask, request.label, request.danger ).then( function ( yes ) {
				if ( yes ) {
					run( panel, button, id, action, next );
				}
			} );
			return;
		}

		run( panel, button, id, action, request );
	} );

	function run( panel, button, id, action, request ) {
		button.disabled = true;
		var opens = 'login' === action || 'password' === action;

		say( panel, opens ? ( text.opening || '' ) : ( text.working || '' ) );

		// The sign-in link is opened from the click, so a pop-up blocker allows it.
		var tab = opens ? window.open( '', '_blank' ) : null;

		// The tab opens on the customer's own website: it must not be able to reach back to this page.
		if ( tab ) {
			try {
				tab.opener = null;
			} catch ( e ) {
				// A browser that does not allow it opened the tab without a link back already.
			}
		}

		call( request[ 0 ], request[ 1 ], request[ 2 ] ).then( function ( result ) {
			button.disabled = false;

			// An extra domain is a paid add-on: show the price, and when it is accepted buy it and add the domain again.
			if ( ! result.ok && 402 === result.status && 'add_domain' === action && result.data && 'beltoft_services_hosting_addon_required' === result.data.code ) {
				// WordPress nests the data of an error response one level down: result.data.data.
				var offer = result.data.data || {};

				say( panel, '' );
				confirmed( result.data.message || '', text.buy || '', false ).then( function ( yes ) {
					if ( ! yes ) {
						say( panel, '' );
						return;
					}

					button.disabled = true;
					call( 'POST', id + '/hosting/addons', { kind: 'domain', target: offer.target, confirm: offer.amount_cents, host: offer.host, slug: offer.slug } ).then( function ( bought ) {
						button.disabled = false;
						pay( panel, bought, function () {
							button.click();
						} );
					} );
				} );

				return;
			}

			if ( result.ok && result.data && 'pending' === result.data.status ) {
				button.disabled = false;
				say( panel, result.data.message || '' );
				return;
			}

			if ( result.ok && result.data && 'pay' === result.data.status && result.data.pay_url ) {
				button.disabled = false;
				window.location.href = result.data.pay_url;
				return;
			}

			if ( ! result.ok ) {
				if ( tab ) {
					tab.close();
				}

				fail( ( result.data && result.data.message ) || text.failed || '' );
				return;
			}

			if ( opens && result.data && result.data.url ) {
				if ( tab ) {
					tab.location = result.data.url;
				}

				// The node tells which administrator the address is for: say so.
				if ( result.data.user ) {
					say( panel, 'password' === action ? format( text.passwordFor, [ result.data.user ] ) : format( text.signedInAs, [ result.data.user, result.data.email || '' ] ).replace( ' ()', '' ) );
				} else {
					say( panel, text.done || '' );
				}

				return;
			}

			// A password is shown once, in place, and the page is not reloaded.
			if ( 'files_password' === action && result.data && result.data.password ) {
				var form = button.closest( '[data-files-form]' );
				var scope = button.closest( '[data-hosting-scope]' ) || panel;
				var reveal = scope.querySelector( '[data-files-reveal]' );
				var secret = scope.querySelector( '[data-files-secret]' );

				if ( secret ) {
					secret.textContent = result.data.password;
				}

				setHidden( reveal, false );
				setHidden( form, true );
				Array.prototype.forEach.call( scope.querySelectorAll( '[data-hosting-field]' ), function ( input ) {
					input.value = '';
				} );
				say( panel, text.done || '' );
				return;
			}

			say( panel, text.done || '' );
			window.setTimeout( function () {
				window.location.reload();
			}, 800 );
		}, function () {
			button.disabled = false;

			if ( tab ) {
				tab.close();
			}

			fail( text.failed || '' );
		} );
	}
}() );
