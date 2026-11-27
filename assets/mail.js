/**
 * My Account, email domain page: makes and deletes mailboxes and aliases, shows a password once, and asks for a new DNS
 * check. Needs the panel script (window.beltoftServicesAccount). No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var mail = window.beltoftServicesMail;

	if ( ! account || ! mail || ! window.fetch || ! window.beltoftApi || ! window.beltoftStatus ) {
		return;
	}

	var strings = mail.strings;
	var S = window.beltoftStatus;

	function resourceOf( box ) {
		var panel = box.closest( '[data-resource]' );

		return panel ? panel.getAttribute( 'data-resource' ) : '';
	}

	function call( box, method, path, body ) {
		return window.beltoftApi.call( account.root + resourceOf( box ) + path, { method: method, body: body || undefined, holder: account } );
	}

	function problem( result ) {
		// The Check now limit says when the button works again; any other limit has its own general sentence.
		if ( 429 === result.status && result.data && 'beltoft_services_mail_rate_limited' === result.data.code && result.data.message ) {
			return result.data.message;
		}

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

		// The password is shown inside the row of its mailbox (a new mailbox: where the box is); it goes back when closed.
		var row = null;

		Array.prototype.forEach.call( box.querySelectorAll( '[data-mail-box]' ), function ( item ) {
			if ( ! row && line && -1 !== String( line ).indexOf( item.getAttribute( 'data-mail-box' ) ) ) {
				row = item;
			}
		} );

		if ( ! holder.beltoftHome ) {
			holder.beltoftHome = { parent: holder.parentNode, next: holder.nextSibling };
		}

		if ( row ) {
			row.appendChild( holder );
		}

		holder.querySelector( '[data-mail-secret-text]' ).textContent = line + ' ' + strings.once;
		holder.querySelector( '[data-mail-secret-value]' ).textContent = password;
		holder.hidden = false;
		holder.querySelector( '[data-mail-secret-value]' ).scrollIntoView( { block: 'nearest' } );
	}

	// h: the handle of the request that asked for the password; it ends here. The password is shown only with the code we email
	// to the account address: code is the one the caller already has (a new password is shown with the code that asked for it),
	// else the customer is asked now.
	function reveal( box, email, line, button, h, code ) {
		var asked = code ? Promise.resolve( code ) : window.beltoftApi.stepUp( 'mail_password', { holder: account } );

		return asked.then( function ( typed ) {
			if ( ! typed ) {
				S.done( h, strings.noCode );

				return null;
			}

			return call( box, 'POST', '/mail-reveal', { email: email, step_code: typed } ).then( function ( result ) {
				if ( ! result.ok || ! result.data.password ) {
					S.fail( h, 409 === result.status ? strings.gone : problem( result ) );

					if ( button ) {
						button.disabled = 409 === result.status;
					}

					return;
				}

				secret( box, line || email, result.data.password );
				S.done( h, line || email );

				if ( button ) {
					button.hidden = true;
				}
			} );
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

	// A change that runs in the background: follows it to its outcome ({ok, text}); null when it is still going after a while.
	function followed( result, h ) {
		return window.beltoftApi.follow( result.data, { holder: account, handle: h } );
	}

	// The end of a followed change that did not succeed: the server's own sentence, or the slow notice.
	function stopped( h, outcome ) {
		S.fail( h, outcome ? ( outcome.text || strings.failed ) : strings.slow );
	}

	function fresh( box ) {
		window.location.reload();
	}

	// Draws the card again from the server (counts, mailbox list and limits) without leaving the page, so a password panel
	// can be shown over fresh numbers. Any failure keeps the page as it is.
	function redraw( box ) {
		return window.fetch( window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } } ).then( function ( response ) {
			return response.ok ? response.text() : '';
		} ).then( function ( html ) {
			var next = html ? new window.DOMParser().parseFromString( html, 'text/html' ).querySelector( '[data-mail-card]' ) : null;

			if ( next ) {
				box.innerHTML = next.innerHTML;
			}

			arm();
		} ).catch( function () {} );
	}

	// A Check now button that waits for its time counts the seconds down and works again when they are over.
	var timer = 0;

	function tick() {
		var waiting = false;

		Array.prototype.forEach.call( document.querySelectorAll( '[data-mail-dns-check][data-next]' ), function ( button ) {
			var left = Math.ceil( ( parseInt( button.getAttribute( 'data-next' ), 10 ) || 0 ) - Date.now() / 1000 );

			if ( left <= 0 ) {
				button.disabled = false;
				button.textContent = button.getAttribute( 'data-label' ) || button.textContent;
				button.removeAttribute( 'data-next' );

				return;
			}

			waiting = true;
			button.disabled = true;

			if ( left <= 90 && strings.again ) {
				button.textContent = strings.again.replace( '%d', left );
			}
		} );

		if ( ! waiting ) {
			window.clearInterval( timer );
			timer = 0;
		}
	}

	function arm() {
		window.clearInterval( timer );
		timer = 0;

		if ( document.querySelector( '[data-mail-dns-check][data-next]' ) ) {
			tick();
			timer = window.setInterval( tick, 1000 );
		}
	}

	arm();

	// A light copy of the server's password rules (MailPassword::check is the authority): the sentence that says what is wrong, or ''.
	function passwordProblem( password, again, email ) {
		var min = mail.passwordMin || 10;
		var max = mail.passwordMax || 128;
		var lower = password.toLowerCase();
		var local = email.split( '@' )[0].toLowerCase();

		if ( Array.from( password ).length < min || Array.from( password ).length > max ) {
			return strings.pwLength.replace( '%1$d', min ).replace( '%2$d', max );
		}

		if ( /[\u0000-\u001f\u007f-\u009f]/.test( password ) ) {
			return strings.pwControl;
		}

		if ( /^\s|\s$/.test( password ) ) {
			return strings.pwSpace;
		}

		if ( ! /\p{L}/u.test( password ) || ! /\p{Nd}/u.test( password ) ) {
			return strings.pwMix;
		}

		if ( email && -1 !== lower.indexOf( email.toLowerCase() ) ) {
			return strings.pwAddress;
		}

		if ( local.length >= 4 && -1 !== lower.indexOf( local ) ) {
			return strings.pwLocal;
		}

		return password === again ? '' : strings.pwSame;
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

			if ( value ) {
				window.beltoftApi.copy( value.textContent ).then( function () {
					S.info( strings.copied );
				}, function () {} );
			}

			return;
		}

		if ( target.hasAttribute( 'data-mail-secret-done' ) ) {
			var shown = target.closest( '[data-mail-secret]' );

			if ( shown && shown.beltoftHome && shown.beltoftHome.parent ) {
				shown.beltoftHome.parent.insertBefore( shown, shown.beltoftHome.next );
			}

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
			var pwForm = target.closest( '[data-mail-newpw]' );
			var pwField = pwForm.querySelector( '[data-mail-password]' );
			var pwAgain = pwForm.querySelector( '[data-mail-password-again]' );
			var pwBad = passwordProblem( pwField.value, pwAgain.value, who );

			if ( pwBad ) {
				S.fail( null, pwBad, pwField );

				return;
			}

			guarded( box, target, strings.resetting, function ( h ) {
				return window.beltoftApi.stepUp( 'mail_password', { holder: account } ).then( function ( code ) {
					if ( ! code ) {
						S.done( h, strings.noCode );

						return null;
					}

					return call( box, 'POST', '/mail/mailboxes/' + encodeURIComponent( who ) + '/password', { step_code: code, password: pwField.value, password_again: pwAgain.value } ).then( function ( result ) {
						if ( ! result.ok ) {
							S.fail( h, problem( result ), pwField );

							return null;
						}

						return followed( result, h ).then( function ( outcome ) {
							pwField.value = '';
							pwAgain.value = '';

							if ( ! outcome || ! outcome.ok ) {
								stopped( h, outcome );

								return null;
							}

							// The customer chose the password: nothing is shown.
							S.done( h, strings.chosenSet.replace( '%s', who ) );

							return null;
						} );
					} );
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
			var pw = form.querySelector( '[data-mail-password]' );
			var pwAgain2 = form.querySelector( '[data-mail-password-again]' );
			var body = { local: local, name: name, password: pw.value, password_again: pwAgain2.value };
			var addressBad = local ? passwordProblem( pw.value, pwAgain2.value, local + '@' + ( form.getAttribute( 'data-mail-domain' ) || '' ) ) : '';

			if ( addressBad ) {
				S.fail( null, addressBad, pw );

				return;
			}

			if ( size && size.value.trim() ) {
				body.quota_mb = Math.round( parseFloat( size.value ) * 1024 );
			}

			guarded( box, target, strings.creating, function ( h ) {
				return call( box, 'POST', '/mail/mailboxes', body ).then( function ( result ) {
					if ( ! result.ok ) {
						// The plan is full: the page shows the way on (delete a mailbox, change plan), so the message is said once, on the bar.
						if ( result.data && 'beltoft_services_mail_limit' === result.data.code ) {
							S.fail( h, problem( result ) );

							return redraw( box );
						}

						S.fail( h, problem( result ), localField );

						return null;
					}

					return followed( result, h ).then( function ( outcome ) {
						if ( ! outcome || ! outcome.ok ) {
							stopped( h, outcome );

							return redraw( box );
						}

						return redraw( box ).then( function () {
							// The customer chose the password: nothing is shown. (A reply that still offers a reveal is an old flow.)
							if ( result.data.reveal ) {
								return reveal( box, result.data.email, strings.created.replace( '%s', result.data.email ), null, h );
							}

							S.done( h, strings.chosenNew.replace( '%s', result.data.email ) );

							return null;
						} );
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-delete' ) ) {
			var gone = target.getAttribute( 'data-mail-delete' );
			var input = target.closest( 'details' ).querySelector( '[data-mail-delete-confirm]' );

			guarded( box, target, strings.deleting, function ( h ) {
				return call( box, 'DELETE', '/mail/mailboxes/' + encodeURIComponent( gone ) + '?confirm=' + encodeURIComponent( S.phrase( input ).trim() ) ).then( function ( result ) {
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
					// The chosen mailboxes, then any other addresses typed (one per line).
					goto: Array.prototype.map.call( alias.querySelectorAll( '[data-mail-alias-box]:checked' ), function ( box ) {
						return box.value;
					} ).concat( alias.querySelector( '[data-mail-alias-goto]' ) ? lines( alias.querySelector( '[data-mail-alias-goto]' ).value ) : [] )
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

		if ( target.hasAttribute( 'data-mail-proof-check' ) ) {
			guarded( box, target, strings.checking, function ( h ) {
				return call( box, 'POST', '/mail/proof/check' ).then( function ( result ) {
					if ( result.ok && result.data && result.data.ready ) {
						fresh( box );
					} else if ( result.ok ) {
						// Not proven yet: say so on the page, then draw the records again.
						S.done( h, strings.proofNot );
						redraw( box );
					} else {
						S.fail( h, problem( result ) );
					}
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-mail-dns-check' ) ) {
			guarded( box, target, strings.checking, function ( h ) {
				return call( box, 'POST', '/mail/dns/check' ).then( function ( result ) {
					if ( ! result.ok ) {
						S.fail( h, problem( result ) );

						// Limited: the page draws the button with its time.
						if ( 429 === result.status ) {
							redraw( box );
						}

						return null;
					}

					return followed( result, h ).then( function ( outcome ) {
						if ( outcome && outcome.ok ) {
							// The outcome in words (all found, or how many are still missing), then the records drawn again.
							S.done( h, outcome.text || strings.checked );
							redraw( box );
						} else {
							stopped( h, outcome );
						}
					} );
				} );
			} );
		}
	} );
}() );
