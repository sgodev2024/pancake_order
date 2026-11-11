# Pancake Local — điều kiện Live của POS-349322

Kiểm tra ngày 05/11/2026, giờ Việt Nam. Đọc DB Local lúc **10:53:12**, kiểm tra bổ sung và đối chiếu lại lúc **10:55:32**; GET provider lúc **10:54:20**; GET metadata key lúc **10:58:52**. Kết luận: **NOT READY để gửi vận đơn thật**.

## Kết luận chính

POS-349322 đã hoàn tất đúng luồng Kho nhưng `can_create_shipment=false`. Có **năm điều kiện trực tiếp của nút đang FAIL**: kết nối Live tắt, creation tắt, chưa xác nhận contract, chưa xác nhận đối soát và chưa phê duyệt ID đơn. Ngoài ra, mapping tenant còn trống và upstream Viettel Post Production chưa được xác minh.

**Không phê duyệt hoặc gửi đơn 349322:** đơn dùng số điện thoại mẫu `0000000641` và địa chỉ chứa “KHÔNG GIAO HÀNG”. Đây là điều kiện vận hành bắt buộc phải loại bỏ, dù validator kỹ thuật hiện tại vẫn chấp nhận số mẫu đó theo regex. Dữ liệu mẫu không phải nguyên nhân trực tiếp khiến thuộc tính `disabled` của nút bật.

## Bằng chứng của đơn

| Thuộc tính | Kết quả đọc Local |
| --- | --- |
| ID / origin / shop | 349322 / local_pos / 38 |
| Người tạo | User 641 |
| Tạo đơn / vào hàng đợi | 05/11/2026 10:38:16 +07:00 |
| Trạng thái Kho | packed |
| Hoàn tất đóng hàng | **05/11/2026 10:48:12 +07:00**, User **637** |
| Audit | Log **70**, action `warehouse.packed`, chuyển `waiting_packing` → `packed`, cùng thời điểm và actor |
| Trạng thái đơn gốc | 0; được phép đóng hàng/tạo shipment theo danh sách trạng thái hiện hành |
| Tổng / đã thu / COD | **238.890 đ / 0 đ / 238.890 đ** |
| Mã VTP legacy và partner | Không có |
| Shipment / attempt của đơn | **0 / 0** |
| `can_create_shipment` | **false**, lấy từ controller thực tế qua luồng chỉ đọc |

Xác nhận **chưa có vận đơn được ghi nhận và chưa có lần gửi nào từ Pancake Local**. Không dùng API đơn Production để suy ra tenant hoặc để khẳng định về thao tác ngoài hệ thống Local.

## Từng điều kiện và trạng thái

`FAIL – chưa xác minh` là thiếu bằng chứng để cho phép gửi thật; không đồng nghĩa đã chứng minh cấu hình phía AICRM sai.

