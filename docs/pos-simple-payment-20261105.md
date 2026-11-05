# POS — đơn giản hóa thanh toán

Ngày hoàn tất: 05/11/2026. Dự án `pancake_order`, branch `viet_dev`. Chỉ chỉnh và kiểm thử Local; không commit/push.

## Hành vi đã triển khai

- Bỏ ô nhập **Khách đã thanh toán** và select **Thu hộ COD** riêng.
- Một select **Hình thức thanh toán**: **Tiền mặt — đã thu đủ**, **Chuyển khoản — đã nhận đủ**, **Thu hộ COD — thu khi giao hàng**. Mặc định COD để không tự ghi nhận tiền đã nhận.
- **Giảm giá đơn**, **Phí vận chuyển thu khách**, **Phụ thu** không bắt buộc, không dấu sao; thiếu/null/chuỗi rỗng hoặc chỉ khoảng trắng chuẩn hóa thành 0. Vẫn kiểm tra tiền không âm, đúng định dạng, tối đa hai chữ số thập phân và giới hạn tiền hiện có.
- Hiển thị tiền hàng, tổng thanh toán, đã thanh toán, còn phải thu và COD dự kiến.
- Tiền mặt/chuyển khoản: bấm **Tạo đơn hàng** mở popup có số tiền, nút **Đã nhận đủ tiền** và **Chưa nhận tiền**. Hủy không gửi yêu cầu tạo đơn. Trước xác nhận, đã thanh toán vẫn bằng 0 và còn phải thu bằng tổng; COD dự kiến bằng 0 theo phương thức đã chọn.
- Thay đổi bản nháp trong lúc popup mở làm xác nhận cũ mất hiệu lực; đổi tài khoản/shop hủy popup. Chống nhấn xác nhận nhiều lần. Khi mất kết quả mạng, gửi lại cùng nội dung và khóa chống trùng, không tự gửi lại hay tự xác nhận tiền lần nữa.

## Công thức và hợp đồng API

Backend tính bằng số nguyên theo đơn vị 1/100 đồng, lấy giá sản phẩm hiện hành từ DB trong transaction:

`T = Σ(số lượng × giá DB) − giảm giá + phí vận chuyển thu khách + phụ thu`

Giảm giá không vượt tiền hàng. Giá phía trình duyệt chỉ dùng để phát hiện giá đã thay đổi, không quyết định giá lưu.

| Phương thức | Điều kiện | paid_amount | outstanding | COD |
| --- | --- | ---: | ---: | ---: |
| Tiền mặt | Xác nhận đã thực sự thu đủ | T | 0 | 0 |
| Chuyển khoản | Xác nhận đã thực sự nhận đủ | T | 0 | 0 |
| Thu hộ COD | Thu khi giao hàng | 0 | T | T |

Frontend mới gửi `payment_mode = cash | bank_transfer | cod`. Cash/bank_transfer phải có `payment_confirmed=true` và `confirmed_total` khớp tổng Backend tính lại. Backend từ chối xác nhận thiếu/sai hoặc giá thay đổi, không tạo đơn/ghi khoản thu. COD không được giả mạo trạng thái đã thu hoặc gửi tổng xác nhận.

Không cho trộn giao thức mới với `paid_amount`, `payment_method`, `cod_mode`. Client cũ không gửi `payment_mode` vẫn dùng giao thức thanh toán một phần hiện có; không xóa dữ liệu cũ. Kiểm thử đóng băng hash yêu cầu cũ bảo đảm phát lại cùng khóa chống trùng vẫn trả đơn cũ.

Xác nhận trong ứng dụng là thao tác nghiệp vụ của nhân viên, không phải đối soát ngân hàng tự động.

## File sửa trong lần này

| File | Nội dung |
| --- | --- |
| `app/Http/Controllers/Api/OrderController.php` | Chuẩn hóa ba khoản tùy chọn, validation giao thức thanh toán mới; giữ giao thức cũ |
| `app/Services/OrderService.php` | Tính paid/COD phía server, kiểm tra xác nhận và tổng tiền, giữ hash yêu cầu cũ |
| `frontend/src/pages/Pos/PosPage.jsx` | Form một select, ba khoản tùy chọn, tổng tiền, popup xác nhận, kiểm soát bản nháp/gửi lại |
| `frontend/src/pages/Pos/posTotals.js` | Công thức theo phương thức và trạng thái xác nhận, chuẩn hóa khoản trống |
| `frontend/src/pages/Pos/posMoneyInput.js` | Giữ dữ liệu nhập sai để báo lỗi, kiểm tra định dạng tiền |
| `tests/Feature/PosOrderTest.php` | Ma trận vai trò/phương thức, validation, số thập phân, tương thích và Kho/COD |
| `frontend/tests/pos.test.js` | Tính tiền, ba phương thức, khoản trống và định dạng |
| `frontend/tests/posShopSelection.test.js` | Form, popup, hủy/xác nhận, đổi tài khoản, lỗi tiền, chống trùng/gửi lại |
| `docs/pos-simple-payment-20261105.md` | Báo cáo này |

Các thay đổi Local có sẵn từ những yêu cầu trước được giữ nguyên. Không sửa chính sách phân quyền, mật khẩu, cấu hình shop, logic Kho, tồn ảo hoặc dịch vụ AICRM/vận chuyển trong lần này.

## Kết quả kiểm thử

