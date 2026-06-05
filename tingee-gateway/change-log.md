# Change Log — Tingee Gateway

## [1.1.1] — 2026-06-05

### Bugfix: Webhook Static QR không khớp được đơn hàng khi ngân hàng/Tingee tự sinh nội dung CK

**Vấn đề**:
- Webhook từ Tingee đôi khi có trường `content` là chuỗi Tingee tự sinh (vd: `TKP#TGE60605173323VCB#...`) thay vì mã đơn hàng ta gửi lúc tạo QR → không khớp `_tingee_purpose` → đơn không được gạch nợ.
- Một số giao dịch chuyển khoản trực tiếp vào VA không có `content` lẫn `billId` → log cảnh báo thiếu thông tin để đối soát thủ công.

**Nguyên nhân gốc**: Logic khớp Static QR chỉ dựa vào exact-match `content` ↔ `_tingee_purpose`. Tingee/ngân hàng không đảm bảo trả về đúng chuỗi nội dung ta đặt trong QR.

**Fix** (`includes/class-tingee-webhook.php`):
- Cấu trúc lại `else` block (Static QR) thành 2 bước:
  1. **Bước 1** (giữ nguyên): khớp chính xác `content` với `_tingee_purpose`.
  2. **Bước 2 (fallback — mới)**: nếu bước 1 thất bại hoặc `content` rỗng, tìm đơn on-hold theo `_tingee_amount` + `payment_method` + `status`. Chỉ tự động xử lý khi có **đúng 1 đơn** khớp số tiền (tránh nhầm khi có nhiều đơn cùng giá).
- Cải thiện log lỗi: thêm `transactionCode` và số tiền vào thông báo "không tìm được đơn" để dễ đối soát thủ công.

---

## [1.1.0] — 2026-06-05

### Đơn giản hóa cấu hình: chỉ cần Client ID + Secret Token

**Mục tiêu**: Merchant không cần nhập tay số tài khoản VA, mã BIN, tên ngân hàng nữa — plugin tự động lấy từ Tingee API.

**Files thay đổi**:

- `includes/class-tingee-api.php`
  - Thêm method `get_va_accounts()` — gọi `POST /v1/get-va-paging` để lấy danh sách tài khoản VA đã liên kết.

- `tingee-gateway.php`
  - Thêm AJAX action `tingee_fetch_accounts` và handler `tingee_ajax_fetch_accounts()`.
  - Handler trả về danh sách accounts kèm tên ngân hàng (enriched từ `/v1/get-banks`).

- `includes/class-tingee-gateway.php`
  - `init_form_fields()`: Xóa các trường nhập tay `bank_bin`, `bank_name_full`, `bank_name_short`, `bank_name_display`. Thay `va_account_number` bằng custom field `va_account_selector`.
  - Thêm renderer `generate_va_account_selector_html()`: hiển thị bộ chọn tài khoản, chứa 4 hidden inputs lưu dữ liệu tài khoản.
  - Thêm renderer `generate_hidden_data_html()`: trả về chuỗi rỗng (các field `hidden_data` chỉ lưu DB, không hiển thị).
  - `thankyou_page()`: Bỏ logic `bank_name_display`, đơn giản hóa thành hiển thị tự động tên NH từ dữ liệu đã fetch.
  - Bỏ property `$bank_name_display` và assignment trong `__construct()`.

- `assets/js/admin.js`
  - Sau khi test kết nối thành công: tự động gọi `tingee_fetch_accounts` AJAX.
  - Nếu 1 tài khoản: tự động chọn.
  - Nếu nhiều tài khoản: hiển thị danh sách radio để admin chọn.
  - Khi chọn tài khoản: cập nhật 4 hidden inputs (`va_account_number`, `bank_bin`, `bank_name_full`, `bank_name_short`).

**Luồng mới**:
1. Admin nhập Client ID + Secret Token.
2. Nhấn "Kiểm tra kết nối" → plugin xác minh credentials và tải danh sách tài khoản VA.
3. Chọn tài khoản (hoặc tự động chọn nếu chỉ có 1).
4. Lưu cài đặt.

**Backward compatibility**: Các cài đặt cũ đã lưu (va_account_number, bank_bin, v.v.) vẫn hoạt động bình thường sau khi nâng cấp.
