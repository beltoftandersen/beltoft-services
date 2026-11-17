/**
 * My Account, Overview, activity log: "Show more" adds the next lines, the service select starts the log again for one
 * service. Both read GET activity; every text is set with textContent, so nothing from a reply is ever parsed as HTML.
 * Needs assets/api.js (window.beltoftApi).
 */
( function () {
	'use strict';

	var settings = window.beltoftServicesActivity;
	var box = document.querySelector( '[data-activity]' );

	if ( ! settings || ! box || ! window.fetch || ! window.beltoftApi ) {
		return;
	}

	var list = box.querySelector( '[data-activity-list]' );
	var button = box.querySelector( '[data-activity-more]' );
	var note = box.querySelector( '[data-activity-note]' );
	var filter = box.querySelector( '[data-activity-filter]' );
	var holder = settings;
	var busy = false;

	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function say( text ) {
		note.textContent = text || '';
		note.hidden = ! text;
	}

	function line( item ) {
		var li = element( 'li' );
		var time = element( 'time', '', item.time );
		var who = item.url ? element( 'a', 'beltoft-services-ov__who', item.service ) : element( 'span', 'beltoft-services-ov__who', item.service );

		time.setAttribute( 'datetime', item.datetime );

		if ( item.url ) {
			who.setAttribute( 'href', item.url );
		}

		li.appendChild( time );
		li.appendChild( who );
		li.appendChild( element( 'span', 'beltoft-services-ov__what', item.text ) );

		return li;
	}

	function render( days, replace ) {
		var last = replace ? '' : box.getAttribute( 'data-day' );
		var ul = null;

		if ( replace ) {
			list.textContent = '';
		} else if ( last ) {
			var lists = list.querySelectorAll( 'ul' );
			ul = lists.length ? lists[ lists.length - 1 ] : null;
		}

		days.forEach( function ( day ) {
			if ( day.day !== last || ! ul ) {
				list.appendChild( element( 'h3', 'beltoft-services-ov__day', day.label ) );
				ul = element( 'ul', 'beltoft-services-ov__log' );
				list.appendChild( ul );
			}

			last = day.day;
			day.lines.forEach( function ( item ) {
				ul.appendChild( line( item ) );
			} );
		} );

		box.setAttribute( 'data-day', last );
	}

	function load( replace ) {
		var service = filter ? filter.value : '0';
		var url = settings.root + '?limit=' + ( replace ? settings.first : settings.more ) + '&service=' + encodeURIComponent( service ) + ( replace ? '' : '&before=' + encodeURIComponent( box.getAttribute( 'data-next' ) ) );
		var label = button.textContent;

		if ( busy ) {
			return;
		}

		busy = true;
		say( '' );
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = settings.strings.loading;

		window.beltoftApi.call( url, { holder: holder } ).then( function ( reply ) {
			if ( ! reply.ok || ! reply.parsed || ! reply.data.days ) {
				throw new Error( 'activity' );
			}

			render( reply.data.days, replace );
			box.setAttribute( 'data-next', String( reply.data.next || 0 ) );

			if ( replace && ! reply.data.days.length ) {
				say( settings.strings.empty );
			}
		} ).catch( function () {
			say( settings.strings.failed );
		} ).then( function () {
			busy = false;
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.textContent = label;
			button.parentNode.hidden = '0' === box.getAttribute( 'data-next' );
		} );
	}

	button.addEventListener( 'click', function () {
		load( false );
	} );

	if ( filter ) {
		filter.addEventListener( 'change', function () {
			load( true );
		} );
	}
}() );
