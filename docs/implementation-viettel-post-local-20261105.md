# Triển khai tạo vận đơn trên Pancake Local — 05/11/2026

> Cập nhật kiến trúc: xem [báo cáo contract AICRM hiện tại](aicrm-contract-readiness-20261105.md). Pancake không còn yêu cầu response providers trả token Viettel Post, hạn token hoặc người gửi; AICRM chịu trách nhiệm các phần nội bộ đó. Các mô tả kiểm tra token/sender bên dưới là lịch sử triển khai trước xác nhận mới.

Repo `/Users/viet/Pancake/pancake_order`, branch `viet_dev`. Đã sửa source trên luồng có sẵn, không thêm Controller, route, Service song song hoặc migration. Không sửa `.env`, người nhận, đơn hàng, dữ liệu khách, upstream/token AICRM hay Production. Không POST shipment thật, bật Live creation, commit/push/deploy.

## Chức năng đã triển khai

- Nút Live tiếp tục dùng `POST /api/v1/warehouse/orders/{id}/viettel-post` → `AicrmShipmentController::store()` → `AicrmShipmentService::create()`. FE chỉ gửi ID qua URL, body rỗng; không gửi API key, tenantId, COD hoặc sản phẩm.
- Nút có loading, một dialog tại một thời điểm và mỗi dialog chỉ submit một lần. React Query không retry mutation. Thành công hiển thị tracking trong thông báo và trạng thái đơn sau khi refetch.
- API Kho trả `shipment_blockers: [{code,message,remedy}]`. Nút vẫn disabled khi Backend không cho phép; giao diện hiển thị từng lý do và cách khắc phục, gồm gate, approval, recipient, binding/configuration, trạng thái đơn hoặc shipment hiện có.
- Preflight provider dùng GET trước reservation/POST: phải có đúng một VIETTEL_POST enabled, lấy trực tiếp ID trả về trong scope API key của Shop để dựng payload, token không rỗng, hạn token ISO có timezone còn hiệu lực và đủ senderName/senderPhone/senderAddress. Lỗi provider hoặc GET timeout không tạo attempt và không POST. Provider DTO/token không trả về FE hoặc lưu vào audit.
- Khi tất cả gate tĩnh của đơn Live đạt, API Kho dùng GET provider để quyết định khả năng bấm nút. Đây chỉ là kiểm tra trước; POST handler vẫn GET lại provider dưới khóa Order để không tin dữ liệu cũ từ trình duyệt.
- Người nhận gửi thật phải có tên, điện thoại đúng định dạng, không phải số bắt đầu ba số 0 hoặc số lặp, địa chỉ ít nhất 10 ký tự và không chứa dấu hiệu KHONG GIAO HANG / KHONG THU TIEN, kể cả tiếng Việt có dấu. Đây là chặn dữ liệu test rõ ràng, không phải xác minh địa chỉ/điện thoại có thể giao bằng dịch vụ hãng. Người vận hành vẫn phải xác nhận người nhận thực tế.
- Mapping/response hiện hữu được giữ: đúng 8 trường, tiền lấy server-side; thành công yêu cầu HTTP success + error=false + status=200 + data.ORDER_NUMBER hợp lệ. Tracking lưu vào shipment và `orders.order_number_vtp`. Không tạo UUID AICRM giả.
- Giữ quyền Kho, quyền Shop, packed/packed_at, active queue, trạng thái nguồn, mốc kích hoạt, đúng một approved ID, lock Order/Shipment, reservation trước HTTP và attempt audit. POST timeout/kết quả không rõ → unknown; processing/unknown không được retry. Đối soát hiện hữu chỉ GET, không tự mở khóa unknown.

## Mô phỏng dùng ngay trên Local

Đã triển khai nút riêng **“Mô phỏng TEST/Mock”**, cùng route hiện hữu:

`POST /api/v1/warehouse/orders/{id}/viettel-post?simulate=1`

