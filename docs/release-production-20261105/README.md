# Pancake — audit chuẩn bị release Production, 05/11/2026

## Quyết định hiện tại: NO-GO triển khai; GO chuẩn bị và diễn tập cô lập

**Chưa đủ bằng chứng để triển khai Production. Không cho phép POST vận đơn thật.** POS/Kho có thể được phát hành riêng với vận chuyển khóa OFF sau khi hoàn tất các điều kiện về server, schema, backup và hồi quy dưới đây. Việc chưa xác minh tenant/Viettel Post không bắt buộc ngăn một release POS chỉ đọc AICRM, nhưng tuyệt đối ngăn kích hoạt tạo vận đơn.

Báo cáo này là kết quả audit Local và bộ lệnh **chuẩn bị để duyệt**, không phải bản ghi triển khai thành công. Không commit/push/pull/merge/deploy, không chạy migration Production, không chỉnh role/config Production và không gửi đơn sang Viettel Post trong lượt này.

### Bằng chứng đã có và phần còn thiếu

| Hạng mục | Trạng thái và kết luận |
| --- | --- |
| Source Local lúc bắt đầu | Sạch, branch `viet_dev`, HEAD `4fe593ea2bb44edcd0cf6d2bc102aed0a70c7c25`; không có thay đổi nghiệp vụ chưa commit |
| Sáu commit mới | `f68a15d`, `c1f78f0`, `f1812ff`, `65abdd6`, `6c2d465`, `4fe593e`; đã push từ lượt trước |
| Source thêm trong lượt audit này | Chỉ tài liệu, manifest và hai công cụ kiểm tra trong thư mục này; để untracked, chưa commit |
| Branch/HEAD/dirty files server | **Chờ kết quả người vận hành**; không suy đoán là `main` hoặc `viet_dev` |
| SSH | Người dùng xác nhận root/22, `123.31.31.112`, `/var/www/html/pancake_order`, đăng nhập bằng mật khẩu và sẽ cung cấp kết quả. Agent không thử lại SSH/key/password |
| Migration Production | **Chưa xác định**; không được dùng trạng thái Local làm trạng thái server |
| AICRM Production từ Backend Local, lượt trước | GET `https://api-vtp.aicrm.vn/api/v1/shipping-providers`, `X-API-Key`, HTTP 200; `VIETTEL_POST`, ID 1, `isEnable=true`. Không có POST |
| GET từ chính server Pancake | **Chưa thực hiện/chưa có bằng chứng**; đây là bước nghiệm thu sau chuẩn bị release |
| Tenant sở hữu key / shop Production | **Chưa xác nhận UUID hoặc mapping**; provider DTO không có tenantId |
| Môi trường Viettel Post upstream | **Chưa xác nhận**; host AICRM Production không chứng minh AICRM đang gọi Viettel Post Production |
| Backup/restore thật, cấu hình runtime server | **Chưa kiểm chứng** |

## 1. Audit chức năng và tác động quyền

| Phạm vi | Kết quả Local/source |
| --- | --- |
| POS và shop | Đăng nhập + grant đọc sản phẩm/khách hàng bắt buộc; tạo POS cần role Sale/Admin hiện có hoặc `pos-create`. `ShopAccessService` kiểm tra Backend; không tin shop từ UI |
| Khách hàng | Staff chỉ đọc/chọn khách được phân công trong shop; Admin/manager theo quy tắc hiện có của `CustomerReadAccessService`. Backend kiểm tra lại khách khi tạo đơn |
| Tự chọn shop | Chỉ tự chọn đúng một shop sau khi API xác minh thành công; 0 shop không gọi API dữ liệu/tạo đơn, nhiều shop chọn thủ công. Đổi tài khoản/scope/shop reset bản nháp và giỏ hàng |
| Thanh toán | Một select 3 phương thức; khoản tùy chọn trống = 0. Backend tính giá DB; cash/bank cần xác nhận đủ tiền và tổng khớp, paid=T/outstanding=0/COD=0; COD paid=0/outstanding=T/COD=T. API cũ thanh toán một phần và khóa chống trùng còn tương thích |
| Kho | Chỉ đưa đơn mới đủ điều kiện vào hàng đợi theo `WAREHOUSE_ACTIVATED_AT`; không backfill đơn cũ. Xem/đóng hàng cần grant Kho rõ ràng, cả Admin; migration POS không cấp quyền Kho |
| AICRM | Payload Backend, khóa processing/unknown, không retry POST tự động, không redirect, tách config live/sandbox/local. Tạo Live còn nhiều chốt: mode, enabled, contract, creation, reconciliation, approved order, mốc kích hoạt |

Migration `2026_11_04_000004_enable_staff_pos_permissions` thêm **các grant thiếu**, theo slug, cho tất cả nhân viên thật mang role tương ứng:

- `staff-sale`: `list-product`, `list-customer`; tạo POS theo role Sale hiện có.
- `staff-cskh`, `manager-cskh`: `pos-create`, `list-product`, `list-customer`.
- Admin/Kho: không thay grant. Admin vẫn cần hai grant đọc thực tế; không tự thêm `pos-create` cho Admin.

**Điều kiện bắt buộc khi triển khai grant:** code `ProductController::authorizeProductWrite()` phải có mặt tại cả store/update/destroy, chặn `staff-sale` và `staff-cskh` bằng 403; FE `canManageProducts` phải đồng bộ. Không chạy riêng migration quyền trong khi API cũ vẫn phục vụ. Trong bảo trì, ngừng toàn bộ đường ghi cũ; chạy migration bằng artifact mới đã kiểm tra, chỉ mở traffic sau khi tất cả tiến trình phục vụ code guard mới.

**Điểm phải đối chiếu riêng trên Production:** guard hiện chỉ chặn hai role staff. `manager-cskh` vẫn có thể quản lý catalog theo `list-product` như logic hiện tại. Local manager đã có quyền này từ trước. Nếu Production manager chưa có `list-product`, migration sẽ đồng thời mở API thêm/sửa/xóa catalog cho manager. Cần xác nhận chính sách hoặc thiết kế quyền ghi riêng trước khi chạy; audit này không tự đổi chính sách.

## 2. Kiểm thử và lỗi baseline

Đã chạy đầy đủ trên SQLite in-memory, PHP 8.4.26, không trên DB Production:

| Kiểm tra | Kết quả |
| --- | --- |
| Backend toàn bộ Unit/Feature | **617 tests, 4.593 assertions; 612 PASS, 5 FAIL, không errors** |
| Nhóm POS/sản phẩm/Kho/phân quyền/AICRM | 231 tests / 2.048 assertions PASS trong kiểm tra cùng HEAD trước lượt này; toàn bộ ca đó cũng nằm trong bộ đầy đủ vừa chạy |
| Frontend toàn bộ | **138 tests; 137 PASS, 1 FAIL** |
| Lint / build FE | PASS / PASS; 18 cảnh báo lint có sẵn |
| Cú pháp PHP công cụ audit | PASS; GET diagnostic chạy Local bị guard từ chối, **0 request**, đúng mục đích chỉ chạy thủ công trên server Production |

Cả 5 lỗi Backend đã tái hiện trên archive baseline `ae1436d` trước sáu commit mới. Đã kiểm tra autoload thật trỏ vào source baseline. Lỗi Frontend cũng tái hiện trên baseline. Đây là bằng chứng lỗi có sẵn, **không phải bằng chứng lỗi vô hại**:

1. `OpportunitySecurityHardeningTest::test_valid_assign_preserves_response_assignment_state_dates_and_activity_log`: danh sách CSKH thiếu `user_creator.id` mong đợi. Cần smoke thực tế người tạo/người được phân công, ảnh hưởng hiển thị/truy vết.
2. `...::test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares`: không tìm thấy nhân viên mong đợi trong dữ liệu monitoring. Cần kiểm tra định danh và workload.
3. `...::test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import`: JSON lỗi trả Unicode escape, test tìm chuỗi UTF-8 trong raw content. Đây là mismatch assertion/encoding ở điểm fail đã quan sát; test dừng sớm nên không suy ra mọi kiểm tra atomicity phía sau đã đạt.
4. `...::test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope`: expected ID Pancake nhưng actual ID null. Có liên quan phạm vi giám sát/định danh, chưa được coi là an toàn chỉ vì có sẵn.
5. `OrderSourceApiTest::test_summary_contract_is_compact_while_default_keeps_the_legacy_payload`: response thêm `order_origin`, `pos_total_amount`; assertion danh sách key cũ thất bại. Cần xác nhận consumer chấp nhận trường bổ sung và cập nhật contract test nếu đúng thiết kế.
6. Frontend `customerCareActionParity`: test tìm hai literal title đã không còn trong source (`indexOf=-1`), cắt ra chuỗi rỗng. Các field thực tế còn tồn tại, nhưng cần render/interaction test modal CSKH trước khi sửa assertion; chưa khẳng định toàn bộ UX đúng.

Đề nghị sửa hoặc có đánh giá rủi ro được ghi nhận rõ cho từng lỗi trước release tổng thể; không sửa test/source ngoài phạm vi audit này để làm xanh kết quả.

## 3. Lỗi `customers.last_order_at` và migration

### Trạng thái Local — đã đọc DB trong transaction READ ONLY

MySQL **8.4.11**, DB `pancake_order`, 74 file migration trong candidate. Có đúng 3 migration pending trên **Local**:

