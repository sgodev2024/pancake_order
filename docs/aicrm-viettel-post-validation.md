# Kết quả tích hợp AICRM Local — 04/11/2026

> Đây là kết quả giai đoạn Mock ban đầu. Audit/kết quả cho phần Live được ghi riêng
> trong [báo cáo Live](aicrm-viettel-post-live-validation.md); không dùng thành công
> Mock trong tài liệu này để kết luận đã kết nối server AICRM thật.

Đã thực hiện trên `viet_dev` trong `pancake_order`; frontend chính là `frontend/`.
Giữ nguyên các thay đổi chưa commit có từ đầu phiên; không commit hoặc push Git.

## Kết quả

| Kiểm tra | Trước thay đổi | Sau thay đổi |
| --- | --- | --- |
| Backend toàn bộ, SQLite bộ nhớ | 466/471 đạt, 5 thất bại | **501/506 đạt**, cùng 5 thất bại |
| Backend AICRM + Kho | — | **53/53 đạt**, 276 assertions |
| Laravel gọi Mock HTTP thật qua loopback | — | **1/1 đạt**, 8 assertions |
| Frontend toàn bộ | 90/91 đạt, 1 thất bại | **97/98 đạt**, cùng 1 thất bại |
| Frontend AICRM + Kho | — | **14/14 đạt** |
| Frontend lint | — | Thành công; 18 warning ở các file ngoài module AICRM |
| Frontend build | — | Thành công; Vite 8.2.2, 3.324 modules |
| PHP Pint, các file PHP mới | — | Đạt |
| `git diff --check` | — | Đạt |

Đã đối chiếu tên từng test lỗi giữa log baseline và log cuối: khớp hoàn toàn.
Không sửa các lỗi ngoài phạm vi module vận đơn để làm xanh bộ test.

Năm lỗi Backend có từ baseline:

1. `OpportunitySecurityHardeningTest::test_valid_assign_preserves_response_assignment_state_dates_and_activity_log`
2. `OpportunitySecurityHardeningTest::test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares`
3. `OpportunitySecurityHardeningTest::test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import`
4. `OpportunitySecurityHardeningTest::test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope`
5. `OrderSourceApiTest::test_summary_contract_is_compact_while_default_keeps_the_legacy_payload`

Lỗi Frontend có từ baseline:
`customerCareActionParity.test.js` — `completion modal has V1 fields, validation, exact payload and reset/refresh behavior`.

## Phạm vi kiểm chứng

- Đóng hàng → tạo Mock → đọc danh sách/chi tiết → lấy lại mã đã lưu khi bấm lại.
- Mapping đúng tám trường trong `CreateOrderRequest`, tổng nhiều sản phẩm,
  COD bằng 0, số tiền có phần thập phân, tổng POS và prepaid Pancake; bỏ qua tiền/shop
  do trình duyệt tự chèn. Không thay đổi tiền, kho hoặc trạng thái đơn.
- Quyền shop, người chỉ có quyền xem, chưa đăng nhập, đơn chưa đóng/đã hủy,
  mã vận đơn có sẵn, trạng thái không hợp lệ và dữ liệu thiếu đều bị chặn.
- Lịch sử lỗi còn nguyên khi retry một lỗi từ chối; timeout/5xx/response thiếu mã/
  envelope lỗi/redirect bị chuyển `unknown`, chặn retry.
- Giả lập request thứ hai ngay khi request đầu đang gửi để kiểm tra reservation
  đã lưu và chặn tạo trùng; unique index `order_id` bảo vệ ở DB.
- Một tiến trình chết để lại `processing` không được tự giải phóng theo thời gian.
- URL Sandbox phải được bật và cho phép rõ ràng; `mock_http` chỉ chấp nhận loopback;
  môi trường ứng dụng Production bị chặn. Không lộ token hoặc body lỗi thô.
- Auth header, tenant theo ID shop Local và endpoint HTTP chính xác được kiểm tra.
- Đồng bộ webhook/bulk Pancake sau khi tạo Mock không làm mất shipment hay tạo thêm.
- Frontend kiểm tra hiển thị/trạng thái nút, phân quyền, vô hiệu hóa khi pending/
  không đủ điều kiện, xác nhận rõ Mock/Sandbox, không tự gửi khi render hoặc chuẩn bị
  hộp xác nhận, không retry POST, hiển thị mã và lịch sử lỗi.

Mock HTTP được khởi động riêng ở `127.0.0.1:8011`; kiểm thử Laravel thực hiện
toàn bộ đường tạo vận đơn với database SQLite bộ nhớ và dữ liệu giả. Không dùng
đơn thật trong MySQL Local để tạo vận đơn trong quá trình kiểm thử.

## Trạng thái môi trường bàn giao

- Migration `2026_11_04_000001_create_aicrm_shipments` đã áp dụng trên MySQL Local:
  thêm hai bảng, không sửa/backfill đơn hoặc quyền.
- Kiểm tra cuối: bảng shipment và attempts trên MySQL Local đều **0 dòng**.
- `.env` Local: `AICRM_MODE=mock`, `AICRM_SHIPPING_PROVIDER_ID=1` là ID giả lập,
  `AICRM_SANDBOX_ENABLED=false`; URL và token ngoài chưa được cấu hình.
- Backend cổng 8002 `/up`: HTTP 200. Frontend cổng 5173 `/kho`: HTTP 200.
  POST tạo vận đơn không đăng nhập: HTTP 401. Route mới đã có Passport cùng cả
  `warehouse-view,strict` và `warehouse-pack,strict`.
- Không chạy queue worker/scheduler; không gọi AICRM/Viettel Post bên ngoài,
  không tạo vận đơn thật, không gọi Production.
- Kiểm thử giao diện dùng render thực React cùng các test hàm/API; chưa hoàn tất
  thao tác bấm trong trình duyệt với tài khoản đăng nhập. Công cụ điều khiển trình
  duyệt gặp lỗi khi mở tab Local, không thay đổi phiên đăng nhập hoặc quyền người dùng.

## Khi có API kiểm thử hoạt động

Theo [hướng dẫn cấu hình](aicrm-viettel-post-local.md), điền URL, auth, provider ID,
host Sandbox và tenant theo hợp đồng AICRM. Dùng đơn thử mới để kiểm tra kết nối.
Chưa thể xác nhận tương thích với server AICRM thật vì API đó chưa sẵn sàng.
Tài liệu v1.0 chưa mô tả auth/tenant header hoặc bảo đảm idempotency upstream;
không được dùng retry tự động khi chưa có xác nhận chính thức.

Log kiểm tra trên máy:

- `/private/tmp/pancake-aicrm-backend-baseline.log`
- `/private/tmp/pancake-aicrm-backend-final.log`
- `/private/tmp/pancake-aicrm-target-tests.log`
- `/private/tmp/pancake-aicrm-frontend-baseline.log`
- `/private/tmp/pancake-aicrm-frontend-final.log`
- `/private/tmp/pancake-aicrm-frontend-target.log`
- `/private/tmp/pancake-aicrm-lint.log`
- `/private/tmp/pancake-aicrm-build.log`
