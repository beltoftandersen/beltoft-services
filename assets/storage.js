/**
 * My Account, backup storage page: shows the password after the emailed code, changes it, switches protocols, takes, deletes
 * and restores snapshots, sets the snapshot plan and copies commands. Needs the panel script (window.beltoftServicesAccount).
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesStorage;
	var S = window.beltoftStatus;

	if ( ! account || ! cfg || ! window.fetch || ! window.beltoftApi || ! S ) {
		return;
	}

	var strings = cfg.strings;

	function call( box, method, path, body ) {
		return window.beltoftApi.call( account.root + box.getAttribute( 'data-resource' ) + path, { method: method, body: body || undefined, holder: account } );
	}

	function problem( result ) {
		if ( 429 === result.status ) {
			return strings.limit;
		}

		return result.data && result.data.message ? result.data.message : strings.failed;
	}

	function guarded( box, button, work ) {
		var scope = box.getAttribute( 'data-resource' );
		var h;

		if ( S.locked( scope ) ) {
			return Promise.resolve();
		}

		h = S.busy( strings.working, scope, button );

		return work( h ).catch( function () {
			S.fail( h, strings.failed );
		} );
	}

	// A change is queued as a job; the page reloads a few seconds later.
	function queued( h, result ) {
		if ( 202 === result.status ) {
			S.done( h, strings.queued );
			window.setTimeout( function () {
				window.location.reload();
			}, 4000 );

			return;
		}

		S.fail( h, problem( result ) );
	}

	function act( box, button, action, args ) {
		return guarded( box, button, function ( h ) {
			return call( box, 'POST', '/actions/' + encodeURIComponent( action ), { args: args || {} } ).then( function ( result ) {
				queued( h, result );
			} );
		} );
	}

	function withCode( purpose, work ) {
		return window.beltoftApi.stepUp( purpose, { holder: account } ).then( function ( code ) {
			return code ? work( code ) : null;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );
		var box = target ? target.closest( '.beltoft-services-storage' ) : null;
		var field;

		if ( ! target || ! box ) {
			return;
		}

		if ( target.hasAttribute( 'data-storage-reveal' ) ) {
			withCode( 'storage_password', function ( code ) {
				return guarded( box, target, function ( h ) {
					return call( box, 'POST', '/storage-password', { step_code: code } ).then( function ( result ) {
						var holder = box.querySelector( '[data-storage-password]' );
						var input = box.querySelector( '[data-storage-password-field]' );

						if ( ! result.ok || ! result.data.password ) {
							S.fail( h, problem( result ) );

							return;
						}

						input.value = result.data.password;
						holder.hidden = false;
						S.done( h, strings.passwordNote );
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-storage-reset' ) ) {
			if ( ! window.confirm( strings.confirmReset ) ) {
				return;
			}

			withCode( 'storage_password', function ( code ) {
				return guarded( box, target, function ( h ) {
					return call( box, 'POST', '/storage-password-reset', { step_code: code } ).then( function ( result ) {
						queued( h, result );
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-storage-rollback' ) ) {
			var snapshot = target.getAttribute( 'data-storage-rollback' );

			if ( ! window.confirm( strings.confirmBack ) ) {
				return;
			}

			withCode( 'storage_restore', function ( code ) {
				return guarded( box, target, function ( h ) {
					return call( box, 'POST', '/storage-rollback', { snapshot: snapshot, step_code: code } ).then( function ( result ) {
						queued( h, result );
					} );
				} );
			} );

			return;
		}

		if ( target.hasAttribute( 'data-storage-drop' ) ) {
			if ( window.confirm( strings.confirmDrop ) ) {
				act( box, target, 'storage_snapshot_delete', { snapshot: target.getAttribute( 'data-storage-drop' ) } );
			}

			return;
		}

		if ( target.hasAttribute( 'data-storage-snapshot' ) ) {
			act( box, target, 'storage_snapshot', {} );

			return;
		}

		if ( target.hasAttribute( 'data-storage-copy' ) ) {
			window.beltoftApi.copy( target.getAttribute( 'data-storage-copy' ), target );

			return;
		}

		if ( target.hasAttribute( 'data-copy-field' ) ) {
			field = box.querySelector( '[data-storage-password-field]' );

			if ( field ) {
				window.beltoftApi.copy( field.value, target );
			}
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var box = form.closest ? form.closest( '.beltoft-services-storage' ) : null;
		var button;
		var args = {};

		if ( ! box ) {
			return;
		}

		button = form.querySelector( 'button[type="submit"]' );

		if ( form.hasAttribute( 'data-storage-access' ) ) {
			event.preventDefault();
			[ 'ssh', 'smb', 'webdav' ].forEach( function ( name ) {
				var input = form.elements[ name ];

				if ( input ) {
					args[ name ] = input.checked ? '1' : '0';
				}
			} );
			act( box, button, 'storage_access', args );
		} else if ( form.hasAttribute( 'data-storage-plan' ) ) {
			event.preventDefault();
			args.every = form.elements.every.value;
			args.hour = form.elements.hour.value;
			act( box, button, 'storage_snapshot_plan', args );
		}
	} );
}() );
