/**
 * My Account: the hosting panel.
 *
 * Buttons and fields of the panel carry data-hosting-action and data-hosting-field attributes. A click sends one
 * request to the hosting REST routes with the wp_rest nonce that the generic panel script already holds
 * (window.beltoftServicesAccount), refreshed from every response's X-WP-Nonce header, and then reloads the page to
 * show the new state. The WordPress sign-in link opens in a new tab. A change that runs in the background (the route answers
 * 202 with a task: every change of the account, a staging copy, the move of WordPress, a backup and a restore included) locks the
 * change buttons with "Wait until ... is done", and window.beltoftApi.follow() reads the resource every 3 seconds until the
 * action's outcome (last_result) is there, saying the step in the line at the top; then the page reloads. No dependencies.
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
	// The line of the account's task (watch) ends with a message; without one, the message is told on its own.
	var taskLine = null;

	function end( message ) {
		if ( taskLine && S ) {
			S.done( taskLine, message );
			taskLine = null;
			return;
		}

		say( null, message );
	}

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
			case 'tool_files':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/files-link', {} ];
			case 'tool_database':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/database-link', {} ];
			case 'staging_open':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/staging/login', {} ];
			case 'staging_create':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/staging', {} ];
			case 'staging_refresh':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/staging/refresh', {} ], false );
			case 'staging_delete':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'DELETE', base + '/sites/' + encodeURIComponent( slug ) + '/staging', undefined ], true );
			case 'staging_undo':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/staging/undo', {} ], true );
			case 'staging_push':
				var what = scope.querySelector( '[data-staging-form] input[type="radio"]:checked' );

				return what ? ask( what.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/staging/push', { files_only: 'files' === what.value } ], 'files' !== what.value ) : null;
			case 'country_save':
			case 'country_off':
				var countryScope = scope.querySelector( '[data-country-form]' ) || scope;
				var picked = [];
				var kind = countryScope.querySelector( 'input[type="radio"]:checked' );

				if ( 'country_save' === button.getAttribute( 'data-hosting-action' ) ) {
					Array.prototype.forEach.call( countryScope.querySelectorAll( 'input[data-country]:checked' ), function ( box ) {
						picked.push( box.value );
					} );
				}

				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/country-block', { countries: picked, scope: kind ? kind.value : 'site' } ];
			case 'php_save':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/php', { php: field( scope, 'php_version' ) } ];
			case 'set_main':
				var chosen = scope.querySelector( 'input[type="radio"]:checked' );

				return chosen ? [ 'POST', base + '/domains/' + encodeURIComponent( chosen.value ) + '/main', {} ] : null;
			case 'wp_address':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/wordpress-address', { host: button.getAttribute( 'data-host' ) || '' } ], false );
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
				// The name typed in the danger block of the website (the server checks it too).
				return [ 'DELETE', base + '/sites/' + encodeURIComponent( slug ) + '?confirm=' + encodeURIComponent( field( scope, 'remove_confirm' ) ), undefined ];
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
				// What is restored (account, files, database); `scope` stays the page section the fields are read from.
				var what = button.getAttribute( 'data-scope' ) || '';

				return ask( text.confirmRestore || '', button, [ 'POST', base + '/backups/restore', { scope: what, volid: button.getAttribute( 'data-volid' ) || '', slug: field( scope, 'restore_slug' ) } ], false );
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

		// The country list narrows to what was typed.
		if ( event.target && event.target.hasAttribute && event.target.hasAttribute( 'data-country-filter' ) ) {
			var typed = String( event.target.value || '' ).trim().toLowerCase();
			var form = event.target.closest( '[data-country-form]' );

			Array.prototype.forEach.call( form ? form.querySelectorAll( '[data-country-name]' ) : [], function ( row ) {
				row.hidden = '' !== typed && -1 === ( row.getAttribute( 'data-country-name' ) || '' ).indexOf( typed );
			} );
		}
	} );

	// Enter in a field does what the button beside it does; Escape closes an opened form.
	document.addEventListener( 'keydown', function ( event ) {
		var el = event.target;

		if ( ! el || ! el.closest || ! el.closest( '.beltoft-hosting' ) ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			var open = el.closest( '[data-php-form], [data-files-form], [data-country-form], [data-staging-form]' );
			var close = open ? open.querySelector( '[data-hosting-action="php_cancel"], [data-hosting-action="files_close"], [data-hosting-action="country_cancel"], [data-hosting-action="staging_cancel"]' ) : null;

			if ( close ) {
				event.preventDefault();
				close.click();
			}

			return;
		}

		if ( 'Enter' !== event.key || 'INPUT' !== el.tagName || /^(radio|checkbox|hidden)$/.test( el.type ) || el.hasAttribute( 'data-hosting-nosubmit' ) ) {
			return;
		}

		var group = el.closest( '[data-files-form]' ) || el.closest( '[data-php-form]' ) || el.closest( '.beltoft-hosting-formrow' );
		var go = group ? ( group.querySelector( '.beltoft-hosting-btn--primary[data-hosting-action]' ) || group.querySelector( 'button[data-hosting-action]:not([data-hosting-action="send_code"]):not([data-hosting-action="files_close"]):not([data-hosting-action="php_cancel"]):not([data-hosting-action="country_cancel"])' ) ) : null;

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
			case 'country_edit':
			case 'country_cancel':
				var countryOpener = scope.querySelector( '[data-hosting-action="country_edit"]' );
				var countryForm = scope.querySelector( '[data-country-form]' );

				setHidden( countryForm, 'country_cancel' === action );
				disclosed( countryOpener, countryForm, 'country_edit' === action );
				return true;
			case 'staging_edit':
			case 'staging_cancel':
				var stagingOpener = scope.querySelector( '[data-hosting-action="staging_edit"]' );
				var stagingForm = scope.querySelector( '[data-staging-form]' );

				setHidden( stagingForm, 'staging_cancel' === action );
				disclosed( stagingOpener, stagingForm, 'staging_edit' === action );
				return true;
			case 'country_list':
				var wanted = ( button.getAttribute( 'data-codes' ) || '' ).split( ' ' );

				Array.prototype.forEach.call( scope.querySelectorAll( 'input[data-country]' ), function ( box ) {
					if ( wanted.indexOf( box.value ) >= 0 ) {
						box.checked = true;
					}
				} );

				// A list that suits the login only sets that scope, unless the website already has a saved block.
				var listScope = button.getAttribute( 'data-scope' );
				var listForm = scope.querySelector( '[data-country-form]' );

				if ( listScope && listForm && ! listForm.hasAttribute( 'data-country-set' ) ) {
					var radio = listForm.querySelector( 'input[type="radio"][value="' + listScope + '"]' );

					if ( radio ) {
						radio.checked = true;
					}
				}
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
				window.beltoftApi.call( account.stepUpUrl, { method: 'POST', body: { purpose: 'hosting_files' }, holder: account } ).then( function ( result ) {
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
		var opens = 'login' === action || 'password' === action || 'tool_files' === action || 'tool_database' === action || 'staging_open' === action;
		var staging = 0 === action.indexOf( 'staging_' ) && ! opens;
		var opening = 'tool_files' === action ? text.openingFiles : ( 'tool_database' === action ? text.openingDatabase : text.opening );

		// Working, done and failed are said under the clicked button (window.beltoftStatus note) and in the state line.
		var handle = S ? S.busy( button.getAttribute( 'data-busy' ) || ( opens ? ( opening || '' ) : ( text.working || '' ) ), 'page', button ) : null;
		var ok = function ( message ) {
			// The button's own done sentence beats the generic one; the server's own message beats both.
			if ( message === text.done && button.getAttribute( 'data-done' ) ) {
				message = button.getAttribute( 'data-done' );
			}

			if ( handle ) {
				S.done( handle, message );
				handle = null;
			} else {
				say( panel, message );
			}
		};
		var bad = function ( message ) {
			if ( handle ) {
				S.fail( handle, message );
				handle = null;
			} else {
				fail( message );
			}
		};


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

				ok( '' );
				confirmed( result.data.message || '', text.buy || '', false ).then( function ( yes ) {
					if ( ! yes ) {
						ok( '' );
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

			// The person's own country is on the list: the answer is a question, and the same request goes again with the answer.
			if ( ! result.ok && 409 === result.status && 'country_save' === action && result.data && 'beltoft_services_hosting_country_confirm' === result.data.code ) {
				ok( '' );
				confirmed( result.data.message || '', text.saveAnyway || '', true ).then( function ( yes ) {
					if ( yes ) {
						var again = request[ 2 ];

						again.confirm = true;
						run( panel, button, id, action, [ request[ 0 ], request[ 1 ], again ] );
					}
				} );

				return;
			}

			// A change that runs in the background: the answer carries its task. The SFTP password is shown first (below).
			if ( result.ok && result.data && result.data.task && 'files_password' !== action ) {
				button.disabled = false;
				ok( '' );
				begin( result.data.task, DRAWN.indexOf( action ) >= 0, button );
				return;
			}

			if ( result.ok && result.data && 'pending' === result.data.status && ! result.data.task ) {
				button.disabled = false;
				ok( result.data.message || '' );
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

				bad( ( result.data && result.data.message ) || text.failed || '' );
				return;
			}

			if ( opens && result.data && result.data.url ) {
				if ( tab ) {
					tab.location = result.data.url;
				}

				// The node tells which administrator the address is for: say so.
				if ( result.data.user ) {
					ok( 'password' === action ? format( text.passwordFor, [ result.data.user ] ) : format( text.signedInAs, [ result.data.user, result.data.email || '' ] ).replace( ' ()', '' ) );
				} else {
					ok( text.done || '' );
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
				ok( text.done || '' );

				// The password is set in the background: the account waits for it like for any other change.
				if ( result.data.task ) {
					begin( result.data.task, false, button );
				}

				return;
			}

			// A move of WordPress and the staging jobs run in the background: the answer says so, and the page shows the state.
			var said = ( ( 'wp_address' === action || staging ) && result.data && result.data.message ) || '';

			ok( said || text.done || '' );

			// Clearing the cache changes nothing on this page: the done line stays and the page is not reloaded.
			if ( 'cache' === action ) {
				return;
			}

			// Every other change reloads to show the new state; the done line is shown again under the same button after it.
			remember( action, button.getAttribute( 'data-slug' ) || '', said || button.getAttribute( 'data-done' ) || text.done || '' );

			window.setTimeout( function () {
				window.location.reload();
			}, 'wp_address' === action ? 2500 : ( staging ? 1500 : 800 ) );
		}, function () {
			button.disabled = false;

			if ( tab ) {
				tab.close();
			}

			bad( text.failed || '' );
		} );
	}


	// The done line of an action that reloads the page: kept for the next load of this page only, then shown under the same button.
	var DONE_KEY = 'beltoftHostingDone';

	function remember( action, slug, message ) {
		try {
			window.sessionStorage.setItem( DONE_KEY, JSON.stringify( { action: action, slug: slug, message: message, path: window.location.pathname, at: Date.now() } ) );
		} catch ( e ) {
			// Without storage the page still reloads; only the line after it is missing.
		}
	}

	function recall() {
		var saved = null;

		try {
			saved = JSON.parse( window.sessionStorage.getItem( DONE_KEY ) || 'null' );
			window.sessionStorage.removeItem( DONE_KEY );
		} catch ( e ) {
			return;
		}

		if ( ! saved || ! saved.message || saved.path !== window.location.pathname || Date.now() - saved.at > 30000 || ! S ) {
			return;
		}

		var selector = '[data-hosting-action="' + String( saved.action ).replace( /["\\]/g, '' ) + '"]' + ( saved.slug ? '[data-slug="' + String( saved.slug ).replace( /["\\]/g, '' ) + '"]' : '' );
		var button = document.querySelector( selector ) || document.querySelector( '[data-hosting-action="' + String( saved.action ).replace( /["\\]/g, '' ) + '"]' );

		if ( button ) {
			var section = button.closest( 'details' );

			if ( section ) {
				section.open = true;
			}

			S.done( S.busy( '', 'page', button ), saved.message );
		} else {
			say( null, saved.message );
		}
	}

	recall();

	// The caches of each website, asked from the node when the page is open (the page itself is drawn without calling the node).
	function cacheFacts() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cache-facts]' ), function ( box ) {
			var panel = box.closest( '[data-hosting-resource]' );
			var slug = box.getAttribute( 'data-slug' ) || '';

			if ( ! panel || ! slug ) {
				return;
			}

			var factsPath = panel.getAttribute( 'data-hosting-resource' ) + '/hosting/sites/' + encodeURIComponent( slug ) + '/cache';

			// A page that loads just after a job ended can get one 409 (the resource is not ready yet): that is "not yet", asked once
			// more after a short wait, quietly. Any other answer, or a second 409, ends it (no loop, the line stays hidden).
			call( 'GET', factsPath ).then( function ( first ) {
				if ( 409 !== first.status ) {
					return first;
				}

				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, 4000 );
				} ).then( function () {
					return call( 'GET', factsPath );
				} );
			} ).then( function ( result ) {
				if ( ! result.ok || ! result.data ) {
					return;
				}

				var pages = { on: text.cachePageOn, off: text.cachePageOff, inactive: text.cachePageInactive, missing: text.cachePageMissing };
				var objects = { on: text.cacheObjectOn, off: text.cacheObjectOff };
				var page = pages[ result.data.page_cache ] || '';
				var object = objects[ result.data.object_cache ] || '';
				var pageBox = box.querySelector( '[data-cache-page]' );
				var objectBox = box.querySelector( '[data-cache-object]' );

				if ( ! page && ! object ) {
					return;
				}

				if ( pageBox ) {
					pageBox.textContent = page;
					pageBox.parentNode.hidden = ! page;
				}

				if ( objectBox ) {
					objectBox.textContent = object;
					objectBox.parentNode.hidden = ! object;
				}

				box.hidden = false;

				// The closed row shows the page cache state beside its name.
				var row = box.closest( 'details' );
				var value = row ? row.querySelector( '.bsh-sec__value' ) : null;

				if ( value && page ) {
					value.textContent = text.cacheRowValue ? text.cacheRowValue.replace( '%s', page ) : page;
				}
			}, function () {
				// The facts are an extra: without them the line stays hidden.
			} );
		} );
	}

	// While a task runs the node is busy: the facts are asked once it is over (finish).
	if ( ! ( document.querySelector( '.beltoft-hosting' ) || document.createElement( 'i' ) ).getAttribute( 'data-hosting-task' ) ) {
		cacheFacts();
	}

	// The change of the account that runs in the background (a task), and the watcher that follows it.
	var root = document.querySelector( '.beltoft-hosting' );
	var LOCKED = [ 'php_save', 'php_rollback', 'cache', 'remove_site', 'add_site', 'add_domain', 'remove_domain', 'set_main', 'country_save', 'country_off', 'files_password', 'files_key', 'remove_key', 'wp_address', 'staging_create', 'staging_refresh', 'staging_delete', 'staging_undo', 'staging_push', 'backup_now', 'restore', 'undo' ];
	// A change whose new state the page shows at once (a row that is being set up, a copy that is being made): the page reloads when it is queued.
	var DRAWN = [ 'add_site', 'remove_site', 'wp_address', 'staging_create', 'staging_refresh', 'staging_delete', 'staging_undo', 'staging_push', 'backup_now', 'restore', 'undo' ];
	var NO_RELOAD = [ 'cache' ];
	var watching = false;
	var current = null;

	function lowerFirst( value ) {
		value = String( value || '' );

		return value.charAt( 0 ).toLowerCase() + value.slice( 1 );
	}

	// The change buttons are off, with the reason, and the line at the top says what the account is doing and at which step.
	function lock( task ) {
		if ( ! root || ! task ) {
			return;
		}

		var wait = format( text.waitUntil, [ lowerFirst( task.label ) ] );

		root.setAttribute( 'data-hosting-task', JSON.stringify( task ) );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-hosting-action]' ), function ( button ) {
			if ( LOCKED.indexOf( button.getAttribute( 'data-hosting-action' ) ) >= 0 ) {
				button.disabled = true;
				button.setAttribute( 'data-task-locked', '1' );
				button.title = wait;
			}
		} );

		var line = root.querySelector( '[data-task-line]' );

		if ( line ) {
			line.hidden = false;
			line.querySelector( '[data-task-wait]' ).textContent = wait;
		}

		Array.prototype.forEach.call( root.querySelectorAll( '[data-task-step]' ), function ( el ) {
			var panel = el.closest( '[data-slug]' );

			if ( ! panel || panel.getAttribute( 'data-slug' ) === task.slug ) {
				el.textContent = task.step_label || task.label || '';
			}
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-site-status]' ), function ( el ) {
			var panel = el.closest( '[data-slug]' );

			if ( panel && panel.getAttribute( 'data-slug' ) === task.slug ) {
				el.textContent = task.step_label || task.label || '';
				el.classList.remove( 'is-ok' );
			}
		} );
	}

	function unlock() {
		if ( ! root ) {
			return;
		}

		root.removeAttribute( 'data-hosting-task' );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-task-locked]' ), function ( button ) {
			button.disabled = false;
			button.removeAttribute( 'data-task-locked' );
			button.removeAttribute( 'title' );
		} );

		var line = root.querySelector( '[data-task-line]' );

		if ( line ) {
			line.hidden = true;
		}

		// The step lines of the task are gone with it: a row says what it is again (Online), the others are emptied.
		Array.prototype.forEach.call( root.querySelectorAll( '[data-task-step]' ), function ( el ) {
			el.textContent = '';
		} );

		Array.prototype.forEach.call( root.querySelectorAll( '[data-site-status]' ), function ( el ) {
			if ( el.hasAttribute( 'data-idle-text' ) ) {
				el.textContent = el.getAttribute( 'data-idle-text' );
				el.classList.toggle( 'is-ok', '1' === el.getAttribute( 'data-idle-ok' ) );
			}
		} );
	}

	// The task is over: a failure is printed by the page itself (the reason, in words); a success is said once after the reload.
	function finish( last ) {
		var message = '';

		watching = false;

		if ( last && ! last.ok ) {
			window.location.reload();
			return;
		}

		message = ( current && current.message ) || ( current && current.label ? format( text.taskDone, [ current.label ] ) : '' );

		if ( current && NO_RELOAD.indexOf( current.action ) >= 0 ) {
			unlock();
			end( message );
			current = null;
			cacheFacts();
			return;
		}

		remember( current ? current.action : '', current ? current.slug : '', message );
		window.location.reload();
	}

	// Follows the account's task until it is gone (the hosting overview has no task: no marker). The task, not one action's result, is
	// what is waited for: a follow-up change (the country block sent again after a restore, the clean-up of a website that was not
	// made) replaces the last result with its own, so a result of the asked action may never come. When the task is gone the page is
	// asked what happened: a failure is printed by the page (it knows the clean-up too), a success is said once after the reload.
	// A restore may take hours: the look is slower after the first ten minutes, and a task still there after seven hours is not
	// called done, the customer is told it goes on in the background.
	function watch( task ) {
		if ( watching || ! root || ! task ) {
			return;
		}

		watching = true;

		// The progress line at the top follows the task and its steps ("Installing WordPress (step 2 of 5)"); the page locks its own buttons.
		if ( S && task.label && ! ( taskLine && ! taskLine.released ) ) {
			taskLine = S.busy( task.label, 'page', null, { lock: false } );
		}

		window.beltoftApi.follow( task, {
			holder: account,
			until: 'idle',
			path: root.getAttribute( 'data-hosting-resource' ) + '/hosting',
			onTick: function ( data ) {
				var progress = data.progress;

				if ( data.task && progress && progress.active && progress.text ) {
					task.step_label = progress.text;
					lock( task );
				}

				if ( taskLine && data.task && progress && progress.active ) {
					S.progress( taskLine, progress, progress.text || task.label );
				}
			}
		} ).then( function ( outcome ) {
			if ( ! outcome ) {
				watching = false;
				end( text.taskLong || '' );
				return;
			}

			finish( outcome.data && outcome.data.last ? { ok: false } : { ok: true } );
		} );
	}

	// A change was queued: the buttons wait for it. A change whose new state is drawn by the page reloads at once, and the page
	// then follows the task; the other changes stay on the page.
	function begin( task, reloadNow, button ) {
		current = {
			action: button ? button.getAttribute( 'data-hosting-action' ) || '' : '',
			slug: button ? button.getAttribute( 'data-slug' ) || '' : '',
			message: button ? button.getAttribute( 'data-done' ) || '' : '',
			label: task.label || ''
		};
		lock( task );

		if ( reloadNow ) {
			window.location.reload();
			return;
		}

		watch( task );
	}

	if ( root && root.getAttribute( 'data-hosting-task' ) ) {
		try {
			var running = JSON.parse( root.getAttribute( 'data-hosting-task' ) );

			current = { action: '', slug: running.slug || '', message: '', label: running.label || '' };
			lock( running );
			watch( running );
		} catch ( e ) {
			// A task that cannot be read is not shown; the buttons stay as they are.
		}
	}

	// A link or a hash that points into a closed section (for example "Connect a domain" from Get started) opens it first.
	function openTo( hash ) {
		var target = hash && hash.length > 1 ? document.getElementById( decodeURIComponent( hash.slice( 1 ) ) ) : null;
		var node = target;

		while ( node ) {
			if ( 'DETAILS' === node.tagName ) {
				node.open = true;
			}

			node = node.parentElement;
		}

		return target;
	}

	window.addEventListener( 'hashchange', function () {
		var target = openTo( window.location.hash );

		if ( target ) {
			target.scrollIntoView( { block: 'start' } );
		}
	} );
	openTo( window.location.hash );
}() );
