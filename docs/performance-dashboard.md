# Tối ưu tải Dashboard và Reports

Phạm vi thay đổi: giảm API không cần thiết, đọc cache trước truy vấn nặng và dùng dữ liệu tổng hợp đã đồng bộ. Giữ giao diện, phân quyền, bộ lọc và cấu trúc JSON hiện tại. Không có tác vụ rebuild chạy trong GET Dashboard.

## Nguyên nhân đã xác nhận trong source

| Vị trí | Nguyên nhân | Thay đổi |
| --- | --- | --- |
| `src/app/reportPreload.js`, `ReportsLayout` trong hai cây frontend | Preload dữ liệu nhiều tab chưa mở làm tăng số request cạnh tranh | Preload code; tab được mở mới tải dữ liệu. `AppLayout` vốn chỉ preload code |
| `LoginPage`, `auth.store`, `HomePage` | Phiên đăng nhập có thể được công bố trước khi có đủ vai trò | Hoàn tất lấy thông tin người dùng trước khi cập nhật phiên đầy đủ |
| `ProductReportController`, `OrderController::salesChartData` | Tìm thời điểm/chu kỳ mới nhất trước khi đọc cache | Cache lookup trước `MAX` và các truy vấn nhóm; cache phân biệt dữ liệu và quyền |
| `OrderController` | Một số truy vấn không có đủ hai đầu ngày bỏ qua summary | Cho phép summary cho toàn thời gian và phạm vi hỗ trợ; bộ lọc ngoài khả năng summary dùng nguồn gốc |
| `OrderSummaryService`, hai job đồng bộ | Rebuild theo ngày không cung cấp ledger đóng góp cũ, retry bền vững và baseline đáng tin cậy | Ledger theo đơn, cập nhật phần đóng góp cũ/mới, hàng đợi pending trong giao dịch và trạng thái readiness |
| `CustomerCareController::overview` | Ba aggregate care và thống kê đơn hôm nay chạy lại mỗi lần | Cache response theo người dùng, quyền, shop, ngày/tuần và revision |
| `UserController::weeklyRevenue` | `MAX`, tìm tuần đủ nhân viên và nhóm doanh thu chạy lại | Cache response trước các truy vấn này; giữ toàn bộ lịch sử |
| `ProvinceController::charts` | `MAX`, ba kỳ so sánh, nhóm tỉnh và xu hướng chạy lại | Cache response trước truy vấn; cửa sổ độ mới một phút cho so sánh kỳ đang diễn ra |

`CustomerController::index()` vẫn cần `pancake_full_data`: frontend đang đọc địa chỉ từ `shop_customer_addresses`. Không bỏ trường này. `orderInsights()` đã giới hạn truy vấn sort trước, sau đó chỉ lấy JSON của trang hiện tại. Chưa thêm index khách hàng khi chưa có EXPLAIN chứng minh cần thiết.

Hai summary hiện tại không chứa chiều nhân viên hoặc tỉnh. Không dùng tổng theo ngày/shop để thay thế trực tiếp doanh thu từng nhân viên hoặc tỉnh. Những endpoint này giữ công thức SQL hiện tại trong trường hợp cache MISS.

## Cách dữ liệu tổng hợp được bảo vệ

- `order_summary_state`: baseline schema version 2 chỉ được bật sau một lần `--all` thành công.
- `order_summary_contributions`: lưu đóng góp đã áp dụng của từng đơn và thời điểm tạo chính xác. Không thay timestamp bằng `MAX(report_date)`.
- `order_summary_pending`: lưu order ID ngay trong giao dịch nguồn. Sau commit mới đồng bộ summary. Retry đọc trạng thái mới nhất của đơn, thay vì cộng lại một webhook cũ.
- Khi giá trị, trạng thái, shop, ngày hoặc sản phẩm đổi, service trừ đóng góp cũ rồi cộng đóng góp mới trong giao dịch. Soft-delete loại đóng góp; restore áp dụng lại.
- Có pending, baseline chưa sẵn sàng hoặc schema chưa đúng thì API dùng nguồn gốc. Không trả summary thiếu dưới dạng số liệu hợp lệ.
- Ghi bulk vào `orders` ngoài các luồng có theo dõi sẽ tắt readiness để tránh tin summary cũ. Phải chạy `--all` để khởi tạo lại đầy đủ; `--date` không bật lại một baseline không đáng tin cậy.
- `daily_order_summaries.shop_id = 0` là dòng toàn hệ thống. Query chọn dòng 0 hoặc các shop được phép; không cộng cả hai loại dòng.
- Sản phẩm có khóa danh tính theo shop/product/variation. Khi thiếu cả hai mã, dùng order ID và vị trí item để tránh gộp các sản phẩm chỉ vì trùng tên.

