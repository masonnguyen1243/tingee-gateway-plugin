# product-spec.md — Tingee Gateway for WooCommerce

> Tài liệu định nghĩa SẢN PHẨM: mục tiêu, người dùng, tính năng, và acceptance criteria.
> Đây là "nguồn chân lý" về việc plugin phải làm gì. Khi nghi ngờ một tính năng có nằm trong phạm vi không, hãy quay lại đây.

---

## 1. Mục tiêu (Goal)

Xây dựng một plugin WordPress giúp chủ website WooCommerce **nhận thanh toán qua chuyển khoản ngân hàng (VietQR)** và **tự động xác nhận đơn hàng** thông qua hệ thống **Tingee (by HENO)**, mà không cần nhân viên ngồi đối soát thủ công.

Tương tự plugin SePay Gateway, nhưng dùng hạ tầng API/Webhook của Tingee.

### Mục tiêu đo lường được
- Đơn hàng được tự động chuyển trạng thái trong vòng **5–15 giây** sau khi khách chuyển khoản thành công.
- Tỷ lệ đối soát đúng đơn (nhờ QR động + mã định danh) đạt **~100%**.
- Plugin được duyệt và publish thành công trên **WordPress.org**.

### Ngoài phạm vi (Non-goals) — phiên bản 1.0
- Không xử lý thẻ tín dụng quốc tế (Visa/Master) trừ khi Tingee hỗ trợ qua cổng.
- Không làm tính năng hoàn tiền (refund) tự động ở v1.0 (có thể thêm sau — Tingee có API `/v1/refund`).
- Không hỗ trợ nền tảng ngoài WooCommerce (chỉ WooCommerce, không phải EDD/Shopify...).

---

## 2. Người dùng (Users)

| Vai trò | Mô tả | Nhu cầu chính |
|---|---|---|
| **Chủ shop / Admin** | Người cài và cấu hình plugin. Đa phần không rành kỹ thuật. | Cài nhanh, kết nối tài khoản Tingee dễ, cấu hình rõ ràng. |
| **Khách mua hàng** | Người thanh toán trên website. | Quét QR trả tiền nhanh, biết ngay khi thanh toán thành công. |
| **Developer/Cường** | Người bảo trì plugin. | Code sạch, dễ debug, có log webhook. |

---

## 3. Hai chế độ tích hợp (đã chốt: hỗ trợ CẢ HAI, admin chọn trong settings)

### Chế độ A — QR động + Webhook (mặc định, giống SePay)
1. Khách chọn "Chuyển khoản QR (Tingee)" ở checkout.
2. Plugin tạo QR động gắn mã định danh đơn hàng (billId/orderId).
3. Trang checkout hiển thị mã QR + thông tin chuyển khoản, JS poll trạng thái.
4. Khách chuyển khoản → Tingee gửi **Webhook (IPN)** về site.
5. Plugin verify chữ ký → gạch nợ → đổi trạng thái đơn → hiện "Thanh toán thành công".

### Chế độ B — Cổng thanh toán (redirect)
1. Khách chọn phương thức Tingee → plugin gọi API tạo **Checkout URL**.
2. Khách được redirect sang trang thanh toán của Tingee.
3. Khách thanh toán → Tingee gửi Webhook (IPN) + điều hướng về `returnUrl`.
4. Plugin xác nhận và cập nhật đơn hàng.

> Admin chọn chế độ trong Settings. Webhook xử lý dùng chung cho cả hai.

---

## 4. Tính năng (Features)

### F1. Cài đặt & kích hoạt
- Plugin hiển thị trong Plugins, kích hoạt không lỗi.
- Kiểm tra WooCommerce đã cài & active; nếu chưa → hiện thông báo admin, không crash.

### F2. Kết nối tài khoản Tingee
- Field nhập **Client ID** và **Secret Token** (lấy từ trang Developers của Tingee).
- Nút "Kiểm tra kết nối" gọi thử một API để xác nhận credentials đúng.
- (Tùy chọn nâng cao) Hỗ trợ tự tạo/đăng ký webhook URL nếu Tingee có API.

### F3. Cấu hình phương thức thanh toán (bám sát tài liệu Tingee)

Chỉ bao gồm những field thực sự cần thiết theo API Tingee:

- **Bật/Tắt** phương thức (chuẩn WooCommerce).
- **Tiêu đề & Mô tả** hiển thị ở trang checkout (chuẩn WooCommerce).
- **Môi trường** (`environment`): Sandbox (UAT) hoặc Production — quyết định Base URL gọi API (`uat-open-api.tingee.vn` vs `open-api.tingee.vn`).
- **Client ID** (`client_id`): header `x-client-id`, bắt buộc mọi request đến Tingee.
- **Secret Token** (`secret_token`): khóa bí mật để sinh chữ ký HMAC-SHA512, bắt buộc mọi request.
- **Nút "Kiểm tra kết nối"**: gọi `GET /v1/get-banks` để xác nhận credentials hợp lệ.
- **Số tài khoản VA nhận tiền** (`va_account_number`): field `vaAccountNumber` trong Tingee API — VA của merchant dùng để nhận thanh toán và hiển thị QR.

