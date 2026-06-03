# change-log.md — Tingee Gateway

> Ghi lại MỌI thay đổi sau mỗi lần triển khai.
> Claude PHẢI cập nhật file này ngay sau khi hoàn thành một task (xem CLAUDE.md mục 1, quy tắc 2).
>
> Cách dùng:
> - Mỗi lần làm → thêm một mục mới ở ĐẦU danh sách (mới nhất lên trên).
> - Ghi: ngày (YYYY-MM-DD), task ID trong implementation-plan, loại thay đổi, mô tả ngắn, file bị ảnh hưởng.
> - Loại thay đổi: [Tính năng mới] · [Cải thiện] · [Fix lỗi] · [Bảo mật] · [Tài liệu] · [Đóng gói].
>
> Khi release lên WordPress.org, phần "Changelog theo phiên bản" bên dưới sẽ được copy sang `readme.txt`.

---

## Nhật ký triển khai (theo ngày)

<!-- MẪU — copy khối này cho mỗi lần làm, điền vào, để lên trên cùng:

-->

### YYYY-MM-DD — [Task T?.?] Tiêu đề ngắn
- **Loại**: [Tính năng mới] / [Fix lỗi] / [Bảo mật] / ...
- **Mô tả**: (làm gì, vì sao)
- **File thay đổi**: path/to/file.php, ...
- **Trạng thái DoD**: Đạt / Chưa (lý do)
- **Cần Cường test**: (nếu có)

-->

### 2026-06-03 — [Task T4.3] JS poll trạng thái đơn hàng trên trang thank-you
- **Loại**: [Tính năng mới]
- **Mô tả**:
  - **`assets/js/checkout.js`** — file mới: đọc `data-order-id` + `data-nonce` từ `#tingee-payment-box`, gọi AJAX `tingee_check_status` mỗi 5 giây, tối đa 36 lần (~3 phút). Khi nhận `paid: true`: thay spinner bằng thông báo thành công (`tingee-status-success`), ẩn ghi chú, chuyển hướng về trang xem đơn sau 3 giây.
  - **`tingee-gateway.php`** — thêm AJAX handler `tingee_ajax_check_status()`: đăng ký cả `wp_ajax_` + `wp_ajax_nopriv_` (hỗ trợ guest checkout); verify nonce theo công thức `tingee_check_status_{order_id}` (khớp với nonce sinh ở T4.2); xác nhận payment method là Tingee trước khi trả dữ liệu; trả `{ paid, status, redirect_url }`.
  - **`class-tingee-gateway.php`** (`enqueue_checkout_scripts()`) — enqueue `checkout.js` với dependency `jquery`, load ở footer; `wp_localize_script` truyền `ajaxUrl` và chuỗi i18n `paid`.
- **File thay đổi**: `assets/js/checkout.js` (mới), `tingee-gateway.php`, `includes/class-tingee-gateway.php`
- **Trạng thái DoD**: Đạt — poll mỗi 5s, dừng khi paid hoặc hết 3 phút; hiện thành công trong ≤10s sau khi đơn chuyển sang paid (2 vòng poll tối đa). Bảo mật: nonce per-order, payment method check, hỗ trợ nopriv.
- **Cần Cường test**: Đặt đơn → chọn Tingee → trang thank-you hiện QR. Giả lập: vào WooCommerce → Đơn hàng → tìm đơn → đổi trạng thái sang "Processing" thủ công → trong ≤10s trang tự đổi sang "✓ Thanh toán thành công!".

---

