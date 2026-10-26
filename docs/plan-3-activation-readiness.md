# PLAN 3 — Kiểm tra trước kích hoạt Kho

> Trạng thái Local mới nhất: migration PLAN 3 PASS; Kho ON từ 2026-10-22T13:54:15+07:00, đã kiểm tra bằng tài khoản/đơn TEST riêng. Xem [báo cáo triển khai Local](/Users/viet/Pancake/pancake_order/docs/plan-3-local-activation.md). Các phần bên dưới ghi lại trạng thái ở thời điểm audit/triển khai trước.

Ngày kiểm tra: **22/10/2026**, timezone Asia/Ho_Chi_Minh. Hai repository đều `viet_dev`.

## Kết luận: READY

**READY về kỹ thuật để trình duyệt kích hoạt theo quy trình dưới đây.** Không còn lỗi chặn trong phạm vi Kho sau khi gia cố migration và kiểm thử lại. READY không phải đã triển khai hoặc cho phép tự kích hoạt: database Local chính vẫn **TẮT**, chưa chạy migration PLAN 3, chưa cấp quyền, chưa backfill. Backup database chính và diễn tập khôi phục bản backup chính vẫn là điều kiện bắt buộc trước khi thực hiện thay đổi được duyệt.

Đã đọc báo cáo PLAN 3, kiểm tra code/API/UI, schema và cấu hình thật bằng transaction `READ ONLY`; kiểm thử ghi dữ liệu chỉ trên SQLite memory và các database MySQL TEST riêng. Không commit/push, không thay `.env`, không xóa/reset database, không gọi MISA/Viettel Post hoặc dịch vụ Pancake thật.

Bằng chứng có cấu trúc: [plan-3-readiness-evidence.json](/Users/viet/Pancake/pancake_order/docs/plan-3-readiness-evidence.json).

## 1. Trạng thái Local và độ an toàn của migration

| Hạng mục | Kết quả kiểm tra thực tế |
| --- | --- |
| Database | pancake_order, MySQL 8.4.11 |
| Tổng orders, kể cả soft-delete | **90.577**, không đổi trước/sau kiểm tra |
| warehouse_queued_at khác null | **0**, không đổi |
| warehouse configuration | enabled=false, activated_at=null, configuration_valid=true |
| Migration PLAN 2 | đã ghi nhận trong migrations |
| Migration PLAN 3 | **chưa chạy** |
| 5 cột PLAN 3 và index packed mới | chưa tồn tại; không trùng với schema hiện tại |
| Quyền warehouse-view / warehouse-pack | chưa có; không tự cấp quyền |
| POS 349305 | created_at 2026-10-21 15:07:39, origin=local_pos, waiting_packing, queued_at=null; COD=6.320.000, cash=200.000; giữ nguyên |
| Tài khoản/phân quyền/shop | fingerprint users, roles, permissions, role_permissions và shop_users **không đổi** |
| Engine | toàn bộ 37 bảng hiện tại dùng InnoDB |
| Dung lượng từ metadata MySQL | orders data ~2,92 GB + index ~0,10 GB; toàn DB ~3,21 GB (ước tính, không phải kích thước file dump) |
| Disk trống lúc kiểm tra | khoảng 560 GiB |
| Queue | database; còn 4 job chờ thuộc customer/product/employee; không xóa/flush queue |
| Timezone | app Asia/Ho_Chi_Minh; MySQL SYSTEM hiện +07 |

Migration cần chạy sau phê duyệt:

[2026_10_21_000002_add_warehouse_completion_and_source_time.php](/Users/viet/Pancake/pancake_order/database/migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php).

Chỉ thêm nullable `source_created_at`, `first_synced_at`, `source_creator_name`, `packed_by_user_id`, `packed_at`, index `(shop_id, warehouse_status, packed_at)`, nhóm/danh mục hai quyền Kho. Không tạo bảng mới, không tái tạo các cột PLAN 2, không UPDATE/DELETE đơn cũ, không ghi timestamp/actor/status cho đơn lịch sử. Những cột mới của đơn cũ giữ null. Không đổi định nghĩa tiền hoặc tồn kho.