| Điều kiện | Trạng thái | Bằng chứng |
| --- | --- | --- |
| Actor có `warehouse-view` | PASS | Người đóng hàng 637 có quyền |
| Actor có `warehouse-pack` | PASS | User 637 có quyền |
| Actor được phép truy cập shop 38 | PASS | ShopAccessService xác nhận |
| Module Kho đã kích hoạt | PASS | `enabled=true`, mốc 22/10/2026 13:54:15 +07:00 |
| Đơn thuộc hàng đợi Kho đang hoạt động | PASS | `inActiveQueue=true` |
| `warehouse_status=packed` | PASS | Đọc DB + controller |
| Có `packed_at` | PASS | 05/11/2026 10:48:12 +07:00 |
| Trạng thái gốc cho phép tạo vận đơn | PASS | status=0 |
| Không có mã vận đơn legacy/partner | PASS | Cả hai trống |
| Không có shipment cũ hoặc trạng thái ngăn gửi lại | PASS | Không có shipment/attempt; không có processing/unknown/succeeded |
| Chế độ kết nối đúng Live | PASS | `AICRM_MODE=live`; Sandbox và Local integration đều tắt |
| `AICRM_LIVE_ENABLED=true` | **FAIL** | **false** — chốt trực tiếp của nút |
| `AICRM_LIVE_CREATION_ENABLED=true` | **FAIL** | **false** — chốt trực tiếp của nút |
| `AICRM_LIVE_CONTRACT_CONFIRMED=true` | **FAIL** | **false** — chốt trực tiếp của nút |
| `AICRM_LIVE_RECONCILIATION_CONFIRMED=true` | **FAIL** | **false** — chốt trực tiếp của nút |
| `AICRM_LIVE_APPROVED_ORDER_ID` đúng ID đơn | **FAIL** | Đang rỗng — chốt trực tiếp của nút; không điền 349322 |
| Đơn tạo sau mốc Live hợp lệ | PASS | 10:38:16 ≥ mốc 05/11/2026 09:45:12 +07:00 |
| URL Live HTTPS, cổng 443, host allowlist, không nhúng credential/query/fragment | PASS | Origin đúng `https://api-vtp.aicrm.vn`, allowlist đúng host |
| Đường dẫn provider và tạo đơn hợp lệ | PASS | `/api/v1/shipping-providers`, `/api/v1/orders` |
| Xác thực tenant bằng API Key, không header tenant tự đặt | PASS | `tenant_mode=api_key`, `tenant_header=null` |
| Key Backend được cấu hình và định dạng hợp lệ | PASS | Kiểm tra boolean, không in key |
| Key dùng được với GET provider hiện tại | PASS | HTTP 200 lúc 10:54:20 |
| Đối chiếu chính bản ghi key đang dùng | PASS | GET `/api/v1/api-keys` HTTP 200; một prefix trùng khớp nội bộ, tên `web2`, ID `cf2ae311-05b6-4060-afd1-c5c8c521a7a6`, ACTIVE |
| UUID tenant đầy đủ được đối chiếu với chính key đang dùng | **FAIL – chưa xác minh** | Không có nguồn quản trị Production xác nhận đầy đủ |
| Mapping shop Local 38 → UUID tenant hợp lệ | **FAIL** | `shop_tenants=[]`, shop 38 không có mapping |
| Credential gắn đúng tenant và provider, không dùng key cho tenant khác | **FAIL** | `credential_tenant=''`, `tenant_credentials=[]`; resolver trả 409 vì chưa có mapping |
| Có đúng một VIETTEL_POST đang bật, ID cấu hình khớp | PASS | GET provider ID **1**, code VIETTEL_POST, enabled=true; cấu hình ID=1 |
| Token hãng có cấu hình và hạn khai báo chưa hết | PASS ở mức metadata | GET cho biết có token và hạn chưa hết; không chứng minh hãng chưa thu hồi token |
| Thông tin người gửi có đủ trường | PASS ở mức metadata | Có tên/điện thoại/địa chỉ; không in dữ liệu này |
| Người gửi/token được chủ tài khoản xác nhận dùng cho thử thật | **FAIL – chưa xác minh** | Cần xác nhận quản trị/chủ tài khoản |
| Effective upstream Viettel Post của AICRM Production là môi trường thật | **FAIL – chưa xác minh** | Chưa có cấu hình effective của deployment |
| Hàng hóa/số lượng và giới hạn payload | PASS ở mức cấu trúc | 2 sản phẩm, tên hàng ghép 84 ký tự ≤255, các dòng hợp lệ |
| COD nguyên, không âm, không vượt tổng | PASS | 238890 VND, tổng 238890 VND |
| Người nhận thật đồng ý, điện thoại/địa chỉ giao được | **FAIL** | Số mẫu + “KHÔNG GIAO HÀNG”; cần tạo đơn mới |
| Hàng vật lý và địa chỉ lấy hàng được người vận hành xác nhận | **FAIL – chưa xác minh** | Log đóng hàng là bằng chứng thao tác, không tự chứng minh hàng vật lý/sender đã được chấp thuận |

Frontend ẩn nút khi thiếu quyền hoặc chưa packed; khi đã hiển thị, nó khóa nút nếu `!order.can_create_shipment || pending`. Với đơn này, backend trả `false` đã đủ giải thích nút bị khóa, độc lập trạng thái pending của trình duyệt. Quyền nêu trên là của user 637, không suy đoán user đang đăng nhập trong từng cửa sổ Chrome.

## Đối chiếu mã nguồn