Cache backend dùng store đang cấu hình; không bắt buộc đổi từ database sang Redis. Revision được đổi sau commit khi nguồn dữ liệu hoặc quyền liên quan thay đổi. Cache key phân biệt người dùng, phạm vi shop, vai trò, bộ lọc và revision. Các báo cáo phụ cache 60 giây và có thành phần phút/ngày để xử lý dữ liệu phụ thuộc đồng hồ.

## Triển khai

Thực hiện trong thư mục backend `pancake_order`. Lệnh dưới đây chỉ thay cấu trúc và dữ liệu dẫn xuất; không xóa hay rebuild bảng đơn hàng nguồn.

1. Kiểm tra trạng thái migration:

   ```console
   php artisan migrate:status
   ```

2. Áp dụng hai migration theo thứ tự, bỏ qua migration đã được áp dụng. Dùng quy trình xác nhận production hiện có của dự án:

   ```console
   php artisan migrate --path=database/migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php
   php artisan migrate --path=database/migrations/2026_11_02_000002_make_order_summaries_incremental.php
   ```

   Migration thứ hai thêm trường/khóa/bảng dẫn xuất và mở rộng độ chính xác doanh thu sản phẩm. Baseline cũ không được mặc định là đầy đủ.

3. Khởi tạo toàn bộ lịch sử một lần ngoài request HTTP:

   ```console
   php artisan orders:sync-summaries --all
   ```

   Chọn thời điểm phù hợp cho tác vụ quét dữ liệu nguồn. GET vẫn có đường fallback trong lúc baseline chưa sẵn sàng.

4. Đối soát trước khi chấp nhận dữ liệu tổng hợp:

   ```console
   php artisan orders:sync-summaries --verify
   ```

   Nếu có pending, xử lý trước rồi chạy lại:

   ```console
   php artisan orders:sync-summaries --pending
   php artisan orders:sync-summaries --verify
   ```

5. `routes/console.php` đã lên lịch `orders:sync-summaries --pending` mỗi phút, có chống chạy chồng. Bảo đảm Laravel scheduler được hệ điều hành gọi mỗi phút:

   ```console
   php artisan schedule:run
   ```

   Mỗi lượt pending xử lý tối đa 1.000 order ID. Phần còn lại được giữ cho lượt sau. Không cần rebuild toàn bộ lịch sử theo lịch.

6. Local hiện được Vite phục vụ từ `pancake_frontend_v2`; quy trình production trong `DEPLOYMENT.md` dùng `frontend/`. Cả hai cây đã nhận sửa đổi tương ứng. Build cây được môi trường đó sử dụng:

   ```console
   npm --prefix pancake_frontend_v2 run build
   npm --prefix frontend run build
   ```

   Khởi động lại Vite development để nhận cấu hình proxy mới. API development dùng cùng origin `/api` qua Vite; cấu hình production hiện có vẫn được giữ.

## Lệnh vận hành

