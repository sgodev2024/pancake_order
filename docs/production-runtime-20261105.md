# Pancake — trạng thái Production ngày 05/11/2026

Audit chỉ đọc trên `123.31.31.112`, `/var/www/html/pancake_order`, bắt đầu lúc **08:53:59 +07:00**. Branch `main`, HEAD **6351b8b28e8d9be90c34727f9b76dddf59f1fcf5**, Git gốc sạch lúc đầu và cuối kiểm tra.

## Kết luận

**Backend, build Frontend và schema POS/Kho đã hiện diện trên server và đang được phục vụ từ repo chung. AICRM thật chưa được cấu hình trên server Pancake.** Không phải trường hợp chỉ có source POS mà chưa build giao diện mới.

Điểm cần xử lý trước khi coi luồng vận chuyển sẵn sàng: cấu hình effective hiện là **APP_ENV=local, AICRM_MODE=mock**. Live creation đang false, nhưng không đồng nghĩa mọi thao tác vận chuyển đều bị khóa: mode Mock còn có thể tạo bản ghi/mã giả khi người có quyền Kho bấm tạo vận đơn. Không có shipment nào trong bảng tại thời điểm kiểm tra; không thử POST để chứng minh bằng cách ghi dữ liệu.

## 1. Backend đang phục vụ

| Hạng mục | Bằng chứng |
| --- | --- |
| Nginx API | `/etc/nginx/conf.d/pancake_order.conf`, cổng 8080, root `/var/www/html/pancake_order/public`, FastCGI socket `/var/run/php-fpm/php-fpm.sock` |
| PHP thực tế qua HTTP | `/up` trả HTTP 200 và `X-Powered-By: PHP/8.2.24` |
| Endpoint có bảo vệ đăng nhập | GET `/api/v1/warehouse/orders` và `/api/v1/products` trả 401 khi không có token; không dùng kết quả này thay bằng chứng phân quyền từng role |
| Routes đã nạp từ cache | Có POST orders, GET Kho, hoàn tất đóng hàng, AICRM viettel-post và CRUD sản phẩm |
| Source khớp Git | Hash byte khớp HEAD cho OrderController, ProductController, WarehouseController, OrderService, WarehouseService, AicrmLiveConnection, AicrmOrderClient và source POS/menu |
| Runtime CLI | PHP 8.2.24, Laravel 12.64.0, MariaDB 10.3.39; yêu cầu PHP >=8.2 của project được đáp ứng |

Source Kho hiện lọc `origin=local_pos` theo commit `b1fb756`. Kho đang enabled, mốc kích hoạt **05/11/2026 08:15:24 +07:00**. Đơn cũ trước mốc hoặc đơn không đủ điều kiện không tự được backfill vào Kho. Audit không đổi mốc và không đóng hàng.

Code trên đĩa, routes, root phục vụ, phiên bản PHP HTTP và thời điểm các worker khớp hướng triển khai hiện tại. Không có endpoint version trả SHA từ chính request PHP; vì vậy không khẳng định đã thực thi thành công mọi nhánh nghiệp vụ chỉ từ các kiểm tra đọc này.

## 2. Frontend thực tế

Frontend sử dụng cho audit: **http://123.31.31.112:8081**.

- Nginx `/etc/nginx/conf.d/pancake_frontend_v2.conf` trỏ vào **`/var/www/html/pancake_order/frontend/dist`**.
- `/api` của Frontend được proxy sang Backend `127.0.0.1:8080`.
- GET `/ban-hang` và `/kho` cổng 8081 trả 200; checksum HTML khớp `frontend/dist/index.html` trên đĩa.
- `index.html` có mtime **08:15:24 +07:00**, entry **`/assets/index-C5mMv1QW.js`**.
- Theo đồ thị import từ chính entry đó, chunk POS active là **`PosPage-BwofjxmH.js`**, Kho là **`WarehousePage-DVgjizIs.js`**. GET hai chunk trả 200 và byte khớp file trên đĩa.
- Chunk POS active có điều kiện một shop `length===1`, kiểm tra `isSuccess && isFetchedAfterMount && !isFetching`, thông báo không có shop, popup **Đã nhận đủ tiền / Chưa nhận tiền**, select **Hình thức thanh toán** và các trường payment mới. Không có nhãn ô cũ **Khách đã thanh toán** trong chunk active.
- Bản hiện tại theo main có **4 lựa chọn**: Tiền mặt đã thu đủ; Chuyển khoản đã nhận đủ; **Chuyển khoản một phần + COD**; Thu hộ COD. Phần một phần đã có trong sáu commit được giữ trước `6351b8b` và có trong build active.
- Chunk Kho active có chức năng tạo vận đơn Viettel Post. Nó chưa chứng minh AICRM Live đã được cấu hình.

