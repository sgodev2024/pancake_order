# PLAN 3 — Quản lý Kho

> Trạng thái Local mới nhất: migration PLAN 3 PASS; Kho ON từ 2026-10-22T13:54:15+07:00, đã kiểm tra bằng tài khoản/đơn TEST riêng. Xem [báo cáo triển khai Local](/Users/viet/Pancake/pancake_order/docs/plan-3-local-activation.md). Các phần bên dưới ghi lại trạng thái ở thời điểm audit/triển khai trước.

Ngày triển khai: 21/10/2026. Backend và Frontend đều ở branch `viet_dev`. Không commit/push. Đã kiểm tra Git, schema MySQL, báo cáo PLAN 2, luồng bulk sync và webhook trước khi chỉnh sửa; giữ nguyên các thay đổi PLAN 1/2 chưa commit.

## Kết quả triển khai

- Menu `/kho`, tab Chờ đóng hàng / Đã đóng hàng; lọc shop, tìm mã đơn hoặc tên/số điện thoại khách, phân trang tại Backend.
- Cùng danh sách nhận đơn Local POS mới và Pancake mới đủ điều kiện, kể cả không có vận đơn. Hiển thị nguồn, khách, địa chỉ, sản phẩm/số lượng, ghi chú, người tạo gốc, ngày tạo gốc, lần đồng bộ đầu tiên; chi tiết có lịch sử đóng hàng.
- Xác nhận trước khi hoàn tất. Lưu người đóng/thời điểm/trạng thái và audit trong một transaction, khóa bản ghi. Request lặp trả `replayed: true`; không đổi người/thời gian và không ghi audit lần hai. Hai request đồng thời trên MySQL riêng đã được xác minh.
- Không sửa tiền/COD hoặc sản phẩm/tồn khi đóng hàng. Không trừ tồn ảo lần hai. Giữ nguyên cách tính tối thiểu 5 và cơ chế POS của PLAN 1/2.
- Tất cả API yêu cầu quyền Kho rõ ràng và kiểm tra shop. Admin cũng cần quyền Kho; quyền quản lý toàn shop vẫn theo `ShopAccessService`. Không cấp quyền cho bất kỳ role/tài khoản thật nào.
- Đơn đang chờ có trạng thái hủy, giao/hoàn tất hoặc không còn hợp lệ sẽ bị loại khỏi chờ; ghi audit một lần. Đơn đã đóng giữ nguyên kết quả khi sync đổi trạng thái; ghi cảnh báo để rà soát, không hoàn tiền/hủy vận đơn/điều chỉnh hàng vật lý/tự xếp hàng lại.
- Webhook thiếu thông tin trên đơn đã vào Kho không xóa sản phẩm, tiền hoặc ngày tạo ban đầu. Các trường ngày nguồn/người tạo nguồn không bị ghi đè khi đồng bộ lại.

## Phần được tái sử dụng

`orders`, `warehouse_status`, `warehouse_queued_at`, `origin`, `created_by_user_id` từ PLAN 2; `OrderService::createLocalOrder`, các job Pancake hiện có; `shop_users`, `ShopAccessService`, role/permission và middleware strict; `ActivityLogService`, activity_logs và khóa idempotency; ProductCell của modal chi tiết đơn, ShopSelect, React Query, Ant Design và bố cục trang hiện có. Không tạo bảng hàng đợi, bảng tồn kho hoặc bảng audit mới.

## API

Prefix `/api/v1`, xác thực Passport như ứng dụng hiện tại:

| API | Quyền | Hành vi |
| --- | --- | --- |
| GET `/warehouse/orders` | warehouse-view | `status=waiting_packing` hoặc `packed`, `shop_id`, `search`, `page`, `page_size` tối đa 100. Trả module configuration cùng danh sách. |
| GET `/warehouse/orders/{id}` | warehouse-view | Chi tiết thuộc shop cho phép và hàng đợi hợp lệ. |
| POST `/warehouse/orders/{id}/complete` | warehouse-view + warehouse-pack | Chỉ nhận ID đơn, transaction + lock, không có trường sửa tiền/tồn. |

Sai shop: 403; dữ liệu lọc sai: 422; hoàn tất khi chưa kích hoạt/đơn không còn hợp lệ: 409. Khi module tắt, list rỗng; không truy vấn các cột migration mới nên code có thể được kiểm tra trong trạng thái chưa triển khai schema.