1. `2026_10_07_000001_add_last_order_at_to_customers_table`
2. `2026_10_07_000002_add_prepaid_amount_to_orders_table`
3. `2026_10_08_000001_create_zalo_chat_bridge_tables`

Local thiếu cả `customers.last_order_at` và `orders.prepaid_amount`. `CustomerController::orderInsights` truy vấn cột last_order_at trực tiếp, không fallback: thiếu cột sẽ gây SQL lỗi/HTTP 500 cho `/api/v1/customers/order-insights`. POS dùng endpoint khách `view=pos` riêng nên không được dùng việc POS chạy được để kết luận module insight an toàn. Các job sync có guard hasColumn nên vẫn có thể chạy trong khi insight lỗi.

Migration last_order_at gồm ADD COLUMN + INDEX + bulk UPDATE JSON. MySQL/MariaDB DDL có thể hoàn tất trước khi backfill thất bại; migration này **không có guard chạy lại**, sẽ trùng cột/index nếu chạy lại sau DDL một phần. Trên bản restore phải kiểm tra JSON, null/chuỗi rỗng, timestamp lỗi, số dòng và thời gian/lock của backfill. **Còn rủi ro timezone:** SQL lấy 19 ký tự đầu rồi STR_TO_DATE, bỏ offset/Z; job sync lại parse UTC rồi đổi sang app timezone. Ví dụ `02:00:00Z` có thể được backfill thành 02:00 trong khi sync ghi 09:00 (+07). Cần chốt quy ước và kế hoạch backfill tương thích trước GO cho module insight; chưa tự sửa migration.

Migration prepaid cũng có DDL và bulk UPDATE từ JSON; phải kiểm tra độ chính xác cast/overflow/null, lock trên orders lớn, drift cột và tiền cũ trước/sau. Zalo migration tạo 3 bảng không có hasTable; nếu bảng tồn tại nhưng lịch sử migration thiếu thì dừng, không chạy lại hoặc tự đánh dấu applied.

### Danh sách đầy đủ pending trên server

**Chưa thể liệt kê chính xác trước khi có lịch sử migration server.** `migrate:status` trên checkout cũ chỉ biết file của checkout đó, có thể không hiển thị migration mới của candidate. `release-manifest.json` liệt kê đủ **74 migration và checksum**; `preflight-readonly.php` so sánh toàn bộ danh sách candidate với bảng `migrations`, xuất `pending_for_candidate` và `applied_not_in_candidate` mà không migrate. Không dùng ba migration Local ở trên làm danh sách Production.

Các nhóm cần rà theo thứ tự lịch sử sau khi prerequisite cũ đã đủ; chỉ chạy file thực sự pending, có schema đúng và đã duyệt:

| Thứ tự | Migration/nhóm | Điều kiện/rủi ro |
| --- | --- | --- |
| 0 | Tất cả migration cũ còn thiếu trong manifest 74 file | Duyệt từng file trên server/restore; không bỏ qua chỉ vì ngoài POS |
| 1 | `2026_10_07_000001_add_last_order_at_to_customers_table` | order_count có sẵn; giải quyết timestamp/backfill/drift ở trên |
| 2 | `2026_10_07_000002_add_prepaid_amount_to_orders_table` | Đối chiếu JSON tiền/kiểu decimal và dữ liệu cũ |
| 3 | `2026_10_08_000001_create_zalo_chat_bridge_tables` | 3 bảng Zalo chưa tồn tại hoặc đã có migration tương ứng |
| 4 | `2026_10_21_000001_add_local_pos_support` | Thay nullable ID/decimal, thêm cột/index POS và virtual_stock; DDL lớn/không chạy lại mù |
| 5 | `2026_10_21_000002_add_warehouse_completion_and_source_time` | Sau PLAN 2; bổ sung metadata/index và catalog quyền Kho, không gán role |
| 6 | `2026_10_22_000001_add_explicit_pos_permission` | Có list-product catalog; chỉ tạo pos-create, chưa grant |
| 7 | `2026_11_04_000001_create_aicrm_shipments` | orders/shops đủ prerequisite; tạo shipment/attempt và FK |
| 8 | `2026_11_04_000002_add_aicrm_remote_order_metadata` | Sau bảng shipment/attempt |
| 9 | `2026_11_04_000003_add_aicrm_tenant_binding` | Sau bảng shipment; cột tenant_id chưa tồn tại; không chạy lại mù |
| 10 | `2026_11_04_000004_enable_staff_pos_permissions` | Slug role/permission duy nhất và active, guard catalog mới bắt buộc; sao lưu grant trước |