**Vấn đề đã tìm và sửa:** MySQL DDL có thể hoàn tất một phần trước khi migration thất bại. Phiên bản trước sẽ báo trùng cột khi chạy lại. Đã tái hiện bằng test, sau đó sửa migration để kiểm tra prerequisite, chỉ thêm cột/index thiếu, tái sử dụng index tương đương, từ chối kiểu/nullability hoặc index có tên giống nhưng định nghĩa không phù hợp. Danh mục quyền nằm trong transaction, không gán role. Test chạy lại cả hai lần và tình huống cột có sẵn/index thiếu đều PASS. Không tự sửa cột bị lệch schema; nếu preflight báo lệch thì dừng để xem xét.

Migration `down()` cố ý từ chối xóa lịch sử. Khi ngừng tính năng, tắt cấu hình và giữ schema/audit; không dùng `migrate:rollback`, migrate:fresh hoặc db:wipe.

Về dữ liệu, thay đổi này an toàn với tập đơn hiện tại theo kiểm tra code và diễn tập 90.577 đơn giả lập. Về vận hành, thêm index vẫn cần đọc dữ liệu và có thể chờ metadata lock; không cam kết thời gian chạy bằng số đo TEST nhỏ. MySQL hỗ trợ thêm cột bằng INSTANT trong các điều kiện phù hợp và tạo secondary index online, nhưng cần cửa sổ triển khai, kiểm tra transaction dài và giám sát lock/I/O. [Tài liệu MySQL 8.4 về online DDL](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/innodb-online-ddl-operations.html).

## 2. Xác định kích hoạt và chống đưa lịch sử vào Kho

Cấu hình duy nhất: `WAREHOUSE_ACTIVATED_AT`, được đọc tại [config/warehouse.php](/Users/viet/Pancake/pancake_order/config/warehouse.php). Trống/sai format = tắt. Mốc tương lai = chưa hoạt động đến đúng thời điểm đó. Dùng ISO đầy đủ, chính xác đến giây và có offset `+07:00`; chọn thời điểm **sau khi duyệt và hoàn tất chuẩn bị**, không dùng mốc trong fixture TEST hoặc đặt lùi ngày.

| Tình huống | Cách xử lý / bằng chứng |
| --- | --- |
| Local POS mới | Chỉ đánh dấu queue tại biên create thật, khi created_at từ mốc T trở đi. Replay idempotency không tạo đơn hoặc queue lại. |
| Pancake mới | Chỉ bản ghi mới tại import, ngày nguồn inserted_at/created_at từ T trở đi và trạng thái chờ hợp lệ. Nếu có cả hai ngày thì lấy ngày sớm hơn. |
| Thiếu/sai/ngày nguồn tương lai | Không tự vào chờ; log warehouse.review_required. Không lấy updated_at hoặc created_at Local để đoán tuổi nguồn. |
| Đơn cũ đồng bộ muộn | Ngày nguồn trước T thì loại, kể cả updated_at vừa thay đổi. |
| Bản ghi đã tồn tại | Không tự enroll, kể cả đồng bộ lại với ngày nguồn khác hoặc khi bật module. Không có scan/backfill. |
| POS 349305 | Nếu T sau 2026-10-21 15:07:39 thì không hợp lệ; queued_at hiện null. Test với bản ghi giả lập cùng ID/tuổi/tiền giữ nguyên và trả 409 nếu cố đóng. **Không dùng đơn thật để thử POST.** |
| Bulk/webhook lặp | Dùng định danh Pancake + shop hiện có; khi bật thì lock shop rồi order; test hai luồng lặp giữ đúng 1 bản ghi/queue. |
| Hoàn tất lặp/đồng thời | Transaction + lockForUpdate; audit key warehouse.packed:order:{id}. Lần sau trả replayed=true, giữ actor/time đầu tiên. |
| Hủy trước đóng | Loại khỏi chờ, audit một lần; không tự requeue khi sync đổi lại. |
| Đã đóng | Đồng bộ không ghi đè trạng thái/actor/time; thay đổi phức tạp ghi cảnh báo. |

