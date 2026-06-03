# implementation-plan.md — Tingee Gateway

> Chia toàn bộ plugin thành các task nhỏ, làm TUẦN TỰ từ trên xuống.
> Mỗi task có: mục tiêu, việc cần làm, "Definition of Done" (DoD).
> Làm xong một task → tick `[x]`, ghi vào `change-log.md`, rồi mới sang task sau.
> Tham chiếu acceptance criteria trong `product-spec.md`.

Ký hiệu độ ưu tiên: 🔴 bắt buộc cho v1.0 · 🟡 nên có · 🟢 để sau.

---

## GIAI ĐOẠN 0 — Chuẩn bị môi trường (làm thủ công, một lần)

> Phần này Cường làm trên máy, không phải code. Cần xong trước khi Claude viết code có ý nghĩa.

- [x] **T0.1** 🔴 Cài môi trường WordPress local (khuyên dùng **LocalWP** hoặc **XAMPP/Docker**). Miễn phí.
- [x] **T0.2** 🔴 Cài & kích hoạt **WooCommerce** trên WordPress local.
- [x] **T0.3** 🔴 Đăng ký tài khoản **Tingee** tại https://app.tingee.vn, vào trang Developers lấy **Client ID** + **Secret Token**.
- [x] **T0.4** 🟡 Cài công cụ test webhook từ ngoài vào local: **ngrok** (tạo URL public trỏ về localhost) — miễn phí ở mức cơ bản.
- [x] **T0.5** 🟢 Cài **Plugin Check** (plugin chính thức WP.org) để tự rà lỗi trước khi submit.

---

## GIAI ĐOẠN 1 — Khung plugin (Scaffold)

- [x] **T1.1** 🔴 Tạo file chính `tingee-gateway.php` với plugin header đầy đủ (Plugin Name, Description, Version, Author, License GPLv2+, Text Domain `tingee-gateway`, Requires PHP 7.2, Requires at least 5.6, WC requires/ tested).
  - **DoD**: Plugin xuất hiện và kích hoạt được trong WordPress, không lỗi.
- [x] **T1.2** 🔴 Định nghĩa hằng số (`TINGEE_PLUGIN_VERSION`, `TINGEE_PLUGIN_DIR`, `TINGEE_PLUGIN_URL`) và autoload/require các class trong `includes/`.
- [x] **T1.3** 🔴 Kiểm tra dependency: nếu WooCommerce chưa active → hiện admin notice, không nạp phần còn lại.
  - **DoD**: Tắt WooCommerce → thấy thông báo, không có fatal error.
- [x] **T1.4** 🔴 Khai báo tương thích **HPOS** (`FeaturesUtil::declare_compatibility`).
- [x] **T1.5** 🟡 Tạo `uninstall.php` (xóa option khi gỡ — có cờ "giữ dữ liệu" trong settings).

---

## GIAI ĐOẠN 2 — Lớp gọi API & chữ ký

- [x] **T2.1** 🔴 `class-tingee-api.php`: hàm `tingee_generate_signature($timestamp, $body, $secret)` dùng HMAC-SHA512 theo công thức `timestamp + ":" + body`.
  - **DoD**: Unit test/so khớp với code mẫu PHP trong tài liệu Tingee ra cùng chữ ký.
- [x] **T2.2** 🔴 Hàm tạo timestamp `yyyyMMddHHmmssSSS` theo **UTC+7**.
- [x] **T2.3** 🔴 Hàm `tingee_request($endpoint, $body)` gắn đủ headers (`x-client-id`, `x-request-timestamp`, `x-signature`, `Content-Type`), gửi qua `wp_remote_post`, parse JSON, xử lý lỗi mạng.
  - **DoD**: Gọi thử một endpoint đơn giản (vd danh sách ngân hàng `/v1/get-banks`) trả dữ liệu.
- [ ] **T2.4** 🟡 Hàm "Kiểm tra kết nối" dùng ở settings (F2).

---

## GIAI ĐOẠN 3 — WC Payment Gateway & Settings

- [ ] **T3.1** 🔴 `class-tingee-gateway.php` extends `WC_Payment_Gateway`. Đăng ký vào WooCommerce qua filter `woocommerce_payment_gateways`.
  - **DoD**: Phương thức xuất hiện ở WooCommerce → Settings → Payments.
- [ ] **T3.2** 🔴 Khai báo `init_form_fields()` với toàn bộ field ở **product-spec F3** (bật/tắt, tiêu đề, mô tả, client id, secret, chọn TK/VA, prefix mã, message thành công, trạng thái đơn, hiển thị tên NH, logo, chế độ tải xuống, **chọn chế độ A/B**).
- [ ] **T3.3** 🔴 Lưu & đọc lại settings đúng (`process_admin_options`).
  - **DoD**: Đáp ứng acceptance "Lưu được toàn bộ field; reload vẫn giữ".

---

## GIAI ĐOẠN 4 — Luồng thanh toán phía khách (Chế độ A: QR + Webhook)