Nếu file đánh dấu applied nhưng schema lệch, hoặc schema có trước khi migration applied: **dừng và lập kế hoạch repair riêng**. Không sửa bảng migrations bằng tay, không chạy `migrate:fresh`, seeders TEST hoặc `migrate:rollback` để thử vận may. `--pretend` không thay thế diễn tập thực: migration quyền có query và điều kiện dữ liệu, dry-run có thể không mô phỏng đúng.

## 4. Branch server và bảo toàn thay đổi khác

Tài liệu lịch sử nói frontend Production có metadata Git riêng trong `frontend/.git`, Nginx FE 8081 và API 8080. Đây là thông tin **08/10/2026**, cần người vận hành xác nhận topology hiện tại, PHP phục vụ thực tế (khác CLI có thể có), supervisor/systemd, scheduler và upstream webhook.

Remote refs **đã lưu ở Local, chưa fetch trong lượt này**: `origin/main=c2c38e4` và `viet_dev=4fe593e` khác nhau **main-only 4 / viet_dev-only 6**. Bốn commit chưa có trong viet_dev:

- `46fdcfe`: báo cáo tổng quát/tối ưu JSON.
- `27ffd27`: thêm gọi API danh sách đơn.
- `7b92aa4`: cache/summary báo cáo.
- `c2c38e4`: chi tiết hai bảng báo cáo.

Không khẳng định server chứa các commit này. Nhưng nếu server ở nhánh đó, deploy thẳng snapshot viet_dev có thể làm mất chức năng mới. **Không git pull/reset/clean hoặc chép đè frontend trong checkout đang chạy.** Tạo checkout tích hợp và release artifact mới, đối chiếu server HEAD, tracked patch, untracked source và frontend Git riêng. Merge/cherry-pick giải quyết conflict trên staging sau duyệt; không dùng ours/theirs cho toàn bộ cây. Chốt một SHA release mới sau tích hợp, chạy lại mọi kiểm tra trên đúng SHA đó.

## 5. AICRM Production / Viettel Post

Bằng chứng GET từ Backend Local xác nhận key được host chấp nhận và provider enabled ID **1** trong scope key. Chưa chứng minh:

- UUID tenant sở hữu key; mapping shop Production → tenant → đúng key/provider. Không tự dùng UUID Local hoặc tenant TEST.
- Token Viettel Post còn hiệu lực, tài khoản hãng có quyền tạo đơn, cấu hình người gửi đúng.
- Runtime `VIETTELPOST_API_URL` trên AICRM Production. Source AICRM Local commit `7dfb3be` có mặc định **`https://partnerdev.viettelpost.vn`** nếu không override; không được suy ra môi trường đang chạy từ source. Cần AICRM operator xác nhận giá trị effective là môi trường Production mong muốn (thông thường partner.viettelpost.vn theo source comment), không gửi token hãng ra báo cáo.

Provider DTO có token và địa chỉ người gửi, nhưng **không có tenantId/base URL môi trường hãng**. Không gọi thêm orders/detail chỉ để đoán tenant. Cần xác nhận từ quản trị AICRM hoặc metadata quản trị được phép; không bật POST để kiểm tra môi trường.

Local hiện: MODE=mock, LIVE_ENABLED=false, CREATION=false, CONTRACT=false, RECONCILIATION=false, không approved order; có Live key nhưng thiếu live URL/allowlist/provider/tenant mapping. WAREHOUSE_ACTIVATED_AT là mốc TEST Local; **không chép mốc này sang Production**. Trạng thái effective trên server vẫn đang chờ.

Trong release chỉ đọc đề xuất, cấu hình Backend Production sau duyệt phải giữ các khóa dưới đây; không tự áp dụng trong audit:

```dotenv
APP_ENV=production
APP_DEBUG=false
AICRM_MODE=live
AICRM_LIVE_API_URL=https://api-vtp.aicrm.vn
AICRM_LIVE_HOSTS=api-vtp.aicrm.vn
AICRM_LIVE_PROVIDERS_PATH=/api/v1/shipping-providers
AICRM_LIVE_ENABLED=false
AICRM_LIVE_CREATION_ENABLED=false
AICRM_LIVE_CONTRACT_CONFIRMED=false
AICRM_LIVE_RECONCILIATION_CONFIRMED=false
AICRM_LIVE_APPROVED_ORDER_ID=
AICRM_LIVE_ACTIVATED_AT=
AICRM_SANDBOX_ENABLED=false
AICRM_LOCAL_ENABLED=false
```

Key chỉ đặt riêng trong Backend; giữ APP_KEY/DB/OAuth keys/.env server. Không `key:generate`, `passport:install`, đưa key vào VITE_* hoặc copy .env Local. Tenant/provider khai báo sau khi xác minh, không điền UUID/ID shop TEST. Provider ID 1 đã đọc không tự được ghi vào config bởi báo cáo này.

