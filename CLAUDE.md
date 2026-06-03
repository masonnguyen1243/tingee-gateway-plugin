# CLAUDE.md — Hướng dẫn Claude khi code plugin "Tingee Gateway"

> File này định nghĩa cách Claude (và bất kỳ AI/dev nào) được phép hành xử khi viết code cho plugin này.
> ĐỌC FILE NÀY TRƯỚC KHI VIẾT BẤT KỲ DÒNG CODE NÀO.
> Đọc kèm: `product-spec.md` (cái gì), `implementation-plan.md` (làm theo thứ tự nào), `change-log.md` (ghi lại đã làm gì).

---

## 0. Bối cảnh dự án

- **Mục tiêu**: Một WordPress plugin tích hợp cổng thanh toán **Tingee (by HENO)** vào **WooCommerce**, tương tự cách SePay Gateway hoạt động.
- **Cách hoạt động cốt lõi**: Khách đặt hàng → plugin hiển thị QR động (hoặc redirect sang cổng Tingee) → khách chuyển khoản → Tingee gửi **Webhook (IPN)** về site → plugin tự động gạch nợ và chuyển trạng thái đơn hàng.
- **Người chủ dự án**: Cường — **chưa từng làm plugin WordPress**. Vì vậy code phải rõ ràng, có comment giải thích, không "thông minh quá mức".
- **Ngôn ngữ giao tiếp**: Trả lời và comment giải thích bằng **Tiếng Việt**. Tên hàm/biến/code bằng tiếng Anh.

---

## 1. Quy tắc vàng (luôn tuân thủ)

1. **Bám sát tài liệu Tingee chính thức** tại https://developers.tingee.vn — không bịa endpoint, không bịa tên field. Nếu không chắc một field tồn tại, DỪNG LẠI và hỏi, đừng đoán.
2. **Mỗi lần triển khai xong một task → ghi vào `change-log.md`** (ngày, version, mô tả thay đổi). Không bỏ qua bước này.
3. **Làm theo đúng thứ tự trong `implementation-plan.md`.** Không nhảy cóc sang task sau khi task trước chưa "Done".
4. **Không bao giờ commit secret** (Client ID, Secret Token, API key) vào code hay repo. Chúng phải nằm trong settings của plugin (database), lưu qua WordPress Options API.
5. **Bảo mật là điều kiện bắt buộc để được duyệt lên WordPress.org.** Mọi output phải escape, mọi input phải sanitize, mọi form phải có nonce. Xem mục 4.
6. **Hỏi trước khi tự ý mở rộng phạm vi.** Nếu một thay đổi không nằm trong spec, xác nhận với Cường trước.

---

## 2. Coding standards (WordPress)

Tuân thủ **WordPress Coding Standards (WPCS)** cho PHP, JS, CSS.

- **Prefix mọi thứ** để tránh đụng tên với plugin khác. Dùng prefix `tingee_` cho hàm, `Tingee_` cho class, `TINGEE_` cho hằng số, `tingee_gateway_` cho option keys.
  - Ví dụ: `tingee_verify_signature()`, `class Tingee_Gateway_Blocks`, `TINGEE_PLUGIN_VERSION`.
- **Text domain** dùng đúng slug plugin: `tingee-gateway`. Mọi chuỗi hiển thị bọc trong `__()`, `esc_html__()`, `esc_attr__()`.
- **Indent**: dùng tab (theo chuẩn WP), không dùng space.
- **Không dùng PHP short tags** `<?`. Luôn `<?php`.
- **Tương thích PHP 7.2+** và **WordPress 5.6+** (giống mức SePay hỗ trợ). Không dùng cú pháp PHP 8-only nếu chưa kiểm tra.
- **Khai báo HPOS-compatible** (WooCommerce High-Performance Order Storage) — dùng `wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()`, KHÔNG dùng `get_post_meta()` trực tiếp lên order.
- **Hỗ trợ Cart & Checkout Blocks** của WooCommerce (không chỉ shortcode cũ).

---

## 3. Kiến trúc & cấu trúc thư mục

Giữ cấu trúc rõ ràng, mỗi file một trách nhiệm:

```
tingee-gateway/
├── tingee-gateway.php          # File chính: plugin header, hằng số, hook khởi tạo
├── readme.txt                  # Định dạng readme của WordPress.org (KHÔNG phải .md)
├── uninstall.php               # Dọn dữ liệu khi gỡ plugin
├── includes/
│   ├── class-tingee-gateway.php        # Class WC_Payment_Gateway chính
│   ├── class-tingee-api.php            # Gọi API Tingee + sinh chữ ký HMAC-SHA512
│   ├── class-tingee-webhook.php        # Nhận & xác thực webhook IPN
│   ├── class-tingee-blocks.php         # Tích hợp Checkout Blocks
│   └── class-tingee-settings.php       # Các field cấu hình
├── assets/
│   ├── js/        # JS cho trang checkout (poll trạng thái, hiện QR)
│   ├── css/
│   └── images/
└── languages/     # File dịch .pot/.po/.mo
```