- `frontend/src/pages/Warehouse/ViettelPostShipment.jsx:11–15`: điều kiện hiện/khóa nút.
- `app/Http/Controllers/Api/WarehouseController.php:108–115`: điều kiện nền và năm chốt Live trả về `can_create_shipment`.
- `app/Services/AicrmShipmentService.php:21–54`: kiểm tra quyền shop, hàng đợi, packing, chống trùng, tenant, tracking, config và payload trước reservation/gửi.
- `app/Services/AicrmLiveConnection.php:13–44`: HTTPS/host, tenant, creation, đối soát, ID phê duyệt, mốc tạo đơn và endpoint.
- `app/Services/AicrmTenantCredentials.php:10–40`: UUID, credential riêng cho tenant, không fallback khi thiếu mapping, không dùng một key cho nhiều tenant.
- `app/Services/AicrmOrderPayload.php`: payload dựng từ dữ liệu đơn ở Backend; regex điện thoại hiện không phát hiện số mẫu 0000000641.
- Source AICRM Local đã đọc: `ApiKeyController` chỉ trả ID/tên/prefix/trạng thái/thời hạn cho danh sách key, không trả `tenant_id`; DTO provider không trả tenant UUID hoặc upstream URL. Không thể lấy đủ bằng chứng chỉ từ hai màn hình/API đó.
- Source `api-vtp/backend/src/main/resources/application.yml:55` có fallback `VIETTELPOST_API_URL → https://partnerdev.viettelpost.vn`. Source này **không chứng minh giá trị đang chạy trên Production**. Tên miền AICRM Production cũng không chứng minh hãng phía sau là môi trường thật.

Không nới bất kỳ chốt nào để thử request. Khi chỉ chạy validator, lỗi đầu tiên hiện tại là **“Kết nối Live AICRM đang tắt.”**; resolver tenant riêng trả **“Shop chưa được ánh xạ UUID tenant AICRM hợp lệ.”**. Các validator này không gửi HTTP.

## Nguồn quản trị còn cần

Người dùng xác nhận có quyền đăng nhập giao diện AICRM Production và đã tạo key. Ưu tiên hướng dẫn qua UI/API quản trị chỉ đọc, không dùng DB hoặc đơn khách. Agent không đăng nhập, không POST, không thay đổi Production. Đã đối chiếu được metadata key; UUID tenant và upstream vẫn chưa xác minh.

Người quản trị cần cung cấp/xác nhận chỉ metadata:

1. Bản ghi của **đúng key đang cấu hình Local**: ID, tên, trạng thái ACTIVE, thời hạn và **tenant UUID đầy đủ**. Đối chiếu prefix riêng tư để xác định bản ghi; không suy UUID từ prefix, không gửi raw key/key_hash/token.
2. Giá trị **effective `viettelpost.api.base-url`** của Backend AICRM đang chạy, bao gồm override môi trường, command-line/system property; xác nhận môi trường hãng thật.
3. Provider **1** thuộc đúng tenant; token hãng còn dùng được, người gửi/địa chỉ lấy hàng được chủ tài khoản chấp thuận cho thử thật.
4. Cho phép mapping duy nhất shop Local **38** sang tenant đó, cùng quy trình đối soát khi timeout/unknown.


### Đã xác định đúng bản ghi API Key

Một GET tới `https://api-vtp.aicrm.vn/api/v1/api-keys` bằng key Backend sẵn có trả HTTP 200 lúc **10:58:52**. Đối chiếu prefix trong bộ nhớ với key đang cấu hình cho đúng **một** bản ghi:

- Tên: **web2**.
- ID bản ghi key: **cf2ae311-05b6-4060-afd1-c5c8c521a7a6** — **đây KHÔNG phải Tenant UUID**.
- Trạng thái: **ACTIVE**.
- Tạo: **05/11/2026 06:57:09 +07:00**.
- Hết hạn khai báo: **05/11/2027 06:57:09 +07:00**.

Không in raw key, prefix hoặc key_hash. Production thực tế trả metadata `id/name/prefix/status/created_at/expires_at` và không trả `tenantId/tenant_id` cho bản ghi khớp.

### Người dùng lấy Tenant UUID qua UI/API chỉ đọc

