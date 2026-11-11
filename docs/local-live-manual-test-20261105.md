# Tự kiểm thử POS → Kho → Viettel Post thật trên Mac Local

Ngày chuẩn bị: 05/11/2026. **READY cho phần POS/Kho Local; NOT READY để bấm tạo vận đơn thật.**

Local đang chạy source branch `viet_dev`, HEAD `4fe593e`; không tự đồng bộ source từ main Production. Backend **http://127.0.0.1:8002**, Frontend **http://localhost:5173** đã kiểm tra HTTP 200. Backend đã nạp lại cấu hình Local, không thay đổi server Pancake Production.

## 1. Đã chuẩn bị và kiểm tra

- Người dùng xác nhận dùng **Shop TEST 38** và có sự cho phép của người quản lý để tự kiểm thử vận đơn thật.
- GET thật bằng X-API-Key trong Backend Local tới `https://api-vtp.aicrm.vn/api/v1/shipping-providers`: **HTTP 200**, đúng provider **VIETTEL_POST, ID 1, enabled**. Không redirect, không retry, không in key hoặc raw provider response.
- Metadata provider xác nhận **có token**, có hạn dùng và hạn được khai báo **chưa hết**, đủ trường tên/điện thoại/địa chỉ người gửi. Phiên quản trị AICRM cũng hiển thị provider hoạt động và có thông tin người gửi; chỉ mở đọc rồi Hủy, không lưu thay đổi.
- Điều này chưa chứng minh token chưa bị thu hồi tại hãng hoặc địa chỉ là địa chỉ lấy hàng được chủ tài khoản chấp nhận. Không gọi trực tiếp hãng để thử tạo hoặc đăng nhập/đổi token.
- **Chưa xác minh được UUID tenant Production** của đúng key đang dùng, và **chưa xác minh effective Viettel Post base URL** của AICRM Production. Không lấy UUID tenant TEST cũ hoặc suy ra UUID đầy đủ từ prefix key.
- Người dùng chọn lấy UUID tenant và VIETTELPOST_API_URL thực tế từ người quản lý. Không đọc đơn Production để suy ra tenant.

## 2. Cấu hình Local hiện tại — khóa gửi thật, không Mock

| Mục | Giá trị |
| --- | --- |
| APP_ENV / DB | local / MySQL 127.0.0.1:3306, pancake_order |
| AICRM_MODE | **live** |
| Live URL / allowlist | https://api-vtp.aicrm.vn / api-vtp.aicrm.vn |
| Provider ID | **1**, theo GET của key hiện có |
| API Key | Giữ nguyên byte, chỉ ở Backend .env được Git ignore |
| Live enabled / creation | **false / false** |
| Contract / reconciliation confirmed | **false / false** |
| Tenant mapping / credential tenant | Rỗng; không điền placeholder thành dữ liệu thật |
| Approved order | **Rỗng** |
| Live activated_at | **2026-11-05T09:45:12+07:00**; đơn cũ trước mốc không được phê duyệt Live |
| Sandbox / AICRM Local test backend | false / false |
| Kho | Enabled theo mốc Local hiện có; không đổi/backfill lịch sử |

Không có nhánh fallback sang Mock khi mode=live mà cấu hình thiếu: Backend từ chối. GET từ API Kho Local trả mode=live, live_enabled=false, live_creation_enabled=false; cả **4 đơn đã đóng cũ có can_create_shipment=false**. Trên UI đã thấy banner **“AICRM Live · Tạo vận đơn thật đang tắt”** và bốn nút **Tạo vận đơn Viettel Post (Live)** bị disabled. Không dùng các đơn cũ để thử gửi thật.

Đã sao lưu .env cũ riêng tư trước sửa, giữ nguyên mật khẩu/role/shop/tài khoản. Không sửa công thức tiền, tồn ảo, nghiệp vụ Kho hoặc cơ chế chống trùng.