| Lệnh | Mục đích | Điều kiện |
| --- | --- | --- |
| `orders:sync-summaries --all` | Khởi tạo lại đầy đủ summary và ledger từ nguồn | Đã chạy migration |
| `orders:sync-summaries --pending` | Retry thay đổi chưa áp dụng | Baseline đã khởi tạo; có thể đang có pending |
| `orders:sync-summaries --date=2026-11-01` | Sửa các đơn ở ngày nguồn hoặc ngày ledger tương ứng | Baseline sẵn sàng, không pending |
| `orders:sync-summaries --days=60` | Sửa đóng góp các ngày gần nhất | Baseline sẵn sàng, không pending |
| `orders:sync-summaries --verify` | Đối soát dữ liệu nguồn và dẫn xuất | Baseline sẵn sàng, không pending |

Chọn một chế độ mỗi lần chạy. Không truyền ngày thì mặc định sửa 60 ngày gần nhất; chế độ này không dùng để khởi tạo toàn bộ lịch sử. Ngày phải đúng `YYYY-MM-DD`; số ngày phải là số nguyên dương.

`--verify` chỉ đọc dữ liệu để đối soát; không tự sửa tổng. Nó kiểm tra từng ngày/shop và dòng toàn hệ thống: số đơn, COD, prepaid, từng nhóm trạng thái, số đơn giao có vận đơn và số lượng giao. Sản phẩm được kiểm tra theo danh tính, ngày/shop/trạng thái/vận đơn: số lượng, doanh thu, số đơn phân biệt và tên hiển thị chuẩn hóa. Tên chuẩn là giá trị lớn nhất theo thứ tự byte (`strcmp`) trong các dòng cùng danh tính; tên không tham gia gộp danh tính. Ledger được kiểm tra danh sách order ID, shop, ngày, timestamp và cờ giao hàng.

Nếu lệch, command trả exit code 1, hiển thị tối đa 20 ví dụ, tắt readiness và đổi revision cache. API quay về nguồn gốc. Chỉ `--all` thành công mới bật lại baseline. Đơn hàng nguồn không bị sửa. Exit code 2 biểu thị lựa chọn lệnh không hợp lệ.

Nếu đối soát không hoàn thành do JSON không đọc được hoặc lỗi thực thi, baseline cũng bị tắt và lỗi được in rõ. Riêng pending xuất hiện trong lúc bắt đầu kiểm tra sẽ hoãn đối soát và hướng dẫn `--pending`, giữ baseline đã khởi tạo để có thể retry.

## Kết quả kiểm tra đã thực hiện

Các số dưới đây là số truy vấn được xác nhận bằng test trên SQLite trong bộ nhớ, không phải latency đo trên MySQL production.

| Trường hợp | Cold | Warm |
| --- | --- | --- |
| Weekly revenue, khoảng ngày cụ thể | 2 truy vấn đọc orders | 0 truy vấn đọc orders |
| Province, không có vận đơn | 1 truy vấn đọc orders | 0 truy vấn orders/schema |
| Care overview | 3 aggregate care và 1 aggregate orders | 0 truy vấn care/orders; vẫn kiểm tra phạm vi shop |

`DashboardReportCacheTest` xác nhận cache HIT, thay đổi sau commit, rollback giữ kết quả đã commit, lịch sử trên 90 ngày, cửa sổ phút và cách ly tài khoản/shop. `SyncOrderSummariesCommandTest` kiểm tra khởi tạo đầy đủ, đối soát chỉ đọc, sai lệch ngày/sản phẩm/ledger, tắt summary khi sai, retry pending, sửa ngày cũ khi đổi shop/ngày, tính idempotent và lựa chọn lệnh không hợp lệ. Mọi migration trong test chạy trên kết nối SQLite `:memory:` riêng, không dùng database MySQL đang có dữ liệu.

Các test khác của thay đổi kiểm tra đóng góp cũ/mới, webhook lặp, tạo/sửa/chuyển trạng thái/hủy/hoàn tiền/đổi shop/ngày, soft-delete/restore và summary chưa sẵn sàng. Kết quả chạy cụ thể được báo cùng đợt bàn giao; không suy ra tốc độ MySQL từ thời gian chạy PHPUnit.