## 6. Bộ lệnh triển khai theo thứ tự — CHƯA CHẠY

**Không chạy nguyên tài liệu như một script.** Các bước có điểm dừng; biến chưa xác nhận phải làm lệnh dừng. Những lệnh thay đổi chỉ dành cho lượt triển khai được duyệt sau audit, không phải quyền thực hiện hiện tại.

### A. Người vận hành thu thập chỉ đọc trên server

```bash
cd /var/www/html/pancake_order
export GIT_OPTIONAL_LOCKS=0
git branch --show-current
git rev-parse HEAD
git status --short
git log -8 --oneline
git log -1 --format='%H %s' origin/viet_dev
# Nếu frontend/.git tồn tại, thu riêng cả ba kết quả:
git -C frontend branch --show-current
git -C frontend rev-parse HEAD
git -C frontend status --short
php -v
php artisan migrate:status --no-ansi
```

Khi bộ audit này được cung cấp riêng cho quản trị, có thể chạy collector trên server, trỏ `--root` về checkout hiện tại (không cần checkout/pull code):

```bash
php /DUONG_DAN_BO_AUDIT/preflight-readonly.php \
  --root=/var/www/html/pancake_order \
  --manifest=/DUONG_DAN_BO_AUDIT/release-manifest.json
```

Collector không HTTP, không migrate, không ghi DB; dùng transaction READ ONLY cho metadata. Chỉ xuất cấu hình whitelist, tenant UUID đã cấu hình, trạng thái key dạng boolean, không in key/DB password/raw .env. Không gửi bản .env, `config:show`, database dump hoặc credential lên chat.

### B. Chốt source, rehearsal và artifact ở máy build/staging

1. Nhận HEAD/patch/source server và frontend; đối chiếu vào checkout tích hợp mới. Khi chưa rõ server branch và uncommitted source, dừng ở đây.
2. Giữ bốn commit main-only nếu server đã dùng; giải quyết conflict, review guard quyền/payment/schema. Chốt `RELEASE_COMMIT` đã duyệt, không mặc định bằng viet_dev nếu còn khác server.
3. Dùng DB restore Production trên **instance cô lập**, cùng phiên bản MySQL/MariaDB và timezone; tắt scheduler/workers/outbound. Diễn tập toàn bộ pending migration, đo lock/backfill, kiểm tra dữ liệu/permission trước/sau và phương án hồi phục. Không gọi upstream thật.
4. Sau chốt thay đổi nguồn, chạy lại tests/lint/build của đúng artifact; baseline failure phải được xử lý hoặc chấp nhận rủi ro có bằng chứng.

```bash
# Chỉ trên checkout build/staging, không dùng .env/DB Production cho tests.
DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M vendor/bin/phpunit
cd frontend
node --test --test-force-exit tests/*.test.js
npm run lint
# URL API công khai đã kiểm tra qua Nginx; không dùng 127.0.0.1/localhost của máy build.
: "${PROD_PUBLIC_API_URL:?Can URL API Production da xac minh}"
VITE_API_URL="$PROD_PUBLIC_API_URL" npm run build
```

Tests cần dev dependencies ở build runner. PHP project yêu cầu >=8.2, đã kiểm thử 8.4; Vite 8 yêu cầu Node ^20.19 hoặc >=22.12. Artifact production cài bằng `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts`, rồi `php artisan package:discover --no-ansi` trong release mới với config server đã kiểm tra. Frontend dùng `npm ci` theo lockfile tại build runner trước build. Không chạy tests trên server trỏ DB Production.

### C. Bảo trì có bảo toàn webhook và backup thật

Trước bảo trì phải xác minh upstream webhook có cơ chế retry hoặc buffer bền vững; không trả 2xx cho sự kiện chưa lưu. Nếu không bảo toàn được sự kiện thì **NO-GO**. Dừng đúng scheduler/producers/worker Pancake một cách graceful sau khi job hiện tại xong; không purge jobs/failed_jobs, không đụng ứng dụng khác.

Các biến do quản trị điền từ topology thực tế; dùng PHP CLI tương thích với PHP phục vụ:

