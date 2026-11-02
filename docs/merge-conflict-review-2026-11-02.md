# Kiểm tra xung đột sau khi gộp main vào tuantu_dev

Source ban đầu đang merge `ae1436d40c53be9f1666e825ee92afbc3d18e0e2` vào HEAD `7b92aa4`. Có ba file chưa giải quyết trong Git index:

- `app/Http/Controllers/Api/OrderController.php`
- `app/Jobs/GetOrderByShopJob.php`
- `app/Jobs/GetOrderFromWebhookJob.php`

## Cách giải quyết

- Giữ nhánh Summary/cache, khóa chống nhập trùng và cập nhật đóng góp theo từng đơn.
- Giữ POS, quyền tạo đơn, Kho, thời điểm nguồn, nhập đơn Kho chưa có vận đơn và bảo vệ snapshot khi webhook chỉ gửi một phần dữ liệu.
- Biểu đồ giữ cache trước truy vấn nặng, đồng thời giữ tên shop từ phép join của nhánh mới.
- Không đổi ý nghĩa COD trong bảng Summary. Tổng quan bổ sung chênh lệch giá trị POS theo đúng shop/ngày/quyền, cache theo phiên bản nguồn. Đơn POS không thu COD vẫn có giá trị tổng quan đúng.
- Dashboard chủ động gửi `include_order_values=1` để nhận `total_cod` và `total_order_value`; response mặc định giữ nguyên. Cách này tránh cộng tiền trả trước của POS hai lần và không phát sinh API bổ sung. Bộ lọc, bố cục và định dạng tiền giữ nguyên.
- Chặn tìm `POS-*` đọc cột chưa có trên database cũ. Tạo POS khi schema chưa sẵn sàng trả 503 có thông báo thay vì lỗi SQL 500.

## Lỗi đăng nhập đã tái hiện và sửa

Ở cả `frontend/` và `pancake_frontend_v2/`, request `/me` cũ có thể hoàn tất sau logout hoặc sau đăng nhập tài khoản mới. Phản hồi 401 của request cũ cũng có thể đăng xuất phiên mới.

Đã thêm generation cho mỗi lần đăng nhập/đổi mật khẩu/khởi tạo phiên, từ trước request xác thực. Kết quả đã bị thay thế không được công bố hoặc xóa phiên hiện hành. HTTP client chỉ xử lý đăng xuất cho 401 dùng token của phiên hiện hành.

## Kiểm thử

Các kiểm thử backend chạy với `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, cache array và các connection kiểm thử riêng. Không chạy migration hoặc tạo đơn thử trên MySQL local hiện có.

- Kiểm tra cú pháp PHP và dấu conflict trong source.
- Kiểm thử đơn hàng, khách hàng, POS, Kho và webhook một phần.
- Kiểm thử thực sự có cả Summary và POS: toàn hệ thống/giới hạn shop, lọc ngày, COD bằng 0, trả trước, thanh toán đủ, cache HIT/MISS, thay đổi sau commit và rollback.
- Kiểm thử response mặc định và phần mở rộng opt-in của Dashboard.
- Kiểm thử frontend với request đăng nhập trả chậm, logout, tài khoản thay thế, 401 cũ, tính tiền POS và cấu hình API.

## Trạng thái database local

Kiểm tra chỉ đọc ghi nhận 90.576 đơn, 66.180 khách, không có pending Summary và readiness đang bật.

Ba migration POS/Kho từ nhánh mới chưa được áp dụng tại môi trường này:

1. `2026_10_21_000001_add_local_pos_support`
2. `2026_10_21_000002_add_warehouse_completion_and_source_time`
3. `2026_10_22_000001_add_explicit_pos_permission`

Chưa kích hoạt POS/Kho hoặc thay quyền tài khoản. Việc rollout các module này cần thực hiện riêng theo tài liệu triển khai tương ứng; không suy ra đã chạy được trên MySQL thật từ kết quả SQLite.

Không tự commit, push, hủy merge hoặc ghi đè các thay đổi đã stage của người dùng. Các bản sửa frontend và test mới được giữ trong working tree để review.

## Kết quả cuối

- Backend: **203 tests / 2.169 assertions**, tất cả đạt trong bộ hồi quy liên quan đã chọn; SQLite trong bộ nhớ.
- Frontend: **33/33 tests ở mỗi cây source**, bao gồm 8 hồi quy phiên đăng nhập và 5 kiểm thử giá trị Dashboard; hai production build và lint phần sửa đạt.
- Các PHP thay đổi đã qua kiểm tra cú pháp; ba file conflict và test tích hợp mới qua Pint; kiểm tra whitespace đạt.
- Không còn conflict marker trong source và `git ls-files -u` rỗng. Đã stage đúng ba file conflict để đánh dấu resolved; merge vẫn đang chờ commit.
- Các thay đổi đã stage sẵn của người dùng được giữ; không commit/push, không chạy ba migration POS/Kho mới và không ghi dữ liệu nghiệp vụ thật.