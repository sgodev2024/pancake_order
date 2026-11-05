# Pancake Frontend V2

Frontend React/Vite của hệ thống Pancake, được phát triển cùng backend Laravel trong repository `pancake_order`.

## Chạy local

```bash
cd frontend # từ thư mục gốc repository pancake_order
npm ci
export LOCAL_API_PROXY=http://127.0.0.1:8002
npm run dev
```

Ứng dụng chạy tại `http://localhost:5173` và đọc API base URL từ `VITE_API_URL`.

Tạo `frontend/.env` cho môi trường local với `VITE_API_URL=http://127.0.0.1:8002/api/v1` khi backend chạy ở cổng 8002. Biến `LOCAL_API_PROXY` cấu hình proxy `/api` của Vite. Điều chỉnh hai địa chỉ này nếu dùng cổng backend khác.

Trên workspace `/Users/viet/Pancake`, script `../.local-runtime/frontend.sh` (tính từ gốc repository) đã trỏ vào frontend này và cấu hình API/proxy cổng 8002. Hai script local hỗ trợ biến `PANCAKE_API_PORT` để đổi cổng backend. Phát triển FE tại `frontend/src` và kiểm thử tại `frontend/tests`; commit các thay đổi cùng backend trong repository này.

## Các chức năng đã chuyển vào repo chung

- `/san-pham`: quản lý sản phẩm, ảnh và tồn kho ảo.
- `/ban-hang`: bán hàng POS, giỏ hàng, thanh toán và xem đơn đã tạo.
- `/kho`: danh sách chờ/đã đóng hàng, chi tiết và hoàn tất đóng hàng theo quyền.
- Trang đơn hàng hiển thị đơn POS và liên kết xem chi tiết POS; header hiển thị nút POS cho tài khoản có quyền.

Backend phải có schema, cấu hình module và quyền tương ứng. Việc chuyển source FE không tự chạy migration, bật module hoặc cấp quyền. Xem `../docs/frontend-monorepo-migration-2026-11-04.md`.

## Kiểm tra build

```bash
npm run lint
npm run build
```

Không đưa token, mật khẩu hoặc production API URL vào source hay file môi trường được commit.
