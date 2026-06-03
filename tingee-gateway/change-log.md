# Change Log — Tingee Gateway

> Ghi lại tất cả thay đổi theo từng task. Thứ tự: mới nhất ở trên.

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
