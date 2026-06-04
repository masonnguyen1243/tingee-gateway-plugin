/* global wc, wp */
/**
 * Tingee Gateway — đăng ký phương thức thanh toán vào WooCommerce Checkout Blocks.
 *
 * File này chạy trong block-based checkout (Gutenberg). Nó đăng ký "tingee_gateway"
 * vào registry của WC Blocks để phương thức xuất hiện cạnh các phương thức khác.
 *
 * Dữ liệu (title, description) được PHP truyền sang qua get_payment_method_data()
 * trong Tingee_Gateway_Blocks, truy cập qua getSetting('tingee_gateway_data').
 *
 * @package Tingee_Gateway
 */
( function () {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting            = wc.wcSettings.getSetting;
	var createElement         = wp.element.createElement;
	var decodeEntities        = wp.htmlEntities.decodeEntities;

	// Lấy dữ liệu do Tingee_Gateway_Blocks::get_payment_method_data() cung cấp.
	var settings    = getSetting( 'tingee_gateway_data', {} );
	var title       = decodeEntities( settings.title || 'Chuyển khoản ngân hàng (Tingee)' );
	var description = decodeEntities( settings.description || '' );

	/**
	 * Nội dung hiển thị bên dưới khi khách chọn phương thức Tingee.
	 * Hiển thị mô tả ngắn; QR thực sự được sinh sau khi đặt hàng (trang thank-you).
	 *
	 * @returns {wp.element.Element|null}
	 */
	function TingeeContent() {
		if ( ! description ) {
			return null;
		}
		return createElement(
			'p',
			{ className: 'tingee-block-description', style: { margin: '8px 0 0', fontSize: '0.9em', color: '#555' } },
			description
		);
	}

	// Đăng ký phương thức thanh toán vào WC Blocks registry.
	registerPaymentMethod( {
		// Phải khớp với $this->name trong Tingee_Gateway_Blocks và $this->id trong Tingee_Gateway.
		name: 'tingee_gateway',

		// Nhãn hiển thị cạnh radio button (string thuần — WC Blocks tự wrap).
		label: title,

		// Nội dung mở rộng khi khách chọn phương thức này (ở checkout).
		content: createElement( TingeeContent, null ),

		// Bản preview trong block editor (wp-admin → trang Checkout).
		edit: createElement( TingeeContent, null ),

		// Luôn trả true — không có điều kiện đặc biệt (VD: không filter theo quốc gia).
		canMakePayment: function () {
			return true;
		},

		// aria-label cho screen readers.
		ariaLabel: title,

		// Danh sách tính năng hỗ trợ — WC Blocks dùng để filter gateway.
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
}() );
