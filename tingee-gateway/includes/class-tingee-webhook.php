<?php
/**
 * Nhận và xử lý Webhook IPN từ Tingee.
 *
 * File này sẽ được triển khai đầy đủ ở Giai đoạn 5.
 * Hiện tại chỉ khai báo class rỗng để plugin có thể kích hoạt mà không lỗi.
 *
 * @package Tingee_Gateway
 */

// Ngăn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tingee_Webhook
 *
 * Chịu trách nhiệm:
 * - Đăng ký REST endpoint nhận POST từ Tingee.
 * - Xác thực chữ ký HMAC-SHA512 trong header x-signature.
 * - Parse payload, tìm đơn hàng, gạch nợ khi thanh toán thành công.
 * - Đảm bảo idempotency (không xử lý trùng webhook).
 *
 * Sẽ triển khai đầy đủ ở Task T5.1 – T5.6.
 */
class Tingee_Webhook {
	// Nội dung sẽ được thêm vào ở Giai đoạn 5.
}