1. Tự đăng nhập [AICRM Production](https://api-vtp.aicrm.vn/) trong trình duyệt của bạn. Agent không thực hiện đăng nhập vì lượt này không gọi POST.
2. Mở mục **Quản lý API Key** hoặc [trang API Key](https://api-vtp.aicrm.vn/api-keys). Đối chiếu dòng **web2**, ACTIVE và ngày tạo nêu trên; không bấm tạo mới, thu hồi hoặc lưu thay đổi.
3. Nếu cần xem ID đầy đủ: mở **DevTools → Network**, tải lại trang API Key rồi chọn request **GET `/api/v1/api-keys` → Preview/Response**. Chỉ xem metadata bản ghi. Không chép Authorization/header token vào chat. API này đã kiểm chứng trên Production nhưng không cung cấp Tenant UUID.
4. Theo source hiện có, `GET /api/v1/auth/me` chỉ có UUID **tài khoản**, email, tên và role; UUID `id` ở đó cũng **không phải Tenant UUID**. Không cố giải mã session token hoặc suy UUID từ prefix key.
5. Source có **GET `/api/v1/access/tenants`**, trả `id` (Tenant UUID), `key`, `name`, `status`, `createdAt`, nhưng **chỉ Platform Admin** (`PLATFORM_ADMIN`) được gọi. Quyền quản lý key không tự đồng nghĩa quyền này. Chưa gọi endpoint này bằng phiên quản trị Production của bạn, nên đây là hướng dẫn theo source, chưa phải kết quả Production.
6. Nếu tài khoản có Platform Admin, có thể dùng đoạn GET dưới đây trong **DevTools → Console của chính origin `https://api-vtp.aicrm.vn`**, với phiên đã đăng nhập. Đoạn này chỉ đọc danh sách metadata tenant, không tạo/sửa dữ liệu và không in token. Nếu 401/403/404, dừng và nhờ quản trị; không đổi quyền hoặc thử header khác.

```javascript
(async () => {
  const session = localStorage.getItem('core-access-token') || sessionStorage.getItem('core-access-token')
  if (!session) throw new Error('Cần dùng phiên quản trị đã đăng nhập trên đúng trang AICRM.')
  const response = await fetch('/api/v1/access/tenants', {
    method: 'GET', headers: { Authorization: `Bearer ${session}`, Accept: 'application/json' },
  })
  if (!response.ok) throw new Error(`HTTP ${response.status}: dừng tại đây và nhờ quản trị AICRM.`)
  const rows = await response.json()
  console.table(rows.map(({ id, key, name, status }) => ({ id, key, name, status })))
})()
```

7. Chỉ lấy `id` của tenant **được xác nhận sở hữu key web2**. API tenant có thể trả nhiều tenant của deployment; không chọn dòng đầu tiên hoặc suy quan hệ chỉ từ tên. Cần kết hợp key `web2` xuất hiện trong danh sách key của đúng phiên tenant và xác nhận quản trị về dòng tenant tương ứng. Nếu UI/API không hiển thị đủ quan hệ, nhờ Platform Admin xác nhận mapping **key ID trên → Tenant UUID**, không cần đọc DB hoặc đơn hàng.
8. Gửi lại chỉ metadata đã xác nhận: Tenant UUID, tenant key/name, key ID `cf2ae311-05b6-4060-afd1-c5c8c521a7a6` và căn cứ quản trị nối hai bản ghi. Không gửi session token/raw key/token Viettel Post.

### Thông tin người vận hành cần xác nhận về Viettel Post

UI Settings hiện theo source chỉ có tên môi trường, service tier, vùng và **Public base URL**; Public base URL không phải URL hãng. Màn hình nhà vận chuyển cũng không cung cấp effective `viettelpost.api.base-url`.

Nhờ người quản lý/người vận hành xác nhận:

- Deployment AICRM Production hiện tại (instance/release và thời điểm kiểm tra).
- Giá trị **effective `viettelpost.api.base-url`** thực sự được RestClient của Backend sử dụng.
- Nguồn giá trị và mọi override: `VIETTELPOST_API_URL`, cấu hình Spring/profile/config server, Java system property hoặc command-line.
- Xác nhận URL đó là Viettel Post thật, không fallback `https://partnerdev.viettelpost.vn`.
- Provider **1** thuộc Tenant UUID đã xác nhận của **web2**, sender được phép dùng, token còn hoạt động; không cần gửi token.

Đây là yêu cầu metadata chỉ đọc. Không nhờ bật Live, đổi URL, sửa provider hoặc thử POST để chứng minh.

Chỉ dùng metadata quản trị của key/provider/config. Không GET danh sách/chi tiết đơn Production để suy tenant; không gọi hãng, không đăng nhập/đổi token hoặc tạo key mới.

## Các bước người dùng thực hiện trên UI

1. Giữ POS-349322 làm bằng chứng thử luồng. Không đổi thông tin người nhận trên đơn đã đóng, không dùng hoặc phê duyệt đơn này để gửi thật.
2. Mở `http://localhost:5173/ban-hang`, đăng nhập Sale/CSKH có quyền và chọn đúng **shop 38**. Bấm **Tạo đơn mới** nếu đang ở biên nhận đơn trước.
3. Chọn hàng thực tế định gửi; kiểm tra tên/số lượng và hàng vật lý. Tồn ảo không phải bằng chứng tồn kho thật.
4. Không chọn khách mẫu. Để trống lựa chọn khách có sẵn rồi nhập **tên thật, số điện thoại thật, địa chỉ giao đầy đủ của người đã đồng ý**; hoặc chỉ chọn khách thật đã đối chiếu. Đảm bảo trường địa chỉ không bị tự điền lại địa chỉ mẫu.
5. Nhập giảm giá/phí vận chuyển/phụ thu bằng VND nguyên. Kiểm tra tổng và COD. Chọn COD nếu chưa thu tiền; chỉ chọn/xác nhận tiền mặt hoặc chuyển khoản đủ khi đã thực nhận. Ghi chú `TEST-LIVE-MANUAL-20261105` để nhận diện đơn, giữ nguyên thông tin người nhận thật.
6. **Bạn tự bấm Tạo đơn hàng một lần** và ghi lại ID **POS mới**. Không giả định ID tiếp theo sẽ là 349323.
7. Mở `http://localhost:5173/kho` bằng tài khoản Kho được cấp shop 38. Trong **Chờ đóng hàng**, tìm đúng `POS-<ID mới>`, đối chiếu người nhận, số lượng, COD và hàng thực tế; **bạn tự bấm Hoàn tất** và xác nhận đóng gói.
8. Vào **Đã đóng hàng**, kiểm tra đúng ID và thời điểm packed; gửi lại **ID mới**, xác nhận người nhận đã đồng ý và nguồn metadata quản trị ở trên. **Dừng trước bước tạo vận đơn** khi chưa có kết luận READY.
9. Chỉ sau khi tất cả điều kiện đã được xác minh và ID mới được người dùng phê duyệt, mới chuẩn bị giới hạn Local duy nhất cho ID đó: đúng shop 38, đúng tenant/key/provider, sau mốc Live, chưa có tracking/shipment/attempt. Không wildcard, không phê duyệt đơn cũ, không tự bật `creation_enabled`.
10. Bật creation là bước riêng cần người dùng cho phép sau khi xem kết quả cụ thể. Khi đã READY và nút được mở theo phê duyệt, **người dùng tự bấm** “Tạo vận đơn Viettel Post (Live)”, đọc hộp xác nhận và tự xác nhận. Nếu processing/unknown/timeout thì không bấm lại, không đổi ID/khóa chống trùng để thử tiếp; chuyển sang đối soát.

## Thay đổi và giới hạn của lượt kiểm tra

- Chỉ đọc DB Mac Local trong giao dịch **READ ONLY**, kiểm tra đúng host/datadir trước khi đọc, rollback sau khi xong. Không tạo đơn/đóng hàng/chỉnh thanh toán.
- Đúng **hai GET** dùng key Backend sẵn có tới host HTTPS được chỉ định: một GET provider và một GET metadata API Key. Không redirect/retry, chỉ lưu metadata đã lọc. **0 POST**, không gọi API đơn Production; chưa gọi API liệt kê tenant của Platform Admin.
- Không đổi `.env`, không bật Live/creation/contract/reconciliation, không phê duyệt 349322 hoặc ID giả định; chưa chuẩn bị cấu hình áp dụng vì còn thiếu điều kiện.
- Không sửa source trong lượt này, không sửa Production, không commit/push/deploy, không tạo/hủy vận đơn.
- Trước/sau đọc Local: **90.594 orders, max ID 349322; 0 shipment, 0 attempt**.
