/* Domain choice on the server's Reinstall form. The server validates every posted value. */
( function () {
	'use strict';

	Array.prototype.forEach.call( document.querySelectorAll( '[data-app-reinstall]' ), function ( scope ) {
		var apps = scope.querySelector( '[data-tiles="app"]' );
		var domain = scope.querySelector( '[data-app-domain]' );
		var fields = scope.querySelector( '[data-app-domain-fields]' );
		var none = domain ? domain.querySelector( 'input[name="app_domain_mode"][value="none"]' ) : null;

		if ( ! apps || ! domain || ! fields || ! none ) {
			return;
		}

		function update() {
			var app = apps.querySelector( 'input[name="app_version"]:checked' );
			var supported = !! app && app.hasAttribute( 'data-domain' );
			var change = domain.querySelector( 'input[name="app_domain_mode"][value="change"]' );

			if ( ! supported ) {
				none.checked = true;
			}
			domain.hidden = ! supported;
			fields.hidden = ! supported || ! change.checked;
		}

		apps.addEventListener( 'change', update );
		domain.addEventListener( 'change', update );
		update();
	} );
}() );