| Hạng mục | Kết quả | Bằng chứng/phạm vi |
| --- | --- | --- |
| Backend liên quan | PASS | 210 tests, 2.024 assertions; POS, sản phẩm, Kho, phân quyền/shop, AICRM shipment |
| Frontend liên quan | PASS | 73 tests, 0 fail; POS, chọn shop, cách ly tài khoản, API sản phẩm, Kho và vận chuyển |
| Sale thường × 3 phương thức | PASS | Request qua route/controller/service thật trong Feature tests, DB SQLite cô lập; kiểm tra người tạo/shop, tiền, chống trùng và Kho chờ đóng hàng |
| CSKH thường × 3 phương thức | PASS | Cùng ma trận và kiểm tra trên |
| Admin × 3 phương thức | PASS | Cùng ma trận và kiểm tra trên |
| Tiền mặt/chuyển khoản thiếu xác nhận hoặc tổng không khớp | PASS | Backend từ chối, không tạo đơn; không tin tổng/giá Frontend |
| COD và trộn trường thanh toán | PASS | COD bằng tổng, không có khoản đã thu; giả mạo đã thu/trộn giao thức bị từ chối |
| Ba ô tiền trống, âm/sai định dạng, phần thập phân | PASS | Cả Frontend và Backend; khoản trống = 0; số sai bị chặn |
| Thanh toán một phần/API và hash cũ | PASS | Luồng cũ được giữ và phát lại đúng đơn, không tạo trùng |
| AICRM COD/tồn ảo/Kho/phạm vi shop | PASS | Bộ hồi quy liên quan; kiểm tra payload AICRM không tạo vận đơn thật |
| Lint Frontend | PASS | Exit 0; 18 cảnh báo có sẵn ngoài file sửa. Lint riêng file sửa không có cảnh báo |
| Build Frontend | PASS | Build production bundle thành công trên máy Local |
| PHP style và diff | PASS | Pint OrderService/PosOrderTest và `git diff --check` |

Backend chạy PHP 8.4, `DB_CONNECTION=sqlite DB_DATABASE=:memory:`. Test chặn request ngoài ý muốn và cô lập queue; không dùng HTTP mock làm bằng chứng tạo đơn MySQL Local.

Các lệnh kiểm thử chính đã chạy:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: /opt/homebrew/opt/php@8.4/bin/php -d memory_limit=512M vendor/bin/phpunit tests/Feature/PosOrderTest.php tests/Feature/ProductPlanOneTest.php tests/Feature/WarehouseTest.php tests/Feature/PrivilegeHardeningAndShopAccessTest.php tests/Feature/AicrmShipmentTest.php
# Trong frontend/
node --test --test-force-exit tests/pos.test.js tests/posShopSelection.test.js tests/shopOptions.test.js tests/authQueryIsolation.test.js tests/productsApi.test.js tests/warehouse.test.js tests/viettelPostShipment.test.js
npm run lint
npm run build
```

## Kiểm tra giao diện và MySQL Local

- Trang Local `http://localhost:5173/ban-hang`, Backend loopback port 8002. Đã xem form Admin 1 và thao tác bằng CSKH TEST 641; shop 38 tự chọn theo danh sách quyền hiện có, tải sản phẩm và khách hàng được phân công.
- Với một sản phẩm TEST giá 1.000 đồng, ba ô tiền thực sự để trống: tổng/còn phải thu/COD = 1.000 đồng, đã thanh toán = 0. Không có dấu sao ở ba ô tùy chọn; nút tạo đơn hợp lệ.
- Nhập giảm giá -1: hiện lỗi và vô hiệu hóa nút tạo đơn. Sửa giảm giá 100, ship 200, phụ thu 50: tổng/còn phải thu/COD = 1.150 đồng khi chọn COD.
- Chọn tiền mặt hoặc chuyển khoản: đã thanh toán = 0, còn phải thu = 1.000 đồng, COD dự kiến = 0 trước xác nhận. Cả hai mở đúng popup khi bấm tạo đơn; đã chọn **Chưa nhận tiền** để kiểm tra hủy. Không xác nhận nhận tiền khi chưa thực nhận.
- Thành công sau xác nhận và tạo đơn theo cả ba phương thức đã kiểm tra tự động trong DB cô lập. Chưa tạo đơn hoặc khoản thu mới trong MySQL Local cho lần thay đổi này; không coi preview/popup là bằng chứng đơn đã lưu MySQL. Sale kiểm tra bằng ma trận tự động, chưa thao tác thủ công lại bằng tài khoản Sale.
- Đã tải lại trang để bỏ giỏ hàng nháp sau kiểm thử.
- Đối chiếu Local trước/sau: **90.593 đơn**, ID lớn nhất **349321**, **0 vận đơn AICRM**, **4 jobs**, tất cả không đổi. Checksum các bảng users/roles/permissions/role_permissions/shop_users/products không đổi.
- Không thu tiền, không giao hàng, không gọi API vận chuyển thật, không thay đổi Production, không commit/push.

Bản sao source trước chỉnh, log kiểm thử và bằng chứng nằm ở `/Users/viet/Pancake/.local-backups/pos-simple-payment-20261104/` (tên thư mục theo ngày bắt đầu công việc). Ảnh: `payment-cod.png`, `payment-empty-adjustments.png`, `payment-confirmation.png`. Bản đối chiếu: `local-before.json`, `local-after.json`; `unchanged-services.json` ghi checksum các dịch vụ/cấu hình liên quan không đổi.
