/**
 * My Account, SSL certificate page: downloads the public parts, takes the private key once, asks for a reissue, a new
 * validation method or a cancellation, and opens the certificate authority's page. Needs the panel script
 * (window.beltoftServicesAccount). No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var ssl = window.beltoftServicesSsl;
	var S = window.beltoftStatus;

	if ( ! account || ! ssl || ! window.fetch || ! S ) {
		return;
	}

	var strings = ssl.strings;

	function resourceOf( box ) {
		return box.getAttribute( 'data-resource' ) || '';
	}

	function call( box, method, path, body ) {
		return window.fetch( account.root + resourceOf( box ) + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response.json().then( function ( data ) {
				return { ok: response.ok, status: response.status, data: data || {} };
			}, function () {
				return { ok: response.ok, status: response.status, data: {} };
			} );
		} );
	}

	function problem( result ) {
		if ( 429 === result.status ) {
			return strings.limit;
		}

		return result.data && result.data.message ? result.data.message : strings.failed;
	}

	// One request per click: the bar says what is going on and the service's other buttons wait.
	function guarded( box, button, text, work ) {
		var scope = resourceOf( box );
		var h;

		if ( S.locked( scope ) ) {
			return Promise.resolve();
		}

		h = S.busy( text, scope, button );

		return work( h ).catch( function () {
			S.fail( h, strings.failed );
		} );
	}

	function save( text, name ) {
		var blob = new window.Blob( [ text ], { type: 'application/x-pem-file' } );
		var link = document.createElement( 'a' );

		link.href = window.URL.createObjectURL( blob );
		link.download = name;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		window.setTimeout( function () {
			window.URL.revokeObjectURL( link.href );
		}, 1000 );
	}

	function savePkcs12( base64, name ) {
		var bytes = window.atob( base64 );
		var array = new window.Uint8Array( bytes.length );
		var i;
		var blob;
		var link = document.createElement( 'a' );

		for ( i = 0; i < bytes.length; i++ ) {
			array[ i ] = bytes.charCodeAt( i );
		}

		blob = new window.Blob( [ array ], { type: 'application/x-pkcs12' } );
		link.href = window.URL.createObjectURL( blob );
		link.download = name;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		window.setTimeout( function () {
			window.URL.revokeObjectURL( link.href );
		}, 1000 );
	}

	function fileName( box, suffix ) {
		var name = ( box.getAttribute( 'data-name' ) || 'certificate' ).replace( /[^A-Za-z0-9.-]/g, '_' );

		return name + suffix;
	}

	// An action goes through the generic route; the job runs in the background, so the page reloads a few seconds later.
	function act( box, action, args, h ) {
		return call( box, 'POST', '/actions/' + encodeURIComponent( action ), { args: args || {} } ).then( function ( result ) {
			if ( 202 === result.status ) {
				S.done( h, strings.queued );
				window.setTimeout( function () {
					window.location.reload();
				}, 4000 );

				return;
			}

			S.fail( h, problem( result ) );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );
		var box = target ? target.closest( '.beltoft-services-ssl' ) : null;

		if ( ! target || ! box ) {
			return;
		}

		if ( target.hasAttribute( 'data-ssl-download' ) ) {
			var part = target.getAttribute( 'data-ssl-download' );

			guarded( box, target, strings.working, function ( h ) {
				return call( box, 'GET', '/ssl-certificate?part=' + encodeURIComponent( part ) ).then( function ( result ) {
					if ( ! result.ok || ! result.data.pem ) {
						S.fail( h, problem( result ) );

						return;
					}

					save( result.data.pem, fileName( box, '-' + part + '.pem' ) );
					S.done( h, '' );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-ssl-key' ) ) {
			guarded( box, target, strings.working, function ( h ) {
				return call( box, 'POST', '/ssl-key' ).then( function ( result ) {
					if ( ! result.ok || ! result.data.key ) {
						S.fail( h, 409 === result.status ? strings.keyGone : problem( result ) );
						target.disabled = 409 === result.status;

						return;
					}

					save( result.data.key, fileName( box, '.key' ) );
					S.done( h, strings.keyOnce );
					target.hidden = true;
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-ssl-p12' ) ) {
			var field = box.querySelector( '[data-ssl-p12-password]' );
			var typedPassword = field ? field.value : '';

			guarded( box, target, strings.working, function ( h ) {
				return call( box, 'POST', '/ssl-pkcs12', { password: typedPassword } ).then( function ( result ) {
					if ( ! result.ok || ! result.data.pkcs12 ) {
						S.fail( h, 409 === result.status ? strings.keyGone : problem( result ) );
						target.disabled = 409 === result.status;

						return;
					}

					savePkcs12( result.data.pkcs12, fileName( box, '.p12' ) );
					S.done( h, result.data.password ? strings.p12Once + ' ' + result.data.password : strings.p12Saved );
					target.hidden = true;

					if ( field ) {
						field.value = '';
						field.hidden = true;
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-ssl-panel' ) ) {
			var tab = window.open( '', '_blank' );

			if ( tab ) {
				tab.opener = null;
			}

			guarded( box, target, strings.working, function ( h ) {
				return call( box, 'POST', '/ssl-panel' ).then( function ( result ) {
					if ( ! result.ok || ! result.data.url ) {
						if ( tab ) {
							tab.close();
						}

						S.fail( h, problem( result ) );

						return;
					}

					if ( tab ) {
						tab.location.href = result.data.url;
					} else {
						window.location.href = result.data.url;
					}

					S.done( h, '' );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-ssl-cancel' ) ) {
			var typed = window.prompt( strings.confirm );

			if ( null === typed ) {
				return;
			}

			guarded( box, target, strings.working, function ( h ) {
				return act( box, 'ssl_cancel', { confirm: typed.trim() }, h );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-ssl-action' ) && 'FORM' !== target.tagName ) {
			guarded( box, target, strings.working, function ( h ) {
				return act( box, target.getAttribute( 'data-ssl-action' ), {}, h );
			} );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( 'form[data-ssl-action]' );
		var box = form ? form.closest( '.beltoft-services-ssl' ) : null;
		var args = {};

		if ( ! form || ! box ) {
			return;
		}

		event.preventDefault();

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( field.name && 'submit' !== field.type ) {
				args[ field.name ] = field.value;
			}
		} );

		guarded( box, form.querySelector( '[type=submit]' ), strings.working, function ( h ) {
			return act( box, form.getAttribute( 'data-ssl-action' ), args, h );
		} );
	} );
}() );