## Quy tắc kích hoạt và tuổi đơn

`config/warehouse.php` đọc `WAREHOUSE_ACTIVATED_AT`. Mặc định trống = TẮT. Chỉ nhận timestamp ISO đầy đủ có timezone, ví dụ `2026-11-01T08:00:00+07:00`; cấu hình sai hoặc thời điểm chưa đến thì không hoạt động. API trả `enabled`, `activated_at`, `configuration_valid` để kiểm tra; giao diện hiển thị trạng thái này.

- Local POS: chỉ tại thời điểm tạo đơn thật; `created_at` phải từ mốc kích hoạt trở đi.
- Pancake: chỉ bản ghi mới tại biên import. Đọc `inserted_at`/`created_at` trong payload gốc; nếu cả hai có thì lấy thời điểm sớm hơn. Thiếu/sai/ngày tương lai bị loại khỏi hàng đợi và log `warehouse.review_required`. Không dùng `updated_at`, thời điểm insert Local hoặc thời điểm sync thay cho ngày phát sinh nguồn.
- Timestamp upstream không kèm timezone giữ quy ước UTC đã có của normalizer; giá trị có offset được xử lý theo offset. Cột MySQL lưu theo timezone ứng dụng `Asia/Ho_Chi_Minh` để so sánh nhất quán với dữ liệu hiện tại.
- Lưu `source_created_at`, `first_synced_at`, `source_creator_name` khi nhập mới trong lúc bật module. Các bản ghi tồn tại không bao giờ tự được nhập hàng đợi, kể cả sync lại hoặc payload thay ngày tạo.
- Trạng thái được đóng: 0, 1, 8, 11, 12, 13, 17, 20 theo danh mục hiện có. Trạng thái giao/hoàn/hủy 2, 3, 4, 5, 6, 7, 9, 15, 16 và trạng thái chưa biết bị loại. Trạng thái chưa biết được ghi log.
- Khi tắt module, giữ bộ lọc vận đơn cũ. Khi bật, bổ sung đường nhận không vận đơn chỉ cho đơn mới đủ điều kiện. Đường bổ sung này không tạo CSKH/ZNS hoặc giả lập vận đơn. Lock shop rồi order giúp bulk và webhook không cùng tạo bản ghi; hoàn tất khóa order và retry deadlock tối đa 3 lần.
- Không có command backfill, không scan để đưa đơn cũ vào Kho. Cấu hình mốc kích hoạt cần giữ cố định khi vận hành; việc đổi mốc sẽ thay đổi phạm vi danh sách, không tạo lại hàng đợi cũ.

## Migration và trạng thái database chính

Migration mới: `database/migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php`.

Bổ sung nullable `source_created_at`, `first_synced_at`, `source_creator_name`, `packed_by_user_id`, `packed_at`, index shop/status/packed_at và hai mục danh mục quyền Kho. Không cập nhật đơn lịch sử, không gán role_permissions. `down()` chủ động từ chối xóa bằng chứng đóng hàng và quyền; khi cần tắt tính năng, bỏ cấu hình kích hoạt.

**CHƯA chạy migration này trên `pancake_order`; CHƯA kích hoạt module chính.** Chỉ kiểm tra migration trên SQLite memory và MySQL TEST riêng. Chưa thay `.env` chính.

Đối chiếu chỉ đọc trước/sau trên database chính:

| Chỉ số | Trước | Sau |
| --- | --- | --- |
| Số bản ghi orders | 90.577 | 90.577 |
| warehouse_queued_at khác null | 0 | 0 |
| Quyền warehouse-view / warehouse-pack | chưa có | chưa có |
| Mốc kích hoạt | null | null |
| PLAN 3 migration | chưa chạy | chưa chạy |
| POS 349305 | waiting_packing, queued null | giữ nguyên |
| COD / cash POS 349305 | 6.320.000 / 200.000 | giữ nguyên |
| updated_at POS 349305 | 2026-10-21 15:07:39 | giữ nguyên |

Sau khi được duyệt triển khai riêng: backup theo quy trình hiện có, kiểm tra schema, chạy migration bổ sung trên DB đích bằng PHP 8.4, cấp đúng hai quyền và shop cho role được duyệt, đặt mốc kích hoạt thực tế rồi refresh config/restart worker theo cách triển khai đang dùng. Không đặt mốc lùi để đưa lịch sử vào Kho. Báo cáo này không thực hiện các bước kích hoạt đó.

