# Đánh giá frontend production — 08/10/2026

Đối tượng: http://123.31.31.112:8081, mã nguồn đang chạy trong /var/www/html/pancake_order/frontend. Đây là baseline trước tối ưu; chưa thay đổi giao diện hoặc cấu hình phục vụ trong đợt đánh giá này.

## Kết luận

Frontend đã có nền tảng phù hợp để tiếp tục tối ưu: React 19, Vite 8, Ant Design 6, React Query, phân trang đơn hàng và lazy loading theo trang. Chưa có bằng chứng cần viết lại toàn bộ frontend. Các điểm cần ưu tiên là lượng tải ban đầu, cache/nén HTTP, tải trước báo cáo và chất lượng kiểm thử.

## Kết quả đo và giới hạn

Đo bằng Chromium headless từ máy local, phiên mới, trang đăng nhập không có xác thực; không mô phỏng mạng chậm hoặc CPU điện thoại.

| Chỉ số | Desktop 1440×900 | Viewport mobile 390×844 |
|---|---:|---:|
| DOMContentLoaded / load | 1.216 / 1.217 giây | 0.870 / 0.870 giây |
| Tài nguyên được tải | 50 | 50 |
| Dữ liệu truyền tài nguyên | 1.018.047 byte | 1.018.047 byte |
| Lỗi JavaScript | 0 | 0 |
| Tràn ngang | Không | Không |

Đây là một lần đo cho mỗi viewport, không phải Core Web Vitals từ người dùng thực tế. Chưa đo LCP/INP của trang báo cáo có xác thực trên mạng 4G hoặc máy yếu.

Build production thành công. Bộ kiểm thử báo cáo riêng đạt 6/6. Lint hiện có 20 cảnh báo, chủ yếu import/biến không dùng và dependency của hook; không có lỗi lint.

## Hiện trạng cần xử lý

1. **Tải trang đầu còn lớn.** Trang đăng nhập cũng tải khoảng 1,02 MB và 50 tài nguyên. Các chunk mới lớn gồm routes khoảng 306 KB, index 233 KB và table 172 KB trước nén. Cần phân tích đường import thực tế trước khi chia chunk; không chia theo thư viện một cách máy móc.

2. **Chưa nén JS và chưa đặt Cache-Control rõ ràng.** Request có Accept-Encoding: gzip vẫn nhận JS không có Content-Encoding. HTML và JS chưa có Cache-Control trong phản hồi kiểm tra. Assets hiện có tên hash, phù hợp để cache lâu; HTML cần kiểm tra lại mỗi lần tải để tránh trỏ tới bản cũ. Cấu hình nén theo [tài liệu Nginx](https://nginx.org/en/docs/http/ngx_http_gzip_module.html); cách xử lý HTML/chunk cũ theo [tài liệu Vite](https://vite.dev/guide/build.html#load-error-handling).

3. **Tải trước báo cáo quá rộng.** ReportsLayout gọi preloadRemainingReports ngay khi vào nhóm báo cáo. Hàm này tải sáu trang và prefetch dữ liệu đồng thời, bao gồm báo cáo sản phẩm theo năm/so sánh kỳ. Đây là tải phụ có thể tranh tài nguyên với trang đang xem. AppLayout còn preload mã báo cáo mặc định sau đăng nhập admin.

4. **Polling Zalo có chi phí khi mở hội thoại.** Tài khoản mỗi 15 giây, hội thoại mỗi 10 giây, tin nhắn mỗi 5 giây: khoảng 22 request/phút cho một tab khi đủ ba query hoạt động. Chưa có realtime ứng dụng. WebSocket HMR của môi trường phát triển khác với realtime tin nhắn; production hiện phục vụ bản build tĩnh.

5. **Code khó bảo trì ở một số trang.** CustomerCarePage 1.283 dòng, RoleSettingsPage 751 dòng, ActivityLogsPage 687 dòng. Cần tách hook dữ liệu, filter, table và modal; chỉ dùng memo/virtualization sau đo profiler. Bảng đơn hàng đã phân trang nên không có cơ sở bắt buộc virtualize mọi bảng.

6. **Dependency và bảo vệ phiên.** npm audit hiện ghi nhận hai gói có mức cao: axios và source-map-js. Một số advisory Axios chỉ liên quan adapter Node; chưa xác nhận ứng dụng trình duyệt có đường khai thác tương ứng. Cần cập nhật có kiểm thử, không chạy audit fix tự động trên production. [Advisory source-map-js](https://github.com/advisories/GHSA-68fv-2mgg-jv7q). Website hiện truy cập qua HTTP và token được lưu localStorage: nên chuyển sang HTTPS trước, rồi đánh giá CSP và cơ chế lưu token.

7. **Độ tin cậy API/kiểm thử cần củng cố.** Axios dùng chung chưa đặt timeout. Một số request chưa truyền signal để hủy khi đổi bộ lọc. Khi triển khai trước đó, SQL mới không tương thích MariaDB production đã gây 500 và được sửa bằng OrderItemSql; cần kiểm thử báo cáo trên đúng phiên bản DB production. Lệnh toàn bộ test thông thường vẫn giữ process sau các test auth đã chạy xong trong hơn 60 giây; baseline chạy thêm với --test-force-exit để phân biệt kết quả assertions với vấn đề teardown.

## Thứ tự đề xuất để duyệt

| Ưu tiên | Phạm vi | Tiêu chí nghiệm thu |
|---|---|---|
| 1 | Nén JS/CSS, cache assets hash và kiểm tra lại HTML | Tài nguyên thực sự được nén; lần tải lại giảm byte; deploy mới không lỗi chunk |
| 2 | Chỉ preload báo cáo theo nhu cầu; giảm bundle trang đăng nhập | Đo lại lượng JS, số request và thời gian theo cùng điều kiện |
| 3 | Nâng dependency có kiểm thử; HTTPS; timeout/hủy request; sửa teardown test | Không hồi quy đăng nhập, báo cáo, CSKH, Zalo; test tự kết thúc |
| 4 | Tách component lớn, tối ưu render/polling theo profiler | Bộ lọc, bảng và hội thoại phản hồi ổn định trên desktop/mobile |

Chưa triển khai các đề xuất tối ưu trong báo cáo này.

## Kết quả bộ test đầy đủ

Lệnh node --test --test-force-exit tests/*.test.js: 67 test, 66 pass, 1 fail (13,58 giây). Test thất bại: completion modal has V1 fields, validation, exact payload and reset/refresh behavior, trong tests/customerCareActionParity.test.js. Test dùng cắt chuỗi mã nguồn và nhận đoạn rỗng; cần kiểm tra lại selector/assertion và luồng modal thực tế trước khi kết luận lỗi chức năng. Lệnh không có test-force-exit giữ process ở nhóm auth hơn 60 giây, cần xử lý teardown/timer.

