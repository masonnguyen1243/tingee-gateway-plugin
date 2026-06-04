<?php
/**
 * Tích hợp WooCommerce Checkout Blocks cho Tingee Gateway.
 *
 * Class này extends AbstractPaymentMethodType để phương thức Tingee hiển thị
 * và hoạt động trong WooCommerce block-based checkout (không chỉ shortcode cũ).
 *
 * @package Tingee_Gateway
 */

// Ngăn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class Tingee_Gateway_Blocks
 *
 * Đăng ký Tingee Gateway vào hệ thống WooCommerce Checkout Blocks.
 * Được khởi tạo và đăng ký qua hook woocommerce_blocks_payment_method_type_registration
 * (xem tingee_register_block_payment_method() trong tingee-gateway.php).
 */
final class Tingee_Gateway_Blocks extends AbstractPaymentMethodType {

	/**
	 * Tên gateway — phải khớp với $this->id trong Tingee_Gateway.
	 *
	 * @var string
	 */
	protected $name = 'tingee_gateway';

	/**
	 * Instance của Tingee_Gateway (để đọc title, description, is_available).
	 *
	 * @var Tingee_Gateway|null
	 */
	private $gateway;

	/**
	 * Khởi tạo — nạp settings và lấy instance gateway chính.
	 * WooCommerce gọi hàm này khi build danh sách block payment methods.
	 */
	public function initialize() {
		// Đọc settings thô từ wp_options (dự phòng nếu gateway chưa khởi tạo).
		$this->settings = get_option( 'woocommerce_tingee_gateway_settings', array() );

		// Lấy instance gateway đã đăng ký để dùng is_available(), title, description.
		$gateways      = WC()->payment_gateways->payment_gateways();
		$this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Trả về true nếu gateway đang bật và có thể thanh toán (is_available()).
	 * WC Blocks dùng giá trị này để quyết định có hiển thị phương thức không.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway instanceof WC_Payment_Gateway && $this->gateway->is_available();
	}

	/**
	 * Trả về danh sách JS handle cho WC Blocks load khi render checkout.
	 * Handle phải được register trước (dùng wp_register_script bên dưới).
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'tingee-blocks',
			TINGEE_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
			TINGEE_PLUGIN_VERSION,
			true // Load ở footer.
		);

		// Cho phép dịch chuỗi trong file JS nếu có file .po tương ứng.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'tingee-blocks', 'tingee-gateway' );
		}

		return array( 'tingee-blocks' );
	}

	/**
	 * Trả về dữ liệu PHP truyền sang JS — accessible trong blocks.js qua:
	 *   wc.wcSettings.getSetting('tingee_gateway_data').
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway
				? $this->gateway->title
				: $this->get_setting( 'title', __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ) ),
			'description' => $this->gateway
				? $this->gateway->description
				: $this->get_setting( 'description', '' ),
			// supports: danh sách tính năng gateway hỗ trợ — dùng để WC Blocks filter.
			'supports'    => $this->get_supported_features(),
		);
	}
}
