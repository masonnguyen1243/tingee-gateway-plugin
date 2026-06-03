<?php
/**
 * WooCommerce Payment Gateway chính của Tingee.
 *
 * File này sẽ được triển khai đầy đủ ở Giai đoạn 3 & 4.
 * Hiện tại khai báo class extends WC_Payment_Gateway với thông tin cơ bản
 * để plugin xuất hiện trong WooCommerce → Settings → Payments.
 *
 * @package Tingee_Gateway
 */

// Ngăn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tingee_Gateway
 *
 * Extends WC_Payment_Gateway — đây là class chính xử lý:
 * - Hiển thị phương thức thanh toán trên checkout.
 * - Xử lý đơn hàng khi khách chọn Tingee (process_payment).
 * - Hiển thị QR và thông tin chuyển khoản.
 *
 * Sẽ triển khai đầy đủ ở Task T3.1 – T4.4.
 */
class Tingee_Gateway extends WC_Payment_Gateway {

	/**
	 * Khởi tạo gateway — thiết lập ID, tiêu đề, mô tả cơ bản.
	 */
	public function __construct() {
		// ID duy nhất của gateway này trong WooCommerce.
		$this->id = 'tingee_gateway';

		// Icon hiển thị cạnh tên gateway ở trang checkout (để trống tạm thời).
		$this->icon = '';

		// Có form thanh toán hiển thị trên checkout không? Không — dùng redirect/QR.
		$this->has_fields = false;

		// Tiêu đề và mô tả hiển thị trong trang Settings WooCommerce.
		$this->method_title       = __( 'Tingee Gateway', 'tingee-gateway' );
		$this->method_description = __( 'Thanh toán qua QR chuyển khoản ngân hàng sử dụng cổng Tingee (by HENO).', 'tingee-gateway' );

		// Nạp các field cấu hình (sẽ triển khai đầy đủ ở T3.2).
		$this->init_form_fields();

		// Nạp giá trị settings đã lưu.
		$this->init_settings();

		// Đọc giá trị từ settings để dùng trong plugin.
		$this->title       = $this->get_option( 'title', __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Quét mã QR để chuyển khoản. Đơn hàng tự động xác nhận sau khi thanh toán.', 'tingee-gateway' ) );

		// Lưu settings khi admin nhấn Save.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Khai báo các field cấu hình trong trang Settings.
	 * Sẽ triển khai đầy đủ ở Task T3.2.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Bật/Tắt', 'tingee-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Bật Tingee Gateway', 'tingee-gateway' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Tiêu đề', 'tingee-gateway' ),
				'type'        => 'text',
				'description' => __( 'Tên phương thức thanh toán hiển thị cho khách hàng.', 'tingee-gateway' ),
				'default'     => __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Mô tả', 'tingee-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Mô tả ngắn hiển thị khi khách chọn phương thức này.', 'tingee-gateway' ),
				'default'     => __( 'Quét mã QR để chuyển khoản. Đơn hàng tự động xác nhận sau khi thanh toán.', 'tingee-gateway' ),
			),
		);
	}

	/**
	 * Xử lý thanh toán khi khách đặt hàng.
	 * Sẽ triển khai đầy đủ ở Task T4.1.
	 *
	 * @param int $order_id ID đơn hàng WooCommerce.
	 * @return array Kết quả redirect.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Tạm thời: đặt đơn về On-Hold và redirect về trang thanh toán.
		// Logic QR + Tingee API sẽ được thêm vào ở Giai đoạn 4.
		$order->update_status( 'on-hold', __( 'Chờ thanh toán qua Tingee.', 'tingee-gateway' ) );

		// Xóa giỏ hàng.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