## 3. Tài khoản để bạn tự thao tác

| Vai trò | ID | Email TEST | Shop / quyền chính | Kiểm tra |
| --- | --- | --- | --- | --- |
| Sale thường | 642 | sale.pos-staff-20261104@pancake.test | 38; list-product/list-customer, POS theo role Sale | Hash::check đúng; /me và GET POS data 200 |
| CSKH | 641 | cskh.pos-role-20261104@pancake.test | 38; pos-create/list-product/list-customer | Hash::check đúng; /me và GET POS data 200 |
| Kho | 637 | kho1.local-plan3@pancake.test | 38; warehouse-view/warehouse-pack | Hash::check đúng; đăng nhập UI thành công; GET Kho 200 |

Sale/CSKH gọi GET sản phẩm shop ngoài phạm vi trả **403**. Không cấp thêm quyền. CSKH không vào được Kho là đúng chính sách; chuyển sang tài khoản Kho để đóng hàng.

Thông tin đăng nhập chỉ nằm trong file riêng trên Mac, không trong Git:

`/Users/viet/Pancake/.local-backups/local-live-manual-20261105/manual-login.private.json`

File này chỉ có ba tài khoản TEST trên, không có API Key. Không gửi hoặc chụp mật khẩu vào chat. Trang Kho ở trình duyệt bên cạnh hiện đã đăng nhập TEST Kho 637; Chrome Local của bạn có phiên riêng, có thể dùng cho Sale/CSKH.

## 4. Những thông tin cần người quản lý AICRM cung cấp

Chỉ cần metadata, không gửi raw key/token/.env/database dump:

1. **UUID tenant đầy đủ sở hữu đúng API Key đang dùng**, kèm cách đối chiếu bản ghi key (ID hoặc tên/prefix đối chiếu riêng). UI đã thấy hai key `web2` và `Web1` ACTIVE, nhưng không tự giả định key Local là key nào hoặc suy UUID từ prefix.
2. **Giá trị effective `viettelpost.api.base-url` / VIETTELPOST_API_URL của Backend AICRM đang chạy**, bao gồm mọi override bằng command-line/system property nếu có. Xác nhận đây là môi trường hãng thật, không `partnerdev.viettelpost.vn`. Source AICRM mặc định Dev khi thiếu override, nên hostname AICRM Production không tự chứng minh môi trường hãng.
3. Xác nhận provider **ID 1** thuộc tenant đó, token hãng dùng được và thông tin người gửi đã được chủ tài khoản chấp thuận cho lấy hàng/thử thật. Đã có bằng chứng token được cấu hình và chưa hết hạn khai báo; không cần gửi token.
4. Xác nhận được phép liên kết **Shop TEST Local 38** với tenant ấy. Mapping chỉ có shop 38; các shop Local khác không được nhận credential.

Nếu quản trị có quyền DB, có thể lấy tenant bằng SELECT metadata theo **ID bản ghi key đúng** trong `identity.api_key`, không lấy key_hash/raw key và không truy vấn đơn hàng/khách. Ví dụ cho người quản trị, không chạy trong lượt này:

```sql
SELECT id, name, status, expires_at, tenant_id
FROM identity.api_key
WHERE id = :verified_api_key_record_id;
```

Phiên quản trị đã kiểm tra không hiển thị UUID tenant hoặc base URL upstream trong trang API Key/provider, nên chưa thể đánh dấu hai điều kiện đó đạt.

## 5. Các bước bạn tự làm trên giao diện

### Giai đoạn A — bạn có thể thử POS và Kho ngay

