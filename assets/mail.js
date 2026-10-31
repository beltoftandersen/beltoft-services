/**
 * My Account, email domain page: makes and deletes mailboxes and aliases, shows a password once, and asks for a new DNS
 * check. Needs the panel script (window.beltoftServicesAccount). No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var mail = window.beltoftServicesMail;

	if ( ! account || ! mail || ! window.fetch ) {
		return;
	}

	var strings = mail.strings;

	function say( box, text ) {
		var out = box.querySelector( '[data-mail-notice]' );

		if ( out ) {
			out.textContent = text;
		}
	}

	function resourceOf( box ) {
		var panel = box.closest( '[data-resource]' );

		return panel ? panel.getAttribute( 'data-resource' ) : '';
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

	function secret( box, line, password ) {
		var holder = box.querySelector( '[data-mail-secret]' );

		if ( ! holder ) {
			return;
		}

		holder.querySelector( '[data-mail-secret-text]' ).textContent = line + ' ' + strings.once;
		holder.querySelector( '[data-mail-secret-value]' ).textContent = password;
		holder.hidden = false;
		holder.querySelector( '[data-mail-secret-value]' ).scrollIntoView( { block: 'nearest' } );
	}

	function reveal( box, email, line, button ) {
		return call( box, 'POST', '/mail-reveal', { email: email } ).then( function ( result ) {
			if ( ! result.ok || ! result.data.password ) {
				say( box, 409 === result.status ? strings.gone : problem( result ) );

				if ( button ) {
					button.disabled = 409 === result.status;
				}

				return;
			}

			say( box, '' );
			secret( box, line || email, result.data.password );

			if ( button ) {
				button.hidden = true;
			}
		} );
	}

	function guarded( box, button, work ) {
		button.disabled = true;
		say( box, '' );

		return work().catch( function () {
			say( box, strings.failed );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	function fresh( box ) {
		window.location.reload();
	}

	function lines( text ) {
		return ( text || '' ).split( /[\n,;]+/ ).map( function ( part ) {
			return part.trim();
		} ).filter( Boolean );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button, a' );
		var box = target ? target.closest( '[data-mail-card]' ) : null;

		if ( ! target || ! box ) {
			return;
		}

		if ( target.hasAttribute( 'data-mail-copy' ) ) {
			var value = box.querySelector( '[data-mail-secret-value]' );

			if ( value && window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( value.textContent ).then( function () {
					say( box, strings.copied );
				} );
			}

			return;
		}

		if ( target.hasAttribute( 'data-mail-secret-done' ) ) {
			fresh( box );

			return;
		}

		if ( target.hasAttribute( 'data-mail-reveal' ) ) {
			var email = target.getAttribute( 'data-mail-reveal' );

			guarded( box, target, function () {
				return reveal( box, email, email, target );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-reset' ) ) {
			var who = target.getAttribute( 'data-mail-reset' );

			guarded( box, target, function () {
				return call( box, 'POST', '/mail/mailboxes/' + encodeURIComponent( who ) + '/password' ).then( function ( result ) {
					if ( ! result.ok ) {
						say( box, problem( result ) );

						return null;
					}

					return reveal( box, who, strings.reset.replace( '%s', who ), null );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-create-submit' ) ) {
			var form = target.closest( '[data-mail-create]' );
			var local = form.querySelector( '[data-mail-local]' ).value.trim();
			var name = form.querySelector( '[data-mail-name]' ).value.trim();
			var size = form.querySelector( '[data-mail-size]' );
			var body = { local: local, name: name };

			if ( size && size.value.trim() ) {
				body.quota_mb = Math.round( parseFloat( size.value ) * 1024 );
			}

			guarded( box, target, function () {
				return call( box, 'POST', '/mail/mailboxes', body ).then( function ( result ) {
					if ( ! result.ok ) {
						say( box, problem( result ) );

						return null;
					}

					return reveal( box, result.data.email, strings.created.replace( '%s', result.data.email ), null );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-delete' ) ) {
			var gone = target.getAttribute( 'data-mail-delete' );
			var input = target.closest( 'details' ).querySelector( '[data-mail-delete-confirm]' );

			guarded( box, target, function () {
				return call( box, 'DELETE', '/mail/mailboxes/' + encodeURIComponent( gone ) + '?confirm=' + encodeURIComponent( input.value.trim() ) ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						say( box, problem( result ) );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-alias-submit' ) ) {
			var alias = target.closest( '[data-mail-alias-create]' );

			guarded( box, target, function () {
				return call( box, 'POST', '/mail/aliases', {
					address: alias.querySelector( '[data-mail-alias-local]' ).value.trim(),
					goto: lines( alias.querySelector( '[data-mail-alias-goto]' ).value )
				} ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						say( box, problem( result ) );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-alias-delete' ) ) {
			var address = target.getAttribute( 'data-mail-alias-delete' );
			var holder = target.closest( '[data-mail-aliases]' );

			if ( holder && ! window.confirm( holder.getAttribute( 'data-confirm' ) || strings.confirm ) ) {
				return;
			}

			guarded( box, target, function () {
				return call( box, 'DELETE', '/mail/aliases/' + encodeURIComponent( address ) ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						say( box, problem( result ) );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-dns-check' ) ) {
			say( box, strings.checking );
			guarded( box, target, function () {
				return call( box, 'POST', '/mail/dns/check' ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						say( box, problem( result ) );
					}
				} );
			} );
		}
	} );

	// A copy button shows only when the browser can copy.
	if ( ! window.navigator.clipboard ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-mail-copy]' ), function ( button ) {
			button.hidden = true;
		} );
	}
}() );