### F4. Hiển thị thanh toán ở checkout
- Hiện QR code + box thông tin chuyển khoản (số TK, tên, số tiền, nội dung).
- Nút copy thông tin chuyển khoản.
- Tự động poll/nhận trạng thái, hiện "Bạn đã thanh toán thành công" khi xong.
- Hoạt động trên cả Checkout cũ (shortcode) và Checkout Blocks.

### F5. Webhook IPN (trái tim của plugin)
- Endpoint nhận POST từ Tingee.
- Verify chữ ký `x-signature` (HMAC-SHA512).
- Idempotency: chống xử lý trùng khi retry (tối đa 5 lần).
- Map giao dịch → đơn hàng qua mã định danh.
- Kiểm tra số tiền khớp; gạch nợ; đổi trạng thái; ghi note vào order (ghi chú admin, không gửi mail khách).

### F6. Sinh chữ ký request
- Mọi request đến Tingee kèm `x-client-id`, `x-request-timestamp`, `x-signature`, `Content-Type: application/json`.

### F7. Log & chẩn đoán
- Ghi log webhook (thành công/thất bại) qua `WC_Logger`.
- Mask secret trong log.

### F8. Quốc tế hóa & gỡ cài đặt
- Toàn bộ chuỗi dịch được (text domain `tingee-gateway`), kèm bản dịch Tiếng Việt.
- `uninstall.php` dọn option khi gỡ (tùy chọn giữ lại để không mất cấu hình).

---

## 5. Acceptance Criteria (tiêu chí nghiệm thu)

Plugin coi là "đạt" khi TẤT CẢ các điều sau đúng:

### Kết nối & cấu hình
- [ ] Cài & kích hoạt không có warning/error PHP.
- [ ] Nhập Client ID + Secret Token sai → "Kiểm tra kết nối" báo lỗi rõ ràng, không crash.
- [ ] Nhập đúng → báo kết nối thành công.
- [ ] Lưu được toàn bộ field cấu hình ở F3; reload vẫn giữ giá trị.

### Luồng thanh toán (Chế độ A — QR + Webhook)
- [ ] Đặt đơn 2.000đ, chọn phương thức Tingee → QR hiển thị đúng kèm đúng số tiền & nội dung có prefix.
- [ ] Sau khi chuyển khoản (hoặc giả lập webhook), trong ≤15s: trang khách hiện "thanh toán thành công", đơn chuyển từ On-Hold → Processing/Completed.
- [ ] Order có note ghi nhận số tiền + thời gian nhận.

### Luồng thanh toán (Chế độ B — Redirect)
- [ ] Chọn phương thức → redirect sang Checkout URL của Tingee.
- [ ] Thanh toán xong → quay về returnUrl, đơn được cập nhật đúng.

### Bảo mật
- [ ] Webhook sai chữ ký → trả 401, KHÔNG cập nhật đơn.
- [ ] Webhook đúng chữ ký nhưng gửi lại lần 2 (retry) → KHÔNG gạch nợ trùng.
- [ ] Webhook báo số tiền thiếu → đơn KHÔNG chuyển sang đã thanh toán đủ (hoặc xử lý theo logic thanh toán một phần đã định).
- [ ] Mọi output escape, input sanitize, form có nonce (rà theo checklist trong CLAUDE.md mục 4).

### Tương thích
- [ ] Hoạt động trên WordPress 5.6+ và PHP 7.2+.
- [ ] Hoạt động với cả Checkout cổ điển và Checkout Blocks.
- [ ] Tương thích HPOS (High-Performance Order Storage).

### Sẵn sàng publish
- [ ] Có `readme.txt` đúng chuẩn WordPress.org (đọc được bằng plugin readme validator).
- [ ] Không còn secret/khóa cứng trong code.
- [ ] Không lỗi khi chạy Plugin Check (plugin chính thức của WordPress.org).

---

## 6. Rủi ro & phụ thuộc
- **Phụ thuộc tài khoản Tingee**: cần Client ID/Secret Token thật để test thật. Trước đó dùng môi trường giả lập của Tingee (nếu đã ra mắt) hoặc tự giả lập webhook.
- **Rủi ro nhãn hiệu tên "Tingee"** trên WordPress.org — xem CLAUDE.md mục 7.
- **Khác biệt giữa các ngân hàng** về hỗ trợ QR động/tĩnh — cần kiểm tra bảng "Lưu ý quan trọng" của Tingee.
