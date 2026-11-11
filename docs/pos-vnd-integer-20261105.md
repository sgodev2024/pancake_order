# POS: tiền nguyên VND — 05/11/2026

Branch: `viet_dev`. Trang: `http://localhost:5173/ban-hang`.

## Nguyên nhân

- `InputNumber precision={2}` dùng mô hình số thập phân. `formatMoneyInput` còn tách dấu chấm như phần thập phân và có thể hiển thị `0,00`, trong khi parser chỉ cho phép tối đa 12 chữ số phần nguyên.
- Khi gõ nối vào chuỗi đã format, ví dụ `1.000` + `0` thành chuỗi tạm `1.0000`, parser/validation hàng nghìn nghiêm ngặt từ chối. `InputNumber` có thể giữ giá trị hợp lệ cũ, trong khi `onInput` tiếp tục báo lỗi từ chuỗi hiển thị. Validator còn kiểm tra giá trị Form nên có hai trạng thái lỗi khác nhau.
- `posTotals.js` tính theo phần trăm đơn vị tiền; API cho phép `decimal:0,2`. Đây là quy ước không phù hợp cho POS chỉ dùng VND nguyên.

## Sửa

- Dùng chung `VndMoneyInput` (Ant Design `Input` dạng text) cho ba khoản điều chỉnh. Giá trị Form là chữ số thuần hoặc nguyên văn dữ liệu lỗi; không giữ ngầm số tiền hợp lệ trước đó.
- Nhập `1000`, `10000`, `100000`, `1000000` hiển thị `1.000 đ`, `10.000 đ`, `100.000 đ`, `1.000.000 đ`. Dấu `đ` là suffix riêng.
- Gõ/chèn/xóa chữ số định dạng lại ngay; giữ con trỏ theo số chữ số trước con trỏ. Xử lý Backspace/Delete cạnh dấu phân cách, xóa trắng và số 0 ban đầu.
- Dán chuỗi có dấu chấm phải đúng nhóm hàng nghìn; `100.000 đ` thành `100000`. Không loại bỏ âm thầm ký tự lạ, dấu âm, dấu phẩy hoặc số lẻ. `1.2`, `0.10`, `1000.00` là lỗi trên UI.
- Điều chỉnh không bắt buộc; trống/null/undefined bằng 0. Tổng UI dùng số nguyên VND trực tiếp. JSON gửi số nguyên cho điều chỉnh, đơn giá và tổng xác nhận; không gửi ký hiệu tiền hay dấu phân cách.
- API tạo đơn yêu cầu chữ số thuần, không âm, tối đa `999999999999` VND cho tất cả khoản tiền. Không nhận chuỗi tiền đã format, số mũ hay phần lẻ. Service cũng chặn giá sản phẩm lẻ, không làm tròn chúng.
- Tổng = tiền hàng − giảm giá + phí vận chuyển + phụ thu. Luồng xác nhận đã thu đủ, COD và API thanh toán một phần + COD được giữ nguyên.

## Tương thích dữ liệu cũ

Không đổi schema, không migration/backfill, không sửa đơn lịch sử. Backend giữ phép tính chính xác và chuỗi lưu/trả `.00` cho các consumer hiện có và hash chống trùng cũ. Đây là định dạng lưu tương thích, không phải cho phép nhập số lẻ. Catalog có hậu tố lưu `.00` được chuyển thành đơn giá nguyên khi gửi. Receipt vẫn đọc được cả snapshot cũ có phần lẻ; không làm tròn hoặc tái tính đơn cũ khi mở xem.

## Xác minh

- 46/46 kiểm tra Frontend POS đạt: định dạng/parse, gõ liên tục, caret khi chèn/xóa, số tiền sai, blank, tổng, COD, thu đủ, xác nhận/retry và payload số nguyên.
- 229/229 kiểm tra Backend liên quan đạt (2201 assertions): POS, sản phẩm, Kho, phân quyền, AICRM. Test dùng SQLite `:memory:` và chặn request ngoài; không tạo đơn hay vận đơn trong DB Local đang dùng.
- Trình duyệt: component thật + Ant Design Form thật trong trang kiểm tra tạm, không gọi API tạo đơn. Cả ba ô gõ từng chữ số đến hàng triệu đạt, bao gồm gõ tiếp từ 0. Đã kiểm tra sửa giữa chuỗi, xóa cạnh dấu chấm, dán `100.000 đ`, dữ liệu âm/ký tự/số lẻ và validation. Trang kiểm tra tạm đã xóa.
- Build Frontend đạt. Lint phạm vi POS không có cảnh báo/lỗi mới.
- Chạy rộng Frontend: 139/140 đạt; 1 test sẵn có trong `customerCareActionParity.test.js` lỗi do cắt source modal bằng tiêu đề literal không còn hiện diện. Cả test và source CSKH không có thay đổi so với HEAD.
- Lint toàn Frontend có 18 cảnh báo ở các file ngoài phạm vi. Pint đánh dấu style có sẵn của `OrderController.php`; đã kiểm tra bản HEAD riêng có cùng danh sách fixer. Service và test POS đạt Pint.

Lệnh kiểm tra chính (chạy Frontend tại thư mục `frontend`):

```sh
node --test tests/pos.test.js tests/posShopSelection.test.js
node --test --test-force-exit tests/*.test.js
npm run build
npx oxlint src/pages/Pos
```

Backend (từ root repo, dùng PHP 8.4 thay cho PHP 7.4 mặc định trên máy):

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: /opt/homebrew/opt/php@8.4/bin/php -d memory_limit=512M vendor/bin/phpunit tests/Feature/PosOrderTest.php tests/Feature/ProductPlanOneTest.php tests/Feature/WarehouseTest.php tests/Feature/PrivilegeHardeningAndShopAccessTest.php tests/Feature/AicrmShipmentTest.php tests/Unit/ProductTest.php
```
