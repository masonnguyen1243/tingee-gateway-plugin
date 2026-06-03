<?php
/**
 * Nhận và xử lý Webhook IPN từ Tingee.
 *
 * Luồng xử lý (T5.1 – T5.6):
 *   1. REST endpoint POST /wp-json/tingee-gateway/v1/webhook nhận request từ Tingee.
 *   2. Verify chữ ký HMAC-SHA512 trong header x-signature → 401 nếu sai.
 *   3. Parse JSON payload, lấy billId → tìm đơn hàng WooCommerce.
 *   4. Idempotency: nếu transactionId đã xử lý → trả 200, bỏ qua.
 *   5. Đối soát số tiền; nếu đủ → payment_complete(); nếu thiếu → giữ On-Hold.
 *   6. Luôn trả HTTP 200 cho webhook hợp lệ để Tingee ngừng retry.
 *
 * @package Tingee_Gateway
 * @since   1.0.0
 */

// Ngăn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tingee_Webhook
 */
class Tingee_Webhook {

	/** Namespace và route của REST endpoint. */
	const REST_NAMESPACE = 'tingee-gateway/v1';
	const REST_ROUTE     = '/webhook';

	/**
	 * Khởi tạo — đăng ký hook REST.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	// =========================================================================
	// T5.1 — Đăng ký REST endpoint
	// =========================================================================

	/**
	 * Đăng ký route nhận webhook từ Tingee.
	 *
	 * URL đầy đủ: https://yoursite.com/wp-json/tingee-gateway/v1/webhook
	 * Đây là URL cần điền vào cấu hình Webhook trong trang Developers của Tingee.
	 *
	 * permission_callback = '__return_true' vì xác thực được thực hiện bằng
	 * chữ ký HMAC-SHA512 (T5.2), không phải auth cookie/nonce của WordPress.
	 * Tingee là server bên ngoài, không có session WP.
	 */
	public function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST only.
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// =========================================================================
	// T5.2 – T5.6 — Handler chính
	// =========================================================================

	/**
	 * Xử lý webhook IPN từ Tingee.
	 *
	 * @param WP_REST_Request $request Request từ Tingee.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {

		// ------------------------------------------------------------------
		// T5.2 — Xác thực chữ ký HMAC-SHA512
		// ------------------------------------------------------------------

		$settings     = get_option( 'woocommerce_tingee_gateway_settings', array() );
		$secret_token = isset( $settings['secret_token'] ) ? $settings['secret_token'] : '';

		if ( empty( $secret_token ) ) {
			$this->log( 'Webhook nhận được nhưng Secret Token chưa cấu hình.', 'error' );
			return new WP_REST_Response( array( 'error' => 'Gateway not configured.' ), 500 );
		}

		$timestamp = $request->get_header( 'x-request-timestamp' );
		$signature = $request->get_header( 'x-signature' );
		$raw_body  = $request->get_body();

		if ( empty( $timestamp ) || empty( $signature ) ) {
			$this->log( 'Webhook bị từ chối: thiếu header x-request-timestamp hoặc x-signature.', 'error' );
			return new WP_REST_Response( array( 'error' => 'Missing required headers.' ), 401 );
		}

		if ( ! Tingee_API::verify_webhook_signature( $signature, $timestamp, $raw_body, $secret_token ) ) {
			$this->log( 'Webhook bị từ chối: chữ ký x-signature không hợp lệ.', 'error' );
			// Trả 401 — Tingee sẽ KHÔNG retry request lỗi chữ ký (chỉ retry 5xx/timeout).
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 401 );
		}

		// ------------------------------------------------------------------
		// T5.3 — Parse payload, tìm đơn hàng
		// ------------------------------------------------------------------

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->log( 'Webhook payload không hợp lệ (không phải JSON object).', 'error' );
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		// Field names theo tài liệu Tingee (developers.tingee.vn/docs/webhook/webhook-payment-callback/).
		// transactionCode — mã định danh giao dịch duy nhất (dùng cho idempotency).
		$transaction_code = isset( $payload['transactionCode'] ) ? sanitize_text_field( $payload['transactionCode'] ) : '';
		// amount — số tiền thực tế nhận được.
		$received_amount  = isset( $payload['amount'] ) ? absint( $payload['amount'] ) : 0;
		// transactionDate — thời gian giao dịch định dạng yyyyMMddHHmmss (dùng ghi log).
		$transaction_date = isset( $payload['transactionDate'] ) ? sanitize_text_field( $payload['transactionDate'] ) : '';
		// content — nội dung chuyển khoản (dùng ghi log).
		$transfer_content = isset( $payload['content'] ) ? sanitize_text_field( $payload['content'] ) : '';

		// billId nằm trong mảng additionalData — chỉ có với QR động (Chế độ A).
		// Tingee gửi: "additionalData": [ { "billId": "xxx" } ]
		$bill_id = '';
		if ( isset( $payload['additionalData'] ) && is_array( $payload['additionalData'] ) ) {
			foreach ( $payload['additionalData'] as $item ) {
				if ( isset( $item['billId'] ) && ! empty( $item['billId'] ) ) {
					$bill_id = sanitize_text_field( $item['billId'] );
					break;
				}
			}
		}

		if ( empty( $bill_id ) ) {
			$this->log( 'Webhook payload thiếu billId trong additionalData — có thể là giao dịch VA thông thường (không phải QR động).', 'warning' );
			// Trả 200 để Tingee không retry — đây là giao dịch không do plugin tạo QR.
			return new WP_REST_Response( array( 'code' => '00', 'message' => 'Ignored.' ), 200 );
		}

		// Tìm đơn hàng bằng billId đã lưu trong order meta ở T4.1.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_tingee_bill_id',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $bill_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $orders ) ) {
			$this->log(
				sprintf( 'Webhook billId=%s: không tìm thấy đơn hàng tương ứng trong hệ thống.', $bill_id ),
				'warning'
			);
			// Trả 200 để Tingee KHÔNG retry — lỗi phía ta, không phải lỗi gửi webhook.
			return new WP_REST_Response( array( 'code' => '00', 'message' => 'Order not found.' ), 200 );
		}

		/** @var WC_Order $order */
		$order = $orders[0];