1. Mở **http://localhost:5173/ban-hang** bằng tài khoản Sale 642 hoặc CSKH 641. Nếu trình duyệt đang ở tài khoản Kho, đăng xuất/đăng nhập lại; có thể dùng hai trình duyệt/profile riêng cho Sale và Kho.
2. Xác nhận **Shop TEST 38 tự chọn** sau khi danh sách tải xong, sản phẩm/khách đúng shop xuất hiện. Không dùng shop Production trong Pancake Production.
3. Chọn sản phẩm bạn định kiểm thử và số lượng. Tồn ảo hiển thị không chứng minh hàng vật lý đã có. Các fixture mang chữ KHONG GIAO HANG chỉ là dữ liệu thử; đối chiếu hàng thực tế nếu sau này tạo vận đơn thật.
4. **Bạn tự nhập người nhận, điện thoại, địa chỉ thật đã đồng ý tham gia test** khi định đi tiếp tới hãng. Không dùng khách mẫu `000000...` hoặc địa chỉ “KHONG GIAO HANG” cho vận đơn thật. Giữ tên người nhận thật; đánh dấu TEST trong ghi chú.
5. Ghi chú **TEST-LIVE-MANUAL-20261105**. Kiểm tra tổng/ship/phụ thu/giảm giá. Local hiện có ba phương thức thanh toán đơn giản; API cũ thanh toán một phần được giữ, không tự đổi source để lấy giao diện khác từ main.
6. Chọn phương thức đúng thực tế: COD khi chưa thu tiền và hai bên đồng ý thu hộ số đó; tiền mặt/chuyển khoản chỉ xác nhận đã nhận đủ khi đã nhận thật. Vận đơn thật có thể phát sinh phí vận chuyển và giao/thu hộ thật.
7. **Bạn tự bấm Tạo đơn hàng đúng một lần**, lưu ID mới dạng **POS-…**. Không sử dụng một đơn cũ. Đơn cần có shop=38, creator=642 hoặc 641, origin=local_pos, ghi chú TEST và tạo sau mốc Live ở phần 2.
8. Sang **http://localhost:5173/kho**, đăng nhập Kho 637. Chọn shop 38, lọc đúng ID mới trong **Chờ đóng hàng**. Đối chiếu người nhận, số lượng, hàng thực tế và COD. Bạn tự bấm **Hoàn tất**, xác nhận khi đã kiểm tra/đóng gói thật.
9. Chuyển tab **Đã đóng hàng**, tìm đúng ID đó. **DỪNG tại đây** nếu chưa có xác nhận READY cho vận đơn thật. Hiện nút vẫn khóa là đúng, không thay ENV để tự mở.

Đơn POS tự vào Kho theo điều kiện hiện có; audit này không tự tạo/đóng hàng để chứng minh. Nếu đơn không xuất hiện, giữ ID và trạng thái lỗi để kiểm tra, không tạo thêm đơn hoặc đóng nhầm đơn lịch sử.

### Giai đoạn B — xác nhận chỉ một đơn được gửi thật

Sau khi có đủ metadata người quản lý và **ID TEST mới do bạn tự tạo**, cần kiểm tra lại trước khi mở nút:

- [ ] Key thuộc đúng UUID tenant Production, owner cho phép test.
- [ ] AICRM upstream trỏ Viettel Post thật; provider 1 đúng tenant, token/sender hợp lệ.
- [ ] Mapping duy nhất shop Local 38 → tenant đó, không fallback cho shop khác.
- [ ] Đơn đúng shop, người tạo TEST, ghi chú TEST, người nhận thực đã đồng ý, tổng/COD đúng và hàng đã kiểm tra.
- [ ] Đơn tạo sau **2026-11-05T09:45:12+07:00**, đã packed, chưa có tracking/shipment.
- [ ] `AICRM_LIVE_APPROVED_ORDER_ID` chỉ có **chính xác một ID đó**; không wildcard/danh sách và không phê duyệt đơn cũ.
- [ ] Đã xác nhận quy trình đối soát khi timeout; không tự mở khóa unknown/processing.
- [ ] Backend Local đã nạp đúng cấu hình; mọi đơn khác vẫn có nút khóa. Chỉ sau kiểm tra mới xem xét bật creation cho ID này, không bật sẵn trong lượt chuẩn bị.

