<?php
/**
 * WooCommerce Payment Gateway chính của Tingee.
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
 */
class Tingee_Gateway extends WC_Payment_Gateway {

	/**
	 * Số tài khoản VA của merchant dùng để nhận thanh toán.
	 *
	 * @var string
	 */
	public $va_account_number;

	/**
	 * Chế độ tích hợp: 'mode_a' (QR + Webhook) hoặc 'mode_b' (Redirect).
	 *
	 * @var string
	 */
	public $integration_mode;

	/**
	 * Khởi tạo gateway — thiết lập ID, tiêu đề, mô tả, và đọc toàn bộ settings.
	 */
	public function __construct() {
		// ID duy nhất của gateway này trong WooCommerce.
		$this->id = 'tingee_gateway';

		// Icon hiển thị cạnh tên gateway ở trang checkout (để trống tạm thời).
		$this->icon = '';

		// Có form thanh toán hiển thị trực tiếp trên checkout không? Không — dùng redirect/QR.
		$this->has_fields = false;

		// Tiêu đề và mô tả hiển thị trong trang Settings WooCommerce (không phải trang checkout).
		$this->method_title       = __( 'Tingee Gateway', 'tingee-gateway' );
		$this->method_description = __( 'Thanh toán qua QR chuyển khoản ngân hàng sử dụng cổng Tingee (by HENO).', 'tingee-gateway' );

		// Nạp các field cấu hình.
		$this->init_form_fields();

		// Nạp giá trị settings đã lưu vào $this->settings.
		$this->init_settings();

		// --- Đọc từng option ra biến riêng để dùng trong toàn plugin ---

		// Hiển thị ở trang checkout phía khách.
		$this->title       = $this->get_option( 'title', __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Quét mã QR để chuyển khoản. Đơn hàng tự động xác nhận sau khi thanh toán.', 'tingee-gateway' ) );

		// Credentials và tài khoản VA.
		$this->va_account_number = $this->get_option( 'va_account_number', '' );

		// Chế độ tích hợp A hoặc B.
		$this->integration_mode = $this->get_option( 'integration_mode', 'mode_a' );

		// Lưu settings khi admin nhấn nút "Save changes".
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Enqueue JS admin chỉ khi đang ở trang Settings của gateway này.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Enqueue file JS admin.js và truyền biến cần thiết (ajaxUrl, nonce, i18n) vào JS.
	 * Chỉ load trên trang Settings → Payments của WooCommerce.
	 *
	 * @param string $hook Tên hook của trang admin hiện tại.
	 */
	public function admin_enqueue_scripts( $hook ) {
		// Chỉ load trên trang WooCommerce Settings.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// Chỉ load khi đang xem tab payment và section của gateway này.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'checkout' !== $tab || $this->id !== $section ) {
			return;
		}

		wp_enqueue_script(
			'tingee-admin',
			TINGEE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TINGEE_PLUGIN_VERSION,
			true // Load ở footer.
		);

		// Truyền dữ liệu PHP sang JS qua wp_localize_script.
		wp_localize_script(
			'tingee-admin',
			'tingeeAdmin', // Tên biến JS toàn cục.
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tingee_test_connection_nonce' ),
				'i18n'    => array(
					'test_btn'         => __( 'Kiểm tra kết nối', 'tingee-gateway' ),
					'testing'          => __( 'Đang kiểm tra...', 'tingee-gateway' ),
					'fill_credentials' => __( 'Vui lòng nhập Client ID và Secret Token trước.', 'tingee-gateway' ),
					'network_error'    => __( 'Lỗi kết nối mạng. Vui lòng thử lại.', 'tingee-gateway' ),
				),
			)
		);
	}