### 2026-06-03 — [Task T4.2] Hiển thị QR + hộp thông tin chuyển khoản trên trang thank-you
- **Loại**: [Tính năng mới]
- **Mô tả**:
  - **`thankyou_page($order_id)`** — method mới trong `Tingee_Gateway`: đọc 5 meta HPOS-safe (`_tingee_bill_id`, `_tingee_qr_code`, `_tingee_qr_account`, `_tingee_amount`, `_tingee_purpose`) và render hộp QR. QR code hỗ trợ cả 2 định dạng: URL (dùng `esc_url`) và base64 PNG (dùng `esc_attr`). Số tiền format kiểu Việt (150.000 ₫). Sinh nonce `tingee_check_status_{id}` vào `data-nonce` để T4.3 dùng khi poll. Không hiển thị nếu đơn đã thanh toán hoặc thiếu meta.
  - **`enqueue_checkout_scripts()`** — enqueue `assets/css/checkout.css` chỉ trên trang order-received, chỉ khi payment method là `tingee_gateway` (tránh load thừa).
  - Hook `woocommerce_thankyou_tingee_gateway` — tự scope theo payment method, chỉ chạy với đơn Tingee.
  - **`assets/css/checkout.css`** — file mới: style cho hộp QR, bảng thông tin, nút sao chép, animation spinner chờ thanh toán, trạng thái success (dùng bởi T4.3).
  - Nút "Sao chép" đã có HTML/CSS (T4.4 sẽ thêm JS click handler).
- **File thay đổi**: `includes/class-tingee-gateway.php`, `assets/css/checkout.css` (mới)
- **Trạng thái DoD**: Đạt — QR và bảng thông tin render đúng. Mọi output đã escape. Nonce sẵn sàng cho T4.3.
- **Cần Cường test**: Đặt đơn hàng → chọn Tingee → Place Order → kiểm tra trang thank-you hiện QR + số TK + số tiền + nội dung CK. Nếu chưa hiển thị: xem F12 Console xem có lỗi JS không; kiểm tra tab Network xem `checkout.css` được tải không.

---

### 2026-06-03 — [Fix] Parse response Tingee — hỗ trợ thêm Kiểu C (object không có field `code`)
- **Loại**: [Fix lỗi]
- **Mô tả**: Log báo `HTTP=200 code=:` khi gọi `/v1/get-banks` — Tingee trả object JSON không có field `code` nhưng code cũ chỉ xử lý 2 kiểu (array thẳng và object có `code`). Thêm nhánh Kiểu C: object không có `code` + HTTP 2xx → coi là thành công; lấy `$parsed_body['data']` nếu có, ngược lại dùng cả object.
- **File thay đổi**: `includes/class-tingee-api.php`
- **Trạng thái**: Fix xong.

---

### 2026-06-03 — [Task T4.1] process_payment() Chế độ A — QR động + lưu meta
- **Loại**: [Tính năng mới]
- **Mô tả**:
  - **`Tingee_API::create_dynamic_qr()`** — thêm static method mới vào `class-tingee-api.php`, gọi `POST /v1/generate-dynamic-qr`, nhận về `qrCode`, `qrAccount`, `billId`.
  - **`process_payment()`** — triển khai đầy đủ cho Chế độ A: (1) validate cấu hình `va_account_number` + `bank_bin`; (2) gọi `create_dynamic_qr()` với amount (int VND), purpose = prefix + order number; (3) lưu 5 meta HPOS-safe (`_tingee_bill_id`, `_tingee_qr_code`, `_tingee_qr_account`, `_tingee_amount`, `_tingee_purpose`); (4) đặt đơn On-Hold kèm note billId; (5) redirect về thank-you page. Chế độ B giữ placeholder, sẽ triển khai ở T6.1.
  - **Settings mới**: thêm 2 field `bank_bin` (bắt buộc cho QR API) và `payment_prefix` (tiền tố nội dung, ví dụ "DH").
- **File thay đổi**: `includes/class-tingee-api.php`, `includes/class-tingee-gateway.php`
- **Trạng thái DoD**: Đạt — code đúng flow, HPOS-safe, escape/sanitize đầy đủ. Cần test thực tế với credentials Tingee.
- **Cần Cường test**: Vào Settings → thêm `bank_bin` (BIN ngân hàng VA của bạn). Tạo đơn hàng test → chọn Tingee → Place Order → kiểm tra: đơn chuyển On-Hold, order note có billId, order meta có `_tingee_bill_id` / `_tingee_qr_code`.

---