```bash
set -euo pipefail
umask 077
APP=/var/www/html/pancake_order
: "${PHP_BIN:?Can PHP dung phien ban}"
: "${BACKUP_PARENT:?Can thu muc backup rieng co du dung luong}"
: "${MYSQL_CNF:?Can file quyen 0600 co credential backup da cap, khong ghi password vao lenh}"
: "${DB_NAME:?Can ten DB Production da xac minh}"
BACKUP_DIR="$BACKUP_PARENT/pancake-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$BACKUP_DIR"
cd "$APP"
"$PHP_BIN" artisan down --retry=60
# Quản trị dừng đúng worker/scheduler/producers đã xác định, không wildcard mọi service.
# Kiểm tra transaction dài/metadata lock và dung lượng trước dump.
git rev-parse HEAD > "$BACKUP_DIR/backend-head.txt"
git status --porcelain=v1 > "$BACKUP_DIR/backend-status.txt"
git diff --binary HEAD > "$BACKUP_DIR/backend-working.patch"
git bundle create "$BACKUP_DIR/backend.bundle" --all
if test -e frontend/.git; then
  git -C frontend bundle create "$BACKUP_DIR/frontend.bundle" --all
  git -C frontend diff --binary HEAD > "$BACKUP_DIR/frontend-working.patch"
fi
# Bao gồm Git metadata riêng, .env, storage/OAuth keys/uploads và dist để rollback.
# Giữ backup riêng tư; file này chứa bí mật, không commit/gửi chat.
tar --exclude='./vendor' --exclude='./frontend/node_modules' \
  -czpf "$BACKUP_DIR/application-private.tgz" -C "$APP" .
```

Lệnh single-transaction dưới đây yêu cầu mọi bảng cần backup đều InnoDB và không có DDL đồng thời; collector xuất engine theo metadata. Nếu còn bảng không transactional, dừng để DBA chuẩn bị backup nhất quán riêng. Chọn client dump **khớp server** sau khi biết MySQL hay MariaDB. Ví dụ dưới đây dùng cú pháp chung; MySQL 8 có thể cần thêm `--no-tablespaces --set-gtid-purged=OFF` theo quyền/cấu hình thực tế; không truyền option MySQL không hỗ trợ vào mariadb-dump.

```bash
: "${DUMP_BIN:?mysqldump hoac mariadb-dump phu hop server}"
"$DUMP_BIN" --defaults-extra-file="$MYSQL_CNF" \
  --single-transaction --quick --routines --events --triggers --hex-blob \
  --default-character-set=utf8mb4 "$DB_NAME" > "$BACKUP_DIR/database.sql"
"$DUMP_BIN" --defaults-extra-file="$MYSQL_CNF" \
  --single-transaction --quick --no-create-info "$DB_NAME" \
  roles permissions role_permissions shop_users > "$BACKUP_DIR/access-before.sql"
sha256sum "$BACKUP_DIR/database.sql" "$BACKUP_DIR/application-private.tgz" \
  > "$BACKUP_DIR/SHA256SUMS"
```

Không coi checksum/dump exit 0 là đã chứng minh restore. Khôi phục bằng client `mysql`/`mariadb` trên instance riêng không outbound, giữ cùng tên schema khi routines/views tham chiếu schema; ghi nhận version, schema, counts, tiền, tồn, grant, đơn và audit trước/sau. Không restore lên DB đang phục vụ. Nếu dùng PITR, cần DBA ghi/giữ binlog coordinates và diễn tập; không tự thêm `--source-data`/global lock trên server chưa khảo sát.

### D. Đưa artifact vào thư mục release mới; migrate trong cửa sổ bảo trì

Không checkout/reset/pull checkout legacy. Chuẩn bị release mới cùng filesystem và symlink current, đồng thời chuẩn bị Nginx/API/worker/scheduler dùng cùng current. Topology chuyển từ đường dẫn thật legacy sang symlink cần cấu hình được review riêng; không `mv` thư mục đang chạy bằng lệnh suy đoán.

```bash
: "${RELEASE_DIR:?Can thu muc artifact da duyet va deploy rieng}"
: "${RELEASE_COMMIT:?Can SHA release sau doi chieu server}"
: "${SHARED_ENV:?Can .env Production rieng da kiem tra, khong phai .env Local}"
: "${SHARED_STORAGE:?Can storage Production giu nguyen uploads va Passport keys}"
: "${APP_OWNER:?Can owner dung cua ung dung}"
: "${APP_GROUP:?Can group dung cua ung dung}"
# Chỉ trên thư mục release mới, không ghi đè vendor/source cũ.
test "$(git -C "$RELEASE_DIR" rev-parse HEAD)" = "$RELEASE_COMMIT"
ln -s "$SHARED_ENV" "$RELEASE_DIR/.env"
# Artifact phải được chuẩn bị storage symlink từ trước, không xóa storage hiện hữu để tạo.
test -L "$RELEASE_DIR/storage"
test "$(readlink -f "$RELEASE_DIR/storage")" = "$(readlink -f "$SHARED_STORAGE")"
cd "$RELEASE_DIR"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
"$PHP_BIN" artisan package:discover --no-ansi
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
chown -R "$APP_OWNER:$APP_GROUP" bootstrap/cache
# Cả web/CLI/worker cùng config, APP_ENV=production, CREATION=false; không bật Kho ngầm.
"$PHP_BIN" /DUONG_DAN_BO_AUDIT/preflight-readonly.php \
  --root="$RELEASE_DIR" --manifest=/DUONG_DAN_BO_AUDIT/release-manifest.json
```

