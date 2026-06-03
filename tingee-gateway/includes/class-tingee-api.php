<?php
/**
 * Lớp gọi API Tingee và sinh chữ ký HMAC-SHA512.
 *
 * Chịu trách nhiệm:
 * - Sinh timestamp đúng định dạng yyyyMMddHHmmssSSS (UTC+7).
 * - Sinh chữ ký HMAC-SHA512 cho mọi request gửi đến Tingee.
 * - Gửi HTTP request đến API Tingee qua wp_remote_post.
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
 * Các lớp khác (Gateway, Webhook) KHÔNG gọi wp_remote_post trực tiếp.
 */
class Tingee_API {

	/**
	 * Base URL của Tingee API.
	 * Không có dấu slash ở cuối.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.tingee.vn';

	/**
	 * Sinh chữ ký HMAC-SHA512 cho một request gửi lên Tingee.
	 *
	 * Công thức (theo tài liệu Tingee):
	 *   signature = HMAC_SHA512( timestamp + ":" + requestBody, secretToken )
	 *
	 * Trong đó:
	 *   - $timestamp : chuỗi yyyyMMddHHmmssSSS (UTC+7) — lấy từ tingee_generate_timestamp().
	 *   - $body      : JSON minified của payload (KHÔNG được format lại — sẽ làm sai chữ ký).
	 *   - $secret    : Secret Token lấy từ trang Developers của Tingee.
	 *
	 * Hàm trả về chuỗi hex lowercase — đúng với giá trị header x-signature Tingee mong đợi.
	 *
	 * @param string $timestamp Timestamp định dạng yyyyMMddHHmmssSSS.
	 * @param string $body      JSON body của request (đã minify, chưa encode thêm).
	 * @param string $secret    Secret Token của merchant.
	 * @return string           Chữ ký HMAC-SHA512 dạng hex.
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
	 *   - Định dạng: yyyyMMddHHmmssSSS (năm 4 số, tháng, ngày, giờ, phút, giây, mili-giây 3 số).
	 *   - Múi giờ: UTC+7 (Asia/Bangkok / Asia/Ho_Chi_Minh).
	 *   - Timestamp không được cũ quá 10 phút so với thời điểm nhận trên server Tingee.
	 *
	 * @return string Timestamp dạng yyyyMMddHHmmssSSS.
	 */
	public static function generate_timestamp() {
		// Tạo DateTimeZone cho UTC+7.
		$tz = new DateTimeZone( 'Asia/Ho_Chi_Minh' ); // UTC+7, tương đương Asia/Bangkok.

		// Dùng DateTime::createFromFormat với 'U.u' (Unix timestamp + microseconds) làm nguồn
		// DUY NHẤT cho cả phần giây lẫn mili-giây — tránh lệch giữa hai lần gọi hàm thời gian.
		// microtime(true) trả về float: phần nguyên = giây Unix, phần thập phân = micro-giây.
		$now = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
		$now->setTimezone( $tz );

		// Lấy mili-giây từ chính đối tượng DateTime (3 chữ số đầu của microsecond).
		// DateTime::format('u') = 6-digit microsecond → lấy 3 chữ số đầu = mili-giây.
		$milliseconds = substr( $now->format( 'u' ), 0, 3 );

		// Ghép định dạng: yyyyMMddHHmmss + mili-giây (17 ký tự tổng).
		return $now->format( 'YmdHis' ) . $milliseconds;
	}