## Kết quả kiểm thử thực tế

| Phạm vi | Kết quả |
| --- | --- |
| WarehouseTest, 15 tests | PASS: 105 assertions |
| Backend liên quan: Kho + POS + sản phẩm + journey | PASS: 84 tests, 559 assertions |
| Hai request hoàn tất MySQL đồng thời | PASS: request đầu `replayed=false`, request sau `replayed=true`, cùng packed_by, đúng 1 audit |
| Frontend Kho mới | PASS: 7/7 |
| Production build | PASS |
| Frontend lint | PASS, vẫn 17 cảnh báo có sẵn |
| Git diff whitespace | PASS cả hai repo |
| Toàn bộ Backend | 454 PASS / 5 FAIL, 3.233 assertions; đúng 5 lỗi baseline trước PLAN 3 |
| Toàn bộ Frontend | 73 PASS / 7 FAIL; đúng 7 lỗi baseline trước PLAN 3 |
| Trình duyệt TEST | PASS: đăng nhập, hai nguồn, chi tiết, xác nhận, hoàn tất, packed_by/time/history, chuyển tab |
| Dữ liệu Local chính | PASS: đối chiếu chỉ đọc như trên |

15 test Kho bao phủ Local mới, hai luồng Pancake, thiếu vận đơn, đơn cũ đồng bộ muộn, thiếu/sai/ngày tạo tương lai, không dùng updated_at, trạng thái đóng/hủy/không biết, phân quyền shop và quyền thao tác kể cả admin, retry không trùng audit, rollback khi audit lỗi, hủy trước đóng/không requeue, giữ đóng khi sync lại, webhook chỉ trạng thái giữ dữ liệu, tìm kiếm/phân trang/timezone, tắt module/không backfill, tiền và tồn không bị thay đổi.

5 lỗi Backend có từ baseline: 4 test trong OpportunitySecurityHardeningTest (`valid_assign_preserves_response_assignment_state_dates_and_activity_log`, `staff_and_monitoring_workload_aggregates_count_only_actionable_cares`, `import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import`, `phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope`); 1 trong OrderSourceApiTest (`summary_contract_is_compact_while_default_keeps_the_legacy_payload`, contract test cũ chưa chứa hai field POS PLAN 2).

7 lỗi Frontend baseline thuộc customerCareActionParity, customerCareOrderSource, orderColumnsRender, orderFilters, orderPagesApi (2), orderSourcesApi. Chưa sửa các lỗi ngoài PLAN 3 này. Log so sánh nằm `/tmp/pancake-plan3-{backend,frontend}-baseline.log` và `/tmp/pancake-plan3-{backend,frontend}-final.log`; các log tạm không phải tài liệu bền vững.

## Môi trường kiểm tra trực quan

- Frontend TEST đang chạy: http://localhost:5174/kho.
- API TEST: http://127.0.0.1:8001/api/v1, database cố định `pancake_warehouse_plan3_test_20261021`, mốc thử `2026-10-21T14:48:37+07:00`.
- Tài khoản giả lập: `packer1@warehouse.test` hoặc `packer2@warehouse.test`, mật khẩu chung `WarehouseTest123!`. Chỉ tồn tại trong TEST; không phải tài khoản Local chính.
- Sau đăng nhập, vào Kho. Tab Chờ có POS-1; tab Đã đóng có POS-2 (test đồng thời) và fixture-pancake-new_1 (đã đóng qua trình duyệt). Đơn lịch sử TEST số 4 không xuất hiện.
- Mở Chi tiết để xem nguồn, người tạo, sản phẩm, địa chỉ, ghi chú, người đóng/thời gian/lịch sử. Muốn thử hoàn tất, dùng POS-1 còn chờ; kiểm tra hộp xác nhận rồi xác nhận trên TEST.
- Có thể cuộn ngang bảng khi nhiều cột; xem đầy đủ người đóng và thời gian trong chi tiết.
- Trang chính sau này: http://localhost:5173/kho; hiện chưa có quyền Kho trên tài khoản thật nên không tự hiển thị menu hoặc cho thao tác.

