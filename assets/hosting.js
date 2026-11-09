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

	if ( ! account || ! window.fetch ) {
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
		return window.fetch( account.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': account.nonce,
				'Content-Type': 'application/json'
			},
			body: undefined === body ? undefined : JSON.stringify( body )
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response.json().then( function ( data ) {
				return { ok: response.ok, status: response.status, data: data };
			}, function () {
				return { ok: response.ok, status: response.status, data: {} };
			} );
		} );
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
				return window.confirm( button.getAttribute( 'data-confirm' ) || '' ) ? [ 'POST', base + '/addons', { kind: 'mail_pack', target: button.getAttribute( 'data-mailbox' ) || '', confirm: true } ] : null;
			case 'remove_pack':
				return window.confirm( text.confirmPack || '' ) ? [ 'DELETE', base + '/addons?target=' + encodeURIComponent( button.getAttribute( 'data-mailbox' ) || '' ), undefined ] : null;
			case 'enable_mail':
				return [ 'POST', base + '/mail', { domain: field( panel, 'mail_domain' ) } ];
			case 'backup_now':
				return [ 'POST', base + '/backups', {} ];
			case 'restore':
				var scope = button.getAttribute( 'data-scope' ) || '';

				if ( ! window.confirm( text.confirmRestore || '' ) ) {
					return null;
				}

				return [ 'POST', base + '/backups/restore', { scope: scope, volid: button.getAttribute( 'data-volid' ) || '', slug: field( panel, 'restore_slug' ) } ];
			case 'undo':
				return window.confirm( text.confirmUndo || '' ) ? [ 'POST', base + '/backups/undo', {} ] : null;
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
				if ( window.confirm( result.data.message || '' ) ) {
					call( 'POST', id + '/hosting/addons', { kind: 'domain', target: result.data.target, confirm: true } ).then( function ( bought ) {
						button.disabled = false;
						pay( panel, bought, function () {
							button.click();
						} );
					} );
				} else {
					button.disabled = false;
					say( panel, '' );
				}

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
	} );
}() );
