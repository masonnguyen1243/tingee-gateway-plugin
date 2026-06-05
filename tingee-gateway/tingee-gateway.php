<?php
/**
 * Plugin Name:       Tingee Gateway for WooCommerce
 * Plugin URI:        https://developers.tingee.vn
 * Description:       Tích hợp cổng thanh toán Tingee (by HENO) vào WooCommerce. Hỗ trợ QR động, chuyển khoản ngân hàng tự động và Webhook IPN.
 * Version:           1.0.0
 * Author:            HENO
 * Author URI:        https://heno.vn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tingee-gateway
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 */

// Ngăn truy cập trực tiếp vào file — bảo mật cơ bản.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Hằng số toàn cục
// ---------------------------------------------------------------------------

/** Phiên bản hiện tại của plugin. */
define( 'TINGEE_PLUGIN_VERSION', '1.0.0' );

/** Đường dẫn tuyệt đối đến thư mục plugin (có trailing slash). */
define( 'TINGEE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** URL đến thư mục plugin (có trailing slash). */
define( 'TINGEE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Đường dẫn đến file chính của plugin (dùng cho activation hook). */
define( 'TINGEE_PLUGIN_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// 2. Khai báo tương thích HPOS (High-Performance Order Storage)
//    Phải khai báo sớm — trước khi WooCommerce khởi tạo feature flags.
// ---------------------------------------------------------------------------
add_action( 'before_woocommerce_init', 'tingee_declare_hpos_compatibility' );

/**
 * Khai báo plugin tương thích với HPOS của WooCommerce.
 * HPOS là hệ thống lưu trữ đơn hàng hiệu năng cao (dùng bảng riêng thay vì post meta).
 */
function tingee_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			TINGEE_PLUGIN_FILE,
			true // true = tương thích.
		);
	}
}

// ---------------------------------------------------------------------------
// 3. Kiểm tra WooCommerce có đang active không
//    Nếu không → hiện notice, dừng nạp phần còn lại của plugin.
// ---------------------------------------------------------------------------

/**
 * Hook này chạy sau khi tất cả plugin được load.
 * Lúc này mới biết chắc WooCommerce có active hay không.
 */
add_action( 'plugins_loaded', 'tingee_init', 0 );

/**
 * Hàm khởi tạo plugin chính.
 * Kiểm tra WooCommerce, rồi nạp các class cần thiết.
 */
function tingee_init() {
	// Kiểm tra class WC_Payment_Gateway có tồn tại không.
	// Nếu không → WooCommerce chưa được kích hoạt.
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'tingee_woocommerce_missing_notice' );
		return; // Dừng lại — không nạp tiếp.
	}

	// WooCommerce đã active → nạp các class của plugin.
	tingee_load_classes();
}

/**
 * Hiển thị thông báo lỗi trong trang Admin khi WooCommerce chưa được kích hoạt.
 */
function tingee_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			$wc_link = '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">' . esc_html__( 'WooCommerce', 'tingee-gateway' ) . '</a>';
			echo wp_kses_post(
				sprintf(
					/* translators: %s: WooCommerce plugin link */
					__( '<strong>Tingee Gateway</strong> yêu cầu %s phải được cài đặt và kích hoạt.', 'tingee-gateway' ),
					$wc_link
				)
			);
			?>
		</p>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// 4. Nạp các class của plugin
// ---------------------------------------------------------------------------

/**
 * Require tất cả các file class trong thư mục includes/.
 * Thứ tự quan trọng: API trước, Gateway sau (Gateway dùng API).
 */
function tingee_load_classes() {
	// Lớp gọi API Tingee + sinh chữ ký HMAC-SHA512.
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-api.php';

	// Lớp cấu hình các field settings.
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-settings.php';

	// Lớp WC_Payment_Gateway chính — logic thanh toán.
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-gateway.php';

	// Lớp nhận và xử lý Webhook IPN từ Tingee.
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-webhook.php';

	// Đăng ký Blocks integration — chỉ khi WooCommerce Blocks đã load AbstractPaymentMethodType.
	// Dùng hook woocommerce_blocks_payment_method_type_registration thay vì require trực tiếp
	// vì AbstractPaymentMethodType chỉ tồn tại khi WC Blocks package được kích hoạt.
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'tingee_register_block_payment_method' );

	// Đăng ký payment gateway vào WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'tingee_register_gateway' );

	// Khởi tạo webhook handler — đăng ký REST endpoint ngay khi plugin load.
	new Tingee_Webhook();
}

/**
 * Thêm class Tingee_Gateway vào danh sách payment gateways của WooCommerce.
 *
 * @param array $gateways Danh sách các class gateway hiện tại.
 * @return array Danh sách đã thêm Tingee.
 */
function tingee_register_gateway( $gateways ) {
	$gateways[] = 'Tingee_Gateway';
	return $gateways;
}

