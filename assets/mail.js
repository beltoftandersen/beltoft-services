/**
 * My Account, email domain page: makes and deletes mailboxes and aliases, shows a password once, and asks for a new DNS
 * check. Needs the panel script (window.beltoftServicesAccount). No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var mail = window.beltoftServicesMail;

	if ( ! account || ! mail || ! window.fetch || ! window.beltoftStatus ) {
		return;
	}

	var strings = mail.strings;
	var S = window.beltoftStatus;

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

	// h: the handle of the request that asked for the password; it ends here.
	function reveal( box, email, line, button, h ) {
		return call( box, 'POST', '/mail-reveal', { email: email } ).then( function ( result ) {
			if ( ! result.ok || ! result.data.password ) {
				S.fail( h, 409 === result.status ? strings.gone : problem( result ) );

				if ( button ) {
					button.disabled = 409 === result.status;
				}

				return;
			}

			secret( box, line || email, result.data.password );
			S.done( h, strings.once );

			if ( button ) {
				button.hidden = true;
			}
		} );
	}

	// One request per click: the bar says what is going on, the button is busy and the domain's other buttons wait.
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
					S.info( strings.copied );
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

			guarded( box, target, strings.revealing, function ( h ) {
				return reveal( box, email, email, target, h );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-reset' ) ) {
			var who = target.getAttribute( 'data-mail-reset' );

			guarded( box, target, strings.resetting, function ( h ) {
				return call( box, 'POST', '/mail/mailboxes/' + encodeURIComponent( who ) + '/password' ).then( function ( result ) {
					if ( ! result.ok ) {
						S.fail( h, problem( result ) );

						return null;
					}

					return reveal( box, who, strings.reset.replace( '%s', who ), null, h );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-create-submit' ) ) {
			var form = target.closest( '[data-mail-create]' );
			var localField = form.querySelector( '[data-mail-local]' );
			var local = localField.value.trim();
			var name = form.querySelector( '[data-mail-name]' ).value.trim();
			var size = form.querySelector( '[data-mail-size]' );
			var body = { local: local, name: name };

			if ( size && size.value.trim() ) {
				body.quota_mb = Math.round( parseFloat( size.value ) * 1024 );
			}

			guarded( box, target, strings.creating, function ( h ) {
				return call( box, 'POST', '/mail/mailboxes', body ).then( function ( result ) {
					if ( ! result.ok ) {
						S.fail( h, problem( result ), localField );

						return null;
					}

					return reveal( box, result.data.email, strings.created.replace( '%s', result.data.email ), null, h );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-delete' ) ) {
			var gone = target.getAttribute( 'data-mail-delete' );
			var input = target.closest( 'details' ).querySelector( '[data-mail-delete-confirm]' );

			guarded( box, target, strings.deleting, function ( h ) {
				return call( box, 'DELETE', '/mail/mailboxes/' + encodeURIComponent( gone ) + '?confirm=' + encodeURIComponent( input.value.trim() ) ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						S.fail( h, problem( result ), input );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-alias-submit' ) ) {
			var alias = target.closest( '[data-mail-alias-create]' );
			var aliasField = alias.querySelector( '[data-mail-alias-local]' );

			guarded( box, target, strings.aliasing, function ( h ) {
				return call( box, 'POST', '/mail/aliases', {
					address: aliasField.value.trim(),
					goto: lines( alias.querySelector( '[data-mail-alias-goto]' ).value )
				} ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						S.fail( h, problem( result ), aliasField );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-alias-delete' ) ) {
			var address = target.getAttribute( 'data-mail-alias-delete' );
			var holder = target.closest( '[data-mail-aliases]' );

			// The same question as before, in the page's own dialog.
			S.confirm( { text: ( holder && holder.getAttribute( 'data-confirm' ) ) || strings.confirm, confirm: target.textContent, danger: true } ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}

				guarded( box, target, strings.deleting, function ( h ) {
					return call( box, 'DELETE', '/mail/aliases/' + encodeURIComponent( address ) ).then( function ( result ) {
						if ( result.ok ) {
							fresh( box );
						} else {
							S.fail( h, problem( result ) );
						}
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-domain-submit' ) ) {
			var addForm = target.closest( '[data-mail-domain-add]' );
			var chosen = addForm.querySelector( '[data-mail-domain-mode]:checked' );
			var ours = chosen && 'ours' === chosen.value;
			var domainField = addForm.querySelector( ours ? '[data-mail-domain-zone]' : '[data-mail-domain-own]' );

			guarded( box, target, strings.adding, function ( h ) {
				return call( box, 'POST', '/mail-domains', { mode: ours ? 'ours' : 'own', domain: domainField.value.trim() } ).then( function ( result ) {
					if ( result.ok && result.data.url ) {
						// The new domain's page: it shows the proof record to add for a domain at another provider.
						window.location.href = result.data.url;
					} else if ( result.ok ) {
						fresh( box );
					} else {
						S.fail( h, problem( result ), domainField );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-domain-remove' ) ) {
			var removed = target.getAttribute( 'data-mail-domain-remove' );
			var rest = target.closest( '[data-mail-domains]' );
			var back = rest ? rest.querySelector( 'a[href]' ) : null;

			if ( target.disabled ) {
				return;
			}

			S.confirm( { text: strings.removeAsk, confirm: target.textContent, danger: true } ).then( function ( yes ) {
				if ( ! yes ) {
					return;
				}

				guarded( box, target, strings.removing, function ( h ) {
					return call( box, 'DELETE', '/mail-domains/' + encodeURIComponent( removed ) ).then( function ( result ) {
						if ( result.ok ) {
							// The page of a removed domain is gone: back to the first domain of the plan.
							if ( back ) {
								window.location.href = back.getAttribute( 'href' );
							} else {
								fresh( box );
							}
						} else {
							S.fail( h, problem( result ) );
						}
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-dns-check' ) ) {
			guarded( box, target, strings.checking, function ( h ) {
				return call( box, 'POST', '/mail/dns/check' ).then( function ( result ) {
					if ( result.ok ) {
						fresh( box );
					} else {
						S.fail( h, problem( result ) );
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
