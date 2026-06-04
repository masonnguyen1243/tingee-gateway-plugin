# Change Log — Tingee Gateway

> Ghi lại tất cả thay đổi theo từng task. Thứ tự: mới nhất ở trên.

---

## [1.0.0] — 2026-06-04 (cập nhật lần 2)

### Bug fixes — QR hiển thị & Webhook nhận

**Bugfix 1** ✅ — Sửa ảnh QR không hiển thị trên trang thank-you

- `includes/class-tingee-gateway.php` — `thankyou_page()`:
  - **Nguyên nhân**: API `POST /v1/generate-viet-qr` trả về `qrCodeImage` dưới dạng Data URI đầy đủ (`data:image/png;base64,XXX`). Code cũ chỉ phân biệt 2 trường hợp (URL bắt đầu `http` hoặc raw base64), nên đã thêm prefix `data:image/png;base64,` lần thứ hai → double-prefix → trình duyệt không parse được → ảnh hiển thị broken.
  - **Sửa**: Thêm nhánh thứ ba `elseif ( 0 === strpos( $qr_code, 'data:' ) )` — nếu giá trị đã là Data URI đầy đủ thì dùng trực tiếp qua `esc_attr()`, không thêm prefix.
  - Logic hiển thị QR sau khi sửa: 3 trường hợp — (1) URL `http...` → `esc_url()`; (2) Data URI `data:...` → `esc_attr()` trực tiếp; (3) Raw base64 → thêm prefix rồi `esc_attr()`.

**Bugfix 2** ✅ — Webhook không nhận được từ Tingee (môi trường local)

- **Không thay đổi code** — đây là vấn đề cấu hình môi trường local:
  - **Nguyên nhân 1**: WordPress đang bật chế độ "Coming soon" → chặn mọi request từ bên ngoài trước khi đến REST API → Tingee nhận redirect thay vì 200/401. **Sửa**: Tắt "Coming soon", chuyển sang Public.
  - **Nguyên nhân 2**: LocalWP Live Link bật HTTP Basic Authentication bắt buộc (username/password tự sinh) → server Tingee không có credentials → bị 401 từ tunnel, chưa bao giờ chạm tới WordPress. **Sửa**: Điền credentials vào webhook URL dạng `https://user:pass@rhetorical-hope.localsite.io/wp-json/tingee-gateway/v1/webhook` khi cấu hình Tingee.
  - **Lưu ý môi trường production**: Khi deploy lên server thật có domain công khai, cả 2 vấn đề trên đều không xuất hiện — chỉ cần điền URL thẳng không cần credentials.

---

## [1.0.0] — 2026-06-04 (cập nhật)

### Giai đoạn 7B — Chuyển sang Static QR

**T7B.4** ✅ — Webhook matching bằng `content` khi không có `billId`

- `includes/class-tingee-webhook.php`:
  - Thay khối `if ( empty( $bill_id ) ) return Ignored` bằng logic phân nhánh:
    - **Có `billId`** (Dynamic QR): tìm đơn qua `_tingee_bill_id` như cũ.
    - **Không có `billId`** (Static QR): tìm đơn qua `_tingee_purpose` = `content` (nội dung CK), lọc thêm `payment_method = tingee_gateway` + `status = on-hold`.
    - Không tìm được theo cả 2 cách → log warning + trả 200 (có thể CK thủ công không liên quan).
  - Thêm biến `$order_identifier` ('billId=...' hoặc 'content=...') để log message rõ ràng theo từng loại QR.
  - Cập nhật 3 log message trong T5.4 và T5.5 từ `$bill_id` → `$order_identifier`.
  - Phần xử lý idempotency + đối soát tiền + `payment_complete()` dùng chung cho cả 2 loại QR.

**T7B.3** ✅ — Sửa `thankyou_page()` bỏ check `bill_id` (T7B.3 làm kèm T7B.2 vì bắt buộc)

- `includes/class-tingee-gateway.php` — `thankyou_page()`:
  - Bỏ `$bill_id = $order->get_meta('_tingee_bill_id')` — meta này không còn được lưu.
  - Đổi điều kiện `empty($bill_id) || empty($qr_code)` → chỉ còn `empty($qr_code)`.
  - Cập nhật docblock bỏ đề cập `_tingee_bill_id`.

**T7B.2** ✅ — Chuyển `process_payment()` sang Static QR

- `includes/class-tingee-gateway.php` — `process_payment()`:
  - Đổi `$qr_params`: `vaAccountNumber` → `accountNumber`, `purpose` → `content`; bỏ `qrCodeType` và `expireInMinute`.
  - Gọi `Tingee_API::create_static_qr()` thay vì `create_dynamic_qr()`.
  - Lưu meta: bỏ `_tingee_bill_id`; lưu `qrCodeImage` (base64 PNG) vào `_tingee_qr_code`.
  - `_tingee_qr_account` lưu từ `$this->va_account_number` (Static QR không trả về `qrAccount`).
  - Thêm `_tingee_qr_type = 'static'` để phân biệt sau khi bật lại Dynamic QR.
  - Cập nhật note trạng thái đơn: bỏ `billId`, ghi nội dung chuyển khoản thay vào.

**T7B.1** ✅ — Thêm `create_static_qr()` vào `class-tingee-api.php`

- `includes/class-tingee-api.php`: thêm static method `create_static_qr($params)` gọi `POST /v1/generate-viet-qr`.
  - Params: `bankBin` (bắt buộc), `accountNumber` (bắt buộc), `amount` (tùy chọn), `content` (tùy chọn), `merchantId` (tùy chọn).
  - Response: `data.qrCode` (chuỗi VietQR EMV), `data.qrCodeImage` (Base64 PNG).
  - Giữ nguyên `create_dynamic_qr()` để dùng lại khi Tingee bật tính năng Dynamic QR.
  - Method delegate về `self::request('/v1/generate-viet-qr', $params)` — tái dùng toàn bộ logic ký chữ ký, gửi request, parse response hiện có.