Đây là cờ chọn thao tác; FE vẫn chỉ gửi ID đơn qua URL và không gửi body. Controller gọi `simulate()` trong chính AicrmShipmentService hiện có. Backend yêu cầu APP_ENV=local, Shop TEST 38, đủ quyền Kho/Shop, packed hợp lệ và chưa có shipment/tracking. Có thể tắt bằng `AICRM_SIMULATION_ENABLED=false`; mặc định trên Local có thể dùng ngay.

Mô phỏng dựng/validate mapping từ đơn với provider fixture rõ ràng, không yêu cầu binding hoặc token hãng. Nó **không gọi HTTP, không tạo shipment/attempt, không đổi đơn và không sinh mã vận đơn**. Response là `mode=simulation`, `status=simulated`, `tracking_number=null`, `network_sent=false`. Ghi ActivityLog `shipment.simulated` với actor; chi tiết Kho hiển thị sự kiện này là “Mô phỏng TEST/Mock · không tạo vận đơn”. Thông tin test không được chuyển sang đường POST thật.

Kiểm tra GET thực thi trong Laravel với actor Kho 637, MySQL Local transaction READ ONLY tại 12:33 +07:00:

| POS-349323, Shop 38 | Kết quả |
|---|---|
| warehouse_status | packed |
| can_simulate_shipment | **true** |
| can_create_shipment | **false** |
| shipment / attempt Local | **0 / 0** |
| audit của đơn | **2 bản ghi cũ, nguyên trạng** |

Đã chạy bài kiểm tra mô phỏng đầy đủ qua route thật của Laravel trên SQLite `:memory:`, xác minh audit và không đổi order/shipment. Chưa tự bấm mô phỏng trên dữ liệu đơn Local để giữ nguyên audit hiện tại cho người dùng tự thao tác.

Để thử ngay:

1. Đăng nhập tài khoản có cả `warehouse-view`, `warehouse-pack` và quyền Shop 38.
2. Mở `http://localhost:5173/kho`, tải lại trang, chọn **Đã đóng hàng**, Shop **38**, tìm **POS-349323**.
3. Xem các lý do khóa dưới nút Live. Bấm **Mô phỏng TEST/Mock** → **Chạy mô phỏng TEST/Mock**.
4. Kết quả hiển thị TEST/Mock, không có mã vận đơn. Mở **Chi tiết** để xem audit mô phỏng. Nút Live tiếp tục giữ khóa.

## Ngoại lệ HTTP loopback và credential riêng

`AICRM_LIVE_API_URL` là biến thực sự được đọc. `AICRM_LIVE_URL` không phải alias.

Ngoại lệ trong AicrmLiveConnection chỉ chấp nhận **chính xác** `http://127.0.0.1:8185`, APP_ENV=local, cờ `AICRM_LIVE_LOCAL_HTTP_ENABLED=true`, Shop 38 và credential/binding riêng. `localhost`, cổng khác, slash cuối, query, userinfo hoặc môi trường production/staging/testing đều bị từ chối. Những đích còn lại vẫn dùng HTTPS, host allowlist, port 443 và validation path hiện hữu. Không redirect hoặc retry POST, không tắt TLS verify.

Credential loopback lấy duy nhất từ các biến Backend mới bên dưới; không fallback sang AICRM_LIVE_API_KEY. Code còn chặn key Local nếu bằng bất kỳ shared key hoặc key trong credential map remote đang cấu hình. Không gửi tenantId hoặc header tenant. UUID binding phải khớp credential tenant; AICRM xác định tenant bằng X-API-Key. Cần xác minh chủ sở hữu key từ nguồn AICRM tin cậy, vì response providers không tự chứng minh UUID tenant.

Các biến chuẩn bị **sau khi được duyệt**, không phải cấu hình đã áp dụng:

```dotenv
APP_ENV=local
AICRM_MODE=live
AICRM_LIVE_API_URL=http://127.0.0.1:8185
AICRM_LIVE_LOCAL_HTTP_ENABLED=true
AICRM_LIVE_LOCAL_API_KEY=<key_TEST_Local_riêng>
AICRM_LIVE_LOCAL_CREDENTIAL_TENANT=9a9e92ae-fd89-4026-ba91-7edc972b6f9a
AICRM_LIVE_LOCAL_SHOP_TENANTS='{"38":"9a9e92ae-fd89-4026-ba91-7edc972b6f9a"}'
AICRM_LIVE_LOCAL_SHIPPING_PROVIDER_ID=1
AICRM_LIVE_PROVIDERS_PATH=/api/v1/shipping-providers
AICRM_LIVE_ORDERS_PATH=/api/v1/orders
AICRM_LIVE_ORDER_DETAIL_PATH=/api/v1/orders/{id}
AICRM_LIVE_CREATION_ENABLED=false
```

UUID/provider 1 ở đây là dữ liệu TEST đã đối chiếu trong lần chẩn đoán trước; phải kiểm tra lại nếu key/tenant/provider thay đổi. Không chép key Production sang biến Local. Key chỉ nằm trong Backend ENV, không đưa vào source/frontend/báo cáo.

Live connection, contract và reconciliation vẫn dùng các gate hiện hữu. `AICRM_LOCAL_*` của connector chỉ GET cũ không tự bật ngoại lệ mới và không thay thế binding loopback mới.

## Điều kiện còn thiếu trước khi người dùng tạo thật

Snapshot effective hiện tại vẫn là mode=live, URL `https://api-vtp.aicrm.vn`, Live enabled/creation/contract/reconciliation đều false, approved ID chưa có, chưa có loopback key/binding Shop 38. Báo cáo bằng chứng không chứa key.

Theo kiểm tra AICRM Mac Local trong lần chẩn đoán trước: Viettel Post upstream là `http://127.0.0.1:9` — đích chủ động chặn, không phải Sandbox/Production — và provider TEST 1 disabled/chưa cấu hình/chưa có token. Các thiết lập đó chưa được thay đổi trong lần triển khai này.

Các bước cần hoàn tất, **không được tác nhân thực hiện tự động trong lần này**:

1. Người phụ trách AICRM Local xác nhận chọn môi trường hãng, cấu hình provider/token còn hạn/người gửi qua quy trình có audit. Chỉ làm trên Mac Local được duyệt; không thay Production. Provider phải enabled, có token/hạn dùng và cùng tenant key TEST.
2. Chuẩn bị ENV Backend loopback riêng như trên, xác minh binding Shop 38 và hợp đồng API. Sau khi xác minh mới đặt `AICRM_LIVE_ENABLED=true`, `AICRM_LIVE_CONTRACT_CONFIRMED=true`; vẫn giữ creation=false. Nạp lại đúng tiến trình Backend 8002 vì tiến trình hiện có các biến AICRM được export; chỉ sửa `.env` hoặc clear config không đảm bảo thay giá trị inherited.
3. Kiểm tra GET qua command hiện hữu:

```sh
/opt/homebrew/opt/php@8.4/bin/php artisan aicrm:live-check --shop-id=38 --actor-id=637 --resource=providers
```

Command chỉ GET, không POST và không in raw provider/key. PASS kết nối/provider enabled của command chưa thay thế việc kiểm tra token/hạn dùng/người gửi. Kiểm tra đầy đủ sẽ được áp dụng bởi source mới khi xét điều kiện tạo và ngay trước POST.