**Dừng** nếu runtime khác Production, creation không false, guard catalog thiếu, bất kỳ schema drift, role/permission thiếu hoặc manager-cskh bị mở thêm quyền ghi chưa duyệt. `critical_files_match_candidate` so với manifest `4fe593e` chỉ dùng cho candidate đó; release đã tích hợp khác phải tạo lại manifest sau review, không coi mọi khác biệt là lỗi hoặc bỏ qua âm thầm.

Tạo file `approved-pending.txt` **từ server pending + review + rehearsal**, mỗi dòng một tên migration, xếp thứ tự tăng dần. Không dùng danh sách Local thay thế. Chạy tuần tự, dừng ngay khi có lỗi:

```bash
: "${APPROVED_PENDING_FILE:?Can danh sach pending Production da duyet}"
LC_ALL=C sort -c "$APPROVED_PENDING_FILE"
while IFS= read -r migration; do
  test -n "$migration"
  case "$migration" in *[!A-Za-z0-9_]* ) exit 1 ;; esac
  test -f "database/migrations/$migration.php"
  "$PHP_BIN" artisan migrate --force --path="database/migrations/$migration.php" --no-ansi
done < "$APPROVED_PENDING_FILE"
"$PHP_BIN" artisan migrate:status --no-ansi
```

Không mở traffic giữa các migration; guard mới phải là code duy nhất phục vụ khi grant có hiệu lực. Kiểm tra columns/index/type/FK và grant diff sau từng nhóm, tiền/đơn cũ không đổi. Chỉ khi toàn bộ phù hợp mới chuyển current; nếu bất kỳ DDL/DML lỗi, giữ bảo trì, lưu trạng thái dở dang và sửa theo kế hoạch riêng, không rerun mù.

### E. Chuyển release, reload và GET AICRM từ chính server

```bash
: "${CURRENT_LINK:?Can symlink current da duoc topology Nginx/API/worker dung}"
test -L "$CURRENT_LINK"
PREVIOUS_RELEASE="$(readlink -f "$CURRENT_LINK")"
# Cùng filesystem; link mới phải chưa tồn tại.
ln -s "$RELEASE_DIR" "${CURRENT_LINK}.next"
mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"
# Reload/restart đúng service API/PHP/worker đã xác định, không toàn hệ thống.
# Ví dụ cho API thực sự chạy bằng systemd (không áp dụng nếu server dùng supervisor):
: "${PANCAKE_API_UNIT:?Can systemd unit Pancake API da xac minh}"
systemctl restart "$PANCAKE_API_UNIT"
```

Không dùng lệnh restart systemd mẫu nếu topology khác. Worker đang tạm dừng chỉ khởi động lại sau schema/config đúng; phải bảo đảm daemon cũ thoát và code/config mới được nạp. Giữ scheduler/upstream producers dừng đến khi smoke đạt. Route/config cache của mỗi release riêng; không chia sẻ bootstrap/cache giữa release. View cache trong shared storage cần quyền đúng, không `optimize:clear` toàn cache một cách mù quáng.

Khi vẫn bảo trì và **POST creation=false**, chạy đúng một GET từ server. File dưới đây chỉ dùng Backend config effective, không in key/raw provider body; không retry/redirect/POST và không bật LIVE_ENABLED:

```bash
"$PHP_BIN" /DUONG_DAN_BO_AUDIT/aicrm-providers-get.php --root="$CURRENT_LINK"
# Nếu đã cấu hình map nhiều tenant, dùng thêm --shop-id=<shop Production đã xác minh>
# thay cho lệnh trên, không chạy cả hai để thử credential hàng loạt.
```

Tiêu chí: HTTP 200, đúng một `VIETTEL_POST` enabled, ID 1 cho đúng scope key, `creation_enabled=false`, `env_unchanged=true`. Kết quả không chứng minh tenant hoặc Viettel Post upstream environment; giữ các việc đó ở trạng thái chưa xác minh nếu chưa có xác nhận AICRM operator. Không gửi raw response vì có thể chứa token hãng/địa chỉ người gửi.

### F. Smoke read-only và mở lại sau nghiệm thu

