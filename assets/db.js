/**
 * My Account, managed database card: shows the password once (it replaces PASSWORD in the connection strings
 * while the customer looks at the page), asks for a new password and restores a backup. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var db = window.beltoftServicesDb;

	if ( ! account || ! db || ! window.fetch ) {
		return;
	}

	function say( box, text ) {
		var out = box.querySelector( '[data-db-notice]' );

		if ( out ) {
			out.textContent = text;
		}
	}

	function post( panel, path, body ) {
		return window.fetch( account.root + panel.getAttribute( 'data-resource' ) + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: body
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response;
		} );
	}

	function reveal( box, panel, button ) {
		button.disabled = true;
		say( box, '' );
		post( panel, '/db-reveal', '{}' ).then( function ( response ) {
			if ( ! response.ok ) {
				say( box, 409 === response.status ? db.strings.gone : db.strings.failed );
				button.disabled = 409 === response.status;

				return null;
			}

			return response.json().then( function ( data ) {
				if ( data && data.password ) {
					Array.prototype.forEach.call( box.querySelectorAll( '[data-db-conn]' ), function ( code ) {
						code.textContent = code.textContent.replace( 'PASSWORD', data.password );
					} );
					button.hidden = true;
					say( box, db.strings.once );
				}
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
		} );
	}

	function waitForAction( box, panel, action, since, tries ) {
		window.setTimeout( function () {
			window.fetch( account.root + panel.getAttribute( 'data-resource' ), { credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce } } ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( data ) {
				var last = data && data.last_result;

				if ( last && action === last.action && last.at >= since ) {
					window.location.reload();
				} else if ( tries < 60 ) {
					waitForAction( box, panel, action, since, tries + 1 );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				if ( tries < 60 ) {
					waitForAction( box, panel, action, since, tries + 1 );
				}
			} );
		}, 5000 );
	}

	function reset( box, panel, button ) {
		var input = box.querySelector( '[data-db-confirm]' );
		var since = Math.floor( Date.now() / 1000 ) - 5;

		button.disabled = true;
		say( box, '' );
		post( panel, '/actions/db_reset_password', JSON.stringify( { args: { confirm: input ? input.value : '' } } ) ).then( function ( response ) {
			if ( response.status === 202 ) {
				say( box, db.strings.working );
				waitForAction( box, panel, 'db_reset_password', since, 0 );

				return null;
			}

			button.disabled = false;

			return response.json().then( function ( data ) {
				say( box, 429 === response.status ? db.strings.limit : ( data && data.message ? data.message : db.strings.failed ) );
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
		} );
	}

	function restore( box, panel, button ) {
		var input = box.querySelector( '[data-db-restore-confirm]' );
		var choice = box.querySelector( '[data-db-backup]' );
		var since = Math.floor( Date.now() / 1000 ) - 5;

		if ( ! choice || ! choice.value ) {
			say( box, db.strings.nobackup );

			return;
		}

		button.disabled = true;
		say( box, '' );
		post( panel, '/actions/restore', JSON.stringify( { args: { confirm: input ? input.value : '', volid: choice.value } } ) ).then( function ( response ) {
			if ( response.status === 202 ) {
				say( box, db.strings.restoring );
				waitForAction( box, panel, 'restore', since, 0 );

				return null;
			}

			button.disabled = false;

			return response.json().then( function ( data ) {
				say( box, 429 === response.status ? db.strings.limit : ( data && data.message ? data.message : db.strings.failed ) );
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
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

	// Copy: the text of the code block next to the button, read when it is clicked (it holds the password once that is shown).
	function copy( box, button ) {
		var code = button.parentNode ? button.parentNode.querySelector( 'code' ) : null;
		var label = button.textContent;

		if ( ! code ) {
			return;
		}

		copyText( code.textContent ).then( function () {
			button.textContent = button.getAttribute( 'data-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}, function () {
			say( box, db.strings.failed );
		} );
	}

	// Access tab: one change to the allow-list, then wait until it is applied.
	function waitForAccess( box, panel, tries ) {
		window.setTimeout( function () {
			window.fetch( account.root + panel.getAttribute( 'data-resource' ) + '/db-access', { credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce } } ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( data ) {
				if ( data && 'applying' !== data.state ) {
					say( box, 'failed' === data.state ? db.strings.notapplied : db.strings.applied );
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else if ( tries < 100 ) {
					waitForAccess( box, panel, tries + 1 );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				if ( tries < 100 ) {
					waitForAccess( box, panel, tries + 1 );
				}
			} );
		}, 3000 );
	}

	function access( box, panel, button, body ) {
		button.disabled = true;
		say( box, '' );
		post( panel, '/db-access', JSON.stringify( body ) ).then( function ( response ) {
			if ( response.status === 202 ) {
				say( box, db.strings.applying );
				waitForAccess( box, panel, 0 );

				return null;
			}

			button.disabled = false;

			return response.json().then( function ( data ) {
				say( box, 429 === response.status ? db.strings.limit : ( data && data.message ? data.message : db.strings.failed ) );
			} );
		} ).catch( function () {
			button.disabled = false;
			say( box, db.strings.failed );
		} );
	}

	function accessClick( box, panel, button ) {
		var cidr = box.querySelector( '[data-db-access-cidr]' );
		var label = box.querySelector( '[data-db-access-label]' );
		var confirm = box.querySelector( '[data-db-access-confirm]' );

		if ( button.hasAttribute( 'data-db-access-mine' ) ) {
			if ( cidr ) {
				cidr.value = button.getAttribute( 'data-db-access-mine' ) || '';
			} else {
				say( box, db.strings.noip );
			}
		} else if ( button.hasAttribute( 'data-db-access-add' ) ) {
			access( box, panel, button, { op: 'add', cidr: cidr ? cidr.value : '', label: label ? label.value : '' } );
		} else if ( button.hasAttribute( 'data-db-access-remove' ) ) {
			if ( window.confirm( db.strings.confirmremove ) ) {
				access( box, panel, button, { op: 'remove', cidr: button.getAttribute( 'data-db-access-remove' ) } );
			}
		} else {
			access( box, panel, button, { op: button.getAttribute( 'data-db-access-world' ), confirm: confirm ? confirm.value : '' } );
		}
	}

	// The buttons are hidden until the script runs: without it there is nothing to copy with.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-db-copy]' ), function ( button ) {
		button.hidden = false;
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-db-reveal], [data-db-reset], [data-db-restore], [data-db-copy], [data-db-access-mine], [data-db-access-add], [data-db-access-remove], [data-db-access-world]' );
		var box = button ? button.closest( '[data-db-card]' ) : null;
		var panel = box ? box.closest( '[data-resource]' ) : null;

		if ( ! button || ! box || ! panel ) {
			return;
		}

		if ( button.hasAttribute( 'data-db-copy' ) ) {
			copy( box, button );
		} else if ( button.hasAttribute( 'data-db-reveal' ) ) {
			reveal( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-restore' ) ) {
			restore( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-access-mine' ) || button.hasAttribute( 'data-db-access-add' ) || button.hasAttribute( 'data-db-access-remove' ) || button.hasAttribute( 'data-db-access-world' ) ) {
			accessClick( box, panel, button );
		} else {
			reset( box, panel, button );
		}
	} );
}() );