Trong dist còn nhiều chunk hash cũ được giữ lại. Audit theo entry/import đang active, không lấy việc tìm thấy file cũ trong thư mục làm bằng chứng người dùng đang chạy bản cũ. Chưa có manifest gắn build với SHA để chứng minh toàn bộ artifact trùng tuyệt đối với commit; đã xác minh trực tiếp những tính năng được yêu cầu trong bundle active. Không đăng nhập mới, không tạo đơn và không thay giỏ hàng của người dùng.

**Khác biệt URL cần chú ý:** virtual host cổng 80 của IP vẫn trỏ Frontend vào `/var/www/html/pancake_frontend/dist`; ở đó không có index.html và GET `/ban-hang` cổng 80 trả **500**. Kết luận giao diện mới hoạt động ở trên áp dụng cho **cổng 8081**, không áp dụng cho URL IP bỏ cổng. Không sửa Nginx trong lượt audit.

## 3. Guard và quyền

`ProductController::authorizeProductWrite()` có mặt và được gọi ở **store, update, destroy**, chặn `staff-sale` và `staff-cskh` bằng AuthorizationException. Source này khớp HEAD. Không gửi POST/PUT/DELETE trên Production để thử 403, nên kết luận là guard và đường gọi đã có, chưa phải test request ghi trên từng tài khoản.

Quyền hiện đọc trực tiếp từ DB:

| Role | Quyền cần cho POS/Kho đã thấy |
| --- | --- |
| staff-sale, ID 14 | list-product, list-customer; quyền tạo theo role Sale hiện có |
| staff-cskh, ID 13 | pos-create, list-product, list-customer |
| manager-cskh, ID 2 | pos-create, list-product, list-customer |
| admin, ID 1 | list-product, list-customer; quyền tạo theo role Admin hiện có |
| warehouse, ID 16 | warehouse-view, warehouse-pack |

Có **1 tài khoản Kho active**, tài khoản đó có ít nhất một shop. Không xuất tên, mật khẩu hoặc danh sách khách. Admin không có hai grant Kho trong kết quả đọc; đây phù hợp kiểm tra grant Kho rõ ràng hiện tại, không tự cấp quyền. Đọc grant không thay thế kiểm tra thực tế shop và khách được phân công của từng tài khoản.

## 4. Cấu hình AICRM effective trên chính server

Đã nạp config theo Laravel đang cache và đối chiếu .env tại server, chỉ xuất whitelist, không in API Key.

| Cấu hình | Giá trị hiện tại |
| --- | --- |
| APP_ENV | **local**; .env cũng khai báo local |
| APP_DEBUG | false |
| AICRM_MODE | **mock** |
| Live API URL | **Trống** |
| Live host allowlist | Rỗng |
| Live enabled | false |
| Live creation | **false** |
| Contract / reconciliation confirmed | false / false |
| Approved order | Chưa cấu hình |
| Live API Key | **Chưa cấu hình trong effective config** |
| Credential tenant | Chưa cấu hình |
| Mapping shop → tenant | Rỗng |
| Tenant credentials | Không có cấu hình map |
| Live provider ID | **Chưa cấu hình** |
| Provider GET path | /api/v1/shipping-providers |
| Sandbox / Local AICRM integration | false / false |

Trong .env **server không có khai báo AICRM_ nào**. Những giá trị false/mock/rỗng đang là mặc định đã được cache; không phải bằng chứng chỉ cần clear cache là key sẽ xuất hiện. Không sử dụng `API_KEY_FROM_PANCAKE_WEB` của dịch vụ khác thay key AICRM Live.

