/**
 * Tingee Gateway — Admin JS
 *
 * Xử lý trang Settings của plugin:
 * - Nút "Kiểm tra kết nối": xác minh credentials.
 * - Sau khi kết nối thành công: tự động tải danh sách tài khoản VA từ Tingee.
 * - Cho phép admin chọn tài khoản nhận tiền, tự động điền các hidden inputs.
 *
 * @package Tingee_Gateway
 * @since   1.0.0
 */

/* global tingeeAdmin, jQuery */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {

		var $btn    = $( '#tingee-test-connection' );
		var $result = $( '#tingee-connection-result' );

		if ( ! $btn.length ) {
			return;
		}

		// ------------------------------------------------------------------
		// Nút "Kiểm tra kết nối"
		// ------------------------------------------------------------------

		$btn.on( 'click', function ( e ) {
			e.preventDefault();

			var clientId    = $( '#woocommerce_tingee_gateway_client_id' ).val();
			var secretToken = $( '#woocommerce_tingee_gateway_secret_token' ).val();

			if ( ! clientId || ! secretToken ) {
				showResult( 'error', tingeeAdmin.i18n.fill_credentials );
				return;
			}

			$btn.prop( 'disabled', true ).text( tingeeAdmin.i18n.testing );
			$result.hide();

			$.ajax( {
				url  : tingeeAdmin.ajaxUrl,
				type : 'POST',
				data : {
					action       : 'tingee_test_connection',
					nonce        : tingeeAdmin.nonce,
					client_id    : clientId,
					secret_token : secretToken,
				},
				success: function ( response ) {
					if ( response.success ) {
						showResult( 'success', response.data.message );
						// Kết nối OK → tải danh sách tài khoản VA.
						fetchVAAccounts( clientId, secretToken );
					} else {
						showResult( 'error', response.data.message );
					}
				},
				error: function () {
					showResult( 'error', tingeeAdmin.i18n.network_error );
				},
				complete: function () {
					$btn.prop( 'disabled', false ).text( tingeeAdmin.i18n.test_btn );
				},
			} );
		} );

		// ------------------------------------------------------------------
		// Helpers hiển thị kết quả
		// ------------------------------------------------------------------

		function showResult( type, msg ) {
			$result
				.removeClass( 'notice-success notice-error' )
				.addClass( 'notice notice-' + type )
				.text( msg )
				.show();
		}

		// ------------------------------------------------------------------
		// Tải danh sách tài khoản VA từ Tingee
		// ------------------------------------------------------------------

		function fetchVAAccounts( clientId, secretToken ) {
			var $list    = $( '#tingee-va-accounts-list' );
			var $current = $( '#tingee-va-current' );
			var $prompt  = $( '#tingee-va-prompt' );

			$prompt.hide();
			$list.html( '<p><em>' + tingeeAdmin.i18n.loading_accounts + '</em></p>' ).show();

			$.ajax( {
				url  : tingeeAdmin.ajaxUrl,
				type : 'POST',
				data : {
					action       : 'tingee_fetch_accounts',
					nonce        : tingeeAdmin.nonce,
					client_id    : clientId,
					secret_token : secretToken,
				},
				success: function ( response ) {
					if ( ! response.success || ! response.data.accounts || ! response.data.accounts.length ) {
						$list.html( '<p class="description">' + tingeeAdmin.i18n.no_accounts + '</p>' );
						return;
					}
					$current.hide();
					renderAccounts( response.data.accounts );
				},
				error: function () {
					$list.html( '<p class="notice notice-error" style="padding:6px 12px;">' + tingeeAdmin.i18n.network_error + '</p>' );
				},
			} );
		}

		// ------------------------------------------------------------------
		// Render danh sách accounts
		// ------------------------------------------------------------------

		function renderAccounts( accounts ) {
			var $list      = $( '#tingee-va-accounts-list' );
			var selectedVA = $( '#woocommerce_tingee_gateway_va_account_number' ).val();

			$list.empty();

			if ( accounts.length === 1 ) {
				// Chỉ 1 tài khoản → tự động chọn, không cần dropdown.
				selectAccount( accounts[ 0 ] );
				$list.append( buildAccountCard( accounts[ 0 ], true ) );
			} else {
				// Nhiều tài khoản → hiển thị danh sách để chọn.
				$list.append( $( '<p><strong>' + tingeeAdmin.i18n.select_account + '</strong></p>' ) );
				$.each( accounts, function ( _i, acc ) {
					var isSelected = ( acc.vaAccountNumber === selectedVA );
					$list.append( buildAccountCard( acc, isSelected ) );
					if ( isSelected ) {
						selectAccount( acc );
					}
				} );
			}
		}

		function buildAccountCard( acc, isSelected ) {
			var bankDisplay = acc.bankShortName || acc.bankFullName || '';
			var bgColor     = isSelected ? '#f0f6fc' : '#fff';
			var borderColor = isSelected ? '#72aee6' : '#c3c4c7';

			var labelHtml = '<strong>' + escHtml( bankDisplay ) + '</strong>'
				+ ' &mdash; ' + escHtml( acc.vaAccountNumber )
				+ ( acc.accountName ? ' <span style="color:#757575;">(' + escHtml( acc.accountName ) + ')</span>' : '' );

			var $card = $( '<div class="tingee-va-card"></div>' );
			var $label = $( '<label style="cursor:pointer; display:flex; align-items:center; gap:8px; padding:8px 12px; border:1px solid; border-radius:4px; margin-bottom:6px;"></label>' )
				.css( { background: bgColor, borderColor: borderColor } );
			var $radio = $( '<input type="radio" name="tingee_va_select">' )
				.val( acc.vaAccountNumber )
				.prop( 'checked', isSelected );

			$label.append( $radio ).append( $( '<span>' ).html( labelHtml ) );
			$card.append( $label );

			$card.on( 'click', function () {
				selectAccount( acc );
				$( '.tingee-va-card label' ).css( { background: '#fff', borderColor: '#c3c4c7' } );
				$( this ).find( 'label' ).css( { background: '#f0f6fc', borderColor: '#72aee6' } );
				$( '.tingee-va-card input[type="radio"]' ).prop( 'checked', false );
				$( this ).find( 'input[type="radio"]' ).prop( 'checked', true );
			} );

			return $card;
		}

		// ------------------------------------------------------------------
		// Điền giá trị vào hidden inputs khi chọn tài khoản
		// ------------------------------------------------------------------

		function selectAccount( acc ) {
			$( '#woocommerce_tingee_gateway_va_account_number' ).val( acc.vaAccountNumber );
			$( '#woocommerce_tingee_gateway_bank_bin' ).val( acc.bankBin );
			$( '#woocommerce_tingee_gateway_bank_name_full' ).val( acc.bankFullName );
			$( '#woocommerce_tingee_gateway_bank_name_short' ).val( acc.bankShortName );
		}

		// ------------------------------------------------------------------
		// Utility: escape HTML để tránh XSS khi render dữ liệu từ API
		// ------------------------------------------------------------------

		function escHtml( str ) {
			return $( '<div>' ).text( String( str ) ).html();
		}

	} );

} )( jQuery );