// ---------------------------------------------------------------------------
// 5. AJAX handler: nút "Kiểm tra kết nối" ở trang Settings
// ---------------------------------------------------------------------------

/**
 * Đăng ký AJAX action cho nút "Kiểm tra kết nối" trong wp-admin.
 * Chỉ cho phép người dùng đã đăng nhập (wp_ajax_) — không có phiên bản nopriv.
 */
add_action( 'wp_ajax_tingee_test_connection', 'tingee_ajax_test_connection' );

/**
 * Xử lý AJAX request kiểm tra kết nối đến Tingee API.
 *
 * Nhận Client ID và Secret Token từ form (chưa lưu vào DB),
 * gọi GET /v1/get-banks để xác nhận credentials hợp lệ.
 * Trả về JSON { success, message }.
 */
function tingee_ajax_test_connection() {
	// Xác thực nonce — bảo vệ khỏi CSRF.
	check_ajax_referer( 'tingee_test_connection_nonce', 'nonce' );

	// Chỉ admin mới được phép.
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'tingee-gateway' ) ) );
	}

	// Lấy và sanitize credentials từ form.
	$client_id    = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
	$secret_token = isset( $_POST['secret_token'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_token'] ) ) : '';

	if ( empty( $client_id ) || empty( $secret_token ) ) {
		wp_send_json_error( array( 'message' => __( 'Vui lòng nhập đầy đủ Client ID và Secret Token trước khi kiểm tra.', 'tingee-gateway' ) ) );
	}

	// Gọi API /v1/get-banks với credentials từ form (không dùng credentials đã lưu).
	$result = Tingee_API::get_banks( $client_id, $secret_token );

	if ( $result['success'] ) {
		$bank_count = is_array( $result['data'] ) ? count( $result['data'] ) : 0;
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: số lượng ngân hàng */
					__( 'Kết nối thành công! Tingee hỗ trợ %d ngân hàng.', 'tingee-gateway' ),
					$bank_count
				),
			)
		);
	} else {
		// Phân loại lỗi theo mã Tingee để hiện thông báo rõ hơn.
		$error_hints = array(
			'97' => __( 'Sai chữ ký — kiểm tra lại Secret Token.', 'tingee-gateway' ),
			'90' => __( 'Sai định dạng timestamp — lỗi server. Vui lòng thử lại.', 'tingee-gateway' ),
			'91' => __( 'Request quá hạn — kiểm tra đồng hồ hệ thống server của bạn.', 'tingee-gateway' ),
		);

		$hint = isset( $error_hints[ $result['tingee_code'] ] ) ? $error_hints[ $result['tingee_code'] ] : '';
		$msg  = $result['message'] ? $result['message'] : __( 'Kết nối thất bại.', 'tingee-gateway' );

		if ( $hint ) {
			$msg .= ' — ' . $hint;
		}

		wp_send_json_error( array( 'message' => $msg ) );
	}
}

// ---------------------------------------------------------------------------
// 6. AJAX handler: lấy danh sách tài khoản VA từ Tingee
// ---------------------------------------------------------------------------

/**
 * Đăng ký AJAX action lấy danh sách tài khoản VA — chỉ cho admin đã đăng nhập.
 */
add_action( 'wp_ajax_tingee_fetch_accounts', 'tingee_ajax_fetch_accounts' );

/**
 * Lấy danh sách tài khoản VA đã liên kết trên Tingee, kèm tên ngân hàng.
 *
 * Dùng cùng nonce với test_connection (tingee_test_connection_nonce).
 * Trả về JSON { accounts: [ { vaAccountNumber, accountNumber, bankBin, accountName, bankFullName, bankShortName } ] }.
 */