4. Dùng **một đơn có người nhận hợp lệ**, đã được chủ đơn xác nhận. POS-349323 hiện bị chặn bởi số/địa chỉ test và không được sửa tự động. Nếu giữ đơn này, cần một chức năng sửa recipient có audit được duyệt riêng (chưa có OrderController::update). Phương án hiện dùng được là người dùng tự tạo một POS mới qua luồng sẵn có, rồi đóng hàng.
5. Chọn `AICRM_LIVE_ACTIVATED_AT` hợp lệ, có timezone, và đảm bảo đơn được tạo sau mốc. Không sửa/backdate ngày đơn hoặc mốc để né kiểm tra. Đặt `AICRM_LIVE_APPROVED_ORDER_ID` đúng **một ID** đó; xác nhận quy trình xử lý timeout rồi mới đặt `AICRM_LIVE_RECONCILIATION_CONFIRMED=true`.
6. Sau review cuối cùng của người dùng, mới đặt `AICRM_LIVE_CREATION_ENABLED=true` trên Pancake Local và nạp lại đúng tiến trình. Thao tác bật không tự POST. Kho sẽ GET provider, hiện lỗi và tiếp tục khóa nếu còn điều kiện chưa đạt.
7. Vào Kho → Đã đóng hàng → đúng Shop/ID đã phê duyệt → đọc người nhận, tổng tiền/COD, kiểm tra không còn lý do khóa → bấm **Tạo vận đơn Viettel Post (Live)** → **Xác nhận tạo vận đơn thật**. Chỉ bước xác nhận này gửi POST. Khi thành công, xem mã ở cột vận đơn/Chi tiết.
8. Sau lần thử có kiểm soát, tắt creation. Nếu timeout/unknown, không bấm lại, không đổi ID/môi trường, không sửa SQL hoặc reset attempt. Nhờ AICRM đối soát đúng tenant/đơn từ xa. Command `aicrm:reconcile` hiện chỉ liên kết UUID cho shipment succeeded đã có tracking; unknown chưa biết tracking vẫn cần vận hành xác minh, không được tự mở khóa.

## File thay đổi trong lần triển khai

| File | Thay đổi |
|---|---|
| `app/Http/Controllers/Api/AicrmShipmentController.php` | Dispatch mô phỏng qua cùng store(), không thêm route |
| `app/Http/Controllers/Api/WarehouseController.php` | Trả blockers, can_simulate; hiển thị audit mô phỏng |
| `app/Services/AicrmLiveConnection.php` | Ngoại lệ loopback có gate; preflight provider/token/sender |
| `app/Services/AicrmTenantCredentials.php` | Credential/binding riêng cho loopback Shop 38, chặn fallback/key remote |
| `app/Services/AicrmOrderPayload.php` | Chặn recipient test; dựng payload mô phỏng có fixture rõ ràng |
| `app/Services/AicrmShipmentService.php` | Tính lý do khóa; simulate có audit; preflight trước reservation |
| `config/aicrm.php` | Khai báo biến ENV loopback và policy mô phỏng Local |
| `frontend/src/api/warehouse.api.js` | Helper mô phỏng cùng route, không body |
| `frontend/src/pages/Warehouse/ViettelPostShipment.jsx` | Lý do/cách khắc phục; nút TEST/Mock riêng |
| `frontend/src/pages/Warehouse/WarehousePage.jsx` | Wiring, loading, chống mở nhiều dialog, kết quả/tracking/audit |
| `frontend/src/pages/Warehouse/shipmentConfirmation.js` | Xác nhận riêng TEST/Mock và dialog single-use |
| `tests/Feature/AicrmShipmentTest.php` | Test route/service, loopback, credential, provider, recipient, audit, double-click, timeout |
| `frontend/tests/warehouseShipmentClick.test.js` | Kiểm thử click thật trên component Kho, React Query và helper API |
| `frontend/tests/fixtures/warehouseUi.js` | Controls test cho lifecycle Kho |
| `docs/implementation-viettel-post-local-20261105.md` | Báo cáo/hướng dẫn này |
| `docs/implementation-viettel-post-local-20261105.evidence.json` | Snapshot GET thực thi trong Laravel với database Local chỉ đọc |

AicrmOrderClient hiện hữu được giữ; lint PHP 8.4 PASS. Các file POS và tài liệu chỉnh dở từ trước vẫn được giữ nguyên.

## Kiểm thử