### 2026-06-03 — [Task T3.1 + T3.2 + T3.3] WC Payment Gateway & Settings hoàn chỉnh
- **Loại**: [Tính năng mới]
- **Mô tả**:
  - **T3.1** — `Tingee_Gateway extends WC_Payment_Gateway` đã đăng ký qua filter `woocommerce_payment_gateways`. Plugin xuất hiện ở WooCommerce → Settings → Payments.
  - **T3.2** — `init_form_fields()` khai báo đầy đủ 9 field theo product-spec F3: `enabled`, `title`, `description`, `environment`, `client_id`, `secret_token`, `va_account_number`, nút `test_connection`, `integration_mode` (chọn Chế độ A/B).
  - **T3.3** — `process_admin_options` hook đăng ký chuẩn WooCommerce; `__construct()` đọc lại toàn bộ field mới (`va_account_number`, `integration_mode`) vào property để dùng trong toàn plugin.
- **File thay đổi**: `includes/class-tingee-gateway.php`, `implementation-plan.md`
- **Trạng thái DoD**: Đạt — toàn bộ field lưu/đọc qua WooCommerce Options API, không có secret cứng trong code.
- **Cần Cường test**: Vào WooCommerce → Settings → Payments → Tingee Gateway → điền đầy đủ các field → Save changes → reload trang → kiểm tra tất cả giá trị vẫn giữ nguyên.

---

### 2026-06-03 — [Task T2.4] Xác nhận hoàn thành — Kiểm tra kết nối (F2)
- **Loại**: [Tính năng mới]
- **Mô tả**: Rà soát và xác nhận T2.4 đã được triển khai đầy đủ (code nằm trong T2.3 trước). Gồm: AJAX handler `tingee_ajax_test_connection()` có nonce + capability check, UI nút custom HTML field, `admin_enqueue_scripts()` truyền `ajaxUrl`/`nonce`/`i18n` sang JS, `assets/js/admin.js` xử lý click + hiển thị kết quả inline.
- **File thay đổi**: Không có file mới — code đã có từ T2.3.
- **Trạng thái DoD**: Đạt.

---

### 2026-06-03 — [Fix] Parse response Tingee — hỗ trợ array thẳng
- **Loại**: [Fix lỗi]
- **Mô tả**: Tingee trả về array JSON thẳng `[{...},{...}]` thay vì `{"code":"00","data":[...]}` như docs mẫu. Fix logic parse trong `request()` để nhận diện cả 2 kiểu response: array thẳng (list) → coi là thành công ngay khi HTTP 2xx; object có field `code` → kiểm tra `code == "00"` như cũ.
- **File thay đổi**: `includes/class-tingee-api.php`
- **Trạng thái**: Đã test thực tế — nút "Kiểm tra kết nối" hiện "Kết nối thành công! Tingee hỗ trợ 14 ngân hàng."

---

### 2026-06-03 — [Task T2.3] Hàm request() hoàn chỉnh + get_banks() + nút Kiểm tra kết nối
- **Loại**: [Tính năng mới]
- **Mô tả**:
  - **`Tingee_API::request()`** — nâng cấp hỗ trợ cả GET và POST; thêm tham số `$client_id`/`$secret_token` để override credentials (dùng khi test từ form chưa lưu); chuẩn hóa output thêm field `tingee_code` (mã "00" = thành công); thêm UAT/PROD URL tự động theo field `environment`.
  - **`Tingee_API::get_banks()`** — wrapper gọi `GET /v1/get-banks`, dùng làm endpoint kiểm tra kết nối.
  - **`Tingee_API::get_base_url()`** — đọc `environment` từ settings, trả về URL UAT hoặc PROD tương ứng.
  - **`class-tingee-gateway.php`** — thêm field `environment` (Sandbox/Production), `client_id`, `secret_token`, nút "Kiểm tra kết nối" (custom HTML field); enqueue `admin.js` chỉ trên trang Settings của gateway.
  - **`assets/js/admin.js`** — JS xử lý click nút test: lấy credentials từ form, gọi AJAX, hiện kết quả inline.
  - **`tingee-gateway.php`** — thêm AJAX handler `tingee_test_connection` với nonce check + capability check.
