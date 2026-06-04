# Change Log — Tingee Gateway

> Ghi lại tất cả thay đổi theo từng task. Thứ tự: mới nhất ở trên.

---

## [1.0.0] — 2026-06-04

### Giai đoạn 7 — Checkout Blocks & tương thích

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
