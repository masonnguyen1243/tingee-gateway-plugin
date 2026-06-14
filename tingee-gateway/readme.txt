=== HENO Tingee Gateway for WooCommerce ===
Contributors: heno, cuongnguyenba
Tags: payment, woocommerce, bank transfer, qr code, vietqr
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.0.0
WC requires at least: 5.0
WC tested up to: 9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate Tingee (by HENO) payment gateway into WooCommerce. Supports VietQR, bank transfer auto-confirmation via Webhook IPN, and Checkout Blocks.

== Description ==

**HENO Tingee Gateway for WooCommerce** kết nối cửa hàng WooCommerce của bạn với hạ tầng thanh toán [Tingee](https://tingee.vn) của HENO, cho phép khách hàng thanh toán qua chuyển khoản ngân hàng (VietQR) với **xác nhận đơn hàng tự động** — không cần đối soát thủ công.

= Cách hoạt động =

**QR + Webhook**

1. Khách hàng chọn "Tingee" khi thanh toán.
2. Plugin tạo mã QR liên kết với đơn hàng.
3. Trang cảm ơn hiển thị mã QR, số tài khoản, số tiền và nội dung chuyển khoản.
4. Khách hàng quét và chuyển khoản → Tingee gửi Webhook (IPN) về site của bạn.
5. Plugin xác thực chữ ký, khớp giao dịch và tự động đánh dấu đơn hàng là đã thanh toán — **trong vòng 5–15 giây**.

= Tính năng nổi bật =

* **Xác nhận đơn hàng tự động** qua Webhook IPN — không cần kiểm tra thủ công.
* **Hiển thị VietQR** trên trang cảm ơn với nút sao chép nhanh số tài khoản, số tiền và nội dung chuyển khoản.
* **Xác thực chữ ký HMAC-SHA512** cho mọi webhook — các yêu cầu giả mạo bị từ chối với mã 401.
* **Bảo vệ idempotency** — webhook bị gửi lại (tối đa 5 lần) không bao giờ ghi nợ đơn hàng hai lần.
* **Tương thích Checkout Blocks** — hoạt động với cả shortcode cũ và WooCommerce Checkout Blocks mới.
* **Tương thích HPOS** — hỗ trợ WooCommerce High-Performance Order Storage.
* **Tích hợp WC Logger** — hoạt động webhook được ghi log tại WooCommerce > Trạng thái > Logs (`tingee-webhook`), thông tin bí mật được ẩn đi.
* **Nút kiểm tra kết nối** — xác minh Client ID và Secret Token trước khi đưa vào hoạt động thực tế.
* **Hỗ trợ tiếng Việt và tiếng Anh**.

= Yêu cầu =

* WordPress 5.6 trở lên
* WooCommerce 5.0 trở lên
* PHP 7.2 trở lên
* Tài khoản merchant [Tingee](https://app.tingee.vn) có Client ID và Secret Token

== Installation ==

= Automatic installation =

1. Đăng nhập vào trang quản trị WordPress của bạn.
2. Vào **Plugins > Thêm mới**.
3. Tìm kiếm **HENO Tingee Gateway for WooCommerce**.
4. Nhấn **Cài đặt ngay**, sau đó **Kích hoạt**.

= Manual installation =

1. Tải file zip của plugin.
2. Vào **Plugins > Thêm mới > Tải Plugin lên**.
3. Chọn file zip và nhấn **Cài đặt ngay**, sau đó **Kích hoạt**.

= Configuration =

1. Vào **WooCommerce > Cài đặt > Thanh toán**.
2. Tìm **Tingee Gateway** và nhấn **Quản lý**.
3. Điền vào các trường sau:
   * **Môi trường** — chọn Production.
   * **Client ID** — lấy từ trang quản lý developer của Tingee.
   * **Secret Token** — lấy từ trang quản lý developer của Tingee.
4. Nhấn **Kiểm tra kết nối** để xác minh thông tin đăng nhập.
5. Sao chép **URL Webhook** hiển thị trong trang cài đặt và dán vào trang quản lý developer Tingee trong phần cấu hình Webhook.
6. Lưu cài đặt.

== Frequently Asked Questions ==

= Tôi lấy Client ID và Secret Token ở đâu? =

Đăng nhập vào tài khoản merchant Tingee tại [app.tingee.vn](https://app.tingee.vn), vào mục **Developers** và sao chép Client ID cùng Secret Token của bạn.

= URL Webhook là gì và tôi điền vào đâu? =

URL Webhook được hiển thị trong trang cài đặt plugin (trường chỉ đọc). Sao chép và dán vào trang quản lý developer Tingee trong phần cấu hình Webhook / IPN. Tingee sẽ gửi thông báo thanh toán đến URL này.

= Tôi có cần ngrok hoặc URL công khai để nhận webhook không? =

Có, trong quá trình phát triển cục bộ bạn cần công cụ như [ngrok](https://ngrok.com) để tạo URL công khai mà Tingee có thể truy cập. Trên server thực tế với tên miền thật, không cần cấu hình thêm.

= Điều gì xảy ra nếu khách hàng chuyển sai số tiền? =

Nếu số tiền chuyển khoản ít hơn tổng đơn hàng, plugin giữ đơn hàng ở trạng thái **Chờ xử lý** và thêm ghi chú quản trị kèm số tiền đã nhận. Bạn có thể xem xét và xử lý thanh toán thiếu thủ công. Trường hợp chuyển thừa cũng theo luồng tương tự — đơn hàng được đánh dấu đã thanh toán và ghi chú chênh lệch.

= Có an toàn không? Ai đó có thể giả mạo webhook không? =

Mọi webhook đều được xác thực bằng **HMAC-SHA512** với Secret Token của bạn. Bất kỳ yêu cầu nào có chữ ký không hợp lệ hoặc thiếu chữ ký đều nhận phản hồi `401 Unauthorized` và đơn hàng không bao giờ bị thay đổi. Tấn công phát lại cũng được ngăn chặn: mỗi ID giao dịch được lưu lại sau khi xử lý, nên việc gửi lại cùng một webhook không có tác dụng.

= Có hoạt động với WooCommerce Checkout Blocks mới không? =

Có. Plugin đăng ký phương thức thanh toán tương thích Blocks thông qua `AbstractPaymentMethodType`, nên hiển thị đúng cả ở checkout shortcode cũ lẫn checkout dạng block mới.

= Có tương thích với WooCommerce High-Performance Order Storage (HPOS) không? =

Có. Toàn bộ dữ liệu đơn hàng được đọc và ghi bằng `wc_get_order()`, `$order->get_meta()` và `$order->update_meta_data()` — tương thích hoàn toàn với HPOS.

= Cài đặt của tôi có bị xóa nếu tắt plugin không? =

Không. Cài đặt được giữ nguyên khi bạn vô hiệu hóa plugin. Chúng chỉ bị xóa khi bạn **xóa** plugin, và chỉ khi tùy chọn "Xóa dữ liệu khi gỡ cài đặt" được bật trong cài đặt.

== External Services ==

Plugin này kết nối với **Tingee** payment API (vận hành bởi HENO) để tạo mã QR, tạo link thanh toán và xác minh thông báo Webhook (IPN) khi thanh toán hoàn tất.

**Dữ liệu gửi đến Tingee:**

* Số tiền đơn hàng, ID đơn hàng và nội dung chuyển khoản — gửi khi tạo QR thanh toán hoặc URL thanh toán.
* Plugin không chuyển tiếp thông tin cá nhân của khách hàng (tên, email, địa chỉ) đến Tingee API.

**Khi nào dữ liệu được gửi:**

* Khi khách hàng đặt hàng và nhấn "Thanh toán" — plugin gọi Tingee API để tạo mã QR hoặc URL thanh toán lưu trữ.
* Khi Tingee gửi Webhook đến site của bạn (đây là dữ liệu *đến* từ Tingee, không phải đi ra).

**Các endpoint Tingee API được sử dụng:**

* Production: `https://open-api.tingee.vn`

Khi sử dụng plugin này, cửa hàng của bạn kết nối với hạ tầng của Tingee. Vui lòng đọc chính sách của Tingee trước khi đưa vào hoạt động thực tế:

* [Điều khoản dịch vụ](https://drive.google.com/file/d/1snw0yOyz6hmanARQDWAX8DEMiG4r6Vmp/view)
* [Chính sách bảo mật](https://drive.google.com/file/d/1baa2tZPZvq9HK6w1Tzi2okAjdeo7bJhx/view)

Tài liệu dành cho nhà phát triển, truy cập [https://developers.tingee.vn](https://developers.tingee.vn).

== Screenshots ==

1. **Payment method at checkout** — Tingee option appears with title and description.
2. **QR code on thank-you page** — shows QR image, account number, amount, and transfer reference with copy buttons.
3. **Plugin settings page** — Client ID, Secret Token, environment, VA account number, integration mode, and connection test button.
4. **Webhook URL field** — read-only URL to copy into your Tingee developer dashboard.
5. **Order confirmed** — thank-you page automatically updates to "Payment successful" after Webhook IPN is received.

== Changelog ==

= 1.0.0 =
* Initial release.
* Mode A: VietQR display on thank-you page with JS polling for automatic confirmation.
* Mode B: Redirect to Tingee hosted payment page.
* Webhook IPN handler with HMAC-SHA512 signature verification and idempotency protection.
* HPOS and Checkout Blocks compatibility.
* WC Logger integration with masked secrets.
* Vietnamese and English translations.
* Connection test button in settings.
* Static QR fallback when dynamic QR is not enabled on the merchant account.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