Đợt kiểm tra backend mở rộng đã đạt **131 test, 1.564 assertion** trong 16,8 giây trên SQLite, gồm các test mới và test hiện có về order journey/history/page/source backfill, authorization và IDOR. Đây là tập test được chọn. Test cũ `PancakeOrderIntegrationTest::test_no_data_is_written_to_ban_mai_database` được loại khỏi lượt này vì giả định có sẵn bảng `orders` trong database thật và thất bại trước khi tạo fixture khi ép kết nối sang SQLite `:memory:`. Không coi đây là kết quả toàn bộ test suite.

## Đo trên môi trường chạy thật

Thời gian 10–20 giây là số người dùng cung cấp trước thay đổi. Dưới 1 giây khi cache HIT là mục tiêu, không phải cam kết đã đo đạt.

Đã đo các phương án SQL riêng lẻ trên MySQL local trước khi hoàn tất triển khai. Mỗi giá trị là median của 3 mẫu:

| Phép đo SQL trước triển khai | Đọc nguồn gốc | Đọc bảng summary cũ |
| --- | --- | --- |
| Tìm `MAX(created_at)` của đơn giao phù hợp | 1.505,40 ms | Không có phép đo tương đương timestamp |
| Tổng số đơn và COD toàn thời gian | 1.376,62 ms | 5,29 ms |
| Số lượng/doanh thu sản phẩm tháng 08/2026 | 1.042,15 ms | 13,72 ms |

Phép đối chiếu sản phẩm tháng 08/2026 khớp số lượng và doanh thu ở 136 nhóm tên. Đối soát mới kiểm tra thêm danh tính product/variation. Các thời gian trên mô tả SQL nguồn và phương án đọc summary trước triển khai; chưa phải số đo API sau sửa hay thời gian mở Dashboard lần đầu.

Có thể lặp phép đo controller/SQL local bằng:

```console
php scripts/benchmark-dashboard.php --write
```

Script có guard chỉ chạy môi trường local và không tạo token đăng nhập. Nó gọi trực tiếp controller/SQL; thời gian không bao gồm HTTP, xác thực, bootstrap ứng dụng hay browser render. Vì vậy kết quả này không đại diện cho thời gian tải Dashboard đầy đủ. Báo cáo JSON sau đo được lưu trong `docs/` khi dùng `--write`.

Đã đo sau thay đổi trên MySQL local lúc **11:26:25 ngày 02/11/2026, UTC+7**, tài khoản Admin, cache store `database`. Dữ liệu gốc có 90.576 đơn và 66.180 khách hàng. Chi tiết lưu tại [dashboard-benchmark-local.json](dashboard-benchmark-local.json).

| Controller / trường hợp | Lần 1 (ms) | Lần 2 / lần 3 (ms) | Số SQL lần 1 → lần 2/3 | Đọc orders lần 1 → lần 2/3 | Đọc care lần 1 → lần 2/3 | Cache response |
| --- | --- | --- | --- | --- | --- | --- |
| Sản phẩm theo tháng | 73.42 | 3.00 / 2.66 | 25 → 6 / 6 | 0 → 0 / 0 | 0 → 0 / 0 | MISS → HIT / HIT |
| Sản phẩm theo kỳ năm | 97.05 | 3.78 / 2.91 | 15 → 6 / 6 | 0 → 0 / 0 | 0 → 0 / 0 | MISS → HIT / HIT |
| Tổng đơn toàn thời gian | 50.49 | 57.03 / 54.60 | 8 → 8 / 8 | 0 → 0 / 0 | 1 → 1 / 1 | Không dùng |
| Care overview | 823.99 | 3.03 / 2.47 | 10 → 5 / 5 | 2 → 0 / 0 | 3 → 0 / 0 | MISS → HIT / HIT |
| Số đơn theo tháng | 14.22 | 6.32 / 6.17 | 5 → 5 / 5 | 0 → 0 / 0 | 0 → 0 / 0 | Không dùng |

