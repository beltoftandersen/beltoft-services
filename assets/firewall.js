/**
 * My Account → Servers → the Firewall tab: the rules of a server, edited as a draft and applied once. The draft is
 * checked again by the server. No dependencies.
 */
( function () {
	'use strict';

	var account = window.beltoftServicesAccount;
	var cfg = window.beltoftServicesFirewall;
	var root = document.querySelector( '[data-fw]' );

	if ( ! account || ! cfg || ! root || ! window.fetch ) {
		return;
	}

	var S = cfg.strings;
	var id = root.getAttribute( 'data-resource' );
	var url = account.root + id + '/firewall';
	var data = null;
	var draft = null;
	var saved = '';
	var confirmOff = false;
	var timer = null;

	function q( selector ) {
		return root.querySelector( selector );
	}

	function request( method, path, body ) {
		var options = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': account.nonce, 'Content-Type': 'application/json' } };

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return window.fetch( path, options ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );

			if ( nonce ) {
				account.nonce = nonce;
			}

			return response.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				return { ok: response.ok, status: response.status, body: json };
			} );
		} );
	}

	function say( text ) {
		q( '[data-fw-status]' ).textContent = text || '';
	}

	function key( rule ) {
		return [ rule.proto, rule.from, rule.to, ( rule.sources || [] ).slice().sort().join( ',' ) ].join( '|' );
	}

	function snapshot() {
		return JSON.stringify( { on: draft.on, rules: draft.rules.map( key ) } );
	}

	function ipToInt( ip ) {
		var p = ip.split( '.' );

		return ( ( +p[ 0 ] << 24 ) >>> 0 ) + ( +p[ 1 ] << 16 ) + ( +p[ 2 ] << 8 ) + ( +p[ 3 ] );
	}

	function covers( sources, ip ) {
		if ( ! ip ) {
			return false;
		}

		return sources.some( function ( source ) {
			var bits = source.split( '/' );
			var len = bits[ 1 ] ? +bits[ 1 ] : 32;
			var mask = len === 0 ? 0 : ( 0xFFFFFFFF << ( 32 - len ) ) >>> 0;

			return ( ipToInt( ip ) & mask ) >>> 0 === ( ipToInt( bits[ 0 ] ) & mask ) >>> 0;
		} );
	}

	/* The rules that open SSH, as indexes of the draft. */
	function sshRules() {
		var out = [];

		draft.rules.forEach( function ( rule, i ) {
			if ( rule.proto === 'tcp' && rule.from <= 22 && rule.to >= 22 ) {
				out.push( i );
			}
		} );

		return out;
	}

	function sshOk() {
		return sshRules().some( function ( i ) {
			var sources = draft.rules[ i ].sources || [];

			return ! sources.length || covers( sources, data.my_ip );
		} );
	}

	function working() {
		return data && ( data.working || data.state === 'applying' ) && data.state !== 'failed';
	}

	function addRule( rule ) {
		var exists = draft.rules.some( function ( other ) {
			return key( other ) === key( rule );
		} );

		if ( exists ) {
			return S.duplicate;
		}

		if ( draft.rules.length >= data.max ) {
			return S.tooMany.replace( '%d', data.max );
		}

		draft.rules.push( rule );

		return '';
	}

	function render() {
		var start = q( '[data-fw-start]' );
		var main = q( '[data-fw-main]' );

		root.setAttribute( 'data-fw-state', data.managed ? 'managed' : 'start' );
		start.hidden = data.managed;
		main.hidden = ! data.managed;

		if ( ! data.managed ) {
			q( '[data-fw-live]' ).textContent = S[ data.live || 'unknown' ] || S.unknown;
			say( '' );

			return;
		}

		q( '[data-fw-switch-label]' ).textContent = draft.on ? S.on : S.off;
		q( '[data-fw-switch]' ).textContent = draft.on ? S.turnOff : S.turnOn;
		q( '[data-fw-off-warning]' ).hidden = draft.on;
		renderRows();
		renderSsh();
		q( '[data-fw-add-mine]' ).hidden = ! data.my_ip;

		var busy = working();
		var same = snapshot() === saved && data.state !== 'failed';

		q( '[data-fw-apply]' ).disabled = busy || same;
		q( '[data-fw-reset]' ).disabled = busy;

		if ( busy ) {
			say( S.applying );
		} else if ( data.state === 'failed' ) {
			say( S.failed );
		} else if ( ! same ) {
			say( S.unsaved );
		} else {
			say( '' );
		}
	}

	function renderRows() {
		var rows = q( '[data-fw-rows]' );

		rows.textContent = '';

		if ( ! draft.rules.length ) {
			var empty = document.createElement( 'p' );

			empty.className = 'beltoft-services-fw__empty';
			empty.textContent = S.noRules;
			rows.appendChild( empty );

			return;
		}

		draft.rules.forEach( function ( rule, index ) {
			var row = document.createElement( 'div' );
			var what = document.createElement( 'span' );
			var port = document.createElement( 'span' );
			var who = document.createElement( 'span' );
			var act = document.createElement( 'span' );
			var remove = document.createElement( 'button' );

			row.className = 'beltoft-services-fw__row';
			row.setAttribute( 'role', 'row' );
			what.textContent = rule.label;

			if ( rule.origin === 'app' ) {
				var note = document.createElement( 'small' );

				note.textContent = S.forApp;
				what.appendChild( note );
			}

			port.textContent = rule.port || portText( rule );
			who.textContent = ( rule.sources && rule.sources.length ) ? rule.sources.join( ', ' ) : S.anyone;
			remove.type = 'button';
			remove.className = 'beltoft-services-fw__link';
			remove.textContent = S.remove;
			remove.setAttribute( 'aria-label', S.remove + ': ' + rule.label );
			remove.addEventListener( 'click', function () {
				draft.rules.splice( index, 1 );
				render();
			} );
			act.appendChild( remove );

			if ( rule.ufw ) {
				var copy = document.createElement( 'button' );

				copy.type = 'button';
				copy.className = 'beltoft-services-fw__link';
				copy.textContent = S.copyUfw;
				copy.addEventListener( 'click', function () {
					copyText( rule.ufw, copy );
				} );
				act.appendChild( copy );
			}

			[ what, port, who, act ].forEach( function ( cell ) {
				cell.setAttribute( 'role', 'cell' );
				row.appendChild( cell );
			} );
			rows.appendChild( row );
		} );
	}

	function portText( rule ) {
		if ( rule.proto === 'icmp' ) {
			return 'Ping';
		}

		return rule.proto.toUpperCase() + ' ' + ( rule.from === rule.to ? rule.from : rule.from + '-' + rule.to );
	}

	function copyText( text, button ) {
		var done = function () {
			var was = button.textContent;

			button.textContent = S.copied;
			window.setTimeout( function () {
				button.textContent = was;
			}, 2000 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			window.prompt( '', text );
		}
	}

	function renderSsh() {
		var box = q( '[data-fw-ssh]' );
		var text = q( '[data-fw-ssh-text]' );
		var ackRow = q( '[data-fw-ssh-ack-row]' );
		var mineRow = q( '[data-fw-ssh-mine-row]' );
		var rules = sshRules();
		var limited = rules.length && ! rules.some( function ( i ) {
			return ! ( draft.rules[ i ].sources || [] ).length;
		} );

		ackRow.hidden = true;
		mineRow.hidden = true;
		box.hidden = true;

		if ( ! draft.on || sshOk() ) {
			return;
		}

		box.hidden = false;

		if ( limited && data.my_ip ) {
			text.textContent = S.notIncluded.replace( '%s', data.my_ip );
			mineRow.hidden = false;
		} else {
			text.textContent = S.noSsh;
		}

		ackRow.hidden = false;
	}

	function load() {
		return request( 'GET', url ).then( function ( reply ) {
			if ( ! reply.ok ) {
				say( S.loadFailed );

				return;
			}

			var first = data === null;
			var wasWorking = data !== null && working();

			data = reply.body;

			if ( first || ! dirtyNow() ) {
				draft = { on: data.on, rules: data.rules.map( copy ) };
				saved = snapshot();
			}

			render();

			if ( working() ) {
				poll();
			} else if ( wasWorking && data.state === 'idle' ) {
				q( '[data-fw-saved]' ).textContent = S.saved;
			}
		} ).catch( function () {
			say( S.loadFailed );
		} );
	}

	function dirtyNow() {
		return draft !== null && snapshot() !== saved;
	}

	function copy( rule ) {
		return JSON.parse( JSON.stringify( rule ) );
	}

	function poll() {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			load();
		}, 4000 );
	}

	function send( on, rules, ack, confirm ) {
		q( '[data-fw-error]' ).textContent = '';
		q( '[data-fw-saved]' ).textContent = '';

		return request( 'PUT', url, { on: on, rules: rules, ack_no_ssh: ack, confirm_off: confirm } ).then( function ( reply ) {
			if ( reply.ok ) {
				data = null;
				saved = '';
				confirmOff = false;
				say( S.applying );

				return load().then( function () {
					if ( data && ! working() && data.state === 'idle' ) {
						q( '[data-fw-saved]' ).textContent = S.saved;
					}
				} );
			}

			var message = reply.body && reply.body.message ? reply.body.message : S.error;

			if ( reply.status === 429 ) {
				message = account.strings.tooMany;
			}

			q( '[data-fw-error]' ).textContent = message;

			return null;
		} ).catch( function () {
			q( '[data-fw-error]' ).textContent = S.error;
		} );
	}

	function dialog( title, body, ok, then ) {
		var box = q( '[data-fw-dialog]' );

		q( '[data-fw-dialog-title]' ).textContent = title;
		q( '[data-fw-dialog-body]' ).textContent = body;
		q( '[data-fw-dialog-ok]' ).textContent = ok;
		box.onclose = null;
		q( '[data-fw-dialog-form]' ).onsubmit = function ( event ) {
			event.preventDefault();
			box.close();
			then();
		};
		q( '[data-fw-dialog-cancel]' ).onclick = function () {
			box.close();
		};

		if ( box.showModal ) {
			box.showModal();
		} else {
			box.setAttribute( 'open', '' );
		}
	}

	function presetRules( name ) {
		var make = function ( proto, port, label ) {
			return { proto: proto, from: port, to: port, sources: [], label: label, origin: 'customer' };
		};

		if ( name === 'ssh' ) {
			return [ make( 'tcp', 22, 'SSH' ) ];
		}

		if ( name === 'web' ) {
			return [ make( 'tcp', 80, 'HTTP' ), make( 'tcp', 443, 'HTTPS' ) ];
		}

		return [ { proto: 'icmp', from: 0, to: 0, sources: [], label: 'Ping', origin: 'customer' } ];
	}

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );

		if ( ! target || ! data ) {
			return;
		}

		if ( target.hasAttribute( 'data-fw-preset' ) ) {
			var problem = '';

			presetRules( target.getAttribute( 'data-fw-preset' ) ).forEach( function ( rule ) {
				problem = addRule( rule ) || problem;
			} );
			q( '[data-fw-form-error]' ).textContent = problem;
			render();
		} else if ( target.hasAttribute( 'data-fw-switch' ) ) {
			if ( draft.on ) {
				dialog( S.offTitle, S.offBody, S.turnOff, function () {
					draft.on = false;
					confirmOff = true;
					render();
				} );
			} else {
				draft.on = true;
				render();
			}
		} else if ( target.hasAttribute( 'data-fw-apply' ) ) {
			var ack = q( '[data-fw-ssh-ack]' ).checked;

			send( draft.on, draft.rules, ack, confirmOff );
		} else if ( target.hasAttribute( 'data-fw-reset' ) ) {
			dialog( S.resetTitle, S.resetBody, S.reset, function () {
				request( 'POST', account.root + id + '/actions/fw_reset', { args: {} } ).then( function ( reply ) {
					if ( reply.ok ) {
						data = null;
						saved = '';
						say( S.applying );
						load();
					} else {
						q( '[data-fw-error]' ).textContent = ( reply.body && reply.body.message ) || S.error;
					}
				} );
			} );
		} else if ( target.hasAttribute( 'data-fw-start-standard' ) ) {
			send( true, data.defaults, false, false );
		} else if ( target.hasAttribute( 'data-fw-start-open' ) ) {
			send( false, data.defaults, false, false );
		} else if ( target.hasAttribute( 'data-fw-add-mine' ) ) {
			var sources = q( '[name="sources"]' );

			sources.value = sources.value.trim() ? sources.value.trim() + ', ' + data.my_ip : data.my_ip;
		} else if ( target.hasAttribute( 'data-fw-ssh-mine' ) ) {
			sshRules().forEach( function ( i ) {
				var sources = draft.rules[ i ].sources || [];

				if ( sources.length && sources.length < 5 && sources.indexOf( data.my_ip ) < 0 ) {
					sources.push( data.my_ip );
					draft.rules[ i ].sources = sources;
				}
			} );
			render();
		}
	} );

	var form = q( '[data-fw-form]' );

	form.querySelector( '[name="who"]' ).addEventListener( 'change', function ( event ) {
		q( '[data-fw-sources-row]' ).hidden = event.target.value !== 'some';
	} );
	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var error = q( '[data-fw-form-error]' );
		var port = form.querySelector( '[name="port"]' ).value.trim();
		var match = /^(\d{1,5})(?:\s*[-:]\s*(\d{1,5}))?$/.exec( port );
		var sources = [];

		error.textContent = '';

		if ( ! match || +match[ 1 ] < 1 || +match[ 1 ] > 65535 || ( match[ 2 ] && ( +match[ 2 ] > 65535 || +match[ 2 ] <= +match[ 1 ] ) ) ) {
			error.textContent = S.badPort;

			return;
		}

		if ( form.querySelector( '[name="who"]' ).value === 'some' ) {
			sources = form.querySelector( '[name="sources"]' ).value.split( /[\s,]+/ ).filter( Boolean );

			if ( ! sources.length ) {
				error.textContent = S.noAddress;

				return;
			}
		}

		var proto = form.querySelector( '[name="proto"]' ).value;
		var from = +match[ 1 ];
		var to = match[ 2 ] ? +match[ 2 ] : from;
		var label = form.querySelector( '[name="label"]' ).value.trim() || ( proto.toUpperCase() + ' ' + ( from === to ? from : from + '-' + to ) );
		var problem = addRule( { proto: proto, from: from, to: to, sources: sources, label: label, origin: 'customer' } );

		if ( problem ) {
			error.textContent = problem;

			return;
		}

		form.reset();
		q( '[data-fw-sources-row]' ).hidden = true;
		render();
	} );

	load();
}() );
