/* The Billing block of the product editor: the premium and the live rates on the screen, steps checked before the save, the check list kept fresh. */
( function ( $ ) {
	'use strict';

	var cfg = window.beltoftPayg || {};
	var KIND = '#_beltoft_services_billing';

	function kindChanged() {
		$( '.bs-payg-premium' ).toggle( 'usage' === $( KIND ).val() );
	}

	function currentPremium() {
		var input = document.getElementById( '_beltoft_services_usage_premium' );
		var p = input ? parseFloat( input.value.trim() ) : NaN;

		return isNaN( p ) || p < 0 ? parseFloat( cfg.default ) : p;
	}

	// The rate and the cap next to a monthly price on a variation row, worked out as the server does.
	function live() {
		var p = currentPremium();

		$( '.bs-payg-live' ).each( function () {
			var m = parseFloat( $( '#' + this.getAttribute( 'data-bs-for' ) ).val() );

			if ( isNaN( m ) || m <= 0 ) {
				$( this ).text( '' );

				return;
			}

			$( this ).text( ( cfg.live || '' ).replace( '%1$s', ( m * ( 1 + p / 100 ) / cfg.hours ).toFixed( 4 ) ).replace( '%2$s', ( m * ( 1 + p / 100 ) ).toFixed( 2 ) ) );
		} );
	}

	function premium() {
		var input = document.getElementById( '_beltoft_services_usage_premium' );
		var table = document.querySelector( '.bs-payg-preview' );

		if ( ! input ) {
			return;
		}

		var raw = input.value.trim();
		var p = parseFloat( raw );
		var text = '';
		var warn = false;

		if ( '' !== raw && ( isNaN( p ) || p < 0 ) ) {
			text = ( cfg.premium || {} ).bad || '';
		} else if ( p > 100 ) {
			text = ( cfg.premium || {} ).high || '';
			warn = true;
		}

		$( '.bs-payg-premium-msg' ).toggle( '' !== text ).find( 'span' ).text( text ).css( 'color', warn ? '#996800' : '#d63638' );
		p = currentPremium();

		if ( table ) {
			$( table ).find( '.bs-payg-hourly' ).each( function () {
				$( this ).text( ( parseFloat( this.getAttribute( 'data-monthly' ) ) * ( 1 + p / 100 ) / cfg.hours ).toFixed( 4 ) );
			} );
			$( table ).find( '.bs-payg-cap' ).each( function () {
				$( this ).text( ( parseFloat( this.getAttribute( 'data-monthly' ) ) * ( 1 + p / 100 ) ).toFixed( 2 ) );
			} );
		}

		live();
	}

	// The saved driver decides what is offered: a changed choice says to save first.
	$( document ).on( 'change', '#_beltoft_services_driver', function () {
		var changed = $( this ).val() !== ( $( '.bs-payg' ).attr( 'data-bs-driver' ) || '' );
		$( '.bs-payg-driver-note' ).toggle( changed );
		$( '.bs-payg-body, .bs-payg-none' ).css( 'opacity', changed ? 0.5 : 1 );
	} );

	$( document ).on( 'change', KIND, kindChanged );
	$( document ).on( 'input change', '#_beltoft_services_usage_premium', premium );
	$( document ).on( 'input change', '.bs-payg-rate input', live );

	/**
	 * The steps of every plan billed per unit, checked as the server checks them. Returns the first plan row with a bad one, or null.
	 */
	function validateSteps() {
		var first = null;

		$( '.bs-payg-js-error' ).remove();

		$( '.bs-payg-variation' ).each( function () {
			var $v = $( this );

			$v.find( '.bs-payg-currency' ).each( function () {
				var $c = $( this );
				var rows = [];

				$c.find( '.bs-payg-steps span' ).each( function () {
					var inputs = $( this ).find( 'input' );

					if ( 2 === inputs.length && ( '' !== inputs[ 0 ].value.trim() || '' !== inputs[ 1 ].value.trim() ) ) {
						rows.push( [ inputs[ 0 ].value.trim(), inputs[ 1 ].value.trim() ] );
					}
				} );

				var bad = false;
				var last = 0;
				var ended = false;

				rows.forEach( function ( r ) {
					var upto = '' === r[ 0 ] ? 0 : Number( r[ 0 ] );
					var price = Number( r[ 1 ] );

					if ( ended || '' === r[ 1 ] || isNaN( price ) || price <= 0 || isNaN( upto ) || upto < 0 || Math.floor( upto ) !== upto || ( upto > 0 && upto <= last ) ) {
						bad = true;
					}

					if ( 0 === upto ) {
						ended = true;
					} else {
						last = upto;
					}
				} );

				if ( rows.length && ! ended ) {
					bad = true;
				}

				if ( bad ) {
					var text = ( cfg.steps || '' ).replace( '%1$s', $v.attr( 'data-bs-plan' ) ).replace( '%2$s', $c.attr( 'data-bs-code' ) );
					$( '<p class="bs-payg-js-error" role="alert"></p>' ).text( text ).appendTo( $c );
					first = first || $v;
				}
			} );
		} );

		return first;
	}

	function stop( e, $v ) {
		e.preventDefault();
		e.stopImmediatePropagation();

		if ( $v && $v.length ) {
			$v.removeClass( 'closed' ).addClass( 'open' ).find( '.woocommerce_variable_attributes' ).show();
			$v[ 0 ].scrollIntoView( { block: 'center' } );
		}
	}

	// Before WooCommerce saves the variations (its own handler runs after this one).
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest ? e.target.closest( 'button.save-variation-changes' ) : null;
		var bad = button ? validateSteps() : null;

		if ( bad ) {
			stop( e, bad.closest( '.woocommerce_variation' ) );
		}
	}, true );

	document.addEventListener( 'submit', function ( e ) {
		var bad = e.target && 'post' === e.target.id ? validateSteps() : null;

		if ( bad ) {
			stop( e, bad.closest( '.woocommerce_variation' ) );
		}
	}, true );

	// The check list again after the variations changed.
	function refresh() {
		var id = $( '.bs-payg' ).attr( 'data-bs-product' );

		if ( ! id || ! cfg.ajax ) {
			return;
		}

		$.post( cfg.ajax, { action: cfg.action, nonce: cfg.nonce, product: id } ).done( function ( res ) {
			if ( res && res.success && res.data ) {
				$( '.bs-payg-problems' ).html( res.data.html );
			}
		} );
	}

	$( document ).on( 'woocommerce_variations_saved woocommerce_variations_added woocommerce_variations_removed', refresh );

	// A plan named in a check: open the Variations tab on it.
	$( document ).on( 'click', 'a.bs-payg-goto', function ( e ) {
		e.preventDefault();

		var id = String( $( this ).attr( 'data-bs-variation' ) );
		var tries = 0;

		$( '.variations_tab a' ).trigger( 'click' );

		( function find() {
			var $v = $( '.woocommerce_variation' ).has( 'input.variable_post_id[value="' + id + '"]' );

			if ( $v.length ) {
				if ( $v.hasClass( 'closed' ) ) {
					$v.find( 'h3' ).first().trigger( 'click' );
				}

				$v[ 0 ].scrollIntoView( { block: 'center' } );

				return;
			}

			if ( tries++ < 30 ) {
				setTimeout( find, 200 );
			}
		}() );
	} );

	$( document ).on( 'woocommerce_variations_loaded', live );

	$( function () {
		kindChanged();
		premium();
	} );
}( jQuery ) );
