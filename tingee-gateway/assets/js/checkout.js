/* global jQuery, tingeeCheckout */
/**
 * Tingee Gateway — JS trang thank-you.
 * Poll trạng thái đơn hàng mỗi 5 giây; khi thanh toán xong → hiện thành công.
 *
 * @package Tingee_Gateway
 */
( function ( $ ) {
	'use strict';

	var pollTimer = null;
	var pollCount = 0;
	var MAX_POLLS = 36; // 36 × 5s = 3 phút tối đa, tránh poll vô hạn.

	/**
	 * Bắt đầu polling nếu trang có hộp QR Tingee.
	 */
	function initPoll() {
		var $box = $( '#tingee-payment-box' );
		if ( ! $box.length ) {
			return;
		}

		var orderId = $box.data( 'order-id' );
		var nonce   = $box.data( 'nonce' );

		if ( ! orderId || ! nonce ) {
			return;
		}

		// Bắt đầu ngay sau 5 giây đầu tiên.
		pollTimer = setInterval( function () {
			pollCount++;

			// Dừng sau MAX_POLLS để tiết kiệm tài nguyên.
			if ( pollCount > MAX_POLLS ) {
				clearInterval( pollTimer );
				return;
			}

			$.ajax( {
				url    : tingeeCheckout.ajaxUrl,
				method : 'POST',
				data   : {
					action   : 'tingee_check_status',
					order_id : orderId,
					nonce    : nonce,
				},
				success: function ( response ) {
					if ( response.success && response.data && response.data.paid ) {
						clearInterval( pollTimer );
						onPaymentSuccess( response.data.redirect_url );
					}
				},
			} );
		}, 5000 );
	}

	/**
	 * Cập nhật UI khi đơn đã được thanh toán xác nhận.
	 *
	 * @param {string} redirectUrl URL trang xem đơn hàng (chuyển hướng sau 3s).
	 */
	function onPaymentSuccess( redirectUrl ) {
		// Thay spinner chờ bằng thông báo thành công.
		$( '#tingee-payment-status' ).html(
			'<span class="tingee-status-success">✓ ' + tingeeCheckout.i18n.paid + '</span>'
		);

		// Ẩn ghi chú "Trang sẽ tự cập nhật...".
		$( '.tingee-payment-box__note' ).hide();

		// Chuyển về trang xem đơn sau 3 giây.
		if ( redirectUrl ) {
			setTimeout( function () {
				window.location.href = redirectUrl;
			}, 3000 );
		}
	}

	$( function () {
		initPoll();
	} );

} )( jQuery );
