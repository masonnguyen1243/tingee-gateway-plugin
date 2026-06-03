/**
 * Tingee Gateway — Admin JS
 *
 * Xử lý nút "Kiểm tra kết nối" trong trang Settings của plugin.
 * File này chỉ được enqueue trên trang Settings của WooCommerce (không load ở trang khác).
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
			return; // Không có nút → thoát sớm.
		}

		$btn.on( 'click', function ( e ) {
			e.preventDefault();

			// Lấy giá trị từ field (chưa lưu cũng được — dùng giá trị đang nhập).
			var clientId    = $( '#woocommerce_tingee_gateway_client_id' ).val();
			var secretToken = $( '#woocommerce_tingee_gateway_secret_token' ).val();

			if ( ! clientId || ! secretToken ) {
				$result
					.removeClass( 'notice-success' )
					.addClass( 'notice notice-error' )
					.text( tingeeAdmin.i18n.fill_credentials )
					.show();
				return;
			}

			// Hiện trạng thái đang kiểm tra.
			$btn.prop( 'disabled', true ).text( tingeeAdmin.i18n.testing );
			$result.hide();

			$.ajax( {
				url    : tingeeAdmin.ajaxUrl,
				type   : 'POST',
				data   : {
					action      : 'tingee_test_connection',
					nonce       : tingeeAdmin.nonce,
					client_id   : clientId,
					secret_token: secretToken,
				},
				success: function ( response ) {
					if ( response.success ) {
						$result
							.removeClass( 'notice-error' )
							.addClass( 'notice notice-success' )
							.text( response.data.message )
							.show();
					} else {
						$result
							.removeClass( 'notice-success' )
							.addClass( 'notice notice-error' )
							.text( response.data.message )
							.show();
					}
				},
				error  : function () {
					$result
						.removeClass( 'notice-success' )
						.addClass( 'notice notice-error' )
						.text( tingeeAdmin.i18n.network_error )
						.show();
				},
				complete: function () {
					$btn.prop( 'disabled', false ).text( tingeeAdmin.i18n.test_btn );
				},
			} );
		} );

	} );

} )( jQuery );