- [ ] **T4.1** 🔴 `process_payment($order_id)`: tạo QR động/mã định danh qua API Tingee, lưu mã định danh + transaction ref vào order meta (HPOS-safe), đặt đơn về On-Hold, trả về redirect tới trang "thank you / pay".
- [ ] **T4.2** 🔴 Hiển thị QR + box thông tin chuyển khoản (số TK, tên, số tiền, nội dung kèm prefix) trên trang nhận đơn / thank-you page.
- [ ] **T4.3** 🔴 JS `assets/js/checkout.js`: poll trạng thái đơn (AJAX, có nonce) mỗi vài giây; khi đã thanh toán → hiện message thành công, dừng poll.
  - **DoD**: Giả lập webhook → trang khách tự đổi sang "thành công" trong ≤15s.
- [ ] **T4.4** 🟡 Nút copy thông tin chuyển khoản; tùy chọn hiển thị tên NH (đầy đủ/viết tắt/cả hai).

---

## GIAI ĐOẠN 5 — Webhook IPN (quan trọng nhất)

- [ ] **T5.1** 🔴 `class-tingee-webhook.php`: đăng ký endpoint nhận POST (REST route hoặc `woocommerce_api_tingee`).
- [ ] **T5.2** 🔴 Verify chữ ký `x-signature` = HMAC-SHA512(`timestamp:body`, secret). Sai → trả **401**, dừng.
  - **DoD**: Gửi payload sai chữ ký → 401, đơn không đổi (acceptance bảo mật).
- [ ] **T5.3** 🔴 Parse payload, lấy mã định danh → tìm order tương ứng.
- [ ] **T5.4** 🔴 **Idempotency**: kiểm tra transaction id đã xử lý chưa (lưu danh sách id đã xử lý vào order meta). Đã xử lý → trả 200, bỏ qua.
  - **DoD**: Gửi webhook 2 lần → chỉ gạch nợ 1 lần.
- [ ] **T5.5** 🔴 Đối soát số tiền: đủ → gọi `$order->payment_complete()` + đổi trạng thái theo cấu hình + ghi note admin (số tiền, thời gian). Thiếu → xử lý thanh toán một phần / giữ On-Hold.
- [ ] **T5.6** 🔴 Luôn trả HTTP 200 cho webhook hợp lệ đã xử lý (để Tingee ngừng retry).

---

## GIAI ĐOẠN 6 — Chế độ B (Redirect Payment Gateway)

- [ ] **T6.1** 🟡 Trong `process_payment`, nếu chế độ B: gọi API tạo **Checkout URL** (kèm `returnUrl`), trả redirect sang URL đó.
- [ ] **T6.2** 🟡 Xử lý `returnUrl` khi khách quay về: hiển thị trạng thái (xác nhận cuối cùng vẫn dựa vào webhook).
  - **DoD**: Đáp ứng acceptance "Luồng Chế độ B".

---

## GIAI ĐOẠN 7 — Checkout Blocks & tương thích

- [ ] **T7.1** 🔴 `class-tingee-blocks.php`: tích hợp `AbstractPaymentMethodType` để phương thức hiện trong **Checkout Blocks**.
  - **DoD**: Bật Checkout Blocks → phương thức Tingee vẫn hiện và thanh toán được.
- [ ] **T7.2** 🟡 Kiểm tra giao diện trên vài theme phổ biến (Storefront, theme mặc định).

---

## GIAI ĐOẠN 8 — Log, i18n, hoàn thiện

- [ ] **T8.1** 🟡 Ghi log webhook qua `WC_Logger`, mask secret.
- [ ] **T8.2** 🟡 Tạo file `.pot`, bản dịch Tiếng Việt; bọc mọi chuỗi trong hàm dịch.
- [ ] **T8.3** 🟢 Tùy chọn tải xuống sản phẩm số (Thủ công/Tự động) như SePay.

---

## GIAI ĐOẠN 9 — Đóng gói & submit WordPress.org

- [ ] **T9.1** 🔴 Viết `readme.txt` đúng chuẩn WP.org (header, Stable tag, Tested up to, Description, Installation, FAQ, Screenshots, Changelog).
- [ ] **T9.2** 🔴 Chạy **Plugin Check**, sửa hết lỗi blocking.
- [ ] **T9.3** 🔴 Rà checklist bảo mật (CLAUDE.md mục 4) lần cuối; bỏ mọi secret cứng.
- [ ] **T9.4** 🟡 Chuẩn bị assets WP.org: `icon-128x128.png`, `banner-772x250.png`, ảnh screenshot.
- [ ] **T9.5** 🔴 Đăng nhập WordPress.org → **Submit a plugin** → upload zip → chờ review (1–10 ngày).
- [ ] **T9.6** 🔴 Sau khi duyệt: nhận SVN repo, commit code vào `trunk`, tag version → plugin lên kho.

---

## Thứ tự ưu tiên build (đường tới bản chạy được sớm nhất - MVP)
GĐ0 → GĐ1 → GĐ2 → GĐ3 → GĐ4 → GĐ5 → GĐ7 (Blocks) → GĐ9.
Chế độ B (GĐ6), log/i18n (GĐ8) có thể làm sau bản MVP đầu tiên.