---

## [1.0.0] — 2026-06-04

### Giai đoạn 7 — Checkout Blocks & tương thích

**T7.2** ✅ — Hardening CSS cross-theme (Storefront, Twenty Twenty-Four, Astra)

- `assets/css/checkout.css`: cải thiện toàn diện để chống xung đột theme.
  - Thêm `box-sizing: border-box` reset cho toàn bộ element trong `.tingee-payment-box`.
  - `.tingee-payment-box__title (h2)`: reset `border` + `padding` (Storefront/Astra thêm border-bottom vào h2).
  - `.tingee-payment-box__qr img`: fix `display: inline-block` (Storefront override `display: block` vào img).
  - `.tingee-transfer-info th, td`: dùng `!important` cho `border`, `background`, `padding` — cần thiết vì TT4 dùng `td, th { border: 1px solid }` (selector quá rộng) và Storefront dùng `table td { border-top }`.
  - Xóa `border-bottom` dòng cuối bảng.
  - `.tingee-copy-btn`: reset mạnh toàn bộ button style (background, border, padding, font, box-shadow, letter-spacing) — mỗi theme style button khác nhau hoàn toàn.
  - `.tingee-block-description`: thêm style mới cho mô tả trong Checkout Blocks (từ T7.1 blocks.js).
  - Thêm responsive breakpoint `@media (max-width: 480px)`.

**T7.1** ✅ — Tích hợp WooCommerce Checkout Blocks (`AbstractPaymentMethodType`)

- `includes/class-tingee-blocks.php`: viết lại đầy đủ, extends `AbstractPaymentMethodType`.
  - `initialize()`: đọc settings, lấy instance `Tingee_Gateway` từ WC gateway registry.
  - `is_active()`: delegate sang `$gateway->is_available()` để nhất quán với classic checkout.
  - `get_payment_method_script_handles()`: register và return handle `tingee-blocks` (file `assets/js/blocks.js`); gắn translations nếu có.
  - `get_payment_method_data()`: truyền `title`, `description`, `supports` sang JS qua WC Settings API.
- `assets/js/blocks.js` (file mới): đăng ký `tingee_gateway` vào `wc.wcBlocksRegistry`.
  - Nhận `title` + `description` từ `getSetting('tingee_gateway_data')`.
  - Render `<p class="tingee-block-description">` khi khách chọn phương thức.
  - QR code vẫn hiển thị trên trang thank-you (xử lý bởi `thankyou_page()` đã có từ T4.2) — không thay đổi.
- `tingee-gateway.php`: bỏ `require_once class-tingee-blocks.php` trực tiếp; thay bằng hook `woocommerce_blocks_payment_method_type_registration` → `tingee_register_block_payment_method()`.
  - Hàm này guard bằng `class_exists(AbstractPaymentMethodType)` → không fatal error khi WC Blocks chưa cài.

**DoD**: Bật Checkout Blocks → phương thức Tingee hiện trong danh sách, chọn → thấy mô tả, đặt hàng → redirect về trang thank-you hiển thị QR bình thường.

---

## [1.0.0] — 2026-06-03

### Giai đoạn 1 — Khung plugin (Scaffold)

**T1.1** — Tạo file chính `tingee-gateway.php`
- Plugin header đầy đủ (Plugin Name, Version, Author, License GPLv2+, Text Domain, Requires PHP 7.2, WC requires at least 5.0).
- Hook `plugins_loaded` để khởi tạo an toàn sau khi tất cả plugin được nạp.
- Load textdomain qua `init` hook.

**T1.2** — Định nghĩa hằng số và autoload
- Khai báo `TINGEE_PLUGIN_VERSION`, `TINGEE_PLUGIN_DIR`, `TINGEE_PLUGIN_URL`, `TINGEE_PLUGIN_FILE`.
- Hàm `tingee_load_classes()` require tất cả file trong `includes/`.
- Đăng ký gateway vào WooCommerce qua filter `woocommerce_payment_gateways`.

**T1.3** — Kiểm tra dependency WooCommerce
- Nếu `WC_Payment_Gateway` chưa tồn tại → hiện `admin_notice` lỗi có link cài WooCommerce.
- Plugin dừng nạp an toàn, không có fatal error.

**T1.4** — Khai báo tương thích HPOS
- Dùng `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` trong hook `before_woocommerce_init`.

**T1.5** — Tạo `uninstall.php`
- Xóa `woocommerce_tingee_gateway_settings` khỏi wp_options khi gỡ plugin.
- Có kiểm tra cờ `keep_data_on_uninstall` — nếu bật thì không xóa gì.
- Không xóa order meta (lịch sử đơn hàng).

### Files đã tạo
- `tingee-gateway.php` — file chính
- `includes/class-tingee-gateway.php` — WC Payment Gateway (scaffold, đầy đủ ở GĐ3–4)
- `includes/class-tingee-api.php` — API caller (scaffold, đầy đủ ở GĐ2)
- `includes/class-tingee-settings.php` — Settings fields (scaffold, đầy đủ ở GĐ3)
- `includes/class-tingee-webhook.php` — Webhook handler (scaffold, đầy đủ ở GĐ5)
- `includes/class-tingee-blocks.php` — Checkout Blocks (scaffold, đầy đủ ở GĐ7)
- `uninstall.php` — dọn dữ liệu khi gỡ