**Nguyên tắc tách lớp**: logic gọi API (`class-tingee-api.php`) phải tách khỏi logic WooCommerce (`class-tingee-gateway.php`). Webhook xử lý độc lập. Không nhồi mọi thứ vào một file.

---

## 4. Bảo mật (BẮT BUỘC — đây là lý do plugin bị từ chối nhiều nhất)

WordPress.org từ chối plugin chủ yếu vì 3 lỗi sau. Claude phải tự kiểm tra trước khi báo "Done":

1. **Escape mọi output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. Không bao giờ echo biến trực tiếp.
2. **Sanitize mọi input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_unslash()` cho `$_POST`/`$_GET`/`$_REQUEST`.
3. **Nonce cho mọi form/AJAX**: `wp_nonce_field()` + `check_admin_referer()` / `wp_verify_nonce()`.

Thêm các quy tắc riêng cho payment plugin:

4. **Xác thực chữ ký webhook**: Tingee ký payload bằng `HMAC_SHA512(requestTimestamp + ':' + JSON.stringify(body), secretToken)` và gửi qua header `x-signature`. **Bắt buộc verify chữ ký** trước khi xử lý. Payload sai chữ ký → trả 401, KHÔNG xử lý.
5. **Sinh chữ ký request**: `x-signature = HMAC_SHA512(x-request-timestamp + ":" + requestBody, secretToken)`. Timestamp format `yyyyMMddHHmmssSSS` múi giờ **UTC+7**, không cũ quá 10 phút. `requestBody` phải là JSON minified (không khoảng trắng thừa) — nếu format lại JSON, chữ ký sẽ sai.
6. **Idempotency**: Webhook có thể bị **retry tối đa 5 lần**. Phải dùng mã giao dịch (transaction id) để tránh gạch nợ trùng. Lưu transaction id đã xử lý vào order meta; nếu đã xử lý → trả 200 và bỏ qua.
7. **Không log secret**. Khi debug, mask token.
8. **Endpoint webhook** không yêu cầu đăng nhập (Tingee gọi từ ngoài) → càng phải verify chữ ký chặt. Dùng `register_rest_route` hoặc `woocommerce_api_*` với permission_callback hợp lý.

---

## 5. Quy trình làm việc của Claude mỗi phiên

1. Đọc `implementation-plan.md`, xác định task tiếp theo chưa Done.
2. Đọc lại mục liên quan trong `product-spec.md` để chắc về acceptance criteria.
3. Viết code cho **đúng một task** đó.
4. Tự kiểm tra theo checklist mục 4 (security) và mục 2 (standards).
5. Ghi kết quả vào `change-log.md`. sau đó đánh dấu đã hoàn thành cho task đã làm để không bị nhầm
6. Báo cáo ngắn gọn: đã làm gì, file nào thay đổi, cần Cường test gì.
7. KHÔNG tự động làm task tiếp theo trừ khi được yêu cầu.

---

## 6. Khi không chắc chắn

- Không chắc field API tồn tại → hỏi, hoặc kiểm tra lại tài liệu Tingee, KHÔNG đoán.
- Không chắc về quy tắc WordPress.org → tham chiếu https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Phát hiện rủi ro nhãn hiệu (tên "Tingee") → nhắc Cường (xem mục 7).

---

## 7. Cảnh báo nhãn hiệu (đọc kỹ)

WordPress.org **cấm dùng tên thương hiệu của bên khác** làm tên plugin nếu bạn không phải chủ sở hữu/đối tác được uỷ quyền.

- Nếu Cường **thuộc team HENO/Tingee** hoặc được uỷ quyền → "Tingee Gateway" OK.
- Nếu **không**, tên này có thể bị từ chối. Phương án dự phòng: đặt tên mô tả như **"VietQR Bank Transfer via Tingee for WooCommerce"** (mô tả tích hợp được phép), hoặc tên trung lập, và chỉ nhắc "Tingee" trong phần mô tả chức năng.
- Claude phải dùng tên/slug đã chốt, nhưng nếu chuẩn bị submit lên WordPress.org mà chưa rõ quyền nhãn hiệu → nhắc lại cảnh báo này.