function tingee_ajax_fetch_accounts() {
	check_ajax_referer( 'tingee_test_connection_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'tingee-gateway' ) ) );
	}

	$client_id    = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
	$secret_token = isset( $_POST['secret_token'] ) ? sanitize_text_field( wp_unslash( $_POST['secret_token'] ) ) : '';

	if ( empty( $client_id ) || empty( $secret_token ) ) {
		wp_send_json_error( array( 'message' => __( 'Vui lòng nhập đầy đủ Client ID và Secret Token.', 'tingee-gateway' ) ) );
	}

	// Lấy danh sách VA accounts và banks song song (tuần tự trong PHP).
	$accounts_result = Tingee_API::get_va_accounts( $client_id, $secret_token );
	$banks_result    = Tingee_API::get_banks( $client_id, $secret_token );

	if ( ! $accounts_result['success'] ) {
		$msg = $accounts_result['message'] ? $accounts_result['message'] : __( 'Không thể lấy danh sách tài khoản từ Tingee.', 'tingee-gateway' );
		wp_send_json_error( array( 'message' => $msg ) );
	}

	// Tạo map tra cứu tên ngân hàng theo BIN: [ bin => { name, shortName } ].
	$banks_map = array();
	if ( $banks_result['success'] && is_array( $banks_result['data'] ) ) {
		foreach ( $banks_result['data'] as $bank ) {
			if ( ! empty( $bank['bin'] ) ) {
				$banks_map[ $bank['bin'] ] = array(
					'name'      => isset( $bank['name'] ) ? $bank['name'] : '',
					'shortName' => isset( $bank['shortName'] ) ? $bank['shortName'] : '',
				);
			}
		}
	}

	// Response có dạng { totalCount, items } — lấy mảng items.
	$items = array();
	$data  = $accounts_result['data'];
	if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
		$items = $data['items'];
	} elseif ( is_array( $data ) && ! isset( $data['totalCount'] ) ) {
		// Fallback: nếu API trả về mảng phẳng.
		$items = $data;
	}

	// Làm giàu dữ liệu account với tên ngân hàng.
	$accounts = array();
	foreach ( $items as $item ) {
		$bin       = isset( $item['bankBin'] ) ? $item['bankBin'] : '';
		$bank_info = isset( $banks_map[ $bin ] ) ? $banks_map[ $bin ] : array( 'name' => '', 'shortName' => '' );

		$accounts[] = array(
			'vaAccountNumber' => isset( $item['vaAccountNumber'] ) ? sanitize_text_field( $item['vaAccountNumber'] ) : '',
			'accountNumber'   => isset( $item['accountNumber'] ) ? sanitize_text_field( $item['accountNumber'] ) : '',
			'bankBin'         => sanitize_text_field( $bin ),
			'accountName'     => isset( $item['accountName'] ) ? sanitize_text_field( $item['accountName'] ) : '',
			'status'          => isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : '',
			'bankFullName'    => sanitize_text_field( $bank_info['name'] ),
			'bankShortName'   => sanitize_text_field( $bank_info['shortName'] ),
		);
	}

	wp_send_json_success( array( 'accounts' => $accounts ) );
}

// ---------------------------------------------------------------------------
// 7. AJAX handler: kiểm tra trạng thái đơn hàng (trang thank-you, T4.3)
// ---------------------------------------------------------------------------

/**
 * Đăng ký AJAX action kiểm tra trạng thái thanh toán.
 * nopriv: khách chưa đăng nhập vẫn cần gọi được (guest checkout).
 */
add_action( 'wp_ajax_tingee_check_status',        'tingee_ajax_check_status' );
add_action( 'wp_ajax_nopriv_tingee_check_status', 'tingee_ajax_check_status' );

/**
 * Kiểm tra xem đơn hàng đã được thanh toán chưa.
 * Trả về JSON { paid, status, redirect_url }.
 *
 * Nonce được sinh trong thankyou_page() theo công thức tingee_check_status_{order_id},
 * đảm bảo mỗi nonce chỉ hợp lệ cho đúng đơn hàng đó.
 */
function tingee_ajax_check_status() {
	// Lấy và validate order_id.
	$order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => 'Invalid order.' ) );
	}

	// Xác thực nonce — gắn với từng order_id cụ thể để tránh dò đơn người khác.
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'tingee_check_status_' . $order_id ) ) {
		wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( array( 'message' => 'Order not found.' ) );
	}

	// Chỉ trả thông tin đơn dùng Tingee — tránh lộ trạng thái đơn của gateway khác.
	if ( 'tingee_gateway' !== $order->get_payment_method() ) {
		wp_send_json_error( array( 'message' => 'Invalid payment method.' ) );
	}

	$paid = $order->is_paid();

	wp_send_json_success(
		array(
			'paid'         => $paid,
			'status'       => $order->get_status(),
			// redirect_url chỉ gửi khi đã thanh toán để tránh lộ URL đơn trước khi xác nhận.
			'redirect_url' => $paid ? $order->get_view_order_url() : '',
		)
	);
}

// ---------------------------------------------------------------------------
// 7. Đăng ký Tingee vào WooCommerce Checkout Blocks
// ---------------------------------------------------------------------------

/**
 * Callback của hook woocommerce_blocks_payment_method_type_registration.
 * Require class và đăng ký vào $registry chỉ khi AbstractPaymentMethodType tồn tại,
 * tránh fatal error khi WC Blocks chưa được cài hoặc phiên bản quá cũ.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry của WC Blocks.
 */
function tingee_register_block_payment_method( $registry ) {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-blocks.php';
	$registry->register( new Tingee_Gateway_Blocks() );
}

// ---------------------------------------------------------------------------
// 8. Nạp file dịch
// WordPress 4.6+ tự động tải bản dịch từ translate.wordpress.org cho plugin
// được host trên WordPress.org — không cần gọi load_plugin_textdomain() nữa.
// ---------------------------------------------------------------------------