20 payload đã lưu gần nhất trong Local có inserted_at dạng ISO sáu chữ số thập phân, không offset; parser chấp nhận format này và dùng UTC theo normalizer hiện tại. Ngày có offset được chuyển đúng về timezone app. Chưa gọi Pancake live để xác minh các format/trạng thái mới ngoài dữ liệu có sẵn; chúng được xử lý an toàn theo danh mục hiện tại và log khi thiếu căn cứ.

Mốc T cần giữ cố định sau khi kích hoạt. Nếu thay T, phạm vi list thay đổi dù hàng đợi không được tạo lại. Khi tạm tắt module sau một sự cố, phải lưu mốc gốc, kiểm soát luồng tạo/sync trong thời gian tắt và rà soát các đơn phát sinh; không đổi T hoặc tự backfill để che lấp khoảng gián đoạn.

## 3. Cấu hình, phân quyền và tính thống nhất UI/API

Không cần thêm role bắt buộc trong code. Tái sử dụng cơ chế role_permissions và shop_users hiện có:

| Đối tượng được duyệt | Quyền | Shop |
| --- | --- | --- |
| Kho chỉ xem | warehouse-view | membership shop_users được duyệt |
| Kho đóng hàng | warehouse-view **và** warehouse-pack | membership shop_users được duyệt |
| Admin quản lý Kho | cũng cần hai quyền phù hợp | phạm vi global theo ShopAccessService hiện có |

Không cấp admin cho nhân viên Kho để thay cho gán shop. Không sửa role dùng chung nếu việc đó vô tình mở quyền cho người chưa được duyệt. Hệ thống gán quyền theo role: nếu chỉ một phần nhân viên trong role cũ được duyệt thì cần role riêng/điều chỉnh tài khoản theo quyết định quản lý, không tự thực hiện. API sync quyền role là thay toàn bộ tập quyền: phải bảo toàn các quyền cũ khi có phê duyệt bổ sung, không gửi chỉ hai quyền rồi vô tình thu hồi quyền khác.

Backend dùng middleware strict và ShopAccessService trên list/detail/complete. ShopSelect gọi `/shops?view=summary`, chỉ trả shop được phép. FE chỉ hiện menu/nút theo đúng slug quyền; không thay thế kiểm tra Backend. Sau cấp quyền, cần đăng nhập lại hoặc nạp mới `/me` để frontend nhận đúng dữ liệu role.

API cùng prefix `/api/v1`:

- GET `/warehouse/orders`: shop/status/search/page/page_size; trả module configuration và danh sách có scope.
- GET `/warehouse/orders/{id}`: chi tiết hợp lệ trong scope.
- POST `/warehouse/orders/{id}/complete`: cần cả hai quyền; payload không sửa tiền/tồn.

Sau bật, list chỉ lấy warehouse_queued_at và ngày sinh từ T trở đi. Đơn chờ còn phải đúng trạng thái nguồn được đóng. Detail và complete áp cùng điều kiện, nên 349305 không có queue marker không thể lọt qua bằng gọi trực tiếp. Sai shop trả 403 cho cả list lọc shop, detail, complete; thiếu quyền cũng trả 403. Invalid filter 422, đơn không còn đủ điều kiện hoàn tất 409.

Đã mở lại API TEST 8001 và FE TEST 5174 do hai tiến trình dừng qua đêm. Trình duyệt đã xác minh ShopSelect chỉ có Shop A, tab Chờ có POS-1, tab Đã đóng có Local/Pancake, chi tiết có người đóng/thời gian/một audit. Không đóng thêm đơn qua trình duyệt trong lượt readiness này. Không đổi source frontend.