GET trước đó HTTP 200, VIETTEL_POST ID 1 enabled là kết quả từ **Backend Local trên máy Mac**, chưa chứng minh key/config đã có trên server này. Audit hiện tại **không gửi GET có key ra AICRM**, vì Backend server chưa có config Live, và không sao chép key từ máy Mac sang server.

Nguy cơ Mock cụ thể: `AicrmOrderClient` chỉ chặn các mode không-Live khi app environment là production. Với app_env=local và mode=mock, nhánh Mock có thể tiếp tục; `WarehouseController` cũng chỉ áp dụng những chốt Live khi mode=live. Do đó người có quyền Kho có thể được phép ghi shipment giả cho đơn đã đóng. Đây là rủi ro từ cấu hình đang chạy, không phải bằng chứng đã có vận đơn thật. Bảng aicrm_shipments hiện **0 bản ghi**.

Tenant Production, token hãng/sender và môi trường Viettel Post upstream vẫn cần xác nhận riêng từ AICRM. Host AICRM Production và provider enabled không tự chứng minh AICRM đang gọi đúng môi trường Viettel Post.

## 5. Cache, FPM, worker, scheduler

| Hạng mục | Trạng thái |
| --- | --- |
| Config cache | Có, `bootstrap/cache/config.php`, mtime **08:15:24 +07**; đang chứa cấu hình local/mock ở trên |
| Route cache | Có, `bootstrap/cache/routes-v7.php`, mtime **07:25:35 +07**; có routes POS/Kho/AICRM cần kiểm tra |
| CLI / FPM / HTTP PHP | Cùng **8.2.24**; PHP hiện tại phù hợp yêu cầu project, không có bằng chứng cần nâng PHP cho các tính năng này |
| OPcache | FPM ini đọc bằng tiến trình kiểm tra mới: enabled, validate_timestamps=On, revalidate_freq=2, không preload/file cache; file ini có mtime cũ trước các worker. Đây là thông tin cấu hình, không phải dump OPcache trực tiếp của worker HTTP |
| Worker Pancake | **17 process queue:work**, `/usr/bin/php`, script `/var/www/html/pancake_order/artisan`, bắt đầu **08:24:19–08:24:23 +07** |
| Mốc source Kho | WarehouseController/WarehouseService cập nhật **08:24:15 +07**, trước các worker trên |
| Supervisor | 11 config chương trình Pancake đã thấy, dùng đường dẫn artisan tuyệt đối đúng repo; không nhầm với worker ứng dụng khác trên cùng server |
| Scheduler | crond active; root crontab có `* * * * *`, cd đúng repo rồi gọi `php artisan schedule:run` |

Worker khởi động sau config cache và source Kho mới; không thấy dấu hiệu chạy từ repo cũ. Sau khi thay config ở lượt được duyệt sau này vẫn cần cho đúng daemon Pancake nạp lại config, không restart toàn bộ ứng dụng khác.

Cron dùng tên `php`, chưa pin đường dẫn tuyệt đối. Audit chưa có dấu vết exit thành công của task scheduler gần nhất trong nguồn log đã kiểm tra, nên **không kết luận các task đều chạy thành công chỉ từ crond active**. Nên xác minh PATH và kết quả scheduler/queue trong lượt nghiệm thu; không gọi schedule:run hoặc queue worker để thử trong lượt đọc này.

## 6. Schema và customers.last_order_at

Đọc metadata trong transaction **READ ONLY** trên MariaDB **10.3.39**, DB `pancake_order`:

- Candidate server có **81 file migration, không còn pending**.
- Migration last_order_at và prepaid_amount: Ran batch **34**.
- PLAN 2 POS: batch **35**; Kho và explicit POS permission: batch **36**.
- Bốn migration AICRM và grant staff POS ngày 04/11: batch **42**.
- Role Kho ngày 05/11: batch **43**.
- `customers.last_order_at` tồn tại, **datetime nullable**, có index `(shop_id,last_order_at)`.
- `SELECT last_order_at FROM customers LIMIT 0` và truy vấn WHERE 1=0 đều thành công. Không đọc/xuất giá trị khách hàng.
- aicrm_shipments / aicrm_shipment_attempts có bảng và các cột metadata/tenant cần thiết.

