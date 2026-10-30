/* Domain choice on the server's Reinstall form. The server validates every posted value. */
( function () {
	'use strict';

	Array.prototype.forEach.call( document.querySelectorAll( '[data-app-reinstall]' ), function ( scope ) {
		var apps = scope.querySelector( '[data-tiles="app"]' );
		var domain = scope.querySelector( '[data-app-domain]' );
		var fields = scope.querySelector( '[data-app-domain-fields]' );
		var none = domain ? domain.querySelector( 'input[name="app_domain_mode"][value="none"]' ) : null;
		var removal = scope.querySelector( '[data-app-domain-removal]' );
		var confirm = scope.closest( 'form' ).querySelector( '[data-app-domain-confirm]' );

		var summary = scope.closest( 'form' ).querySelector( '[data-app-reinstall-summary]' );
		var versions = scope.querySelector( '[data-tiles-version]' );
		var versionRow = scope.querySelector( '[data-tiles-version-row]' );

		// The confirmation says which app is installed: the version when one is shown, else the app's name.
		function say() {
			var app = apps ? apps.querySelector( 'input[name="app_version"]:checked' ) : null;
			var name = app ? app.closest( 'label' ) : null;
			var text = '';

			if ( ! summary || ! app ) {
				return;
			}
			name = name ? name.querySelector( '.beltoft-services-tile__name' ) : null;
			if ( app.hasAttribute( 'data-none' ) ) {
				text = summary.getAttribute( 'data-text-none' );
			} else {
				var option = versions && versionRow && ! versionRow.hidden ? versions.options[ versions.selectedIndex ] : null;
				text = ( summary.getAttribute( 'data-text-app' ) || '%s' ).replace( '%s', ( option ? option.textContent.replace( /^Current: /, '' ) : '' ) || ( name ? name.textContent : '' ) );
			}
			summary.textContent = text;
		}

		if ( apps ) {
			scope.addEventListener( 'change', say );
			say();
		}

		if ( ! apps || ! domain || ! fields || ! none ) {
			return;
		}

		function update() {
			var app = apps.querySelector( 'input[name="app_version"]:checked' );
			var supported = !! app && app.hasAttribute( 'data-domain' );
			var change = domain.querySelector( 'input[name="app_domain_mode"][value="change"]' );
			var mode = domain.querySelector( 'input[name="app_domain_mode"]:checked' );
			var zone = domain.querySelector( 'select[name="app_zone"]' );
			var sub = domain.querySelector( 'input[name="app_sub"]' );
			var part = sub ? sub.value.trim().toLowerCase() : '';
			var target = zone ? ( part && '@' !== part ? part + '.' : '' ) + zone.value : '';
			var same = mode && 'change' === mode.value && target === domain.getAttribute( 'data-current-domain' );
			var removing = domain.hasAttribute( 'data-domain-made' ) && ( ! supported || ( mode && 'keep' !== mode.value && ! same ) );

			if ( ! supported ) {
				none.checked = true;
			}
			domain.hidden = ! supported;
			fields.hidden = ! supported || ! change.checked;
			if ( removal ) {
				removal.hidden = ! removing;
			}
			if ( confirm ) {
				confirm.hidden = ! removing;
			}
		}

		apps.addEventListener( 'change', update );
		domain.addEventListener( 'change', update );
		domain.addEventListener( 'input', update );
		update();
	} );
}() );