## 4. Kiểm thử lại

| Kiểm thử | Kết quả |
| --- | --- |
| Kho Backend | **17 PASS**, 118 assertions |
| Backend liên quan Kho/POS/sản phẩm/journey | **86 PASS**, 572 assertions |
| MySQL schema theo Local + 90.577 đơn giả lập | **PASS**: hash toàn bộ giá trị cột cũ trước/sau giống nhau; 5 cột mới null; không tự gán quyền; chạy migration lại không lỗi/không trùng |
| Kích hoạt trên MySQL TEST | **PASS**: list ban đầu 0; mẫu 349305 giả lập bị từ chối; đơn mới không vận đơn qua bulk/webhook lặp đúng 1 bản ghi; hoàn tất 200, replay true, đúng 1 audit; sai shop 403; tiền/số lượng không đổi |
| Backup/restore TEST | **PASS**: 90.578 đơn sau thêm 1 đơn mới, 8 bảng đối chiếu count/hash đều khớp |
| Frontend Kho | **7 PASS** |
| Production build / lint | **PASS**; lint vẫn 17 cảnh báo có sẵn |
| Full Backend | 456 PASS / **5 FAIL** (3.246 assertions), đúng các lỗi baseline đã ghi trong báo cáo PLAN 3 |
| Full Frontend | 73 PASS / **7 FAIL**, đúng các lỗi baseline đã ghi trong báo cáo PLAN 3 |
| Main Local sau toàn bộ kiểm tra | **PASS**: dữ liệu, schema, migration và fingerprint quyền/tài khoản/shop không đổi |

MySQL diễn tập: `pancake_warehouse_readiness_test_20261022`. Schema lấy từ định nghĩa Local, không sao chép dữ liệu thật; fixture bỏ foreign key để dựng độc lập. Có 90.577 đơn giả lập ban đầu; sau test thêm 1 đơn mới = 90.578. DDL ~0,236 giây; dump ~31,3 MB và restore ~2,838 giây. **Các số này không dự báo hiệu năng trên bảng thật có JSON lớn ~2,9 GB và tải đồng thời.**

Restore sang DB TEST mới `pancake_warehouse_restore_test_20261022`; dữ liệu TEST gốc và DB khôi phục đều giữ lại, không reset/drop. Bản backup chính thực tế chưa được tạo hoặc khôi phục trong lượt này.

5 lỗi Backend baseline thuộc OpportunitySecurityHardeningTest (4) và OrderSourceApiTest (1); 7 lỗi Frontend baseline thuộc customer care và order filters/source/page/contracts. Không phát sinh lỗi mới do sửa migration. Không tuyên bố toàn bộ ứng dụng sạch lỗi; các lỗi ngoài Kho vẫn cần được quản lý riêng.

## 5. Sao lưu và khôi phục đề xuất

**Chỉ là phương án, chưa chạy trên Local chính.**

Trước triển khai, sao lưu **toàn bộ pancake_order**, không chỉ bảng orders: cần cả migrations, audit, customers/products, counter virtual_stock, role/permission/shop_users và queue. Sao lưu source hai repo ở đúng working tree hiện tại, gồm file untracked PLAN 1/2/3; lưu `.env`, cấu hình process/cron, `storage/app/public` (ảnh sản phẩm), Passport keys và liên kết storage ở nơi hạn chế truy cập. Giữ nguyên APP_KEY/keys khi phục hồi; không đưa bí mật vào Git hoặc báo cáo.

