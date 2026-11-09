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

	function say( panel, message ) {
		var line = panel.querySelector( '.beltoft-hosting-message' );

		if ( line ) {
			line.textContent = message;
		}
	}

	function field( panel, name ) {
		var el = panel.querySelector( '[data-hosting-field="' + name + '"]' );

		return el ? String( el.value || '' ).trim() : '';
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

		switch ( button.getAttribute( 'data-hosting-action' ) ) {
			case 'login':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/login', {} ];
			case 'php_rollback':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/php/rollback', {} ];
			case 'cache':
				return [ 'POST', base + '/sites/' + encodeURIComponent( slug ) + '/cache', {} ];
			case 'remove_site':
				var typed = window.prompt( text.confirm || '' );

				return null === typed ? null : [ 'DELETE', base + '/sites/' + encodeURIComponent( slug ) + '?confirm=' + encodeURIComponent( typed ), undefined ];
			case 'add_site':
				return '' === field( panel, 'slug' ) ? null : [ 'POST', base + '/sites', { slug: field( panel, 'slug' ), php: field( panel, 'php' ) } ];
			case 'add_domain':
				return '' === field( panel, 'host' ) ? null : [ 'POST', base + '/sites/' + encodeURIComponent( field( panel, 'domain_slug' ) ) + '/domains', { host: field( panel, 'host' ) } ];
			case 'add_pack':
				return ask( button.getAttribute( 'data-confirm' ) || '', button, [ 'POST', base + '/addons', { kind: 'mail_pack', target: button.getAttribute( 'data-mailbox' ) || '', confirm: parseInt( button.getAttribute( 'data-cents' ) || '0', 10 ) } ], false );
			case 'remove_pack':
				return ask( text.confirmPack || '', button, [ 'DELETE', base + '/addons?target=' + encodeURIComponent( button.getAttribute( 'data-mailbox' ) || '' ), undefined ], true );
			case 'enable_mail':
				return [ 'POST', base + '/mail', { domain: field( panel, 'mail_domain' ) } ];
			case 'backup_now':
				return [ 'POST', base + '/backups', {} ];
			case 'restore':
				var scope = button.getAttribute( 'data-scope' ) || '';

				return ask( text.confirmRestore || '', button, [ 'POST', base + '/backups/restore', { scope: scope, volid: button.getAttribute( 'data-volid' ) || '', slug: field( panel, 'restore_slug' ) } ], false );
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
			say( panel, ( bought.data && bought.data.message ) || text.failed || '' );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-hosting-action]' ) : null;
		var panel = button ? button.closest( '.beltoft-hosting' ) : null;

		if ( ! button || ! panel ) {
			return;
		}

		var id = panel.getAttribute( 'data-hosting-resource' );
		var request = plan( panel, button, id );
		var action = button.getAttribute( 'data-hosting-action' );

		if ( ! request ) {
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
		say( panel, 'login' === action ? ( text.opening || '' ) : ( text.working || '' ) );

		// The sign-in link is opened from the click, so a pop-up blocker allows it.
		var tab = 'login' === action ? window.open( '', '_blank' ) : null;

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

				say( panel, ( result.data && result.data.message ) || text.failed || '' );
				return;
			}

			if ( 'login' === action && result.data && result.data.url ) {
				if ( tab ) {
					tab.location = result.data.url;
				}

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

			say( panel, text.failed || '' );
		} );
	}
}() );
