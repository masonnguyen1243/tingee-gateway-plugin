# Change Log — Tingee Gateway

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
