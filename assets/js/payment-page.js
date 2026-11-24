/**
 * sattle.me for WooCommerce: the payment page of an order.
 *
 * Counts down the time left, copies the invoice or the address, and asks the server for news of
 * the payment every few seconds: the page reloads when what it shows changed, and goes to the
 * order's "order received" page once paid. The server decides everything; without JavaScript,
 * the page offers a button to check the payment instead.
 */
( function () {
	'use strict';

	// Selects the text of `source`: copied by the browser's own command, or else left selected
	// for the customer to copy.
	function copySelected( source ) {
		var range = document.createRange();
		var selection = window.getSelection();
		range.selectNodeContents( source );
		selection.removeAllRanges();
		selection.addRange( range );
		try {
			return document.execCommand( 'copy' );
		} catch ( e ) {
			return false;
		}
	}

	function copyButtons( root ) {
		Array.prototype.forEach.call( root.querySelectorAll( '[data-sattleme-copy]' ), function ( button ) {
			var source = document.getElementById( button.getAttribute( 'data-sattleme-copy' ) );
			if ( ! source ) {
				return;
			}
			button.hidden = false;
			button.addEventListener( 'click', function () {
				function copied() {
					button.textContent = button.getAttribute( 'data-copied' );
				}
				function fallback() {
					if ( copySelected( source ) ) {
						copied();
					}
				}
				if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
					fallback();
					return;
				}
				navigator.clipboard.writeText( source.textContent.trim() ).then( copied, fallback );
			} );
		} );
	}

	function countdown( root ) {
		var element = document.getElementById( 'sattleme-countdown' );
		var left = parseInt( root.getAttribute( 'data-seconds-left' ), 10 ) || 0;
		var started = Date.now();
		if ( ! element ) {
			return;
		}
		function render() {
			var seconds = Math.max( 0, left - Math.floor( ( Date.now() - started ) / 1000 ) );
			element.textContent = Math.floor( seconds / 60 ) + ':' + ( '0' + ( seconds % 60 ) ).slice( -2 );
		}
		render();
		window.setInterval( render, 1000 );
	}

	function poll( root ) {
		var url = root.getAttribute( 'data-status-url' );
		var version = root.getAttribute( 'data-version' );
		var interval = parseInt( root.getAttribute( 'data-poll-interval' ), 10 );
		if ( ! url || ! interval || ! window.fetch ) {
			return;
		}
		function later() {
			window.setTimeout( ask, interval * 1000 );
		}
		function ask() {
			window.fetch( url, {
				credentials: 'same-origin',
				cache: 'no-store',
				headers: { Accept: 'application/json' }
			} ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).then( function ( answer ) {
				if ( answer && typeof answer.redirect === 'string' ) {
					window.location.assign( answer.redirect );
				} else if ( answer && answer.version && answer.version !== version ) {
					window.location.reload();
				} else {
					later();
				}
			}, later );
		}
		later();
	}

	function start() {
		var root = document.getElementById( 'sattleme-payment' );
		if ( ! root ) {
			return;
		}
		copyButtons( root );
		countdown( root );
		poll( root );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
