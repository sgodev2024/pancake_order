# Pancake → AICRM Local: kiểm thử HTTP thật, 04/11/2026

**Kết quả: PASS cho kết nối đọc Local. Provider chưa đủ điều kiện tạo vận đơn.**

## Môi trường đã xác nhận

- Pancake: `/Users/viet/Pancake/pancake_order`, branch `viet_dev`, HEAD `ae1436d40c53be9f1666e825ee92afbc3d18e0e2`; working tree có sẵn nhiều thay đổi, được giữ nguyên ngoài phạm vi bổ sung này.
- Pancake backend: `http://127.0.0.1:8002`, `/up` trả HTTP 200. Tiến trình chạy từ thư mục public của đúng repository. Cổng 8000 thuộc dự án khác, không sử dụng cho kiểm thử.
- AICRM backend: `http://127.0.0.1:8185`, readiness HTTP 200. Không sửa source hoặc dữ liệu AICRM trong lần kiểm thử này.
- Pancake database: MySQL `pancake_order` trên `127.0.0.1:3306`, APP_ENV=local, không DB_URL; đã kiểm tra điều kiện Local trước các thao tác database.
- AICRM database: PostgreSQL `aicrm_api_key_test` trên `127.0.0.1:55437`.
- Tenant: `9a9e92ae-fd89-4026-ba91-7edc972b6f9a`, tên `AICRM Mac Local TEST`.
- Shop mới riêng cho bài kiểm thử: id **40**, tên `TEST AICRM Loopback - KHONG TAO VAN DON`, pancake_shop_id=`test-aicrm-loopback-no-sync`; không có API key Pancake bên ngoài, không chạy đồng bộ.
- Actor CLI: id **640**, email `aicrm-local-reader@test.local`, role `aicrm-local-read-test`; chỉ cấp warehouse-view và membership shop TEST, không cấp warehouse-pack hoặc admin.

## Sao lưu và migration

Database Local khoảng 3,2 GB theo information_schema. Trước thay đổi đã sao lưu đầy đủ bằng mysqldump single-transaction, gồm schema/data/triggers/routines/events; nén gzip, kiểm tra giải nén toàn bộ và dấu hoàn tất dump.

- File: `storage/app/private/aicrm-local/pancake-order-before-tenant-binding-20261104T132548Z.sql.gz`, quyền 0600, ngoài Git.
- Kích thước nén: 296.277.764 byte; dữ liệu giải nén: 1.991.611.986 byte.
- SHA-256: `6837685d8ea39e87a39bd50a3ea6ad71d015dce21dca3cfe1a45539e159c6010`.
- mysqldump exit=0; gzip integrity PASS; chưa thực hiện phục hồi vào database khác.
- Chỉ chạy migration còn thiếu `2026_11_04_000003_add_aicrm_tenant_binding.php`, thành công; cột nullable tenant_id đã có trên aicrm_shipments.
- Không chạy migration khác, không backfill shipment cũ, không sửa migration nguồn. Migration này không thêm tenant_id vào bảng attempts.
- Tạo shop/actor/role/membership TEST riêng sau sao lưu; không đổi quyền các tài khoản hiện có.

## Kết nối Local bổ sung

- `app/Services/AicrmLocalConnection.php`: client chỉ có GET providers; không có method tạo vận đơn.
- `app/Console/Commands/CheckAicrmLocalConnection.php`: lệnh `aicrm:local-check`, kiểm tra actor/shop/warehouse-view, xuất whitelist metadata; không xuất raw response, token hãng hoặc API key.
- `config/aicrm.php`: khối `local` độc lập. Không sửa logic hoặc điều kiện HTTPS của Live/Sandbox.
- `.env.example`: chỉ thêm placeholder Local, không chứa key.
- `.env` Local: bật AICRM_LOCAL_ENABLED, origin `http://127.0.0.1:8185`, map shop 40 → UUID tenant trên, expected provider id=1; chỉ lưu đường dẫn file key riêng.
- File key: `storage/app/private/aicrm-local/aicrm-test-api-key`, quyền 0600, thư mục 0700, được Git bỏ qua. Key được đọc từ file TEST AICRM rồi chép trực tiếp bằng chương trình; không xuất key, không đưa vào source/command line/history/báo cáo.
- Client chỉ cho phép APP_ENV=local và hostname chính xác 127.0.0.1/localhost; chặn domain khác, IPv6 không nằm trong danh sách, path/query/fragment/userinfo. Không redirect, retry, cookie hoặc proxy; TLS verification giữ true nếu dùng HTTPS Local.
- Các luồng tạo vận đơn vẫn giữ chế độ cũ; Local GET không đi qua Mock và không bật Live. `AICRM_MODE=local` cũng không được client tạo vận đơn chấp nhận.
- Live.enabled=false, Live.creation_enabled=false. Kết nối Local không có công tắc cho phép POST.

## Bằng chứng GET thật

Đã chạy trong Laravel bằng PHP 8.4:

```sh
php artisan aicrm:local-check --shop-id=40 --actor-id=640 --verify-rejections
```