Tất cả bảng đang InnoDB nên có thể dùng mysqldump single-transaction + quick. Trong lúc dump không chạy DDL/migration khác; kiểm tra exit code, dung lượng, checksum và khôi phục thử trước khi coi backup hợp lệ. Đây là yêu cầu của snapshot nhất quán trong [tài liệu mysqldump MySQL 8.4](https://docs.oracle.com/cd/E17952_01/mysql-8.4-en/mysqldump.html).

Ví dụ lệnh **để người triển khai chạy sau phê duyệt**, với login-path do người quản trị cấu hình sẵn; không ghi password vào lệnh:

```sh
mysqldump --login-path=pancake-local --single-transaction --quick \
  --routines --events --triggers --hex-blob --no-tablespaces \
  --set-gtid-purged=OFF --skip-add-drop-table pancake_order \
  > "$WAREHOUSE_BACKUP_DIR/pancake_order-before-warehouse.sql"
shasum -a 256 "$WAREHOUSE_BACKUP_DIR/pancake_order-before-warehouse.sql"
```

Thư mục backup riêng, chỉ người quản trị đọc; xác nhận đủ chỗ và lệnh đầu kết thúc thành công trước lệnh checksum. Không dump kèm `--databases`/CREATE DATABASE nếu định phục hồi vào schema khác. Với backup chính chứa views/routines/triggers/events có thể tham chiếu tên schema, ưu tiên khôi phục diễn tập trên **MySQL instance riêng, không kết nối vận hành**, giữ tên database `pancake_order`, không rewrite hàng loạt tên schema; tắt scheduler/workers và outbound ở instance khôi phục để không tạo side effect. Kiểm tra cấu trúc/FK, counts, số tiền/tồn, sample 349305, audit và phân quyền; mới cho phép triển khai. Bài diễn tập hiện tại chỉ khôi phục dữ liệu giả lập và không thay thế bước kiểm chứng backup thật.

Nếu lỗi sau triển khai:

1. Ngừng thao tác Kho và dừng các worker liên quan một cách graceful; tắt WAREHOUSE_ACTIVATED_AT và reload đúng config/process. Giữ schema mới và audit, giữ mốc T đã ghi nhận. Đây là phương án ngừng tính năng ưu tiên, không xóa dữ liệu.
2. Nếu chỉ lỗi code/config, phục hồi bản source/config đã lưu, giữ DB; rà soát các đơn trong khoảng gián đoạn trước khi duyệt mở lại. Không tự requeue lịch sử.
3. Nếu cần phục hồi DB, giữ nguyên DB lỗi và xuất dữ liệu/audit phát sinh sau backup để đối chiếu. Khôi phục vào instance/schema mới, kiểm chứng rồi chuyển kết nối sau duyệt; không ghi đè/xóa DB đang dùng. Không phục hồi snapshot cũ lên dữ liệu mới mà bỏ qua đơn, tiền và tồn phát sinh sau backup.
4. Binlog đang bật, retention cấu hình 30 ngày, GTID OFF. Chưa xác minh chuỗi binlog đầy đủ hoặc diễn tập PITR với backup thật; **không coi đây là khả năng phục hồi đã được chứng minh**. Nếu cần PITR, người quản trị phải ghi vị trí/thu thập binlog và dựng phục hồi riêng để chọn các giao dịch hợp lệ, tránh replay DDL/lỗi gây sự cố.

## 6. Thứ tự kích hoạt đề xuất — chưa thực hiện

1. **Duyệt riêng** danh sách tài khoản/role/shop, thời điểm T, thời gian bảo trì và phương án phục hồi. Chốt snapshot source hiện tại gồm mọi file untracked. Giữ T trống.
2. **Kiểm soát luồng ghi:** tạm ngừng POS tạo đơn, scheduler/bulk producers và cho worker đang chạy hoàn tất rồi dừng. Webhook phải được lưu bền vững/đệm và phát lại; không acknowledge 2xx khi chưa lưu. Nếu không có phương án giữ webhook hoặc retry được xác minh thì dừng triển khai. Không chỉ chạy artisan down rồi giả định upstream sẽ tự gửi lại; không xóa queue/jobs/failed_jobs.
3. **Backup toàn bộ + restore kiểm chứng** như mục 5; ghi baseline count/sample/quyền, schema, mốc và thời điểm chốt. Xác nhận không có DDL/transaction dài đang giữ orders. Tùy cơ chế đệm, các webhook đến sau snapshot phải được bảo toàn để khôi phục/đối soát khi có sự cố.
4. **Xác nhận DB đích pancake_order và PHP 8.4**, PLAN 2 đã applied, PLAN 3 chưa applied, schema không drift. Chạy **chỉ migration PLAN 3**, không migrate toàn bộ pending migration chưa được duyệt:

   ```sh
   /opt/homebrew/opt/php@8.4/bin/php artisan migrate \
     --path=database/migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php --force
   ```

   Theo dõi metadata lock/I/O/disk; nếu chờ lâu hoặc lỗi thì giữ module tắt, kiểm tra nguyên nhân và schema. Không tự chuyển ALGORITHM=COPY/rebuild bảng. Sau thành công kiểm tra migration history, 5 cột nullable, index, hai mục quyền; count/tiền/tồn/trạng thái cũ không đổi, queued_count vẫn 0. Bản sửa hỗ trợ chạy lại khi phần cột/index trước đó đã hoàn tất, nhưng chỉ một người/process triển khai migration.
5. **Đưa Backend/Frontend cùng phiên bản vào phục vụ**, vẫn giữ module tắt; API/UI phải báo tắt và không tạo queue. Không thay API_URL chính sang cổng TEST 8001.
6. **Cấp quyền theo danh sách đã duyệt** qua cơ chế hiện có; giữ nguyên quyền của người không thuộc phê duyệt. Gán shop_users cho Kho, không cấp admin thay cho scope shop. Kiểm tra hai API list/detail và thao tác hoàn tất bị từ chối với tài khoản ngoài phạm vi.
7. **Chốt T tương lai đủ thời gian triển khai xong**, độ chính xác đến giây với timezone +07:00; cập nhật WAREHOUSE_ACTIVATED_AT trên các process/API/worker liên quan. Nạp lại config theo cơ chế đang dùng (config cache nếu được sử dụng), restart worker/process bằng quản lý tiến trình thực tế trước T. Hiện CLI chưa cache config. Không chỉ sửa `.env` rồi để worker cũ tiếp tục dùng config cũ. Các queue cần được tiêu thụ gồm get-order-chunk, get-order và get-order-webhook; giữ nguyên các queue nghiệp vụ khác theo cấu hình đang chạy.
8. **Trước T**, kiểm tra API trả đúng T, enabled=false, configuration_valid=true; đồng hồ/app/MySQL cùng quy ước. Nếu chưa sẵn sàng trước T thì hoãn khi vẫn đang chặn ghi; không mở luồng bán để lại khoảng dữ liệu bị bỏ sót.
9. **Đến T**, xác nhận enabled=true trên HTTP và worker cùng phiên bản/config; mở lại luồng ghi/worker theo phương án đã duyệt. Theo dõi đơn mới thật phát sinh. Không sửa ngày của đơn cũ hoặc dùng POS 349305 làm đơn thao tác thử. Không chạy demo/backfill command.
10. **Theo dõi sau bật**, chỉ công bố hoàn tất khi các kiểm tra dưới đây đạt; nếu có lỗi thì dừng tính năng theo mục 5 và giữ bằng chứng.

Không tìm thấy cấu hình Supervisor/launchd/cron vận hành trong repo, nên không sáng tác tên service để restart. Người triển khai cần xác định đúng các tiến trình hiện có trước bước 7; đây là bước vận hành phải được chốt, không phải thay đổi ứng dụng cần viết thêm.

## 7. Cách kiểm tra sau kích hoạt

- Trang chính [http://localhost:5173/kho](http://localhost:5173/kho); TEST hiện tại [http://localhost:5174/kho](http://localhost:5174/kho). Hai môi trường phải dùng đúng API/database riêng.
- Tài khoản được duyệt thấy menu và shop đúng; tài khoản không quyền không thấy nút/menu và Backend trả 403. Đăng nhập lại sau cấp quyền để UI cập nhật.
- GET list trả enabled=true và T đúng; không có order lịch sử trước T. Truy vấn chỉ đọc xác nhận 349305 vẫn queued_at=null, tiền/trạng thái/update time cũ không đổi. Không POST vào mẫu thật.
- Theo dõi một POS mới và một Pancake mới thật sau T; nhãn nguồn, người tạo, ngày gốc, sản phẩm, số lượng, ghi chú đúng. Đơn cũ sync muộn hoặc không đủ ngày nguồn không vào queue. Đơn không vận đơn vẫn vào nếu hợp lệ.
- Nhân viên chỉ hoàn tất khi đã đóng gói thực tế. Kiểm tra packed_by_user_id/packed_at, đúng 1 audit; tab chờ mất đơn và tab đã đóng có đơn. Hoàn tất không đổi tiền/COD/virtual_stock hoặc tồn nguồn. Các request lặp/sync không tạo audit hay hàng đợi trùng.
- Sai shop phải 403 ở list lọc shop/detail/complete. Sau giao quyền, chỉ kiểm tra tình huống này bằng bài thử được duyệt; không thao tác đơn thật ngoài phạm vi để thử lỗi.
- Theo dõi log warehouse.review_required, webhook error, failed jobs, lock wait/timeout, thời gian API và queue lag. Đối chiếu trạng thái đồng bộ sau hủy, không tự hoàn tác đơn đã đóng.

## 8. Rủi ro còn lại

- DDL/index trên bảng thật lớn và transaction đang chạy có thể gây lock hoặc chậm; diễn tập nhỏ không đo được tải JSON thật.
- Backup chính/restore thực tế và vị trí quản lý worker chưa được thực hiện/chốt trong lượt chỉ kiểm tra này. Chỉ kích hoạt sau khi các bước triển khai tương ứng được duyệt và hoàn tất.
- Process/API/worker lệch mốc cấu hình hoặc đồng hồ có thể làm thiếu đơn; phải nạp config/restart đồng bộ trước T. Không tự đổi T sau vận hành.
- Dữ liệu nguồn không có ngày sinh đáng tin hoặc trạng thái chưa biết sẽ bị loại để kiểm tra, có thể cần xử lý thủ công được duyệt. Chưa gọi upstream live để xác minh thêm.
- 5 lỗi Backend và 7 lỗi Frontend ngoài Kho từ baseline vẫn tồn tại.
- Hủy sau đóng, hoàn tiền/vận đơn/tồn vật lý, mở lại queue chưa được quản lý chốt; tiếp tục chỉ log và giữ kết quả đóng Local.

## 9. File thay đổi trong lượt readiness này

- Sửa [migration PLAN 3](/Users/viet/Pancake/pancake_order/database/migrations/2026_10_21_000002_add_warehouse_completion_and_source_time.php): an toàn khi chạy lại sau DDL một phần, kiểm tra schema, catalog transaction.
- Sửa [WarehouseTest.php](/Users/viet/Pancake/pancake_order/tests/Feature/WarehouseTest.php): thêm test retry migration và kích hoạt/replay/source microseconds/mẫu 349305 trên SQLite riêng.
- Tạo báo cáo này và [bằng chứng JSON](/Users/viet/Pancake/pancake_order/docs/plan-3-readiness-evidence.json); thêm liên kết ở báo cáo PLAN 3 cũ.
- Frontend không sửa code; chỉ chạy lại tests/build/lint và kiểm tra UI trên TEST.

Các script/log diễn tập nằm trong `/tmp/pancake-warehouse-readiness-*`; bằng chứng tổng hợp đã lưu vào docs để không phụ thuộc tệp tạm. Không có script tự kích hoạt database chính.
