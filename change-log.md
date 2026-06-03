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

### YYYY-MM-DD — [Task T?.?] Tiêu đề ngắn
- **Loại**: [Tính năng mới] / [Fix lỗi] / [Bảo mật] / ...
- **Mô tả**: (làm gì, vì sao)
- **File thay đổi**: path/to/file.php, ...
- **Trạng thái DoD**: Đạt / Chưa (lý do)
- **Cần Cường test**: (nếu có)

-->

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
