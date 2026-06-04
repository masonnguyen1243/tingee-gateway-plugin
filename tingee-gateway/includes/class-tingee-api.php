<?php
/**
 * Lớp gọi API Tingee và sinh chữ ký HMAC-SHA512.
 *
 * Chịu trách nhiệm:
 * - Sinh timestamp đúng định dạng yyyyMMddHHmmssSSS (UTC+7).
 * - Sinh chữ ký HMAC-SHA512 cho mọi request gửi đến Tingee.
 * - Gửi HTTP request đến API Tingee qua wp_remote_get / wp_remote_post.
 * - Parse và trả về kết quả, xử lý lỗi mạng.
 *
 * @package Tingee_Gateway
 * @since   1.0.0
 */

// Ngăn truy cập trực tiếp.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tingee_API
 *
 * Mọi giao tiếp với hệ thống Tingee đều đi qua class này.
 * Các lớp khác (Gateway, Webhook) KHÔNG gọi wp_remote_post/get trực tiếp.
 */
class Tingee_API {

	/**
	 * Base URL môi trường PRODUCTION.
	 *
	 * @var string
	 */
	const BASE_URL_PROD = 'https://open-api.tingee.vn';

	/**
	 * Base URL môi trường UAT (giả lập / sandbox).
	 * Dùng để test mà không ảnh hưởng dữ liệu thật.
	 *
	 * @var string
	 */
	const BASE_URL_UAT = 'https://uat-open-api.tingee.vn';

	// -------------------------------------------------------------------------
	// PHẦN 1: Sinh chữ ký & timestamp
	// -------------------------------------------------------------------------

	/**
	 * Sinh chữ ký HMAC-SHA512 cho một request gửi lên Tingee.
	 *
	 * Công thức (theo tài liệu Tingee):
	 *   signature = HMAC_SHA512( timestamp + ":" + requestBody, secretToken )
	 *
	 * Trong đó:
	 *   - $timestamp : chuỗi yyyyMMddHHmmssSSS (UTC+7).
	 *   - $body      : JSON minified của payload (KHÔNG được format lại — sẽ làm sai chữ ký).
	 *   - $secret    : Secret Token lấy từ trang Developers của Tingee.
	 *
	 * @param string $timestamp Timestamp định dạng yyyyMMddHHmmssSSS.
	 * @param string $body      JSON body của request (đã minify, chưa encode thêm).
	 * @param string $secret    Secret Token của merchant.
	 * @return string           Chữ ký HMAC-SHA512 dạng hex lowercase (128 ký tự).
	 */
	public static function generate_signature( $timestamp, $body, $secret ) {
		// Dữ liệu ký = timestamp + ":" + body — theo đúng tài liệu Tingee.
		$data_to_sign = $timestamp . ':' . $body;

		// hash_hmac với algo 'sha512' trả về hex string lowercase.
		return hash_hmac( 'sha512', $data_to_sign, $secret );
	}

	/**
	 * Tạo timestamp theo định dạng yyyyMMddHHmmssSSS múi giờ UTC+7.
	 *
	 * Tingee yêu cầu:
	 *   - Định dạng: yyyyMMddHHmmssSSS (17 ký tự, toàn số).
	 *   - Múi giờ: UTC+7 (Asia/Ho_Chi_Minh).
	 *   - Timestamp không được cũ quá 10 phút so với thời điểm nhận trên server Tingee.
	 *
	 * Lưu ý kỹ thuật: dùng microtime(true) làm nguồn DUY NHẤT cho cả giây lẫn mili-giây
	 * để tránh race condition khi gọi DateTime() và microtime() tách biệt.
	 *
	 * @return string Timestamp dạng yyyyMMddHHmmssSSS (17 ký tự).
	 */
	public static function generate_timestamp() {
		$tz = new DateTimeZone( 'Asia/Ho_Chi_Minh' ); // UTC+7.

		// DateTime::createFromFormat('U.u') nhận Unix timestamp với micro-giây —
		// đây là nguồn duy nhất cho cả phần giây lẫn mili-giây.
		$now = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
		$now->setTimezone( $tz );

		// format('u') = 6-digit microsecond → 3 ký tự đầu = mili-giây.
		$milliseconds = substr( $now->format( 'u' ), 0, 3 );

		// Kết quả: yyyyMMddHHmmss + SSS = 17 ký tự.
		return $now->format( 'YmdHis' ) . $milliseconds;
	}