- Kiểm tra `/up`, FE assets/index và refresh deep link `/ban-hang`, `/kho`; API công khai đúng HTTPS/CORS, không trỏ Local. Giữ assets hash cũ trong giai đoạn chuyển để tab cũ không lỗi chunk.
- Sale/CSKH/Admin bằng tài khoản Production được phép: đăng nhập, profile/grant, số shop và selector, sản phẩm/khách trong scope; khách ngoài phân công bị chặn. Không dùng tài khoản TEST Local 641/642 hay Shop 38 trên Production.
- Kiểm tra GET shop ngoài phạm vi trả 403; không dùng Admin toàn cục để chứng minh ràng buộc shop của staff. Kiểm tra guard write sản phẩm bằng tests trên staging, không thêm/sửa/xóa catalog thật để thử.
- Preview cash/bank, popup xác nhận và hủy; ba khoản trống/âm; không xác nhận đã nhận tiền, không tạo đơn/thu tiền trên Production trong bước này. Thành công ghi đơn và queue Kho đã kiểm tra ở staging/DB restore; nếu cần canary ghi Production thì phải có kế hoạch riêng được duyệt.
- GET danh sách/chi tiết Kho với actor có grant, tách staff không grant; xác minh activation/mốc hiện có, không đổi mốc hoặc backfill. Kiểm tra order-insights không 500, báo cáo cũ/mới, CSKH creator/assignment/monitoring, Zalo read-only không làm thay đổi trạng thái upstream.
- Không gọi bất kỳ POST `/warehouse/orders/*/viettel-post`, kể cả làm smoke; không gọi tạo đơn trực tiếp sang AICRM/Viettel Post.
- Sau đạt tiêu chí, `php artisan up` ở current release và khôi phục đúng worker/scheduler/producers, phát lại webhook đã đệm theo thứ tự/đối soát. Giám sát HTTP 5xx, queue lag, failed jobs, giao dịch tiền và quyền; giữ POST Live OFF.

### G. Rollback

1. Nếu lỗi: giữ/đưa về bảo trì, giữ webhook bền vững và dừng ghi/worker liên quan. Giữ tất cả chốt POST OFF; không gửi lại unknown/processing shipment.
2. Ưu tiên roll-forward hoặc quay về artifact đã được chứng minh tương thích với schema/đơn POS mới. **Không rollback DB tự động**; PLAN 2/Kho/catalog quyền/grant có down() cố ý từ chối; AICRM down() có thể xóa bằng chứng vận chuyển.
3. Nếu quay về code cũ thiếu guard catalog mà grant POS mới còn tồn tại, Sale/CSKH có thể sửa sản phẩm: **không mở lại traffic**. Giữ code guard hoặc thu hồi đúng grant mới thêm từ diff trước/sau đã duyệt. Không restore đè toàn bảng role_permissions và không thu hồi các quyền có sẵn từ trước.
4. Chỉ khi điều kiện trên đạt, đổi current về PREVIOUS_RELEASE, reload đúng API/worker với config đã phục hồi và smoke read-only; giữ DB mới/audit. Lưu release lỗi để điều tra.

```bash
# Chỉ sau khi đã review tương thích schema và guard/grant ở trên.
: "${PREVIOUS_RELEASE:?Can release truoc da xac minh}"
ln -s "$PREVIOUS_RELEASE" "${CURRENT_LINK}.rollback"
mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"
systemctl restart "$PANCAKE_API_UNIT"  # Chỉ khi đúng topology systemd đã xác nhận.
```

5. Nếu cần phục hồi dữ liệu: giữ DB lỗi và mọi đơn/tiền/tồn/audit phát sinh sau backup; restore trên instance mới, đối chiếu/PITR có DBA, chuyển kết nối sau duyệt. Không import database.sql trực tiếp lên Production đang có giao dịch mới. Nếu tắt Kho bằng mốc trống để ngừng tính năng, giữ mốc gốc và đối soát khoảng gián đoạn; không tự đổi mốc hoặc đưa đơn cũ vào Kho.

## 7. Điều kiện chuyển kết luận

**GO release POS/Kho + GET-only AICRM** khi: server branch/dirty source đã bảo toàn trong release SHA cụ thể; đầy đủ pending migration/schema đã duyệt và diễn tập đúng DB engine; backup restore thật đạt; baseline lỗi đã có xử lý/đánh giá; guard catalog cùng grant và config effective trên mọi process đúng; smoke read-only và GET AICRM từ chính server đạt; transport POST vẫn OFF.

**NO-GO tạo vận đơn thật** cho đến khi UUID tenant/mapping từng shop và provider được AICRM xác nhận, runtime Viettel Post đúng môi trường/tài khoản/token/sender, quy trình đối soát timeout được chứng minh, và có phê duyệt kích hoạt riêng. Báo cáo không đề xuất mở POST trong release này.

Kết quả server còn chờ: branch/HEAD/status cả Backend và frontend nếu Git riêng; danh sách migration applied/pending và engine/version; effective config không chứa secrets; topology phục vụ/worker/scheduler/webhook. Sẽ đối chiếu lại phần 3–6 từ thông tin này, không suy đoán thay kết quả thực tế.