Tái sử dụng môi trường thử khi server dừng (không tạo lại DB đã có):

```sh
# Backend, thư mục pancake_order
/opt/homebrew/opt/php@8.4/bin/php -S 127.0.0.1:8001 -t public scripts/warehouse-test-router.php
# Frontend, thư mục pancake_frontend_v2
VITE_API_URL=http://127.0.0.1:8001/api/v1 npm run dev -- --port 5174
```

Router TEST khóa kết nối MySQL vào đúng DB giả lập, đọc metadata `/tmp/pancake-plan3-test-environment.json`; không thay cấu hình chính. Script `scripts/warehouse-test-environment.php --create` chỉ dành lần khởi tạo DB mới; cố ý từ chối khi bảng users đã tồn tại, không có thao tác reset/drop. Không chạy lại để xóa dữ liệu thử. Schema fixture dùng tập migration cần thiết cho chức năng, không phải bản sao toàn bộ dữ liệu Local. Không gọi Pancake/VTP/MISA thật; payload nguồn và khách trong fixture là giả lập.

## File tạo/sửa trong PLAN 3

Backend (tương đối từ `/Users/viet/Pancake/pancake_order`):

- **Tạo:** `app/Http/Controllers/Api/WarehouseController.php`, `app/Services/WarehouseService.php`, `config/warehouse.php`, `database/migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php`, `tests/Feature/WarehouseTest.php`, `scripts/warehouse-test-environment.php`, `scripts/warehouse-test-router.php`, `docs/plan-3-warehouse-implementation.md`.
- **Sửa:** `app/Jobs/GetOrderByShopJob.php`, `app/Jobs/GetOrderFromWebhookJob.php`, `app/Models/Order.php`, `app/Services/OrderService.php`, `routes/api.php`.

Frontend (tương đối từ `/Users/viet/Pancake/pancake_frontend_v2`):

- **Tạo:** `src/api/warehouse.api.js`, `src/pages/Warehouse/WarehousePage.jsx`, `src/pages/Warehouse/warehouse.css`, `tests/warehouse.test.js`.
- **Sửa:** `src/app/router.jsx`, `src/auth/permissions.js`, `src/components/OrderHistoryModal.jsx` (export ProductCell để tái sử dụng), `src/constants/menu.js`, `src/constants/routes.js`, `src/pages/Pos/PosOrderReceipt.jsx` (nhãn trạng thái đã đóng).

Danh sách trên đã đối chiếu với snapshot trước PLAN 3, không gộp các file thay đổi sẵn từ PLAN 1/2.

## Phần còn lại / giới hạn

Không có blocker triển khai code trong phạm vi PLAN 3. Còn bước duyệt migration/quyền/mốc kích hoạt chính thức theo yêu cầu; module chính vẫn tắt. Không xác minh với dịch vụ Pancake live vì không được yêu cầu gửi/sửa dữ liệu bên ngoài; đã kiểm tra payload qua chính hai job hiện có trong môi trường tách biệt. Nếu nguồn bổ sung trạng thái mới hoặc thay format thời gian, cần xác nhận dữ liệu nguồn rồi cập nhật danh mục; hiện loại khỏi chờ và log để không tự xử lý nhầm.

Hủy sau đóng hàng, hoàn tiền, hủy vận đơn, nhập/xuất tồn vật lý và mở lại hàng đợi cần nghiệp vụ được quản lý duyệt ở PLAN sau. Không triển khai chúng trong PLAN 3.

## Kiểm tra trước kích hoạt ngày 22/10/2026

Đã kiểm tra lại code/schema/cấu hình và gia cố migration để chạy lại an toàn sau DDL hoàn tất một phần; bổ sung test migration retry và mẫu POS 349305 giả lập. Kết luận kỹ thuật **READY**, vẫn chưa kích hoạt/chạy migration/cấp quyền trên Local chính. Kết quả hiện tại, rủi ro, backup/restore và thứ tự kích hoạt nằm trong [báo cáo readiness](/Users/viet/Pancake/pancake_order/docs/plan-3-activation-readiness.md) và [bằng chứng kiểm thử](/Users/viet/Pancake/pancake_order/docs/plan-3-readiness-evidence.json). Các số test ở phần triển khai phía trên là kết quả ngày 21/10; dùng báo cáo readiness cho số liệu mới nhất.
