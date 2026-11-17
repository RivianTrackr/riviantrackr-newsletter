/* RivianTrackr Weekly signup box and account tab. */
( function () {
	'use strict';

	var config = window.rtnlConfig || {};
	var i18n = config.i18n || {};

	function post( path, body ) {
		var headers = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		return fetch( config.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( body ),
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || i18n.error );
				}
				return data;
			} );
		} );
	}

	function setup( root ) {
		var msg = root.querySelector( '[data-rtnl-msg]' );

		function say( text, isError ) {
			if ( ! msg ) {
				return;
			}
			msg.textContent = text;
			msg.classList.toggle( 'rtnl-signup__msg--error', !! isError );
			msg.hidden = false;
		}

		function text( key ) {
			var tpl = root.querySelector( '[data-rtnl-text="' + key + '"]' );
			return tpl ? tpl.content.textContent.trim() : '';
		}

		function panels( name ) {
			root.querySelectorAll( '[data-rtnl-panel]' ).forEach( function ( el ) {
				el.hidden = el.getAttribute( 'data-rtnl-panel' ) !== name;
			} );
		}

		function busy( button, on ) {
			if ( on ) {
				button.dataset.label = button.textContent;
				button.textContent = i18n.working;
			} else if ( button.dataset.label ) {
				button.textContent = button.dataset.label;
			}
			button.disabled = on;
		}

		// Confirm or invite link landing on the newsletter page.
		var params = new URLSearchParams( window.location.search );
		var token = params.get( 'rtnl_confirm' ) || params.get( 'rtnl_invite' );
		var accept = root.querySelector( '[data-rtnl-accept]' );
		if ( token && accept ) {
			panels( 'accept' );
			accept.addEventListener( 'click', function () {
				busy( accept, true );
				post( '/accept', { token: token } ).then( function () {
					panels( 'none' );
					say( text( 'accepted' ) );
					if ( window.history.replaceState ) {
						window.history.replaceState( null, '', window.location.pathname );
					}
				} ).catch( function ( err ) {
					busy( accept, false );
					say( err.message, true );
				} );
			} );
		}

		var form = root.querySelector( '[data-rtnl-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var button = form.querySelector( 'button[type="submit"]' );
				var email = form.elements.email.value.trim();
				if ( ! form.elements.email.checkValidity() || ! email ) {
					form.elements.email.focus();
					return;
				}
				busy( button, true );
				post( '/subscribe', { email: email, website: form.elements.website.value } ).then( function () {
					panels( 'none' );
					say( text( 'sent' ) );
				} ).catch( function ( err ) {
					busy( button, false );
					say( err.message, true );
				} );
			} );
		}

		var member = root.querySelector( '[data-rtnl-member]' );
		if ( member ) {
			member.querySelectorAll( '[data-rtnl-toggle]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var want = member.dataset.subscribed !== '1';
					busy( button, true );
					post( '/me', { subscribed: want } ).then( function () {
						busy( button, false );
						member.dataset.subscribed = want ? '1' : '0';
						member.querySelectorAll( '[data-rtnl-state]' ).forEach( function ( el ) {
							el.hidden = el.getAttribute( 'data-rtnl-state' ) !== ( want ? '1' : '0' );
						} );
						say( text( want ? 'accepted' : 'unsubscribed' ) );
					} ).catch( function ( err ) {
						busy( button, false );
						say( err.message, true );
					} );
				} );
			} );
		}
	}

	function init() {
		document.querySelectorAll( '[data-rtnl-signup]' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