- **File thay đổi**: `includes/class-tingee-api.php`, `includes/class-tingee-gateway.php`, `tingee-gateway.php`, `assets/js/admin.js` (mới)
- **Trạng thái DoD**: Đạt về code. Cần Cường test thực tế với credentials thật.
- **Cần Cường test**: Vào WooCommerce → Settings → Payments → Tingee Gateway → nhập Client ID + Secret Token (môi trường Sandbox) → bấm "Kiểm tra kết nối" → phải thấy "Kết nối thành công! Tingee hỗ trợ X ngân hàng."

---

### 2026-06-03 — [Task T2.2] Fix generate_timestamp() — yyyyMMddHHmmssSSS UTC+7
- **Loại**: [Cải thiện] [Fix lỗi]
- **Mô tả**: Fix edge case trong `generate_timestamp()`: trước đây dùng `new DateTime()` và `microtime(true)` tách biệt có thể bị lệch mili-giây khi CPU tải nặng. Nay dùng `DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)))` làm nguồn duy nhất cho cả phần giây lẫn mili-giây, đảm bảo output luôn nhất quán 17 ký tự. Đã test edge case `usec=007500` → `ms=007` (leading zero đúng).
- **File thay đổi**: `tingee-gateway/includes/class-tingee-api.php`
- **Trạng thái DoD**: Đạt — output đúng định dạng `yyyyMMddHHmmssSSS`, 17 ký tự, toàn số, múi giờ UTC+7.
- **Cần Cường test**: Không cần test riêng; sẽ kiểm chứng thực tế qua T2.3.

---

### 2026-06-03 — [Task T2.1] Implement lớp Tingee_API — sinh chữ ký HMAC-SHA512
- **Loại**: [Tính năng mới] [Bảo mật]
- **Mô tả**: Triển khai đầy đủ `class Tingee_API` trong `includes/class-tingee-api.php` với các phương thức:
  - `generate_signature($timestamp, $body, $secret)` — HMAC-SHA512 theo công thức `timestamp + ":" + body`.
  - `generate_timestamp()` — sinh timestamp `yyyyMMddHHmmssSSS` múi giờ UTC+7.
  - `request($endpoint, $body)` — gửi POST request kèm đủ headers Tingee, parse JSON, xử lý lỗi mạng.
  - `verify_webhook_signature(...)` — xác minh chữ ký webhook bằng `hash_equals()` (chống timing attack).
- **File thay đổi**: `tingee-gateway/includes/class-tingee-api.php`
- **Trạng thái DoD**: Đạt — logic HMAC-SHA512 được verify bằng test script Python (kết quả khớp: 128-char hex, phân biệt body minify vs pretty-print, timing-safe compare).
- **Cần Cường test**: Chưa cần test thủ công ở bước này (chưa có endpoint thật để gọi). Sẽ test thực tế ở T2.3 khi gọi `/v1/get-banks`.

---

### 2026-06-03 — [Khởi tạo] Thiết lập bộ tài liệu dự án
- **Loại**: [Tài liệu]
- **Mô tả**: Tạo bộ 4 file định hướng dự án: CLAUDE.md, product-spec.md, implementation-plan.md, change-log.md. Nghiên cứu tài liệu API Tingee (banking, payment-gateway, webhook, qr, config-info) và plugin SePay làm tham chiếu.
- **File thay đổi**: CLAUDE.md, product-spec.md, implementation-plan.md, change-log.md
- **Trạng thái DoD**: Đạt (chưa có code)
- **Cần Cường test**: Đọc lại 4 file, xác nhận phạm vi & tên plugin trước khi bắt đầu Giai đoạn 0.

---

## Changelog theo phiên bản (sẽ đưa vào readme.txt)

> Định dạng giống WordPress.org. Cập nhật khi release.

### [Chưa phát hành] v0.1.0
- Khởi tạo dự án và tài liệu.

<!--
### v1.0.0 — YYYY-MM-DD
- [Tính năng mới] Thanh toán QR động qua Tingee + tự xác nhận bằng Webhook IPN.
- [Tính năng mới] Hỗ trợ Checkout Blocks.
- [Bảo mật] Xác thực chữ ký HMAC-SHA512 cho webhook; chống xử lý trùng (idempotency).
-->