Chưa có ID mới nên audit **không thể phê duyệt trước**. Hiện approved_order_id rỗng, creation=false. Việc phê duyệt ID trong ENV chỉ đổi giới hạn cho Local; không tạo đơn hoặc gọi POST. Không cung cấp lệnh bật creation sẵn khi metadata còn thiếu.

### Giai đoạn C — chỉ khi đã được báo READY cho ID đó

1. Bạn mở đúng đơn ở tab Đã đóng hàng. Nút phải ghi **(Live)**, không Mock/Sandbox.
2. Bạn tự bấm và đọc hộp xác nhận vận đơn thật, kiểm tra lại người nhận/COD/phí rồi tự xác nhận. Agent không bấm thay.
3. Khi thành công, giữ mã vận đơn và trạng thái AICRM Live. POS ID, mã tracking và UUID AICRM là các định danh khác nhau; POST không tự cung cấp UUID AICRM.
4. Nếu timeout/unknown/processing: **không bấm lại, không đổi ID phê duyệt sang đơn khác để thử**, giữ trạng thái để đối soát chỉ đọc. Không tự xóa shipment/attempt hoặc sửa status trong DB.

## 6. Chống gửi trùng và xử lý lỗi được giữ nguyên

- Reservation trong DB trước HTTP; khóa theo đơn, một shipment/order và reference riêng.
- Đã succeeded thì replay trả kết quả cũ; processing/unknown bị khóa gửi lại.
- Frontend mutation retry=false; Backend không tự retry POST và không theo redirect.
- Tạo Live kiểm tra lại shop/tenant, approval ID, activation, trạng thái đóng hàng, tracking và payload; browser không được tự quyết định người nhận/COD/provider từ dữ liệu giả mạo.
- Đối soát GET chỉ liên kết khi tracking/UUID/tenant khớp, không tự mở khóa unknown. Trường hợp timeout chưa biết tracking cần người vận hành AICRM hỗ trợ, không đoán theo tên/điện thoại.

Không chạy lại các test tự tạo đơn/shipment trong lượt chuẩn bị này; chỉ đọc source/cơ chế hiện có và GET kiểm chứng trạng thái khóa. Không dùng Mock làm bằng chứng vận đơn thật.

## 7. Đối chiếu và trạng thái cuối

Trước/sau chuẩn bị: **90.593 orders, max ID 349321; 0 shipment, 0 attempt, 4 jobs**, không đổi. Role/shop và Hash::check tài khoản TEST không đổi. Chỉ có đăng xuất/đăng nhập TEST trên UI để kiểm tra; không thay mật khẩu hoặc quyền.

Đã đổi .env **Mac Local** và nạp lại đúng Backend 8002; Frontend 5173 giữ chạy. Không sửa source ứng dụng, không commit/push/deploy, không sửa server Pancake Production hoặc cấu hình AICRM Production, không tự tạo đơn/đóng hàng, không POST vận đơn.

**NOT READY — phần gửi Viettel Post thật đang khóa.** Còn thiếu UUID tenant xác thực, effective môi trường Viettel Post Production và một ID TEST mới do bạn tự tạo để phê duyệt. **POS/Kho Local đã READY cho bạn tự thao tác tới bước dừng.**

Bằng chứng và backup riêng: `/Users/viet/Pancake/.local-backups/local-live-manual-20261105/`. File `env-before.private` chứa bí mật nên không đưa vào Git/chat. Ảnh `local-live-locked.png` thể hiện banner Live OFF và nút disabled.

Ghi nhận an toàn: phương án GET danh sách/chi tiết một đơn Production để suy ra tenant đã bị duyệt tự động chặn vì có thể đọc dữ liệu khách ngoài phạm vi metadata; **không được thực hiện**. Người dùng đã chọn lấy metadata từ người quản lý thay cho phương án đó.
