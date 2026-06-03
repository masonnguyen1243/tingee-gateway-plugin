<?php
/**
 * Dọn dẹp dữ liệu khi người dùng GỠ CÀI ĐẶT plugin (không phải chỉ deactivate).
 *
 * WordPress chỉ chạy file này khi người dùng nhấn "Delete" plugin trong Admin.
 * Nếu người dùng bật "Giữ dữ liệu khi gỡ" trong Settings → bỏ qua việc xóa.
 *
 * @package Tingee_Gateway
 */

// Bảo mật: chỉ cho phép WordPress gọi file này, không cho truy cập trực tiếp.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Kiểm tra cờ "Giữ dữ liệu khi gỡ"
// Nếu người dùng đã bật tùy chọn này trong cấu hình plugin → không xóa gì cả.
// ---------------------------------------------------------------------------
$gateway_settings = get_option( 'woocommerce_tingee_gateway_settings', array() );
$keep_data        = isset( $gateway_settings['keep_data_on_uninstall'] ) ? $gateway_settings['keep_data_on_uninstall'] : 'no';

if ( 'yes' === $keep_data ) {
	// Người dùng muốn giữ dữ liệu → thoát, không xóa.
	return;
}

// ---------------------------------------------------------------------------
// Xóa toàn bộ settings của plugin khỏi wp_options.
// ---------------------------------------------------------------------------

// Settings chính của gateway (lưu bởi WooCommerce Settings API).
delete_option( 'woocommerce_tingee_gateway_settings' );

// ---------------------------------------------------------------------------
// Lưu ý: KHÔNG xóa order meta (tingee_transaction_id, v.v.) vì đó là
// lịch sử đơn hàng quan trọng — khách hàng cần để tra cứu về sau.
// ---------------------------------------------------------------------------
