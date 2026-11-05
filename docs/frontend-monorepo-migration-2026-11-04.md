# Phát triển frontend trong repo chung — 04/11/2026

Theo yêu cầu người dùng, frontend chính là `frontend/` trong repository `pancake_order`, cùng nhánh `viet_dev` với backend. Repository FE độc lập ở `../pancake_frontend_v2` được giữ nguyên để tham khảo, không còn được script local sử dụng.

## Nguồn và phạm vi chuyển

- Nguồn: repo FE độc lập tại commit `68f5b2d` (working tree sạch trước khi chuyển).
- Đích: `frontend/` trong repo chung trên nhánh `viet_dev`, commit nền `ae1436d`.
- Chuyển trang POS, trang Kho, quản lý sản phẩm/ảnh/tồn kho ảo và tiện ích sản phẩm.
- Chuyển API tạo/xem đơn, API tạo/sửa sản phẩm, API Kho; tích hợp route, menu, header, phân quyền và hiển thị đơn POS.
- Chuyển khả năng khóa ShopSelect khi đang tạo đơn và export ProductCell dùng chung cho chi tiết Kho.
- Giữ source CustomerCare, Zalo và Dashboard đang có trong repo chung; không chép đè toàn bộ cây source từ FE cũ.
- Chuyển các test POS, sản phẩm, Kho và hồi quy Dashboard. Test Kho được sửa assertion theo nhãn nút thực tế `Hoàn tất` trong danh sách; nội dung xác nhận vẫn là `Hoàn tất đóng hàng`. Các test chỉ xem/không có quyền đóng hàng cũng kiểm tra đúng nhãn nút này.

## Chạy và phát triển

Từ workspace `/Users/viet/Pancake`:

```sh
./.local-runtime/backend.sh
# Terminal khác:
./.local-runtime/frontend.sh
```

FE tại `http://localhost:5173`, API tại `http://127.0.0.1:8002/api/v1`. Cổng 8000 đang do dự án Travelviet sử dụng; giữ nguyên tiến trình đó và dùng cổng 8002 riêng cho Pancake. Hai script local hỗ trợ `PANCAKE_API_PORT` (mặc định 8002); script FE đặt API URL và proxy tương ứng, có thể ghi đè bằng `VITE_API_URL` và `LOCAL_API_PROXY`.

`frontend/.env` dùng API local cổng 8002 và được Git ignore. Dependencies cài bằng `npm ci` theo lockfile hiện có. Phát triển tại `frontend/src`, kiểm thử tại `frontend/tests`; commit cùng các thay đổi backend trong repository này.

Các đường dẫn: `/san-pham`, `/ban-hang`, `/kho`, `/don-hang`. POS và Kho dùng quyền hiện có do backend kiểm tra; chuyển source không tự cấp thêm quyền.

## Kiểm chứng

- Baseline trước khi chuyển: build/lint thành công; 66/67 test đạt. Test CSKH `completion modal has V1 fields, validation, exact payload and reset/refresh behavior` đã thất bại từ trước.
- Sau khi chuyển: build/lint thành công; 90/91 test đạt. Toàn bộ 24 test POS/sản phẩm/Kho/Dashboard chuyển sang đã đạt; chỉ còn lỗi CSKH từ baseline.
- Vite đã được xác nhận chạy từ `pancake_order/frontend`; các trang `/`, `/ban-hang`, `/kho` và module POS/Kho/sản phẩm trả HTTP 200.
- Backend Pancake cổng 8002: `/up` trả HTTP 200; `/api/v1/me` không có token trả HTTP 401 trực tiếp và qua proxy Vite. API client đã xác minh dùng đúng cổng 8002. Chưa kiểm thử tạo đơn/đóng hàng bằng tài khoản thật trong lần chuyển này.

## Phạm vi Git và dữ liệu

Không merge `origin/main` hoặc `origin/tuantu_dev` trong lần chuyển FE này. Theo lần fetch ngày 04/11/2026, `viet_dev` repo chung vẫn thiếu 4 commit từ hai nhánh đó. Cần tích hợp các thay đổi mới với FE đã chuyển trong bước đồng bộ tiếp theo.

Không sửa source backend, lockfile, cấu hình production hoặc database; không chạy migration, tạo đơn, đóng hàng, bật module, cấp quyền, commit hoặc push. Chỉ đổi script chạy local để dùng cổng riêng. Việc kiểm tra FE không thay thế kiểm thử nghiệp vụ trên môi trường có schema/quyền phù hợp.
