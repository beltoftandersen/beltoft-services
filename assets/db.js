/**
 * My Account, managed database card: shows the password once (it replaces PASSWORD in the connection strings
 * while the customer looks at the page), asks for a new password and restores a backup. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var db = window.beltoftServicesDb;

	if ( ! account || ! db || ! window.fetch || ! window.beltoftApi || ! window.beltoftStatus ) {
		return;
	}

	var S = window.beltoftStatus;

	function scopeOf( panel ) {
		return panel.getAttribute( 'data-resource' );
	}

	// The server's own refusal text is written for the customer; anything else gets a fixed line.
	function refusal( reply ) {
		return 429 === reply.status ? db.strings.limit : ( reply.data.message ? reply.data.message : db.strings.failed );
	}

	function post( panel, path, body ) {
		return window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ) + path, { method: 'POST', body: body, holder: account } );
	}

	function reveal( box, panel, button ) {
		var h;

		if ( S.locked( scopeOf( panel ) ) ) {
			return;
		}

		h = S.busy( db.strings.revealing, scopeOf( panel ), button );
		post( panel, '/db-reveal', {} ).then( function ( reply ) {
			if ( ! reply.ok ) {
				S.fail( h, 409 === reply.status ? db.strings.gone : db.strings.failed );
				button.disabled = 409 === reply.status;

				return null;
			}

			if ( ! reply.parsed ) {
				throw new Error( 'json' );
			}

			if ( reply.data.password ) {
				var own = box.querySelector( '[data-db-password]' );

				Array.prototype.forEach.call( box.querySelectorAll( '[data-db-conn]' ), function ( code ) {
					code.textContent = code.textContent.replace( 'PASSWORD', reply.data.password );
				} );

				// The password on its own line too: inside a long connection line it is easy to miss.
				if ( own ) {
					own.querySelector( '[data-db-password-value]' ).textContent = reply.data.password;
					own.hidden = false;
				}
				S.done( h, db.strings.once );
				button.hidden = true;
			} else {
				S.fail( h, db.strings.failed );
			}
		} ).catch( function () {
			S.fail( h, db.strings.failed );
		} );
	}

	// After the action: the page to open (the Overview shows the new password once), or this page again.
	function done( after ) {
		if ( after ) {
			window.location.href = after;
		} else {
			window.location.reload();
		}
	}

	// h: the handle of the request that is waited for; it stays busy (and the service locked) until the page is read again.
	function waitForAction( panel, action, since, tries, after, h ) {
		window.setTimeout( function () {
			window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ), { holder: account } ).then( function ( reply ) {
				if ( reply.ok && ! reply.parsed ) {
					throw new Error( 'json' );
				}

				return reply.ok ? reply.data : null;
			} ).then( function ( data ) {
				var last = data && data.last_result;

				if ( last && action === last.action && last.at >= since && data.power_pending && tries < 60 ) {
					// The job is over but the VM has not changed power yet: keep waiting, as the server page does.
					waitForAction( panel, action, since, tries + 1, after, h );
				} else if ( last && action === last.action && last.at >= since && data.power_failed ) {
					S.fail( h, 'shutdown' === action ? db.strings.stillrunning : db.strings.notstarted );
					window.setTimeout( function () {
						done( after );
					}, 4000 );
				} else if ( last && action === last.action && last.at >= since ) {
					done( after );
				} else if ( tries < 60 ) {
					waitForAction( panel, action, since, tries + 1, after, h );
				} else {
					done( after );
				}
			} ).catch( function () {
				if ( tries < 60 ) {
					waitForAction( panel, action, since, tries + 1, after, h );
				}
			} );
		}, 5000 );
	}

	// One request of an action: busy at once, then wait for the job (202) or show the refusal under the field.
	function run( panel, button, text, path, body, action, after, field ) {
		var since = Math.floor( Date.now() / 1000 ) - 5;
		var h;

		if ( S.locked( scopeOf( panel ) ) ) {
			return;
		}

		h = S.busy( text, scopeOf( panel ), button );
		post( panel, path, body ).then( function ( reply ) {
			if ( 202 === reply.status ) {
				waitForAction( panel, action, since, 0, after, h );

				return null;
			}

			S.fail( h, refusal( reply ), field );
		} ).catch( function () {
			S.fail( h, db.strings.failed );
		} );
	}

	function reset( box, panel, button ) {
		var input = box.querySelector( '[data-db-confirm]' );

		run( panel, button, db.strings.working, '/actions/db_reset_password', JSON.stringify( { args: { confirm: input ? input.value : '' } } ), 'db_reset_password', button.getAttribute( 'data-db-after' ), input );
	}

	// Shut down and Start: the server's own actions. Shut down asks first. The page is read again when the job has run.
	function power( box, panel, button ) {
		var action = button.getAttribute( 'data-db-power' );

		function go() {
			run( panel, button, 'shutdown' === action ? db.strings.shuttingdown : db.strings.starting, '/actions/' + action, JSON.stringify( { args: {} } ), action, undefined );
		}

		if ( 'shutdown' === action ) {
			S.confirm( { text: db.strings.confirmshutdown, confirm: button.textContent } ).then( function ( yes ) {
				if ( yes ) {
					go();
				}
			} );

			return;
		}

		go();
	}

	function policy( box, panel, button ) {
		var choice = box.querySelector( '[data-db-policy]' );

		if ( ! choice || ! choice.value ) {
			return;
		}

		run( panel, button, db.strings.policyworking, '/actions/db_set_policy', JSON.stringify( { args: { policy: choice.value } } ), 'db_set_policy', undefined );
	}

	function restore( box, panel, button ) {
		var input = box.querySelector( '[data-db-restore-confirm]' );
		var choice = box.querySelector( '[data-db-backup]' );

		if ( ! choice || ! choice.value ) {
			S.fail( null, db.strings.nobackup, choice );

			return;
		}

		run( panel, button, db.strings.restoring, '/actions/restore', JSON.stringify( { args: { confirm: input ? input.value : '', volid: choice.value } } ), 'restore', undefined, input );
	}

	// Copy: the text of the code block next to the button, read when it is clicked (it holds the password once that is shown).
	function copy( box, button ) {
		var code = button.parentNode ? button.parentNode.querySelector( 'code' ) : null;
		var label = button.textContent;

		if ( ! code ) {
			return;
		}

		window.beltoftApi.copy( code.textContent ).then( function () {
			button.textContent = button.getAttribute( 'data-copied' ) || label;
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		}, function () {
			S.fail( null, db.strings.failed );
		} );
	}

	// Access tab: one change to the allow-list, then wait until it is applied.
	function waitForAccess( panel, tries, h ) {
		window.setTimeout( function () {
			window.beltoftApi.call( account.root + panel.getAttribute( 'data-resource' ) + '/db-access', { holder: account } ).then( function ( reply ) {
				if ( reply.ok && ! reply.parsed ) {
					throw new Error( 'json' );
				}

				return reply.ok ? reply.data : null;
			} ).then( function ( data ) {
				if ( data && 'applying' !== data.state ) {
					if ( 'failed' === data.state ) {
						S.fail( h, db.strings.notapplied );
					} else {
						S.done( h, db.strings.applied );
					}
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
				} else if ( tries < 100 ) {
					waitForAccess( panel, tries + 1, h );
				} else {
					window.location.reload();
				}
			} ).catch( function () {
				if ( tries < 100 ) {
					waitForAccess( panel, tries + 1, h );
				}
			} );
		}, 3000 );
	}

	function access( panel, button, body, field ) {
		var h;

		if ( S.locked( scopeOf( panel ) ) ) {
			return;
		}

		h = S.busy( db.strings.applying, scopeOf( panel ), button );
		post( panel, '/db-access', body ).then( function ( reply ) {
			if ( reply.status === 202 ) {
				waitForAccess( panel, 0, h );

				return null;
			}

			S.fail( h, refusal( reply ), field );
		} ).catch( function () {
			S.fail( h, db.strings.failed );
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
				S.fail( null, db.strings.noip );
			}
		} else if ( button.hasAttribute( 'data-db-access-add' ) ) {
			access( panel, button, { op: 'add', cidr: cidr ? cidr.value : '', label: label ? label.value : '' }, cidr );
		} else if ( button.hasAttribute( 'data-db-access-remove' ) ) {
			S.confirm( { text: db.strings.confirmremove, confirm: button.textContent, danger: true } ).then( function ( yes ) {
				if ( yes ) {
					access( panel, button, { op: 'remove', cidr: button.getAttribute( 'data-db-access-remove' ) } );
				}
			} );
		} else {
			access( panel, button, { op: button.getAttribute( 'data-db-access-world' ), confirm: confirm ? confirm.value : '' }, confirm );
		}
	}

	// The buttons are hidden until the script runs: without it there is nothing to copy with.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-db-copy]' ), function ( button ) {
		button.hidden = false;
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-db-reveal], [data-db-reset], [data-db-power], [data-db-restore], [data-db-policy-save], [data-db-copy], [data-db-access-mine], [data-db-access-add], [data-db-access-remove], [data-db-access-world]' );
		var panel = button ? button.closest( '[data-resource]' ) : null;
		// The power buttons are in the page's head, outside the card.
		var box = button ? ( button.closest( '[data-db-card]' ) || ( panel ? panel.querySelector( '[data-db-card]' ) : null ) ) : null;

		if ( ! button || ! box || ! panel ) {
			return;
		}

		if ( button.hasAttribute( 'data-db-copy' ) ) {
			copy( box, button );
		} else if ( button.hasAttribute( 'data-db-reveal' ) ) {
			reveal( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-power' ) ) {
			power( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-policy-save' ) ) {
			policy( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-restore' ) ) {
			restore( box, panel, button );
		} else if ( button.hasAttribute( 'data-db-access-mine' ) || button.hasAttribute( 'data-db-access-add' ) || button.hasAttribute( 'data-db-access-remove' ) || button.hasAttribute( 'data-db-access-world' ) ) {
			accessClick( box, panel, button );
		} else {
			reset( box, panel, button );
		}
	} );
}() );