	/**
	 * Gửi request đến Tingee API.
	 *
	 * Hàm tự động:
	 *   1. Lấy Client ID và Secret Token từ settings của plugin.
	 *   2. Sinh timestamp mới.
	 *   3. JSON-encode $body thành chuỗi minified.
	 *   4. Sinh chữ ký HMAC-SHA512.
	 *   5. Gửi POST request kèm đủ headers Tingee yêu cầu.
	 *   6. Parse JSON response và trả về mảng PHP.
	 *
	 * @param string $endpoint  Đường dẫn API, ví dụ '/v1/get-banks'. Có dấu slash đầu.
	 * @param array  $body      Mảng PHP sẽ được JSON-encode. Truyền [] nếu không có body.
	 * @return array {
	 *     @type bool   $success  true nếu request thành công và code HTTP 2xx.
	 *     @type array  $data     Dữ liệu response (đã parse JSON). Rỗng nếu lỗi.
	 *     @type string $message  Thông báo lỗi (nếu có).
	 *     @type int    $code     HTTP status code.
	 * }
	 */
	public static function request( $endpoint, $body = array() ) {
		// --- Lấy credentials từ settings của plugin ---
		$gateway_settings = get_option( 'woocommerce_tingee_gateway_settings', array() );
		$client_id        = isset( $gateway_settings['client_id'] ) ? sanitize_text_field( $gateway_settings['client_id'] ) : '';
		$secret_token     = isset( $gateway_settings['secret_token'] ) ? $gateway_settings['secret_token'] : '';

		if ( empty( $client_id ) || empty( $secret_token ) ) {
			return array(
				'success' => false,
				'data'    => array(),
				'message' => __( 'Client ID hoặc Secret Token chưa được cấu hình.', 'tingee-gateway' ),
				'code'    => 0,
			);
		}

		// --- Sinh timestamp và JSON body ---
		$timestamp = self::generate_timestamp();

		// JSON minified — KHÔNG dùng JSON_PRETTY_PRINT vì sẽ làm sai chữ ký.
		$json_body = wp_json_encode( $body );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'data'    => array(),
				'message' => __( 'Không thể encode body thành JSON.', 'tingee-gateway' ),
				'code'    => 0,
			);
		}

		// --- Sinh chữ ký ---
		$signature = self::generate_signature( $timestamp, $json_body, $secret_token );

		// --- Chuẩn bị headers ---
		$headers = array(
			'Content-Type'        => 'application/json',
			'x-client-id'         => $client_id,
			'x-request-timestamp' => $timestamp,
			'x-signature'         => $signature,
		);

		// --- Gửi request qua WordPress HTTP API ---
		$url      = self::BASE_URL . $endpoint;
		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $json_body,
				'timeout' => 30, // Giây — đủ cho mạng chậm, không block PHP quá lâu.
			)
		);

		// --- Xử lý lỗi mạng (WP_Error) ---
		if ( is_wp_error( $response ) ) {
			// Log lỗi mạng (không log secret).
			if ( class_exists( 'WC_Logger' ) ) {
				$logger = wc_get_logger();
				$logger->error(
					sprintf(
						// translators: 1: endpoint, 2: error message.
						__( 'Tingee API lỗi mạng khi gọi %1$s: %2$s', 'tingee-gateway' ),
						esc_html( $endpoint ),
						esc_html( $response->get_error_message() )
					),
					array( 'source' => 'tingee-gateway' )
				);
			}

			return array(
				'success' => false,
				'data'    => array(),
				'message' => $response->get_error_message(),
				'code'    => 0,
			);
		}

		// --- Parse HTTP response ---
		$http_code   = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$parsed_body = json_decode( $body_raw, true );

		// Nếu JSON parse thất bại, trả body thô trong message.
		if ( null === $parsed_body ) {
			return array(
				'success' => false,
				'data'    => array(),
				'message' => sprintf(
					// translators: 1: HTTP code, 2: raw response body.
					__( 'Phản hồi không phải JSON hợp lệ (HTTP %1$d): %2$s', 'tingee-gateway' ),
					(int) $http_code,
					esc_html( substr( $body_raw, 0, 200 ) ) // Giới hạn 200 ký tự để tránh log quá dài.
				),
				'code'    => (int) $http_code,
			);
		}

		// HTTP 2xx = thành công.
		$success = ( $http_code >= 200 && $http_code < 300 );

		// Lấy message từ response nếu có (Tingee thường trả field 'message' khi lỗi).
		$api_message = '';
		if ( ! $success ) {
			$api_message = isset( $parsed_body['message'] ) ? sanitize_text_field( $parsed_body['message'] ) : __( 'Lỗi không xác định từ Tingee API.', 'tingee-gateway' );
		}

		return array(
			'success' => $success,
			'data'    => $parsed_body,
			'message' => $api_message,
			'code'    => (int) $http_code,
		);
	}

	/**
	 * Xác minh chữ ký webhook gửi về từ Tingee.
	 *
	 * Tingee ký payload bằng:
	 *   HMAC_SHA512( requestTimestamp + ':' + rawBody, secretToken )
	 * rồi gửi kết quả trong header x-signature.
	 *
	 * Hàm dùng hash_equals() để so sánh — tránh timing attack.
	 *
	 * @param string $received_signature Chữ ký lấy từ header x-signature của request.
	 * @param string $timestamp          Giá trị header x-request-timestamp.
	 * @param string $raw_body           Body thô của webhook (CHƯA json_decode — phải giữ nguyên byte).
	 * @param string $secret             Secret Token của merchant.
	 * @return bool true nếu chữ ký hợp lệ, false nếu không khớp.
	 */
	public static function verify_webhook_signature( $received_signature, $timestamp, $raw_body, $secret ) {
		// Tính chữ ký kỳ vọng theo công thức Tingee.
		$expected_signature = self::generate_signature( $timestamp, $raw_body, $secret );

		// hash_equals tránh timing attack khi so sánh chuỗi bí mật.
		return hash_equals( $expected_signature, $received_signature );
	}
}
