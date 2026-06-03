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
	 * BIN code của ngân hàng liên kết với VA account (bắt buộc để sinh QR).
	 *
	 * @var string
	 */
	public $bank_bin;

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

		// Trạng thái bật/tắt — BẮT BUỘC phải set thủ công để is_available() hoạt động đúng.
		$this->enabled = $this->get_option( 'enabled' );

		// Hiển thị ở trang checkout phía khách.
		$this->title       = $this->get_option( 'title', __( 'Chuyển khoản ngân hàng (Tingee)', 'tingee-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Quét mã QR để chuyển khoản. Đơn hàng tự động xác nhận sau khi thanh toán.', 'tingee-gateway' ) );

		// Credentials và tài khoản VA.
		$this->va_account_number = $this->get_option( 'va_account_number', '' );

		// Chế độ tích hợp A hoặc B.
		$this->integration_mode = $this->get_option( 'integration_mode', 'mode_a' );

		// BIN code ngân hàng VA.
		$this->bank_bin = $this->get_option( 'bank_bin', '' );

		// Lưu settings khi admin nhấn nút "Save changes".
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Enqueue JS admin chỉ khi đang ở trang Settings của gateway này.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// Hiển thị QR + thông tin chuyển khoản trên trang thank-you — chỉ chạy với đơn Tingee.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		// Enqueue CSS/JS frontend chỉ trên trang thank-you.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
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
				'desc_tip'    => false,
				'default'     => '',
				'placeholder' => 'VD: 9704000000000018',
			),

			'bank_bin' => array(
				'title'       => __( 'Mã BIN ngân hàng (bankBin)', 'tingee-gateway' ),
				'type'        => 'text',
				/* translators: examples of Vietnamese bank BIN codes */
				'description' => __( 'Mã BIN của ngân hàng liên kết với tài khoản VA ở trên — bắt buộc để sinh QR động. Xem danh sách BIN trong tài liệu Tingee. Ví dụ: <code>970422</code> (MB Bank), <code>970436</code> (Vietcombank), <code>970415</code> (VietinBank).', 'tingee-gateway' ),
				'desc_tip'    => false,
				'default'     => '',
				'placeholder' => 'VD: 970422',
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
	 *
	 * Chế độ A (QR + Webhook):
	 *   - Gọi Tingee API tạo QR động.
	 *   - Lưu billId, qrCode, qrAccount vào order meta (HPOS-safe).
	 *   - Đặt đơn về On-Hold, chờ Webhook xác nhận.
	 *   - Redirect khách đến trang cảm ơn (hiển thị QR ở T4.2).
	 *
	 * Chế độ B (Redirect): sẽ triển khai đầy đủ ở T6.1.
	 *
	 * @param int $order_id ID đơn hàng WooCommerce.
	 * @return array|void Mảng { result, redirect } hoặc void khi thất bại.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Chế độ B — sẽ triển khai ở T6.1. Tạm thời đặt On-Hold và redirect.
		if ( 'mode_b' === $this->integration_mode ) {
			$order->update_status( 'on-hold', __( 'Chờ thanh toán qua Tingee (Chế độ B — Redirect).', 'tingee-gateway' ) );
			WC()->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// --- Chế độ A: QR động + Webhook ---

		// Kiểm tra cấu hình bắt buộc để sinh QR.
		if ( empty( $this->va_account_number ) || empty( $this->bank_bin ) ) {
			wc_add_notice(
				__( 'Cổng thanh toán Tingee chưa cấu hình đầy đủ (thiếu số VA hoặc mã BIN ngân hàng). Vui lòng liên hệ quản trị viên.', 'tingee-gateway' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		// Chuẩn bị tham số tạo QR.
		// amount: WooCommerce trả float, Tingee nhận int (VND không có thập phân).
		$amount = (int) round( $order->get_total() );

		// purpose: số đơn hàng — hiển thị trong QR để khách tham chiếu.
		$purpose = (string) $order->get_order_number();

		$qr_params = array(
			'vaAccountNumber' => $this->va_account_number,
			'qrCodeType'      => 'dynamic-one-time-payment',
			'bankBin'         => $this->bank_bin,
			'amount'          => $amount,
			'purpose'         => $purpose,
			'expireInMinute'  => 30,
		);

		// Gọi API Tingee tạo QR động.
		$result = Tingee_API::create_dynamic_qr( $qr_params );

		if ( ! $result['success'] ) {
			wc_add_notice(
				__( 'Không thể tạo mã QR thanh toán. Vui lòng thử lại hoặc chọn phương thức thanh toán khác.', 'tingee-gateway' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$data = $result['data'];

		// Lưu thông tin QR vào order meta — HPOS-safe (dùng $order API, không dùng update_post_meta).
		$order->update_meta_data( '_tingee_bill_id',    sanitize_text_field( $data['billId'] ) );
		$order->update_meta_data( '_tingee_qr_code',    sanitize_text_field( $data['qrCode'] ) );
		$order->update_meta_data( '_tingee_qr_account', sanitize_text_field( $data['qrAccount'] ) );
		$order->update_meta_data( '_tingee_amount',     $amount );
		$order->update_meta_data( '_tingee_purpose',    $purpose );
		$order->save();

		// Đặt đơn về On-Hold — chờ Webhook từ Tingee xác nhận thanh toán.
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: Tingee billId */
				__( 'Chờ thanh toán qua Tingee. Mã hóa đơn (billId): %s', 'tingee-gateway' ),
				sanitize_text_field( $data['billId'] )
			)
		);

		// Xóa giỏ hàng sau khi đơn được tạo thành công.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Enqueue CSS và JS cho trang thank-you.
	 * Chỉ load khi đang ở trang xác nhận đơn hàng (order-received) và đơn dùng Tingee.
	 */
	public function enqueue_checkout_scripts() {
		// is_order_received_page() trả true trên URL /checkout/order-received/.
		if ( ! is_order_received_page() ) {
			return;
		}

		// Lấy order từ query var để kiểm tra payment method trước khi load tài nguyên.
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}

		wp_enqueue_style(
			'tingee-checkout',
			TINGEE_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			TINGEE_PLUGIN_VERSION
		);

		// JS poll trạng thái đơn hàng (T4.3).
		wp_enqueue_script(
			'tingee-checkout',
			TINGEE_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			TINGEE_PLUGIN_VERSION,
			true // Load ở footer.
		);

		// Truyền dữ liệu PHP sang JS.
		wp_localize_script(
			'tingee-checkout',
			'tingeeCheckout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'paid' => __( 'Thanh toán thành công! Đang chuyển hướng...', 'tingee-gateway' ),
				),
			)
		);
	}

	/**
	 * Hiển thị hộp QR code và thông tin chuyển khoản trên trang thank-you.
	 * Hook: woocommerce_thankyou_tingee_gateway — WooCommerce tự scope theo payment method.
	 *
	 * Dữ liệu đọc từ order meta được lưu bởi process_payment() ở T4.1:
	 *   _tingee_bill_id    — mã hóa đơn Tingee.
	 *   _tingee_qr_code    — chuỗi QR code (base64 PNG hoặc URL).
	 *   _tingee_qr_account — số tài khoản VA hiển thị cho khách.
	 *   _tingee_amount     — số tiền (int VND).
	 *   _tingee_purpose    — nội dung chuyển khoản.
	 *
	 * @param int $order_id ID đơn hàng.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Đọc meta đã lưu từ T4.1.
		$bill_id    = $order->get_meta( '_tingee_bill_id' );
		$qr_code    = $order->get_meta( '_tingee_qr_code' );
		$qr_account = $order->get_meta( '_tingee_qr_account' );
		$amount     = (int) $order->get_meta( '_tingee_amount' );
		$purpose    = $order->get_meta( '_tingee_purpose' );

		// Không hiển thị nếu thiếu dữ liệu hoặc đơn đã hoàn tất.
		if ( empty( $bill_id ) || empty( $qr_code ) ) {
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}

		// Format số tiền theo kiểu Việt Nam: 150.000 ₫.
		$amount_display = number_format( $amount, 0, ',', '.' ) . ' ₫';

		// Nonce để JS poll trạng thái (sẽ dùng ở T4.3).
		$status_nonce = wp_create_nonce( 'tingee_check_status_' . $order_id );
		?>
		<section class="tingee-payment-box"
			id="tingee-payment-box"
			data-order-id="<?php echo esc_attr( $order_id ); ?>"
			data-nonce="<?php echo esc_attr( $status_nonce ); ?>">

			<h2 class="tingee-payment-box__title">
				<?php esc_html_e( 'Quét mã QR để thanh toán', 'tingee-gateway' ); ?>
			</h2>

			<div class="tingee-payment-box__qr">
				<?php
				// QR code có thể là URL hoặc chuỗi base64 PNG.
				if ( 0 === strpos( $qr_code, 'http' ) ) {
					echo '<img src="' . esc_url( $qr_code ) . '"'
						. ' alt="' . esc_attr__( 'Mã QR thanh toán Tingee', 'tingee-gateway' ) . '"'
						. ' width="220" height="220" />';
				} else {
					// base64: chỉ chứa [A-Za-z0-9+/=] — esc_attr() an toàn.
					echo '<img src="data:image/png;base64,' . esc_attr( $qr_code ) . '"'
						. ' alt="' . esc_attr__( 'Mã QR thanh toán Tingee', 'tingee-gateway' ) . '"'
						. ' width="220" height="220" />';
				}
				?>
			</div>

			<div class="tingee-payment-box__info">
				<table class="tingee-transfer-info">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Số tài khoản', 'tingee-gateway' ); ?></th>
							<td>
								<span id="tingee-field-account"><?php echo esc_html( $qr_account ); ?></span>
								<button type="button"
									class="tingee-copy-btn"
									data-copy-target="tingee-field-account"
									aria-label="<?php esc_attr_e( 'Sao chép số tài khoản', 'tingee-gateway' ); ?>">
									<?php esc_html_e( 'Sao chép', 'tingee-gateway' ); ?>
								</button>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Số tiền', 'tingee-gateway' ); ?></th>
							<td>
								<span id="tingee-field-amount-raw" class="tingee-amount-value"><?php echo esc_html( $amount_display ); ?></span>
								<span id="tingee-field-amount" style="display:none;"><?php echo esc_html( $amount ); ?></span>
								<button type="button"
									class="tingee-copy-btn"
									data-copy-target="tingee-field-amount"
									aria-label="<?php esc_attr_e( 'Sao chép số tiền', 'tingee-gateway' ); ?>">
									<?php esc_html_e( 'Sao chép', 'tingee-gateway' ); ?>
								</button>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Nội dung chuyển khoản', 'tingee-gateway' ); ?></th>
							<td>
								<span id="tingee-field-purpose"><?php echo esc_html( $purpose ); ?></span>
								<button type="button"
									class="tingee-copy-btn"
									data-copy-target="tingee-field-purpose"
									aria-label="<?php esc_attr_e( 'Sao chép nội dung', 'tingee-gateway' ); ?>">
									<?php esc_html_e( 'Sao chép', 'tingee-gateway' ); ?>
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="tingee-payment-box__status" id="tingee-payment-status">
				<span class="tingee-status-waiting">
					<?php esc_html_e( 'Đang chờ xác nhận thanh toán...', 'tingee-gateway' ); ?>
				</span>
			</div>

			<p class="tingee-payment-box__note">
				<?php esc_html_e( 'Trang sẽ tự động cập nhật khi nhận được xác nhận thanh toán.', 'tingee-gateway' ); ?>
			</p>

		</section>
		<?php
	}
}