		// ------------------------------------------------------------------
		// T5.4 — Idempotency: tránh gạch nợ trùng khi Tingee retry
		// ------------------------------------------------------------------

		if ( ! empty( $transaction_code ) ) {
			$processed_ids = $order->get_meta( '_tingee_processed_transactions' );
			$processed_ids = is_array( $processed_ids ) ? $processed_ids : array();

			if ( in_array( $transaction_code, $processed_ids, true ) ) {
				$this->log(
					sprintf(
						'Webhook billId=%s transactionCode=%s: đã xử lý trước đó, bỏ qua (idempotency).',
						$bill_id,
						$transaction_code
					),
					'info'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Already processed.' ), 200 );
			}
		}

		// ------------------------------------------------------------------
		// T5.5 — Đối soát số tiền & gạch nợ
		// ------------------------------------------------------------------

		// Tingee chỉ gửi webhook khi giao dịch THÀNH CÔNG (không có field status trong payload).
		// Không cần kiểm tra status — hễ nhận được webhook hợp lệ là tiền đã vào.

		$expected_amount = (int) $order->get_meta( '_tingee_amount' );

		if ( $received_amount >= $expected_amount && $expected_amount > 0 ) {
			// --- Đủ tiền: xác nhận thanh toán ---

			// Lưu transactionCode vào danh sách đã xử lý (cho idempotency T5.4).
			if ( ! empty( $transaction_code ) ) {
				$processed_ids   = $order->get_meta( '_tingee_processed_transactions' );
				$processed_ids   = is_array( $processed_ids ) ? $processed_ids : array();
				$processed_ids[] = $transaction_code;
				$order->update_meta_data( '_tingee_processed_transactions', $processed_ids );
				$order->save();
			}

			// Ghi note admin — không gửi email cho khách.
			$order->add_order_note(
				sprintf(
					/* translators: 1: số tiền nhận, 2: mã giao dịch, 3: nội dung CK, 4: thời gian GD */
					__( 'Tingee xác nhận thanh toán thành công. Số tiền: %1$s ₫. Mã GD: %2$s. Nội dung: %3$s. Thời gian GD: %4$s.', 'tingee-gateway' ),
					number_format( $received_amount, 0, ',', '.' ),
					! empty( $transaction_code ) ? $transaction_code : '—',
					! empty( $transfer_content ) ? $transfer_content : '—',
					! empty( $transaction_date ) ? $transaction_date : current_time( 'd/m/Y H:i:s' )
				),
				false // false = không gửi email khách hàng.
			);

			// payment_complete() tự chuyển đơn sang Processing (hoặc Completed với sản phẩm số),
			// kích hoạt các hook WooCommerce tiêu chuẩn (giảm tồn kho, gửi email, v.v.).
			$order->payment_complete( $transaction_code );

			$this->log(
				sprintf(
					'Webhook billId=%s transactionCode=%s: thanh toán thành công. Đơn #%d → Processing.',
					$bill_id,
					! empty( $transaction_code ) ? $transaction_code : 'N/A',
					$order->get_id()
				),
				'info'
			);

		} else {
			// --- Thiếu tiền: giữ On-Hold, ghi note để admin xử lý thủ công ---
			$order->add_order_note(
				sprintf(
					/* translators: 1: số tiền nhận, 2: số tiền cần, 3: mã giao dịch */
					__( '[Cảnh báo] Tingee nhận thanh toán THIẾU TIỀN. Nhận: %1$s ₫ / Cần: %2$s ₫. Mã GD: %3$s. Đơn giữ On-Hold — vui lòng xử lý thủ công.', 'tingee-gateway' ),
					number_format( $received_amount, 0, ',', '.' ),
					number_format( $expected_amount, 0, ',', '.' ),
					! empty( $transaction_code ) ? $transaction_code : '—'
				),
				false
			);
			$order->save();

			$this->log(
				sprintf(
					'Webhook billId=%s: thanh toán THIẾU TIỀN. Nhận %d / Cần %d. Đơn #%d giữ On-Hold.',
					$bill_id,
					$received_amount,
					$expected_amount,
					$order->get_id()
				),
				'warning'
			);
		}

		// ------------------------------------------------------------------
		// T5.6 — Trả HTTP 200 + body đúng format Tingee yêu cầu để ngừng retry
		// ------------------------------------------------------------------
		return new WP_REST_Response( array( 'code' => '00', 'message' => 'Success' ), 200 );
	}

	// =========================================================================
	// Tiện ích
	// =========================================================================

	/**
	 * Ghi log qua WC_Logger.
	 * Không bao giờ log secret token hoặc thông tin thẻ.
	 *
	 * @param string $message Nội dung log.
	 * @param string $level   Level: 'info' | 'warning' | 'error'.
	 */
	private function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'tingee-webhook' ) );
		}
	}

	/**
	 * Trả về URL webhook đầy đủ để admin điền vào trang cấu hình Tingee.
	 *
	 * @return string URL webhook, ví dụ: https://yoursite.com/wp-json/tingee-gateway/v1/webhook
	 */
	public static function get_webhook_url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}
}