Lỗi thiếu cột `customers.last_order_at` từng gặp trên Local **không tồn tại về schema ở Production này**. Điều đó chưa xác minh chất lượng backfill/múi giờ của mọi dòng hoặc toàn bộ API order-insights; cần kiểm thử GET có quyền riêng nếu cần chứng minh endpoint đầy đủ. Không sửa dữ liệu và không chạy lại migration.

## 7. Phân loại và thao tác tối thiểu tiếp theo

| Phân loại | Các mục |
| --- | --- |
| Đang chạy đúng trên Production | Đường dẫn Backend/release, PHP, routes, guard source sản phẩm, grant POS, schema, build POS/Kho active tại cổng 8081 |
| Source có nhưng Frontend chưa build | **Chưa phát hiện trong các tính năng POS/chọn shop/thanh toán/Kho được yêu cầu**; không có căn cứ build lại chỉ để đưa những tính năng này lên |
| Cấu hình còn thiếu/sai | APP_ENV=local; AICRM mock và toàn bộ config Live chưa có; cổng 80 trỏ FE cũ thiếu index |
| Cần kiểm thử thêm | Đăng nhập/role/shop/customer thực tế, xác nhận thanh toán và chuyển Kho trên môi trường kiểm thử, GET AICRM từ server, tenant/upstream Viettel Post, kết quả scheduler/queue |

Thứ tự tối thiểu đề xuất, **chưa thực hiện**:

1. Chốt URL sử dụng **cổng 8081**; nếu muốn dùng IP/cổng 80 hoặc domain chung, duyệt riêng sửa virtual host cho đúng FE. Build hiện tại đã chứa chức năng cần kiểm tra.
2. Trong lượt được duyệt, sao lưu và chỉnh **một lần cấu hình Backend server**: APP_ENV=production, AICRM mode phù hợp Live với các chốt tạo thật vẫn OFF, URL `https://api-vtp.aicrm.vn`, allowlist và key Backend riêng. Điền UUID tenant và mapping shop/provider sau khi AICRM xác nhận; không dùng ID shop/tenant TEST Local. Tránh để Production cho phép tạo shipment Mock.
3. Sau chỉnh, tạo lại Laravel config cache và cho đúng PHP/worker Pancake nạp cấu hình theo quy trình vận hành; giữ creation=false, không approved order. Chỉ thay đổi config/cache/process sau khi được cho phép, không làm trong audit này.
4. Thực hiện đúng một **GET shipping-providers từ chính server** bằng X-API-Key trong Backend. Nếu dùng lệnh aicrm:live-check hiện có, nó còn yêu cầu bật kết nối đọc, contract/mapping và actor/shop có warehouse-view; creation vẫn false. Hoặc dùng diagnostic GET-only đã review mà không bật tạo vận đơn. Ghi HTTP/status/provider ID đã lọc, không raw body có token hãng. Xác nhận provider ID 1 thuộc đúng tenant và Viettel Post upstream thực sự ở môi trường mong muốn.
5. Nghiệm thu Sale/CSKH/Admin và tài khoản Kho theo shop: UI đọc/preview/hủy xác nhận; kiểm thử ghi đơn, thu tiền và đóng hàng trên staging trước. Không tự tạo canary Production trong audit. Kiểm tra scheduler và queue có kết quả thành công. Chỉ xem xét mở tạo vận đơn thật ở một bước phê duyệt riêng sau khi các điều kiện này đạt.

Không cần chạy lại migration chỉ vì lỗi last_order_at Local, không cần mặc định build lại Frontend, và không được coi GET Local trước đây là kiểm thử từ server.

## Phạm vi đã thực hiện

Chỉ Git/read filesystem/process metadata, GET nội bộ health/HTML/JS và API không đăng nhập, đọc config đã lọc bí mật, SELECT metadata/permission và số shipment trong transaction READ ONLY. Không đăng nhập mới, không xuất dữ liệu khách, không gọi API vận chuyển ngoài, không tạo đơn/shipment, không sửa source/.env/cache/config, không build/deploy/migrate/restart/commit/push.

Bằng chứng đã lọc được lưu riêng trên máy Mac tại `/Users/viet/Pancake/.local-backups/prod-runtime-audit-20261105/`, không nằm trong repo và không chứa API Key. Báo cáo này là file Local, chưa commit.