	// -------------------------------------------------------------------------
	// PHẦN 2: Gửi HTTP request
	// -------------------------------------------------------------------------

	/**
	 * Lấy Base URL theo môi trường đang cấu hình (UAT hoặc PROD).
	 *
	 * @return string Base URL không có dấu slash cuối.
	 */
	public static function get_base_url() {
		$settings    = get_option( 'woocommerce_tingee_gateway_settings', array() );
		$environment = isset( $settings['environment'] ) ? $settings['environment'] : 'production';

		return ( 'sandbox' === $environment ) ? self::BASE_URL_UAT : self::BASE_URL_PROD;
	}

	/**
	 * Gửi request đến Tingee API (hỗ trợ cả GET và POST).
	 *
	 * Hàm tự động:
	 *   1. Lấy Client ID và Secret Token từ settings của plugin.
	 *   2. Sinh timestamp mới.
	 *   3. JSON-encode $body thành chuỗi minified.
	 *   4. Sinh chữ ký HMAC-SHA512.
	 *   5. Gửi request kèm đủ headers Tingee yêu cầu.
	 *   6. Parse JSON response và trả về mảng PHP chuẩn hóa.
	 *
	 * Lưu ý quan trọng về GET:
	 *   Ngay cả khi method là GET (không có body thực sự), Tingee vẫn yêu cầu
	 *   ký chữ ký với body = "{}" (object JSON rỗng). Hàm này xử lý tự động.
	 *
	 * @param string $endpoint  Đường dẫn API, ví dụ '/v1/get-banks'. Phải có dấu slash đầu.
	 * @param array  $body      Mảng PHP sẽ được JSON-encode làm body. Truyền [] nếu không có body.
	 * @param string $method    HTTP method: 'GET' hoặc 'POST'. Mặc định 'POST'.
	 * @param string $client_id     (tuỳ chọn) Client ID ghi đè settings. Dùng cho nút "Test kết nối".
	 * @param string $secret_token  (tuỳ chọn) Secret Token ghi đè settings. Dùng cho nút "Test kết nối".
	 * @return array {
	 *     @type bool   $success      true nếu HTTP 2xx và code Tingee là "00".
	 *     @type array  $data         Dữ liệu từ field 'data' trong response (đã parse JSON).
	 *     @type string $tingee_code  Mã kết quả Tingee (vd "00", "97"). Rỗng nếu lỗi mạng.
	 *     @type string $message      Thông báo lỗi hoặc mô tả (nếu có).
	 *     @type int    $http_code    HTTP status code (0 nếu lỗi mạng).
	 * }
	 */
	public static function request( $endpoint, $body = array(), $method = 'POST', $client_id = '', $secret_token = '' ) {
		// --- Lấy credentials: ưu tiên tham số truyền vào (dùng cho test), fallback về settings ---
		if ( empty( $client_id ) || empty( $secret_token ) ) {
			$settings     = get_option( 'woocommerce_tingee_gateway_settings', array() );
			$client_id    = isset( $settings['client_id'] ) ? sanitize_text_field( $settings['client_id'] ) : '';
			$secret_token = isset( $settings['secret_token'] ) ? $settings['secret_token'] : '';
		}

		if ( empty( $client_id ) || empty( $secret_token ) ) {
			return array(
				'success'      => false,
				'data'         => array(),
				'tingee_code'  => '',
				'message'      => __( 'Client ID hoặc Secret Token chưa được cấu hình.', 'tingee-gateway' ),
				'http_code'    => 0,
			);
		}

		// --- Sinh timestamp ---
		$timestamp = self::generate_timestamp();

		// --- JSON-encode body (minified — KHÔNG dùng JSON_PRETTY_PRINT) ---
		// Với GET request, body logic là {} nhưng vẫn cần ký chữ ký với "{}".
		$json_body = wp_json_encode( empty( $body ) ? new stdClass() : $body );
		if ( false === $json_body ) {
			return array(
				'success'      => false,
				'data'         => array(),
				'tingee_code'  => '',
				'message'      => __( 'Không thể encode body thành JSON.', 'tingee-gateway' ),
				'http_code'    => 0,
			);
		}

		// --- Sinh chữ ký ---
		$signature = self::generate_signature( $timestamp, $json_body, $secret_token );

		// --- Chuẩn bị headers dùng chung cho cả GET và POST ---
		$headers = array(
			'Content-Type'        => 'application/json',
			'x-client-id'         => $client_id,
			'x-request-timestamp' => $timestamp,
			'x-signature'         => $signature,
		);

		// --- Gửi request ---
		$url          = self::get_base_url() . $endpoint;
		$wp_http_args = array(
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( 'GET' === strtoupper( $method ) ) {
			// GET: không gửi body thực sự trong HTTP, chỉ dùng body để ký chữ ký.
			$response = wp_remote_get( $url, $wp_http_args );
		} else {
			// POST: gửi JSON body.
			$wp_http_args['body'] = $json_body;
			$response             = wp_remote_post( $url, $wp_http_args );
		}

		// --- Xử lý lỗi mạng (WP_Error) ---
		if ( is_wp_error( $response ) ) {
			self::log(
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: error message */
					__( 'Tingee API lỗi mạng [%1$s %2$s]: %3$s', 'tingee-gateway' ),
					strtoupper( $method ),
					$endpoint,
					$response->get_error_message()
				),
				'error'
			);

			return array(
				'success'      => false,
				'data'         => array(),
				'tingee_code'  => '',
				'message'      => sanitize_text_field( $response->get_error_message() ),
				'http_code'    => 0,
			);
		}

		// --- Parse HTTP response ---
		$http_code   = (int) wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$parsed_body = json_decode( $body_raw, true );

		// JSON parse thất bại.
		if ( null === $parsed_body ) {
			return array(
				'success'      => false,
				'data'         => array(),
				'tingee_code'  => '',
				'message'      => sprintf(
					/* translators: 1: HTTP code, 2: raw body (truncated) */
					__( 'Phản hồi không phải JSON hợp lệ (HTTP %1$d): %2$s', 'tingee-gateway' ),
					$http_code,
					esc_html( substr( $body_raw, 0, 300 ) )
				),
				'http_code'    => $http_code,
			);
		}

		// --- Chuẩn hóa kết quả ---
		// Tingee có 3 kiểu response thực tế:
		//   Kiểu A: array thẳng          — [ {...}, {...} ]
		//   Kiểu B: object có code field  — { "code": "00", "message": "...", "data": {...} }
		//   Kiểu C: object không có code  — { "data": {...} } hoặc { "key": "value", ... }
		$is_list = isset( $parsed_body[0] );

		if ( $is_list ) {
			// Kiểu A: array thẳng — HTTP 2xx là thành công.
			$tingee_code = '00';
			$api_message = '';
			$api_data    = $parsed_body;
		} elseif ( isset( $parsed_body['code'] ) ) {
			// Kiểu B: object có code field — kiểm tra code == "00".
			$tingee_code = (string) $parsed_body['code'];
			$api_message = isset( $parsed_body['message'] ) ? sanitize_text_field( $parsed_body['message'] ) : '';
			$api_data    = isset( $parsed_body['data'] ) ? $parsed_body['data'] : array();
		} else {
			// Kiểu C: object không có code — HTTP 2xx là thành công.
			$tingee_code = ( $http_code >= 200 && $http_code < 300 ) ? '00' : '';
			$api_message = isset( $parsed_body['message'] ) ? sanitize_text_field( $parsed_body['message'] ) : '';
			$api_data    = isset( $parsed_body['data'] ) ? $parsed_body['data'] : $parsed_body;
		}

		// Thành công khi: HTTP 2xx VÀ Tingee code là "00".
		$success = ( $http_code >= 200 && $http_code < 300 ) && ( '00' === $tingee_code );

		if ( ! $success ) {
			self::log(
				sprintf(
					/* translators: 1: endpoint, 2: HTTP code, 3: Tingee code, 4: message */
					__( 'Tingee API lỗi [%1$s] HTTP=%2$d code=%3$s: %4$s', 'tingee-gateway' ),
					$endpoint,
					$http_code,
					$tingee_code,
					$api_message
				),
				'error'
			);
		} else {
			self::log(
				sprintf(
					/* translators: 1: HTTP method, 2: endpoint, 3: HTTP code */
					__( 'Tingee API thành công [%1$s %2$s] HTTP=%3$d', 'tingee-gateway' ),
					strtoupper( $method ),
					$endpoint,
					$http_code
				),
				'info'
			);
		}

		return array(
			'success'      => $success,
			'data'         => $api_data,
			'tingee_code'  => $tingee_code,
			'message'      => $api_message,
			'http_code'    => $http_code,
		);
	}

