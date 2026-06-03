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
			echo wp_kses_post(
				sprintf(
					/* translators: %s: WooCommerce plugin link */
					__( '<strong>Tingee Gateway</strong> yêu cầu <a href="%s">WooCommerce</a> phải được cài đặt và kích hoạt.', 'tingee-gateway' ),
					esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) )
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

	// Lớp tích hợp WooCommerce Checkout Blocks.
	require_once TINGEE_PLUGIN_DIR . 'includes/class-tingee-blocks.php';

	// Đăng ký payment gateway vào WooCommerce.
	add_filter( 'woocommerce_payment_gateways', 'tingee_register_gateway' );
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
// 5. Nạp file dịch
// ---------------------------------------------------------------------------
add_action( 'init', 'tingee_load_textdomain' );

/**
 * Nạp file dịch ngôn ngữ của plugin.
 */
function tingee_load_textdomain() {
	load_plugin_textdomain(
		'tingee-gateway',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
}