Tổng thời gian SQL của ba lượt tương ứng: sản phẩm tháng **37.90 / 2.06 / 1.84 ms**; sản phẩm kỳ năm **43.17 / 2.58 / 1.91 ms**; tổng đơn **37.11 / 47.77 / 45.34 ms**; Care overview **814.99 / 1.87 / 1.45 ms**; số đơn tháng **9.75 / 5.43 / 5.31 ms**. Số SQL gồm truy vấn cache database, metadata và phạm vi quyền, nên HIT vẫn có SQL. Tổng đơn và số đơn tháng đọc summary trực tiếp, không có cache toàn response.

Mỗi trường hợp chỉ có ba mẫu, đủ ghi nhận các lượt đã chạy; chưa tính p95. Chưa đo HTTP, preflight, số GET toàn trang, đăng nhập hoặc browser render. Không dùng bảng này để khẳng định Dashboard đầy đủ tải dưới một giây.

| Chỉ tiêu | Trước | Sau |
| --- | --- | --- |
| Dashboard đầy đủ, tài khoản và bộ lọc cố định | Chưa có phép đo lặp cùng điều kiện | Cần ghi sau triển khai |
| Từng API: cold, warm, mở lại, cache hết hạn | Cần ghi | Đã có controller/SQL ở bảng trên; còn HTTP/mở lại/hết hạn |
| GET/OPTIONS, thời gian waiting/queue | Cần HAR/Network | Cần HAR/Network |
| Số SQL, tổng SQL time, cache HIT/MISS | SQL phương án trước triển khai ghi ở trên | Đã ghi controller local; còn luồng trang đầy đủ |
| p50/p95 | Chưa đủ mẫu | Thu tối thiểu đủ mẫu cùng kịch bản rồi tính |

Đo cùng tài khoản, shop, dữ liệu, trình duyệt và bộ lọc. Ghi cold cache một lần, warm nhiều lần, mở lại trang, tạo đơn mới, sửa đơn và hết TTL. Tách thời gian chờ HTTP/server khỏi thời gian SQL. Dùng HAR để xác nhận tab chưa mở không phát API dữ liệu, Admin không gọi API CareHome và development không gửi preflight cho `/api` cùng origin.

So sánh JSON/KPI trên cùng snapshot trước/sau với Admin toàn shop, nhân viên giới hạn shop, không dữ liệu và summary thiếu/chưa đồng bộ. Chỉ chấp nhận kết quả nhanh hơn khi số liệu và quyền truy cập khớp.

## Khôi phục

Giữ các bảng dẫn xuất khi rollback code; không chạy `migrate:rollback` trên hệ thống có dữ liệu chỉ để quay về phiên bản trước. Nếu đối soát lỗi, readiness đã tắt và báo cáo đọc nguồn. Sửa nguyên nhân rồi chạy `--all` và `--verify`.

Để quay lại phiên bản ứng dụng trước, phục hồi code theo quy trình hiện có và làm mới cache ứng dụng theo quy trình triển khai. Không phục hồi hay xóa bảng `orders`/`customers` vì thay đổi này không sửa dữ liệu nghiệp vụ của chúng.

## Đối soát local sau triển khai

Đã áp dụng migration bổ sung và khởi tạo Summary trên MySQL local. Lệnh `orders:sync-summaries --verify` hoàn tất thành công với **2.650 nhóm ngày/shop, 22.669 nhóm sản phẩm và 90.576 đơn nguồn**. Kiểm tra cuối ghi nhận 90.576 đơn, 66.180 khách hàng, không còn pending và readiness bật. Không sửa dữ liệu đơn hàng/khách hàng nguồn để benchmark.

Bộ kiểm thử frontend cuối đạt **20/20 ở mỗi cây source**, bao gồm reset shop khi logout/đổi tài khoản và giữ bộ lọc shop khi token cùng người dùng được đổi. Hai bản production build đều thành công. Bộ backend chọn lọc chạy cuối đạt **131 tests / 1.564 assertions**; kiểm tra bổ sung các đầu vào ngày đảo/ngày không chuẩn và shop số 0 cũng đạt. Một kiểm thử cũ dựa vào bảng orders của database mặc định được loại khỏi lần chạy ép SQLite trong bộ nhớ; không coi đây là toàn bộ suite của repository đã đạt.