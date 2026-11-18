/**
 * My Account, dedicated server page: restart and power, rescue system, reverse DNS, firewall and reinstall. Reads
 * resources/<id>/dedicated once and sends each change to its route. Needs the panel script (window.beltoftServicesAccount).
 * No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesDedicated;
	var S = window.beltoftStatus;

	if ( ! account || ! cfg || ! window.fetch || ! window.beltoftApi || ! S ) {
		return;
	}

	var T = cfg.strings;

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		var key;

		for ( key in ( attrs || {} ) ) {
			if ( Object.prototype.hasOwnProperty.call( attrs, key ) ) {
				node.setAttribute( key, attrs[ key ] );
			}
		}

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function button( label, onClick, extra ) {
		var b = el( 'button', { type: 'button', 'class': 'button bs-btn' + ( extra ? ' ' + extra : '' ) }, label );

		b.addEventListener( 'click', function () {
			onClick( b );
		} );

		return b;
	}

	function problem( result ) {
		if ( 429 === result.status ) {
			return T.limit;
		}

		return result.data && result.data.message ? result.data.message : T.failed;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dedicated]' ), function ( box ) {
		var id = box.getAttribute( 'data-resource' );
		var scope = id;

		function url( path ) {
			return account.root + id + '/dedicated' + ( path || '' );
		}

		function request( method, path, body ) {
			return window.beltoftApi.call( url( path ), { method: method, body: body || undefined, holder: account } );
		}

		// One change: the busy line, the call, the answer, then the overview again.
		function run( btn, method, path, body, purpose ) {
			var h;

			if ( S.locked( scope ) ) {
				return Promise.resolve();
			}

			return ( purpose ? window.beltoftApi.stepUp( purpose, { holder: account } ) : Promise.resolve( '' ) ).then( function ( code ) {
				if ( purpose && ! code ) {
					return null;
				}

				if ( purpose ) {
					body = body || {};
					body.step_code = code;
				}

				h = S.busy( T.working, scope, btn );

				return request( method, path, body ).then( function ( result ) {
					if ( ! result.ok ) {
						S.fail( h, problem( result ) );

						return null;
					}

					S.done( h, result.data && result.data.note ? result.data.note : T.done );
					load();

					return result;
				} );
			} ).catch( function () {
				S.fail( h || null, T.failed );
			} );
		}

		function section( key ) {
			var s = box.querySelector( '[data-ded-section="' + key + '"]' );

			return s ? s : null;
		}

		function body( key, ok ) {
			var s = section( key );
			var b = s ? s.querySelector( '[data-ded-body]' ) : null;

			if ( ! s ) {
				return null;
			}

			s.hidden = false;

			if ( b ) {
				b.textContent = '';

				if ( ! ok ) {
					b.appendChild( el( 'p', { 'class': 'bs-note' }, T.notAvailable ) );

					return null;
				}
			}

			return b;
		}

		function power( data ) {
			var b = body( 'power', data.reset || data.wol );
			var row;

			if ( ! b ) {
				return;
			}

			row = el( 'p' );
			( data.reset || [] ).forEach( function ( item ) {
				row.appendChild( button( item.label, function ( btn ) {
					if ( window.confirm( T.confirmReset ) ) {
						run( btn, 'POST', '/reset', { type: item.type }, item.code ? 'dedicated_power' : '' );
					}
				} ) );
				row.appendChild( document.createTextNode( ' ' ) );
			} );

			if ( data.wol ) {
				row.appendChild( button( T.switchOn, function ( btn ) {
					run( btn, 'POST', '/wol' );
				} ) );
			}

			b.appendChild( row );
		}

		function rescue( data ) {
			var b = body( 'rescue', data.rescue );
			var row;
			var pw;

			if ( ! b ) {
				return;
			}

			b.appendChild( el( 'p', {}, data.rescue.active ? T.rescueIs : T.rescueIsNot ) );
			row = el( 'p' );

			if ( data.rescue.active ) {
				row.appendChild( button( T.rescueOff, function ( btn ) {
					run( btn, 'DELETE', '/rescue' );
				} ) );

				if ( data.rescue.password ) {
					row.appendChild( document.createTextNode( ' ' ) );
					pw = el( 'input', { type: 'text', readonly: 'readonly', size: '24', hidden: 'hidden', 'aria-label': T.showPassword } );
					row.appendChild( button( T.showPassword, function ( btn ) {
						window.beltoftApi.stepUp( 'dedicated_rescue', { holder: account } ).then( function ( code ) {
							var h;

							if ( ! code ) {
								return null;
							}

							h = S.busy( T.working, scope, btn );

							return request( 'POST', '/rescue-password', { step_code: code } ).then( function ( result ) {
								if ( ! result.ok || ! result.data.password ) {
									S.fail( h, problem( result ) );

									return;
								}

								pw.value = result.data.password;
								pw.hidden = false;
								S.done( h, T.passwordNote );
							} );
						} ).catch( function () {
							S.fail( null, T.failed );
						} );
					} ) );
					row.appendChild( document.createTextNode( ' ' ) );
					row.appendChild( pw );
				}
			} else {
				row.appendChild( button( T.rescueBoot, function ( btn ) {
					if ( window.confirm( T.confirmReset ) ) {
						run( btn, 'POST', '/rescue', { boot: true } );
					}
				} ) );
				row.appendChild( document.createTextNode( ' ' ) );
				row.appendChild( button( T.rescueOn, function ( btn ) {
					run( btn, 'POST', '/rescue', { boot: false } );
				} ) );
			}

			b.appendChild( row );
		}

		function rdns( data ) {
			var b = body( 'rdns', data.rdns );
			var list;
			var addr;
			var ptr;
			var form;
			var addresses = data.addresses || {};

			if ( ! b ) {
				return;
			}

			list = el( 'ul', { 'class': 'bs-list' } );
			data.rdns.forEach( function ( row ) {
				var li = el( 'li', {}, row.ip + '  ' + row.ptr + '  ' );

				li.appendChild( button( T.remove, function ( btn ) {
					run( btn, 'DELETE', '/rdns', { ip: row.ip } );
				}, 'bs-btn--sm' ) );
				list.appendChild( li );
			} );
			b.appendChild( list );

			form = el( 'p' );
			addr = el( 'input', { type: 'text', size: '30', 'aria-label': T.address, placeholder: ( addresses.ipv6 ? T.ipv6Hint + ' ' + addresses.ipv6 : '' ) } );
			addr.value = ( addresses.ipv4 || [] )[ 0 ] || '';
			ptr = el( 'input', { type: 'text', size: '30', 'aria-label': T.name, placeholder: 'server.example.com' } );
			form.appendChild( addr );
			form.appendChild( document.createTextNode( ' ' ) );
			form.appendChild( ptr );
			form.appendChild( document.createTextNode( ' ' ) );
			form.appendChild( button( T.save, function ( btn ) {
				run( btn, 'PUT', '/rdns', { ip: addr.value, ptr: ptr.value } );
			} ) );
			b.appendChild( form );
		}

		function select( options, value, label ) {
			var s = el( 'select', { 'aria-label': label } );

			options.forEach( function ( option ) {
				var o = el( 'option', { value: option[ 0 ] }, option[ 1 ] );

				if ( option[ 0 ] === value ) {
					o.selected = true;
				}

				s.appendChild( o );
			} );

			return s;
		}

		function firewall( data ) {
			var b = body( 'firewall', data.firewall );
			var status;
			var table;
			var rules;

			if ( ! b ) {
				return;
			}

			rules = data.firewall.input.slice();
			status = select( [ [ 'active', T.fwActive ], [ 'disabled', T.fwOff ] ], 'disabled' === data.firewall.status ? 'disabled' : 'active', T.fwStatus );
			table = el( 'div', { 'class': 'beltoft-services-dedicated__rules' } );

			function draw() {
				table.textContent = '';
				rules.forEach( function ( rule, index ) {
					var row = el( 'p' );
					var fields = {
						name: el( 'input', { type: 'text', size: '14', 'aria-label': T.fwName, placeholder: T.fwName, value: rule.name || '' } ),
						ip_version: select( [ [ '', T.any ], [ 'ipv4', 'IPv4' ], [ 'ipv6', 'IPv6' ] ], rule.ip_version || '', T.fwVersion ),
						src_ip: el( 'input', { type: 'text', size: '16', 'aria-label': T.fwSource, placeholder: T.fwSource, value: rule.src_ip || '' } ),
						dst_port: el( 'input', { type: 'text', size: '8', 'aria-label': T.fwPort, placeholder: T.fwPort, value: rule.dst_port || '' } ),
						protocol: select( [ [ '', T.any ], [ 'tcp', 'tcp' ], [ 'udp', 'udp' ], [ 'icmp', 'icmp' ], [ 'gre', 'gre' ], [ 'ipip', 'ipip' ], [ 'ah', 'ah' ], [ 'esp', 'esp' ] ], rule.protocol || '', T.fwProto ),
						action: select( [ [ 'accept', T.accept ], [ 'discard', T.discard ] ], rule.action || 'accept', T.fwAction )
					};

					Object.keys( fields ).forEach( function ( key ) {
						fields[ key ].addEventListener( 'change', function () {
							rules[ index ][ key ] = fields[ key ].value;
						} );
						row.appendChild( fields[ key ] );
						row.appendChild( document.createTextNode( ' ' ) );
					} );
					row.appendChild( button( T.remove, function () {
						rules.splice( index, 1 );
						draw();
					}, 'bs-btn--sm' ) );
					table.appendChild( row );
				} );
			}

			draw();
			b.appendChild( el( 'p' ) ).appendChild( status );
			b.appendChild( table );
			b.appendChild( button( T.fwAdd, function () {
				rules.push( { name: '', ip_version: 'ipv4', src_ip: '', dst_port: '', protocol: 'tcp', action: 'accept' } );
				draw();
			} ) );
			b.appendChild( document.createTextNode( ' ' ) );
			b.appendChild( button( T.fwSave, function ( btn ) {
				var input = rules.map( function ( rule ) {
					return { name: rule.name, ip_version: rule.ip_version, src_ip: rule.src_ip, dst_port: rule.dst_port, protocol: rule.protocol, action: rule.action };
				} );

				// The server says whether this needs the emailed code: ask for it only when a first try is refused.
				request( 'PUT', '/firewall', { status: status.value, input: input } ).then( function ( result ) {
					var h;

					if ( result.ok ) {
						S.done( S.busy( T.working, scope, btn ), T.done );
						load();

						return null;
					}

					if ( 403 === result.status && result.data && 'step_up' === result.data.code ) {
						return run( btn, 'PUT', '/firewall', { status: status.value, input: input }, 'dedicated_firewall' );
					}

					h = S.busy( T.working, scope, btn );
					S.fail( h, problem( result ) );

					return null;
				} );
			} ) );
		}

		function reinstall() {
			var form = box.querySelector( '[data-ded-reinstall]' );
			var s = section( 'reinstall' );

			if ( s ) {
				s.hidden = false;
			}

			if ( ! form || form.getAttribute( 'data-ready' ) ) {
				return;
			}

			form.setAttribute( 'data-ready', '1' );
			form.addEventListener( 'submit', function ( event ) {
				var image = form.querySelector( 'input[name="image"]:checked' );
				var btn = form.querySelector( 'button[type="submit"]' );

				event.preventDefault();

				if ( ! image ) {
					S.fail( null, T.chooseSystem );

					return;
				}

				window.beltoftApi.stepUp( 'dedicated_reinstall', { holder: account } ).then( function ( code ) {
					var h;

					if ( ! code ) {
						return null;
					}

					h = S.busy( T.working, scope, btn );

					return request( 'POST', '/reinstall', { image: image.value, confirm: form.elements.confirm.value, step_code: code } ).then( function ( result ) {
						if ( 202 !== result.status ) {
							S.fail( h, problem( result ) );

							return null;
						}

						S.done( h, T.reinstalling );

						return window.beltoftApi.follow( result.data, { holder: account } ).then( function () {
							window.location.reload();
						} );
					} );
				} ).catch( function () {
					S.fail( null, T.failed );
				} );
			} );
		}

		function load() {
			request( 'GET', '' ).then( function ( result ) {
				var loading = box.querySelector( '[data-ded-loading]' );

				if ( loading ) {
					loading.hidden = true;
				}

				if ( ! result.ok ) {
					return;
				}

				power( result.data );
				rescue( result.data );
				rdns( result.data );
				firewall( result.data );
				reinstall();
			} );
		}

		load();
	} );
}() );