	// -------------------------------------------------------------------------
	// PHẦN 3: Các endpoint cụ thể
	// -------------------------------------------------------------------------

	/**
	 * Lấy danh sách ngân hàng Tingee hỗ trợ.
	 *
	 * Endpoint: GET /v1/get-banks
	 * Dùng để: hiển thị danh sách NH cho admin chọn tài khoản nhận tiền,
	 * và dùng làm endpoint "kiểm tra kết nối" vì không cần body phức tạp.
	 *
	 * Response data mỗi item: { code, name, bin, shortName, urlLogo }
	 *
	 * @param string $client_id    (tuỳ chọn) Ghi đè Client ID từ settings.
	 * @param string $secret_token (tuỳ chọn) Ghi đè Secret Token từ settings.
	 * @return array Kết quả chuẩn hóa từ self::request().
	 */
	public static function get_banks( $client_id = '', $secret_token = '' ) {
		return self::request( '/v1/get-banks', array(), 'GET', $client_id, $secret_token );
	}

	/**
	 * Tạo QR động cho một đơn hàng — Chế độ A (QR + Webhook).
	 *
	 * Endpoint: POST /v1/generate-dynamic-qr
	 *
	 * Response data: { qrCode, qrAccount, billId }
	 *   - qrCode     : Chuỗi nội dung QR để render thành hình ảnh.
	 *   - qrAccount  : Số tài khoản nhận tiền (hiển thị cho khách).
	 *   - billId     : Mã hóa đơn trong hệ thống Tingee — dùng để map webhook → đơn hàng.
	 *
	 * @param array $params {
	 *     @type string $vaAccountNumber  Số VA của merchant (bắt buộc).
	 *     @type string $qrCodeType       'dynamic-one-time-payment' hoặc 'dynamic-recurring-payment' (bắt buộc).
	 *     @type string $bankBin          BIN code của ngân hàng VA (bắt buộc).
	 *     @type int    $amount           Số tiền VND, không thập phân (bắt buộc).
	 *     @type string $purpose          Nội dung chuyển khoản hiển thị trên QR (không bắt buộc).
	 *     @type int    $expireInMinute   Số phút QR còn hiệu lực (bắt buộc).
	 *     @type int    $merchantId       Master Merchant ID (không bắt buộc).
	 * }
	 * @return array Kết quả chuẩn hóa từ self::request().
	 */
	public static function create_dynamic_qr( $params ) {
		return self::request( '/v1/generate-dynamic-qr', $params );
	}

