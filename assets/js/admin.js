/**
 * Drives the ifthenpay panel in LifterLMS › Settings › Checkout › ifthenpay.
 *
 * The panel is server-rendered inside LifterLMS's #mainform, with nameless controls, and saves
 * through our own AJAX. The Connect/Disconnect dialogs live outside the form, in the footer.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.iftpLlmsSettings || {};
	var i18n = cfg.i18n || {};
	var actions = cfg.actions || {};
	var DEBOUNCE = 600;
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	var saveTimer = null;
	var saving = false;
	var saveQueued = false;
	var saveFailed = false;
	var pendingSubmit = null;
	var lastTrigger = null;
	var eurStore = !! cfg.eurStore;

	function apiPost( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data || {} ), null, 'json' );
	}

	function responseMessage( response ) {
		return ( response && response.data && response.data.message ) || '';
	}

	// No JSON body means the request never got a real answer.
	function xhrMessage( xhr ) {
		var json = xhr && xhr.responseJSON;

		if ( ! json ) {
			return i18n.unreachable || i18n.error || '';
		}

		return responseMessage( json ) || i18n.error || '';
	}

	function $config() {
		return $( '[data-iftp-llms-config]' );
	}

	function setSaveState( state, text ) {
		var $state = $( '[data-iftp-llms-save-state]' );

		$state.attr( 'data-state', state );
		$state.find( '[data-iftp-llms-save-text]' ).text( text || '' );
	}

	// Mirrors the server's readiness order: Gateway Key, then a method, then the currency.
	function updateReadiness( connected, configured ) {
		var $row = $( '[data-iftp-llms-readiness]' );
		var html = '';

		if ( connected && ! configured ) {
			if ( ! $( '[data-iftp-llms-gateway-key]' ).val() ) {
				html = i18n.readinessGatewayKey;
			} else if ( ! $( '[data-iftp-llms-enabled]:checked' ).length ) {
				html = i18n.readinessMethods;
			} else if ( ! eurStore ) {
				html = i18n.readinessCurrency;
			}
		}

		// Our own escaped strings, from wp_localize_script.
		$row.find( '[data-iftp-llms-readiness-text]' ).html( html || '' );
		$row.prop( 'hidden', ! html );
	}

	function applyFragments( data ) {
		if ( 'string' === typeof data.connectionHtml ) {
			$( '#iftp-llms-connection' ).html( data.connectionHtml );
		}

		if ( 'string' === typeof data.gatewayKeysHtml ) {
			$( '[data-iftp-llms-gateway-key]' ).html( data.gatewayKeysHtml );
		}

		if ( 'string' === typeof data.methodsHtml ) {
			$( '#iftp-llms-methods' ).html( data.methodsHtml );
		}

		if ( 'string' === typeof data.description ) {
			$( '#iftp-llms-description' ).val( data.description );
		}

		if ( data.expiryDays ) {
			$( '#iftp-llms-expiry-days' ).val( String( data.expiryDays ) );
			validateExpiry();
		}

		if ( 'boolean' === typeof data.eurStore ) {
			eurStore = data.eurStore;
		}

		$config().prop( 'hidden', ! data.connected );
		updateReadiness( !! data.connected, !! data.configured );
	}

	/* Dialogs: the hidden attribute is the only open/closed state. */

	function openModal( id, trigger ) {
		var $modal = $( '#' + id );

		lastTrigger = trigger || null;
		$modal.prop( 'hidden', false );
		$modal.find( '.iftp-llms-modal__message' ).text( '' );

		if ( 'iftp-llms-connect-modal' === id ) {
			$( '#iftp-llms-backoffice-key' ).val( '' ).removeAttr( 'aria-invalid' ).prop( 'readonly', false ).trigger( 'focus' );
		} else {
			// An alertdialog starts on the safe choice.
			$modal.find( '[data-iftp-llms-modal-close]' ).filter( 'button' ).last().trigger( 'focus' );
		}
	}

	function closeModal( $modal, focusTarget ) {
		var target = focusTarget || lastTrigger;

		$modal.prop( 'hidden', true );
		$( '#iftp-llms-backoffice-key' ).val( '' );
		lastTrigger = null;

		if ( target && document.body.contains( target ) ) {
			target.focus();
		}
	}

	function openModalEl() {
		return $( '[data-iftp-llms-modal]' ).not( '[hidden]' ).first();
	}

	function connect() {
		var $button = $( '#iftp-llms-connect-submit' );
		var $input = $( '#iftp-llms-backoffice-key' );
		var $message = $( '#iftp-llms-connect-message' );
		var key = String( $input.val() || '' ).trim();

		if ( $button.prop( 'disabled' ) ) {
			return;
		}

		if ( ! key ) {
			$message.text( i18n.enterKey || '' );
			$input.attr( 'aria-invalid', 'true' ).trigger( 'focus' );

			return;
		}

		$button.prop( 'disabled', true ).text( i18n.connecting || '' );
		$input.prop( 'readonly', true ).removeAttr( 'aria-invalid' );
		$message.text( '' );

		apiPost( actions.connect, { backoffice_key: key } )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyFragments( response.data || {} );
					closeModal( $( '#iftp-llms-connect-modal' ), $( '#iftp-llms-gateway-key' )[ 0 ] );
					setSaveState( 'saved', i18n.connected );

					return;
				}

				$message.text( responseMessage( response ) || i18n.error || '' );
				$input.attr( 'aria-invalid', 'true' );
			} )
			.fail( function ( xhr ) {
				$message.text( xhrMessage( xhr ) );
				$input.attr( 'aria-invalid', 'true' );
			} )
			.always( function () {
				$input.val( '' ).prop( 'readonly', false );
				$button.prop( 'disabled', false ).text( i18n.connect || '' );

				if ( ! $( '#iftp-llms-connect-modal' ).prop( 'hidden' ) ) {
					$input.trigger( 'focus' );
				}
			} );
	}

	function disconnect() {
		var $button = $( '#iftp-llms-disconnect-submit' );
		var $message = $( '#iftp-llms-disconnect-message' );

		if ( $button.prop( 'disabled' ) ) {
			return;
		}

		$button.prop( 'disabled', true ).text( i18n.disconnecting || '' );
		$message.text( '' );

		apiPost( actions.disconnect, {} )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Nothing left to save once the configuration is gone.
					window.clearTimeout( saveTimer );
					saveTimer = null;
					saveFailed = false;
					applyFragments( response.data || {} );
					setSaveState( 'idle', '' );
					closeModal( $( '#iftp-llms-disconnect-modal' ), $( '[data-iftp-llms-open-connect]' )[ 0 ] );

					return;
				}

				$message.text( responseMessage( response ) || i18n.error || '' );
			} )
			.fail( function ( xhr ) {
				$message.text( xhrMessage( xhr ) );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( i18n.disconnect || '' );
			} );
	}

	/* Autosave. */

	function validateExpiry() {
		var $input = $( '#iftp-llms-expiry-days' );
		var raw = String( $input.val() || '' ).trim();
		var max = parseInt( cfg.maxExpiry, 10 ) || 365;
		var value = Number( raw );
		var valid = /^\d+$/.test( raw ) && value >= 1 && value <= max;

		if ( valid ) {
			$input.removeAttr( 'aria-invalid' );
		} else {
			$input.attr( 'aria-invalid', 'true' );
		}

		$( '[data-iftp-llms-expiry-error]' ).prop( 'hidden', valid );

		return valid;
	}

	function collectPreferences() {
		var enabled = [];

		$( '[data-iftp-llms-enabled]:checked' ).each( function () {
			enabled.push( String( this.value ) );
		} );

		return {
			enabled: enabled,
			default_method: String( $( '[data-iftp-llms-star]:checked' ).val() || '' ),
			description: String( $( '#iftp-llms-description' ).val() || '' ),
			expiry_days: String( $( '#iftp-llms-expiry-days' ).val() || '' ),
		};
	}

	function resumeSubmit() {
		var button = pendingSubmit;

		if ( button && ! saving && ! saveQueued && ! saveTimer ) {
			pendingSubmit = null;
			button.click();
		}
	}

	// One request at a time; a change made mid-save goes out right after, with the latest values.
	function save() {
		window.clearTimeout( saveTimer );
		saveTimer = null;

		// An invalid expiry is never sent; the field shows why.
		if ( ! validateExpiry() ) {
			resumeSubmit();

			return;
		}

		if ( saving ) {
			saveQueued = true;

			return;
		}

		saving = true;
		setSaveState( 'saving', i18n.saving );

		apiPost( actions.savePreferences, collectPreferences() )
			.done( function ( response ) {
				saveFailed = ! ( response && response.success );

				if ( saveFailed ) {
					setSaveState( 'error', i18n.saveFailed );

					return;
				}

				setSaveState( 'saved', i18n.saved );
				updateReadiness( true, !! ( response.data && response.data.configured ) );
			} )
			.fail( function () {
				saveFailed = true;
				setSaveState( 'error', i18n.saveFailed );
			} )
			.always( function () {
				saving = false;

				if ( saveQueued ) {
					saveQueued = false;
					save();

					return;
				}

				resumeSubmit();
			} );
	}

	function saveSoon() {
		window.clearTimeout( saveTimer );
		saveTimer = window.setTimeout( save, DEBOUNCE );
	}

	function setStar( $star, isDefault ) {
		$star.next( '.iftp-llms-star' ).find( '.iftp-llms-star__icon' )
			.toggleClass( 'dashicons-star-filled', isDefault )
			.toggleClass( 'dashicons-star-empty', ! isDefault );
	}

	// Re-triggered by class, since a CSS-only :checked animation can't replay on the same state.
	function wink( $star ) {
		var $label = $star.next( '.iftp-llms-star' );

		if ( ! $label.length ) {
			return;
		}

		$label.removeClass( 'iftp-llms-star--wink' );
		void $label[ 0 ].offsetWidth;
		$label.addClass( 'iftp-llms-star--wink' );
	}

	/* Events. */

	$( document ).on( 'click', '[data-iftp-llms-open-connect]', function ( e ) {
		e.preventDefault();
		openModal( 'iftp-llms-connect-modal', this );
	} );

	$( document ).on( 'click', '[data-iftp-llms-open-disconnect]', function ( e ) {
		e.preventDefault();
		openModal( 'iftp-llms-disconnect-modal', this );
	} );

	$( document ).on( 'click', '[data-iftp-llms-modal-close]', function ( e ) {
		e.preventDefault();
		closeModal( $( this ).closest( '[data-iftp-llms-modal]' ) );
	} );

	$( document ).on( 'click', '#iftp-llms-connect-submit', function ( e ) {
		e.preventDefault();
		connect();
	} );

	$( document ).on( 'click', '#iftp-llms-disconnect-submit', function ( e ) {
		e.preventDefault();
		disconnect();
	} );

	// Escape closes, Tab stays inside the dialog, Enter in the key field connects.
	$( document ).on( 'keydown', function ( e ) {
		var $modal = openModalEl();
		var $focusable;
		var first;
		var last;

		if ( ! $modal.length ) {
			return;
		}

		if ( 'Escape' === e.key ) {
			e.preventDefault();
			closeModal( $modal );

			return;
		}

		if ( 'Tab' !== e.key ) {
			return;
		}

		$focusable = $modal.find( '.iftp-llms-modal__dialog' ).find( FOCUSABLE ).filter( ':visible' );
		first = $focusable.first()[ 0 ];
		last = $focusable.last()[ 0 ];

		if ( ! first ) {
			return;
		}

		if ( e.shiftKey && ( document.activeElement === first || ! $modal[ 0 ].contains( document.activeElement ) ) ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && ( document.activeElement === last || ! $modal[ 0 ].contains( document.activeElement ) ) ) {
			e.preventDefault();
			first.focus();
		}
	} );

	$( document ).on( 'keydown', '#iftp-llms-backoffice-key', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			connect();
		}
	} );

	$( document ).on( 'change', '[data-iftp-llms-gateway-key]', function () {
		var $select = $( this );
		var previous = $select.data( 'iftpLlmsPrevious' );
		var $spinner = $( '[data-iftp-llms-gk-spinner]' );
		var $methods = $( '#iftp-llms-methods' );

		$select.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );
		$methods.attr( 'aria-busy', 'true' );
		setSaveState( 'saving', i18n.loadingMethods );

		apiPost( actions.selectGatewayKey, { gateway_key: String( $select.val() || '' ) } )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyFragments( response.data || {} );
					$select.data( 'iftpLlmsPrevious', String( $select.val() || '' ) );
					setSaveState( 'saved', i18n.saved );

					return;
				}

				$select.val( previous );
				setSaveState( 'error', responseMessage( response ) || i18n.saveFailed );
			} )
			.fail( function ( xhr ) {
				$select.val( previous );
				setSaveState( 'error', xhrMessage( xhr ) );
			} )
			.always( function () {
				$select.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
				$methods.attr( 'aria-busy', 'false' );
			} );
	} );

	// A method switched off can't stay the default: hide and disable its star, and clear it.
	$( document ).on( 'change', '[data-iftp-llms-enabled]', function () {
		var enabled = $( this ).prop( 'checked' );
		var $row = $( this ).closest( 'tr' );
		var $star = $row.find( '[data-iftp-llms-star]' );

		$star.prop( 'disabled', ! enabled );
		$star.next( '.iftp-llms-star' ).toggleClass( 'iftp-llms-star--hidden', ! enabled );

		if ( ! enabled && $star.prop( 'checked' ) ) {
			$star.prop( 'checked', false );
			setStar( $star, false );
		}

		save();
	} );

	// Native radio group: one default, and clicking the starred one again is a no-op.
	$( document ).on( 'change', '[data-iftp-llms-star]', function () {
		$( '[data-iftp-llms-star]' ).each( function () {
			setStar( $( this ), $( this ).prop( 'checked' ) );
		} );

		wink( $( this ) );
		save();
	} );

	$( document ).on( 'input', '[data-iftp-llms-pref]', function () {
		if ( 'iftp-llms-expiry-days' === this.id && ! validateExpiry() ) {
			window.clearTimeout( saveTimer );
			saveTimer = null;

			return;
		}

		saveSoon();
	} );

	$( document ).on( 'change', '[data-iftp-llms-pref]', save );

	// Our rows sit inside LifterLMS's #mainform; Enter there would submit the whole form.
	$( document ).on( 'keydown', '.iftp-llms-contract input, .iftp-llms-contract__config input', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			save();
		}
	} );

	$( document ).on( 'click', '[data-iftp-llms-request-activation]', function () {
		var $button = $( this );
		var $label = $button.find( '[data-iftp-llms-activation-label]' );
		var original = $label.text();

		$button.prop( 'disabled', true );
		$label.text( i18n.requesting || original );

		apiPost( actions.requestActivation, { entity: String( $button.data( 'entity' ) || '' ) } )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Stays disabled: the server just started this method's 24-hour cooldown.
					$label.text( i18n.requested || original );
					window.alert( i18n.requestSent || '' );

					return;
				}

				$button.prop( 'disabled', false );
				$label.text( original );
				window.alert( responseMessage( response ) || i18n.requestFailed || '' );
			} )
			.fail( function ( xhr ) {
				$button.prop( 'disabled', false );
				$label.text( original );
				window.alert( ( xhr && xhr.responseJSON && responseMessage( xhr.responseJSON ) ) || i18n.requestFailed || '' );
			} );
	} );

	// LifterLMS's Save Changes waits for a pending autosave, then submits as clicked.
	document.addEventListener(
		'click',
		function ( e ) {
			var button = e.target && e.target.closest ? e.target.closest( '#mainform input[name="save"]' ) : null;

			if ( ! button || ! ( saveTimer || saving || saveQueued ) ) {
				return;
			}

			e.preventDefault();
			pendingSubmit = button;

			if ( saveTimer ) {
				save();
			}
		},
		true
	);

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( saveTimer || saving || saveFailed ) {
			e.preventDefault();
			e.returnValue = i18n.unsaved || '';
		}
	} );

	$( function () {
		var $select = $( '[data-iftp-llms-gateway-key]' );

		if ( ! $( '#iftp-llms-contract' ).length ) {
			return;
		}

		$select.data( 'iftpLlmsPrevious', String( $select.val() || '' ) );
	} );
}( jQuery ) );