- Backend: AicrmShipmentTest, AicrmLocalConnectionTest, WarehouseTest, PosOrderTest — **218 tests / 1.940 assertions PASS** trên SQLite `:memory:`.
- FE riêng Kho: **22/22 PASS**, gồm click → đúng URL/body rỗng → kết quả, pending, double-click chỉ một request, TEST/Mock không có tracking và unknown không retry. Backend feature test kiểm tra tiếp phần route → Controller → Service → HTTP fake → database; không tuyên bố đây là browser-to-live-server end-to-end POST thật.
- Toàn bộ Frontend: **142/143 PASS** bằng `node --test --test-force-exit --test-timeout=60000 tests/*.test.js`. Dùng force-exit để đóng các timer/cache còn treo của suite sau khi tests hoàn tất; không bỏ qua assertion.
- Một FAIL có sẵn tại `frontend/tests/customerCareActionParity.test.js:20`: slice source modal CSKH trả chuỗi rỗng, không tìm được field “Thời gian xác nhận”. Cả file test và `CustomerCarePage.jsx` khớp HEAD, không nằm trong diff triển khai.
- PHP Pint trên 8 file Backend/test được sửa: PASS. PHP syntax client: PASS. FE lint file vừa sửa: PASS không warning. `npm run lint`: exit 0, có 18 warning ở file ngoài phần thay đổi này. Frontend build: PASS. `git diff --check`: PASS.

Không chạy POST thật hoặc mô phỏng trên database nghiệp vụ Local. Không thay đổi cấu hình AICRM; các điều kiện hãng thật vẫn chưa sẵn sàng và creation vẫn tắt.

## Bổ sung lấy provider ID động từ GET

Luồng Live/Local hiện lấy `shippingProviderId` từ response GET `/api/v1/shipping-providers` ngay trong lần tạo, với cùng X-API-Key của Shop dùng cho POST `/api/v1/orders`. Không còn yêu cầu ID từ GET khớp một ID cũ trong ENV. UUID tenant/binding Shop, credential Local riêng, token/sender và toàn bộ Live gates vẫn được kiểm tra như trước.

ID từ GET được truyền vào AicrmOrderPayload để tạo đúng 8 trường và lưu vào request_payload của attempt trước POST. Thiếu/sai ID trong ENV không chặn discovery; ID trình duyệt gửi không được sử dụng. Các biến shipping provider ID vẫn được giữ tương thích với luồng cũ/mô phỏng, nhưng không phải nguồn ID cho POST Live. Readiness của Kho cũng dùng kết quả discovery và handler vẫn GET mới dưới khóa Order, không dùng cache readiness để POST.

Response phải là JSON array; chỉ đếm các bản ghi có code chính xác VIETTEL_POST và isEnable boolean true. Không có bản ghi phù hợp, có nhiều bản ghi phù hợp (kể cả bản ghi trùng hoàn toàn), ID không phải số nguyên dương, hoặc response không hợp lệ đều trả 409 rõ nguyên nhân trước reservation/POST. Một provider bị tắt không được chọn; nếu còn đúng một provider đang bật hợp lệ thì dùng provider đang bật đó.

Chỉ bổ sung trong AicrmLiveConnection, AicrmShippingProviders, AicrmOrderPayload, AicrmShipmentService và AicrmShipmentTest; Controller/route/FE/cấu hình giữ nguyên từ lượt trước.

Thêm 17 trường hợp Http::fake(): kiểm GET rồi POST đúng thứ tự, cùng key, đúng 8 trường, ID động khi ENV thiếu/cũ/0, payload audit/tracking, replay không gửi lại, readiness và các response provider lỗi. Kiểm tra không lộ provider token/API key trong response, shipment/attempt và log qua Log spy. Test chạy trên SQLite `:memory:`; không gọi AICRM thật.

Kết quả sau bổ sung: **235 Backend tests / 2.307 assertions PASS**, **22 FE Kho tests PASS**, PHP Pint và `git diff --check` PASS. Kết quả 218 Backend tests ở phần triển khai ban đầu là mốc trước bổ sung này.