	/**
	 * Tạo Static VietQR cho một đơn hàng — dùng khi Dynamic QR chưa được tài khoản hỗ trợ.
	 *
	 * Endpoint: POST /v1/generate-viet-qr
	 *
	 * Response data: { qrCode, qrCodeImage }
	 *   - qrCode      : Chuỗi VietQR (EMV QR format) — có thể dùng để tự render.
	 *   - qrCodeImage : Ảnh QR dạng Base64 PNG — dùng trực tiếp trong thẻ <img>.
	 *
	 * Khác với Dynamic QR:
	 *   - Không có billId → webhook phải match đơn qua trường `content` (nội dung CK).
	 *   - Không có thời hạn hết hạn (QR tĩnh — luôn quét được).
	 *
	 * @param array $params {
	 *     @type string $bankBin       BIN code của ngân hàng (bắt buộc, vd "970436" = VPBank).
	 *     @type string $accountNumber Số tài khoản nhận tiền (bắt buộc).
	 *     @type int    $amount        Số tiền VND (không bắt buộc — nếu để trống khách tự nhập).
	 *     @type string $content       Nội dung chuyển khoản mặc định (không bắt buộc).
	 *     @type int    $merchantId    Master Merchant ID (không bắt buộc).
	 * }
	 * @return array Kết quả chuẩn hóa từ self::request().
	 */
	public static function create_static_qr( $params ) {
		return self::request( '/v1/generate-viet-qr', $params );
	}

