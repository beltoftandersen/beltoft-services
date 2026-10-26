/* Domain actions use the same REST nonce and resource id as the service account panel. */
( function () {
	'use strict';
	var config = window.beltoftServicesAccount;
	if ( ! config ) { return; }
	function request( method, path, args ) {
		return window.fetch( config.root + path, { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: args ? JSON.stringify( { args: args } ) : undefined } ).then( function ( response ) {
			var nonce = response.headers.get( 'X-WP-Nonce' );
			if ( nonce ) { config.nonce = nonce; }
			return response.json().then( function ( body ) { if ( ! response.ok ) { throw new Error( body.message || 'Request failed' ); } return body; } );
		} );
	}
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.beltoft-services-domain-form' );
		if ( ! form ) { return; }
		event.preventDefault();
		var panel = form.closest( '[data-domain-resource]' );
		var args = {};
		new FormData( form ).forEach( function ( value, key ) { args[ key ] = String( value ); } );
		var button = form.querySelector( 'button[type=submit]' );
		button.disabled = true;
		request( 'POST', panel.dataset.domainResource + '/actions/' + form.dataset.domainAction, args ).then( function () { window.location.reload(); } ).catch( function ( error ) { window.alert( error.message ); button.disabled = false; } );
	} );
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.beltoft-services-domain-auth' );
		if ( ! button ) { return; }
		var panel = button.closest( '[data-domain-resource]' );
		button.disabled = true;
		request( 'GET', panel.dataset.domainResource + '/domain-authcode' ).then( function ( body ) {
			panel.querySelector( '.beltoft-services-domain-code' ).textContent = body.auth_code;
			button.disabled = false;
		} ).catch( function ( error ) { window.alert( error.message ); button.disabled = false; } );
	} );
}() );
