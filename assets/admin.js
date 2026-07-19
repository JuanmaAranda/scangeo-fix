/* global scangeoFixer, jQuery */
( function ( $ ) {
	'use strict';

	function rowByUid( uid ) {
		return $( '#scangeo-issues .scangeo-issue[data-uid="' + uid + '"]' );
	}

	function buildProposalHtml( uid, proposal ) {
		var $wrap = $( '<div class="scangeo-proposal"></div>' ).attr( 'data-uid', uid );
		var count = 0;
		$.each( proposal, function ( url, text ) {
			count++;
			var $item = $( '<div class="scangeo-proposal-item"></div>' ).attr( 'data-url', url );
			$( '<small></small>' ).text( url ).appendTo( $item );
			$( '<textarea class="scangeo-proposal-text" rows="2"></textarea>' )
				.attr( 'data-url', url )
				.val( text )
				.appendTo( $item );
			var $itemActions = $( '<div class="scangeo-proposal-item-actions"></div>' );
			$( '<button type="button" class="button button-primary button-small scangeo-apply-one">Aplicar esta</button>' ).appendTo( $itemActions );
			$( '<button type="button" class="button button-small scangeo-discard-one">Descartar esta</button>' ).appendTo( $itemActions );
			$item.append( $itemActions );
			$wrap.append( $item );
		} );
		if ( count > 1 ) {
			var $bulk = $( '<div class="scangeo-proposal-bulk"></div>' );
			$( '<button type="button" class="button button-primary scangeo-apply-suggestion">Aplicar todas</button>' ).appendTo( $bulk );
			$( '<button type="button" class="button scangeo-discard-suggestion">Descartar todas</button>' ).appendTo( $bulk );
			$wrap.append( $bulk );
		}
		return $wrap;
	}

	/**
	 * Repinta una fila entera a partir de un resultado (status, message,
	 * proposal, undo) tanto si viene de "Reparar" como de "Aplicar"/"Deshacer".
	 */
	function setRowState( $row, status, message, data ) {
		data = data || {};
		$row.attr( 'data-status', status );
		$row.find( '.scangeo-icon' ).attr( 'class', 'scangeo-icon scangeo-icon-' + status );

		var $msgCell = $row.find( '.scangeo-result-msg' ).empty();
		if ( message ) {
			$( '<div class="scangeo-msg-text"></div>' ).text( message ).appendTo( $msgCell );
		} else {
			$msgCell.text( '—' );
		}
		if ( 'suggested' === status && data.proposal ) {
			$msgCell.append( buildProposalHtml( $row.attr( 'data-uid' ), data.proposal ) );
		}

		var $actionCell = $row.find( '.col-action' ).empty();
		if ( 'suggested' === status ) {
			$( '<em>Revisar arriba ↑</em>' ).appendTo( $actionCell );
		} else if ( 'fixed' === status && data.undo ) {
			$( '<button type="button" class="button scangeo-undo-fix">Deshacer</button>' ).appendTo( $actionCell );
		} else if ( 'manual' === status ) {
			$( '<em class="scangeo-manual-note">Solución manual</em>' ).appendTo( $actionCell );
		} else {
			var $btn = $( '<button type="button" class="button scangeo-fix-one">Reparar</button>' );
			if ( 'fixed' === status || 'fixing' === status ) {
				$btn.prop( 'disabled', true );
			}
			if ( 'fixing' === status ) {
				$btn.text( scangeoFixer.i18n.fixing );
			}
			$actionCell.append( $btn );
		}
		updateCount();
	}

	function updateCount() {
		var fixed = $( '#scangeo-issues .scangeo-issue[data-status="fixed"]' ).length;
		$( '#scangeo-fixed-count' ).text( fixed );
	}

	function fixIssue( $row ) {
		var issueId = $row.data( 'issue' );
		var uid     = $row.attr( 'data-uid' ) || '';
		setRowState( $row, 'fixing', scangeoFixer.i18n.fixing );

		return $.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_fix_issue',
			nonce: scangeoFixer.nonce,
			issue_id: issueId,
			uid: uid
		} ).then(
			function ( res ) {
				if ( res && res.success && res.data ) {
					setRowState( $row, res.data.status, res.data.message, res.data );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : scangeoFixer.i18n.failed;
					setRowState( $row, 'failed', msg );
				}
			},
			function () {
				setRowState( $row, 'failed', scangeoFixer.i18n.failed + ' (error de red o timeout)' );
			}
		);
	}

	$( document ).on( 'click', '.scangeo-fix-one', function () {
		fixIssue( $( this ).closest( '.scangeo-issue' ) );
	} );

	/* ------------------------- Propuestas de IA ------------------------- */

	$( document ).on( 'click', '.scangeo-apply-one', function () {
		var $btn  = $( this ).prop( 'disabled', true ).text( 'Aplicando…' );
		var $item = $btn.closest( '.scangeo-proposal-item' );
		var $wrap = $btn.closest( '.scangeo-proposal' );
		var uid   = $wrap.data( 'uid' );
		var $row  = rowByUid( uid );
		var url   = $item.data( 'url' );
		var edited = {};
		edited[ url ] = $item.find( '.scangeo-proposal-text' ).val();

		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_apply_suggestion',
			nonce: scangeoFixer.nonce,
			uid: uid,
			edited: JSON.stringify( edited )
		} ).then(
			function ( res ) {
				if ( res && res.success && res.data ) {
					setRowState( $row, res.data.status, res.data.message, res.data );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : scangeoFixer.i18n.failed;
					window.alert( msg );
					$btn.prop( 'disabled', false ).text( 'Aplicar esta' );
				}
			},
			function () {
				window.alert( 'Error de red al aplicar la propuesta.' );
				$btn.prop( 'disabled', false ).text( 'Aplicar esta' );
			}
		);
	} );

	$( document ).on( 'click', '.scangeo-discard-one', function () {
		var $btn  = $( this ).prop( 'disabled', true ).text( 'Descartando…' );
		var $item = $btn.closest( '.scangeo-proposal-item' );
		var $wrap = $btn.closest( '.scangeo-proposal' );
		var uid   = $wrap.data( 'uid' );
		var $row  = rowByUid( uid );
		var url   = $item.data( 'url' );

		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_discard_suggestion',
			nonce: scangeoFixer.nonce,
			uid: uid,
			url: url
		} ).then(
			function ( res ) {
				if ( res && res.success && res.data ) {
					setRowState( $row, res.data.status, res.data.message, res.data );
				} else {
					$btn.prop( 'disabled', false ).text( 'Descartar esta' );
				}
			},
			function () {
				$btn.prop( 'disabled', false ).text( 'Descartar esta' );
			}
		);
	} );

	$( document ).on( 'click', '.scangeo-apply-suggestion', function () {
		var $btn  = $( this ).prop( 'disabled', true ).text( 'Aplicando…' );
		var $wrap = $btn.closest( '.scangeo-proposal' );
		var uid   = $wrap.data( 'uid' );
		var $row  = rowByUid( uid );
		var edited = {};
		$wrap.find( '.scangeo-proposal-text' ).each( function () {
			edited[ $( this ).data( 'url' ) ] = $( this ).val();
		} );

		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_apply_suggestion',
			nonce: scangeoFixer.nonce,
			uid: uid,
			edited: JSON.stringify( edited )
		} ).then(
			function ( res ) {
				if ( res && res.success && res.data ) {
					setRowState( $row, res.data.status, res.data.message, res.data );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : scangeoFixer.i18n.failed;
					window.alert( msg );
					$btn.prop( 'disabled', false ).text( 'Aplicar todas' );
				}
			},
			function () {
				window.alert( 'Error de red al aplicar la propuesta.' );
				$btn.prop( 'disabled', false ).text( 'Aplicar todas' );
			}
		);
	} );

	$( document ).on( 'click', '.scangeo-discard-suggestion', function () {
		var $wrap = $( this ).closest( '.scangeo-proposal' );
		var uid   = $wrap.data( 'uid' );
		var $row  = rowByUid( uid );

		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_discard_suggestion',
			nonce: scangeoFixer.nonce,
			uid: uid
		} ).always( function () {
			setRowState( $row, 'pending', '—', {} );
		} );
	} );

	/* ------------------------- Pestañas por categoría ------------------------- */

	$( document ).on( 'click', '.scangeo-cat-tab', function () {
		var $tab = $( this );
		var cat  = $tab.data( 'category' );

		$( '.scangeo-cat-tab' ).removeClass( 'is-active' );
		$tab.addClass( 'is-active' );

		$( '#scangeo-issues .scangeo-issue' ).each( function () {
			var show = ( 'all' === cat ) || ( $( this ).data( 'category' ) === cat );
			$( this ).toggle( show );
		} );
	} );

	/* ------------------------------ Deshacer ----------------------------- */

	$( document ).on( 'click', '.scangeo-undo-fix', function () {
		var $btn = $( this ).prop( 'disabled', true ).text( 'Deshaciendo…' );
		var $row = $btn.closest( '.scangeo-issue' );
		var uid  = $row.attr( 'data-uid' );

		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_undo_fix',
			nonce: scangeoFixer.nonce,
			uid: uid
		} ).then(
			function ( res ) {
				if ( res && res.success && res.data ) {
					setRowState( $row, 'pending', res.data.message, {} );
				} else {
					$btn.prop( 'disabled', false ).text( 'Deshacer' );
				}
			},
			function () {
				$btn.prop( 'disabled', false ).text( 'Deshacer' );
			}
		);
	} );

	/* ------------------------- Ajustes: clave API ------------------------- */

	function setKeyStatus( state, message ) {
		$( '#scangeo-key-status' )
			.attr( 'class', 'scangeo-key-status scangeo-key-' + state )
			.text( message || '' );
	}

	function populateModels( models, selected ) {
		var $sel = $( '#scangeo-model-select' ).empty();
		$( '<option>' ).val( '' ).text( '— Modelo por defecto —' ).appendTo( $sel );
		models.forEach( function ( m ) {
			var $opt = $( '<option>' ).val( m.id ).text( m.label );
			if ( m.id === selected ) {
				$opt.prop( 'selected', true );
			}
			$sel.append( $opt );
		} );
		$sel.prop( 'disabled', false );
		$( '#scangeo-model-row' ).show();
	}

	function verifyKey( apiKey ) {
		setKeyStatus( 'checking', scangeoFixer.i18n.verifying );
		$( '#scangeo-verify-key' ).prop( 'disabled', true );

		return $.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_verify_key',
			nonce: scangeoFixer.nonce,
			provider: $( '#scangeo-provider' ).val(),
			api_key: apiKey
		} ).then(
			function ( res ) {
				$( '#scangeo-verify-key' ).prop( 'disabled', false );
				if ( res && res.success && res.data ) {
					setKeyStatus( 'ok', scangeoFixer.i18n.verified );
					$( '#scangeo-api-key' ).val( '' ).attr( 'placeholder', '••••••••  (clave guardada)' );
					populateModels( res.data.models || [], res.data.model || scangeoFixer.model );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : 'Error';
					setKeyStatus( 'error', msg );
					$( '#scangeo-model-row' ).hide();
				}
			},
			function () {
				$( '#scangeo-verify-key' ).prop( 'disabled', false );
				setKeyStatus( 'error', 'Error de red al verificar.' );
			}
		);
	}

	$( document ).on( 'click', '#scangeo-verify-key', function () {
		var key = $.trim( $( '#scangeo-api-key' ).val() );
		if ( ! key && $( '#scangeo-key-status' ).data( 'saved' ) !== 1 ) {
			setKeyStatus( 'error', scangeoFixer.i18n.needKey );
			return;
		}
		verifyKey( key );
	} );

	function renderIncludedQuota( limit, remaining ) {
		var $box = $( '#scangeo-included-quota' ).empty();
		if ( null === limit || undefined === limit ) {
			$( '<span class="description"></span>' ).text( 'No se ha podido comprobar la cuota ahora mismo. Se reintentará automáticamente.' ).appendTo( $box );
			return;
		}
		$( '<strong></strong>' ).text( remaining + ' de ' + limit ).appendTo( $box );
		$box.append( ' consultas gratis restantes este mes.' );
		if ( 0 === remaining ) {
			$( '<p class="description"></p>' ).text( 'Se ha agotado la cuota de este mes. Cambia a tu propia clave arriba para seguir sin límite, o espera a que se renueve el mes que viene.' ).appendTo( $box );
		}
	}

	$( document ).on( 'change', '#scangeo-provider', function () {
		var provider = $( this ).val();
		if ( 'included' === provider ) {
			$( '#scangeo-included-row' ).show();
			$( '#scangeo-own-key-fields' ).hide();
			$( '#scangeo-included-quota' ).text( 'Comprobando cuota…' );
			$.post( scangeoFixer.ajaxUrl, {
				action: 'scangeo_use_included',
				nonce: scangeoFixer.nonce
			} ).then(
				function ( res ) {
					if ( res && res.success && res.data ) {
						renderIncludedQuota( res.data.limit, res.data.remaining );
					} else {
						renderIncludedQuota( null, null );
					}
				},
				function () {
					renderIncludedQuota( null, null );
				}
			);
		} else {
			$( '#scangeo-included-row' ).hide();
			$( '#scangeo-own-key-fields' ).show();
			setKeyStatus( '', '' );
			$( '#scangeo-model-row' ).hide();
			$( '#scangeo-api-key' ).attr( 'placeholder', 'sk-…' );
		}
	} );

	$( document ).on( 'change', '#scangeo-model-select', function () {
		var model = $( this ).val();
		$( '#scangeo-model-status' ).attr( 'class', 'scangeo-key-status scangeo-key-checking' ).text( '…' );
		$.post( scangeoFixer.ajaxUrl, {
			action: 'scangeo_save_model',
			nonce: scangeoFixer.nonce,
			model: model
		} ).always( function () {
			$( '#scangeo-model-status' ).attr( 'class', 'scangeo-key-status scangeo-key-ok' ).text( scangeoFixer.i18n.modelSaved );
		} );
	} );

	$( function () {
		if ( $( '#scangeo-verify-key' ).length && scangeoFixer.keySaved ) {
			verifyKey( '' );
		}
	} );

	/* --------------------------- Reparar todo ---------------------------- */

	$( document ).on( 'click', '#scangeo-fix-all', function () {
		if ( ! window.confirm( scangeoFixer.i18n.confirm ) ) {
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );
		// Las filas con propuesta pendiente ('suggested') se dejan fuera:
		// necesitan revisión humana, no se aplican en bloque.
		var rows = $( '#scangeo-issues .scangeo-issue' ).filter( function () {
			var st = $( this ).attr( 'data-status' );
			return st !== 'fixed' && st !== 'suggested' && st !== 'manual';
		} ).toArray();

		( function next() {
			if ( ! rows.length ) {
				$btn.prop( 'disabled', false );
				return;
			}
			var row = rows.shift();
			fixIssue( $( row ) ).always( function () {
				window.setTimeout( next, 400 );
			} );
		} )();
	} );
} )( jQuery );
