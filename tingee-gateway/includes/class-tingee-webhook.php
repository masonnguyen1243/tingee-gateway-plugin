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
			$this->log(
				__( 'Webhook nhận được nhưng Secret Token chưa cấu hình. Vào WooCommerce → Settings → Payments → Tingee để điền Secret Token.', 'tingee-gateway' ),
				'error'
			);
			return new WP_REST_Response( array( 'error' => 'Gateway not configured.' ), 500 );
		}

		$timestamp = $request->get_header( 'x-request-timestamp' );
		$signature = $request->get_header( 'x-signature' );
		$raw_body  = $request->get_body();

		if ( empty( $timestamp ) || empty( $signature ) ) {
			$this->log(
				__( 'Webhook bị từ chối: thiếu header x-request-timestamp hoặc x-signature.', 'tingee-gateway' ),
				'error'
			);
			return new WP_REST_Response( array( 'error' => 'Missing required headers.' ), 401 );
		}

		if ( ! Tingee_API::verify_webhook_signature( $signature, $timestamp, $raw_body, $secret_token ) ) {
			// Log thêm 4 ký tự đầu của signature nhận được để dễ debug (không log secret).
			$this->log(
				sprintf(
					/* translators: %s: 4 ký tự đầu của signature nhận được */
					__( 'Webhook bị từ chối: chữ ký không hợp lệ. Signature nhận được bắt đầu bằng: %s', 'tingee-gateway' ),
					Tingee_API::mask_secret( $signature )
				),
				'error'
			);
			// Trả 401 — Tingee sẽ KHÔNG retry request lỗi chữ ký (chỉ retry 5xx/timeout).
			return new WP_REST_Response( array( 'error' => 'Invalid signature.' ), 401 );
		}

		$this->log(
			sprintf(
				/* translators: 1: IP address của server Tingee, 2: timestamp từ header */
				__( 'Webhook hợp lệ nhận được. IP: %1$s. Timestamp: %2$s.', 'tingee-gateway' ),
				$request->get_header( 'x-real-ip' ),
				$timestamp
			),
			'info'
		);

		// ------------------------------------------------------------------
		// T5.3 — Parse payload, phân loại Chế độ A hay Chế độ B
		// ------------------------------------------------------------------

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->log( __( 'Webhook payload không hợp lệ (không phải JSON object).', 'tingee-gateway' ), 'error' );
			return new WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		// Phân biệt webhook Chế độ A (QR + Webhook) vs Chế độ B (Payment Gateway):
		//   Chế độ B: có field 'orderId' (top-level) + 'status' trong payload.
		//   Chế độ A: có 'transactionCode' + 'additionalData[].billId', không có 'status'.
		$has_order_id = isset( $payload['orderId'] ) && ! empty( $payload['orderId'] );
		$has_status   = isset( $payload['status'] );

		if ( $has_order_id && $has_status ) {
			return $this->handle_mode_b( $payload );
		}

		// --- Chế độ A: QR động + Webhook ---

		// transactionCode — mã định danh giao dịch duy nhất (dùng cho idempotency T5.4).
		$transaction_code = isset( $payload['transactionCode'] ) ? sanitize_text_field( $payload['transactionCode'] ) : '';
		// amount — số tiền thực tế nhận được.
		$received_amount  = isset( $payload['amount'] ) ? absint( $payload['amount'] ) : 0;
		// transactionDate — thời gian giao dịch định dạng yyyyMMddHHmmss.
		$transaction_date = isset( $payload['transactionDate'] ) ? sanitize_text_field( $payload['transactionDate'] ) : '';
		// content — nội dung chuyển khoản.
		$transfer_content = isset( $payload['content'] ) ? sanitize_text_field( $payload['content'] ) : '';

		// billId nằm trong additionalData — chỉ có với QR động (Chế độ A).
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

		// Tìm đơn hàng — Dynamic QR dùng billId, Static QR dùng content (nội dung CK).
		// $order_identifier dùng trong log bên dưới để nhận diện đơn theo loại QR.
		$order_identifier = '';

		if ( ! empty( $bill_id ) ) {
			// Dynamic QR: tìm bằng _tingee_bill_id đã lưu lúc tạo QR.
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => '_tingee_bill_id',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $bill_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( empty( $orders ) ) {
				$this->log(
					sprintf(
						/* translators: %s: billId từ Tingee */
						__( 'Webhook billId=%s: không tìm thấy đơn hàng tương ứng.', 'tingee-gateway' ),
						$bill_id
					),
					'warning'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Order not found.' ), 200 );
			}

			$order_identifier = 'billId=' . $bill_id;

		} else {
			// Static QR: không có billId → tìm qua nội dung chuyển khoản = mã đơn WC.
			// Nếu khách sửa nội dung CK thì match sẽ thất bại — cần xử lý thủ công.
			if ( empty( $transfer_content ) ) {
				$this->log(
					__( 'Webhook thiếu cả billId lẫn content — không thể xác định đơn hàng.', 'tingee-gateway' ),
					'warning'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Ignored: no identifier.' ), 200 );
			}

			$orders = wc_get_orders(
				array(
					'limit'          => 1,
					'payment_method' => 'tingee_gateway',
					'status'         => array( 'wc-on-hold' ),
					'meta_key'       => '_tingee_purpose',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $transfer_content,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( empty( $orders ) ) {
				$this->log(
					sprintf(
						/* translators: %s: nội dung chuyển khoản */
						__( 'Webhook Static QR: không tìm được đơn on-hold nào với nội dung CK "%s". Khách có thể đã sửa nội dung CK — cần xử lý thủ công.', 'tingee-gateway' ),
						$transfer_content
					),
					'warning'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Order not found.' ), 200 );
			}

			$order_identifier = 'content=' . $transfer_content;
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
						/* translators: 1: order identifier (billId=... hoặc content=...), 2: transaction code */
						__( 'Webhook Mode A %1$s transactionCode=%2$s: đã xử lý trước đó, bỏ qua (idempotency).', 'tingee-gateway' ),
						$order_identifier,
						$transaction_code
					),
					'info'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Already processed.' ), 200 );
			}
		}

		// ------------------------------------------------------------------
		// T5.5 — Đối soát số tiền & gạch nợ (Chế độ A)
		// ------------------------------------------------------------------

		// Tingee chỉ gửi webhook khi giao dịch THÀNH CÔNG (không có field status trong payload Chế độ A).
		$expected_amount = (int) $order->get_meta( '_tingee_amount' );

		if ( $received_amount >= $expected_amount && $expected_amount > 0 ) {
			// Lưu transactionCode cho idempotency.
			if ( ! empty( $transaction_code ) ) {
				$processed_ids   = $order->get_meta( '_tingee_processed_transactions' );
				$processed_ids   = is_array( $processed_ids ) ? $processed_ids : array();
				$processed_ids[] = $transaction_code;
				$order->update_meta_data( '_tingee_processed_transactions', $processed_ids );
				$order->save();
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: số tiền nhận, 2: mã giao dịch, 3: nội dung CK, 4: thời gian GD */
					__( 'Tingee xác nhận thanh toán thành công. Số tiền: %1$s ₫. Mã GD: %2$s. Nội dung: %3$s. Thời gian GD: %4$s.', 'tingee-gateway' ),
					number_format( $received_amount, 0, ',', '.' ),
					! empty( $transaction_code ) ? $transaction_code : '—',
					! empty( $transfer_content ) ? $transfer_content : '—',
					! empty( $transaction_date ) ? $transaction_date : current_time( 'd/m/Y H:i:s' )
				),
				false
			);

			$order->payment_complete( $transaction_code );

			$this->log(
				sprintf(
					/* translators: 1: order identifier, 2: transaction code, 3: WooCommerce order ID */
					__( 'Webhook Mode A %1$s transactionCode=%2$s: thanh toán thành công. Đơn #%3$d → Processing.', 'tingee-gateway' ),
					$order_identifier,
					! empty( $transaction_code ) ? $transaction_code : 'N/A',
					$order->get_id()
				),
				'info'
			);

		} else {
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
					/* translators: 1: order identifier, 2: received amount, 3: expected amount, 4: WooCommerce order ID */
					__( 'Webhook Mode A %1$s: THIẾU TIỀN. Nhận %2$d / Cần %3$d. Đơn #%4$d giữ On-Hold.', 'tingee-gateway' ),
					$order_identifier,
					$received_amount,
					$expected_amount,
					$order->get_id()
				),
				'warning'
			);
		}

		// ------------------------------------------------------------------
		// T5.6 — Trả HTTP 200 để Tingee ngừng retry
		// ------------------------------------------------------------------
		return new WP_REST_Response( array( 'code' => '00', 'message' => 'Success' ), 200 );
	}

	// =========================================================================
	// T6.2 — Xử lý webhook Chế độ B (Payment Gateway Redirect)
	// =========================================================================

	/**
	 * Xử lý webhook từ Tingee khi thanh toán qua Payment Gateway (Chế độ B).
	 *
	 * Payload Chế độ B (khác hoàn toàn Chế độ A):
	 *   - orderId     : mã đơn do plugin sinh ra khi tạo link (map về WC order).
	 *   - status      : "success" | "expired" | "canceled".
	 *   - statusCode  : "00" = thành công.
	 *   - paidAmount  : số tiền thực tế khách đã thanh toán.
	 *   - amount      : số tiền gốc của link.
	 *   - requestId   : UUID của webhook request này (dùng cho idempotency).
	 *   - paidAt      : thời gian thanh toán (ISO 8601).
	 *   - paymentMethod: "qr", v.v.
	 *
	 * @param array $payload Payload JSON đã được json_decode.
	 * @return WP_REST_Response
	 */
	private function handle_mode_b( array $payload ) {
		$tingee_order_id    = sanitize_text_field( $payload['orderId'] );
		$status             = strtolower( sanitize_text_field( $payload['status'] ) );
		$status_code        = isset( $payload['statusCode'] ) ? sanitize_text_field( $payload['statusCode'] ) : '';
		$received_amount    = isset( $payload['paidAmount'] ) ? absint( $payload['paidAmount'] ) : 0;
		$paid_at            = isset( $payload['paidAt'] ) ? sanitize_text_field( $payload['paidAt'] ) : '';
		$payment_method     = isset( $payload['paymentMethod'] ) ? sanitize_text_field( $payload['paymentMethod'] ) : '';
		// requestId của webhook (không phải requestId ta gửi đi) — dùng cho idempotency.
		$webhook_request_id = isset( $payload['requestId'] ) ? sanitize_text_field( $payload['requestId'] ) : '';

		// Tìm đơn hàng bằng _tingee_order_id_ext đã lưu khi tạo link ở T6.1.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_tingee_order_id_ext',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $tingee_order_id,         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $orders ) ) {
			$this->log(
				sprintf(
					/* translators: %s: Tingee order ID */
					__( 'Webhook Mode B orderId=%s: không tìm thấy đơn hàng.', 'tingee-gateway' ),
					$tingee_order_id
				),
				'warning'
			);
			return new WP_REST_Response( array( 'code' => '00', 'message' => 'Order not found.' ), 200 );
		}

		/** @var WC_Order $order */
		$order = $orders[0];

		// Idempotency: kiểm tra webhook request ID này đã xử lý chưa.
		if ( ! empty( $webhook_request_id ) ) {
			$processed_webhooks = $order->get_meta( '_tingee_processed_webhooks_b' );
			$processed_webhooks = is_array( $processed_webhooks ) ? $processed_webhooks : array();

			if ( in_array( $webhook_request_id, $processed_webhooks, true ) ) {
				$this->log(
					sprintf(
						/* translators: %s: webhook request ID */
						__( 'Webhook Mode B requestId=%s: đã xử lý, bỏ qua (idempotency).', 'tingee-gateway' ),
						$webhook_request_id
					),
					'info'
				);
				return new WP_REST_Response( array( 'code' => '00', 'message' => 'Already processed.' ), 200 );
			}
		}

		// Chỉ gạch nợ khi status = 'success' VÀ statusCode = '00'.
		// Các status khác ('expired', 'canceled'): ghi note để admin biết, không làm gì thêm.
		if ( 'success' !== $status || '00' !== $status_code ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: status, 2: statusCode */
					__( 'Tingee (Chế độ B) thông báo: trạng thái "%1$s" (statusCode: %2$s). Đơn không được thanh toán.', 'tingee-gateway' ),
					$status,
					$status_code
				),
				false
			);
			$order->save();

			$this->log(
				sprintf(
					/* translators: 1: Tingee order ID, 2: status, 3: statusCode */
					__( 'Webhook Mode B orderId=%1$s: status=%2$s, statusCode=%3$s — không phải success.', 'tingee-gateway' ),
					$tingee_order_id,
					$status,
					$status_code
				),
				'info'
			);
			return new WP_REST_Response( array( 'code' => '00', 'message' => 'Status noted.' ), 200 );
		}

		// Đối soát số tiền: so paidAmount với amount đã lưu khi tạo link.
		$expected_amount = (int) $order->get_meta( '_tingee_amount' );

		if ( $received_amount >= $expected_amount && $expected_amount > 0 ) {
			// Lưu idempotency key trước khi payment_complete (phòng lỗi giữa chừng).
			if ( ! empty( $webhook_request_id ) ) {
				$processed_webhooks   = $order->get_meta( '_tingee_processed_webhooks_b' );
				$processed_webhooks   = is_array( $processed_webhooks ) ? $processed_webhooks : array();
				$processed_webhooks[] = $webhook_request_id;
				$order->update_meta_data( '_tingee_processed_webhooks_b', $processed_webhooks );
				$order->save();
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: số tiền, 2: phương thức, 3: thời gian */
					__( 'Tingee (Chế độ B) xác nhận thanh toán thành công. Số tiền: %1$s ₫. Phương thức: %2$s. Thời gian: %3$s.', 'tingee-gateway' ),
					number_format( $received_amount, 0, ',', '.' ),
					! empty( $payment_method ) ? $payment_method : '—',
					! empty( $paid_at ) ? $paid_at : current_time( 'd/m/Y H:i:s' )
				),
				false
			);

			// payment_complete() dùng tingee_order_id làm transaction_id (billId có thể null trong Mode B).
			$order->payment_complete( $tingee_order_id );

			$this->log(
				sprintf(
					/* translators: 1: Tingee order ID, 2: WooCommerce order ID */
					__( 'Webhook Mode B orderId=%1$s: thanh toán thành công. Đơn #%2$d → Processing.', 'tingee-gateway' ),
					$tingee_order_id,
					$order->get_id()
				),
				'info'
			);

		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1: số tiền nhận, 2: số tiền cần */
					__( '[Cảnh báo] Tingee (Chế độ B) nhận THIẾU TIỀN. Nhận: %1$s ₫ / Cần: %2$s ₫. Đơn giữ On-Hold — vui lòng xử lý thủ công.', 'tingee-gateway' ),
					number_format( $received_amount, 0, ',', '.' ),
					number_format( $expected_amount, 0, ',', '.' )
				),
				false
			);
			$order->save();

			$this->log(
				sprintf(
					/* translators: 1: Tingee order ID, 2: received amount, 3: expected amount, 4: WooCommerce order ID */
					__( 'Webhook Mode B orderId=%1$s: THIẾU TIỀN. Nhận %2$d / Cần %3$d. Đơn #%4$d giữ On-Hold.', 'tingee-gateway' ),
					$tingee_order_id,
					$received_amount,
					$expected_amount,
					$order->get_id()
				),
				'warning'
			);
		}

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