Laravel HTTP client gửi request thực tới Spring Boot. Không dùng Http::fake, mock response, mock router hoặc response dựng sẵn trong phép kiểm thử kết nối này.

| Trường hợp | Kết quả | Lớp kiểm tra |
|---|---|---|
| Key TEST đúng | HTTP **200**, JSON array trực tiếp | AICRM thật |
| Không gửi X-API-Key | HTTP **401** | AICRM thật |
| Gửi key sai ngẫu nhiên | HTTP **401** | AICRM thật |
| Shop gắn UUID khác tenant chủ sở hữu credential | Lỗi **409**, request_sent=false | Binding tại Pancake; không phải response HTTP từ AICRM |

Provider nhận được qua HTTP thật, chỉ trích các trường không nhạy cảm:

```json
{
  "id": 1,
  "code": "VIETTEL_POST",
  "name": "Viettel Post - LOCAL TEST ONLY",
  "isEnable": false
}
```

Kết quả phân loại: `selected_shipping_provider_id=null`, `provider_ready_for_creation=false`, `creation_enabled=false`. Không coi ID nhận diện là provider đang hoạt động. Provider không có token hãng.

**Giới hạn bằng chứng tenant:** response providers không có tenantId. Tenant được xác nhận bằng truy vấn chỉ đọc trên database TEST AICRM: hash của key tương ứng key ACTIVE/chưa hết hạn, tenant của key khớp tài khoản, và provider id=1 thuộc cùng tenant. Không lấy tenant từ một header do người dùng chọn. Việc từ chối sai tenant nêu trên kiểm tra shop mapping không khớp tenant đã xác nhận của key; không tuyên bố AICRM hỗ trợ hoặc từ chối X-Tenant-ID tùy ý. Nếu vận hành khai báo sai cả chủ sở hữu key và map shop, response providers đơn lẻ không tự phát hiện được; cần đối chiếu quyền sở hữu credential bằng nguồn tin cậy.

Kết quả whitelist lưu tại `storage/app/private/aicrm-local/real-check-result.txt`. Chứng cứ scope không chứa key lưu tại `aicrm-local-scope-proof.json` trong cùng thư mục.

## Kiểm thử đã chạy

| Kiểm tra | Kết quả |
|---|---|
| Baseline AicrmShipmentTest trước thay đổi | 113 PASS / 866 assertions |
| AICRM regression + Local validation guards | **134 PASS / 890 assertions** |
| Integration thật Local, không fake HTTP | **4 PASS / 13 assertions** |
| PHP syntax client/command | PASS |
| Pint các file mới và config | PASS |
| git diff --check | PASS |

21 kiểm tra bảo vệ Local không cần gửi HTTP. Bộ AicrmShipmentTest cũ tiếp tục dùng cách cô lập HTTP vốn có; không dùng kết quả bộ này làm bằng chứng kết nối thật.

Integration thật được đặt riêng trong `tests/Integration/AicrmLocalConnectionRealTest.php`, không nằm trong testsuite mặc định; chỉ chạy khi được bật rõ ràng:

```sh
AICRM_RUN_LOCAL_INTEGRATION=1 AICRM_LOCAL_TEST_SHOP_ID=40 \
DB_CONNECTION=sqlite DB_DATABASE=:memory: \
php artisan test tests/Integration/AicrmLocalConnectionRealTest.php
```

Các test thật kiểm tra HTTP 200 và provider đang tắt, thiếu key 401, sai key 401, và sai tenant bị chặn trước transport. Chúng không tạo dữ liệu hoặc gửi POST. Test guard kiểm tra host, path, môi trường Production, tenant, file key thiếu/quyền quá rộng, chế độ tắt và không cho tạo vận đơn Local.

## Việc còn lại cho Production

1. Xác nhận hostname B2B HTTPS và version Backend/Frontend AICRM đang deploy; hoàn tất quyền mạng qua cơ chế do AICRM hỗ trợ nếu có Cloudflare chặn.
2. Cấp key Production riêng và xác nhận tenant sở hữu; key TEST Local không được dùng cho Production. Cấu hình tenant/provider theo từng shop trong khối Live hiện có.
3. Lấy provider Production qua GET đã xác thực; phải enabled/configured, có token hãng còn hạn và cấu hình người gửi đúng. Không dùng provider TEST id=1 làm ID mặc định.
4. Xử lý các vấn đề AICRM đã ghi nhận: grant runtime sau migration, kiểm tra quyền/scopes/account của API key, không trả token hãng trong DTO provider.
5. Chốt môi trường Viettel Post, COD/phí và quy trình đối soát timeout/idempotency. Chỉ bật tạo vận đơn theo nhiệm vụ riêng được phép; hiện không gửi POST hoặc gọi Viettel Post.
6. Áp dụng migration Pancake theo quy trình sao lưu/phê duyệt của môi trường đích; việc đã chạy Local không xác nhận migration Production.

Không sửa database/cấu hình Production, không bật Live Production, không commit hoặc push. Tệp secret và backup vẫn ở thư mục storage được Git bỏ qua.