	/**
	 * Tạo link thanh toán (Checkout URL) — Chế độ B (Payment Gateway Redirect).
	 *
	 * Endpoint: POST /v1/payment-gateway/create-link
	 *
	 * Response: $data là Checkout URL dạng string — redirect khách đến URL này.
	 *
	 * @param array $params {
	 *     @type string $requestId       UUID v4, tối đa 36 ký tự (bắt buộc).
	 *     @type string $orderId         Mã đơn từ hệ thống của bạn, tối đa 100 ký tự, chỉ [a-zA-Z0-9-_] (bắt buộc).
	 *     @type int    $amount          Số tiền VND (bắt buộc, 2,000–10,000,000,000).
	 *     @type string $returnUrl       URL redirect sau khi khách thanh toán (không bắt buộc).
	 *     @type string $description     Mô tả giao dịch, tối đa 200 ký tự (không bắt buộc).
	 *     @type int    $expireInMinute  Số phút link còn hiệu lực 5–30, mặc định 30 (không bắt buộc).
	 *     @type string $customerEmail   Email khách hàng (không bắt buộc).
	 * }
	 * @return array Kết quả chuẩn hóa từ self::request(). $data là Checkout URL string.
	 */
	public static function create_payment_link( $params ) {
		return self::request( '/v1/payment-gateway/create-link', $params );
	}

	// -------------------------------------------------------------------------
	// PHẦN 4: Xác minh webhook
	// -------------------------------------------------------------------------

	/**
	 * Xác minh chữ ký webhook gửi về từ Tingee.
	 *
	 * Tingee ký payload bằng:
	 *   HMAC_SHA512( requestTimestamp + ':' + rawBody, secretToken )
	 * rồi gửi kết quả trong header x-signature.
	 *
	 * Dùng hash_equals() để so sánh — tránh timing attack.
	 *
	 * @param string $received_signature Chữ ký từ header x-signature.
	 * @param string $timestamp          Giá trị header x-request-timestamp.
	 * @param string $raw_body           Body thô (CHƯA json_decode — giữ nguyên byte).
	 * @param string $secret             Secret Token của merchant.
	 * @return bool true nếu hợp lệ.
	 */
	public static function verify_webhook_signature( $received_signature, $timestamp, $raw_body, $secret ) {
		$expected = self::generate_signature( $timestamp, $raw_body, $secret );
		return hash_equals( $expected, $received_signature );
	}

	// -------------------------------------------------------------------------
	// PHẦN 5: Tiện ích nội bộ
	// -------------------------------------------------------------------------

	/**
	 * Ghi log vào WooCommerce Logger (nếu có).
	 * Không bao giờ log secret token — dùng mask_secret() nếu cần tham chiếu.
	 *
	 * @param string $message Nội dung log.
	 * @param string $level   Level: 'info' | 'warning' | 'error'. Mặc định 'error'.
	 */
	private static function log( $message, $level = 'error' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'tingee-gateway' ) );
		}
	}

	/**
	 * Che bớt secret token để an toàn khi ghi log hoặc hiển thị.
	 *
	 * Ví dụ: "abcdef1234567890" → "abcd************"
	 * Chỉ giữ lại 4 ký tự đầu, phần còn lại thay bằng '*'.
	 * Nếu chuỗi ngắn hơn 4 ký tự → trả về "****" hoàn toàn.
	 *
	 * @param string $secret Secret Token cần che.
	 * @return string Chuỗi đã mask.
	 */
	public static function mask_secret( $secret ) {
		$len = strlen( $secret );
		if ( $len <= 4 ) {
			return '****';
		}
		return substr( $secret, 0, 4 ) . str_repeat( '*', $len - 4 );
	}
}