	/**
	 * Khai báo toàn bộ field cấu hình trong trang Settings — theo product-spec F3.
	 *
	 * Cấu trúc 2 nhóm:
	 *  1. Hiển thị tại checkout (tiêu đề, mô tả).
	 *  2. Kết nối & cấu hình Tingee (môi trường, credentials, VA account, chế độ A/B).
	 */
	public function init_form_fields() {
		$this->form_fields = array(

			// ================================================================
			// NHÓM 1 — Hiển thị tại trang checkout
			// ================================================================

			'enabled' => array(
				'title'   => __( 'Bật/Tắt', 'tingee-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Bật Tingee Gateway', 'tingee-gateway' ),
				'default' => 'no',
			),

			'title' => array(
				'title'       => __( 'Tiêu đề', 'tingee-gateway' ),
				'type'        => 'text',
				'description' => __( 'Tên phương thức thanh toán hiển thị cho khách hàng ở trang checkout.', 'tingee-gateway' ),
				'default'     => __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ),
				'desc_tip'    => true,
			),

			'description' => array(
				'title'       => __( 'Mô tả', 'tingee-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Mô tả ngắn hiển thị khi khách chọn phương thức này.', 'tingee-gateway' ),
				'default'     => __( 'Quét mã QR để chuyển khoản. Đơn hàng tự động xác nhận sau khi thanh toán.', 'tingee-gateway' ),
			),

			// ================================================================
			// NHÓM 2 — Kết nối & cấu hình Tingee
			// ================================================================

			'connection_section' => array(
				'title'       => __( 'Kết nối Tingee', 'tingee-gateway' ),
				'type'        => 'title',
				/* translators: %s: link đến trang Developers Tingee */
				'description' => sprintf(
					__( 'Lấy Client ID và Secret Token tại <a href="%s" target="_blank" rel="noopener noreferrer">trang Developers của Tingee</a>.', 'tingee-gateway' ),
					esc_url( 'https://app.tingee.vn/m/developers' )
				),
			),

			'environment' => array(
				'title'       => __( 'Môi trường', 'tingee-gateway' ),
				'type'        => 'select',
				'description' => __( 'Dùng Sandbox (UAT) để test, Production cho website thật.', 'tingee-gateway' ),
				'desc_tip'    => true,
				'default'     => 'production',
				'options'     => array(
					'production' => __( 'Production (thật)', 'tingee-gateway' ),
					'sandbox'    => __( 'Sandbox / UAT (test)', 'tingee-gateway' ),
				),
			),

			'client_id' => array(
				'title'       => __( 'Client ID', 'tingee-gateway' ),
				'type'        => 'text',
				'description' => __( 'Mã định danh đối tác do Tingee cung cấp. Header x-client-id trong mọi API request.', 'tingee-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
				'placeholder' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
			),

			'secret_token' => array(
				'title'       => __( 'Secret Token', 'tingee-gateway' ),
				'type'        => 'password', // Ẩn giá trị như mật khẩu.
				'description' => __( 'Khóa bí mật để sinh chữ ký HMAC-SHA512. Không chia sẻ cho bất kỳ ai.', 'tingee-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),

			'va_account_number' => array(
				'title'       => __( 'Số tài khoản VA nhận tiền', 'tingee-gateway' ),
				'type'        => 'text',
				'description' => __( 'Số tài khoản ảo (Virtual Account — vaAccountNumber) của merchant trên Tingee. Dùng để hiển thị QR cho khách và nhận thanh toán. Lấy trong mục quản lý tài khoản tại app.tingee.vn.', 'tingee-gateway' ),
				'desc_tip'    => false, // Hiển thị đầy đủ mô tả vì admin cần đọc rõ.
				'default'     => '',
				'placeholder' => 'VD: 9704000000000018',
			),

			// Nút kiểm tra kết nối — render bởi generate_test_connection_button_html().
			'test_connection' => array(
				'title'       => __( 'Kiểm tra kết nối', 'tingee-gateway' ),
				'type'        => 'test_connection_button',
				'description' => __( 'Bấm để kiểm tra Client ID và Secret Token có hợp lệ không (chưa cần lưu).', 'tingee-gateway' ),
			),

			'integration_mode' => array(
				'title'       => __( 'Chế độ tích hợp', 'tingee-gateway' ),
				'type'        => 'select',
				/* translators: thẻ strong để làm đậm tên chế độ */
				'description' => __( '<strong>Chế độ A (khuyên dùng)</strong>: Hiển thị QR trực tiếp trên website, tự động xác nhận qua Webhook — khách không rời trang. <strong>Chế độ B</strong>: Chuyển hướng khách sang trang thanh toán của Tingee.', 'tingee-gateway' ),
				'desc_tip'    => false,
				'default'     => 'mode_a',
				'options'     => array(
					'mode_a' => __( 'Chế độ A — QR động + Webhook (khuyên dùng)', 'tingee-gateway' ),
					'mode_b' => __( 'Chế độ B — Redirect sang trang Tingee', 'tingee-gateway' ),
				),
			),

		);
	}

	/**
	 * Render HTML cho field type "test_connection_button" — nút kiểm tra kết nối.
	 *
	 * WooCommerce gọi hàm generate_{type}_html() khi gặp field có type tùy chỉnh.
	 *
	 * @param string $key  Tên field (không dùng trực tiếp).
	 * @param array  $data Dữ liệu cấu hình của field.
	 * @return string HTML output.
	 */
	public function generate_test_connection_button_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html( $data['title'] ); ?>
			</th>
			<td class="forminp">
				<button type="button" id="tingee-test-connection" class="button button-secondary">
					<?php esc_html_e( 'Kiểm tra kết nối', 'tingee-gateway' ); ?>
				</button>
				<span id="tingee-connection-result" style="display:none; margin-left:10px; padding:5px 10px; border-radius:3px;"></span>
				<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Xử lý thanh toán khi khách đặt hàng.
	 * Sẽ triển khai đầy đủ ở Task T4.1 (Chế độ A) và T6.1 (Chế độ B).
	 *
	 * @param int $order_id ID đơn hàng WooCommerce.
	 * @return array Kết quả redirect.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Đặt đơn về On-Hold, chờ xác nhận từ Webhook Tingee.
		// Logic QR + Tingee API sẽ được thêm ở Giai đoạn 4.
		$order->update_status( 'on-hold', __( 'Chờ thanh toán qua Tingee.', 'tingee-gateway' ) );

		// Xóa giỏ hàng.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
