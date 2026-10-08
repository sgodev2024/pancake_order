# Triển khai repository gộp

Backend Laravel nằm ở gốc; frontend React/Vite ở frontend/. Mã snapshot được lấy từ server production ngày 08/10/2026. Frontend upstream trước khi gộp: vietit132006/pancake_frontend_v2, HEAD 70d54a1. Backend baseline: 61b65e8 cùng các thay đổi đang chạy trên server.

Không commit .env, token, database dump, storage, vendor, frontend/node_modules hoặc frontend/dist.

Server hiện phục vụ /var/www/html/pancake_order/frontend/dist qua Nginx cổng 8081. Laravel API cổng 8080 và queue workers vẫn thuộc backend. Zalo Chat cần Java Zalo upstream/API key production riêng; không có bộ giả lập hay tài khoản demo trong release.

Clone repository này có đầy đủ frontend source. Cài dependencies bằng composer install và npm ci trong frontend, sau đó npm run build. Giữ .env và APP_KEY production; không chạy seeders mặc định/test và không dùng cấu hình database local.

Triển khai có thay đổi schema phải sao lưu, chạy migrate --force trong cửa sổ bảo trì, kiểm thử endpoint báo cáo/Zalo/webhook và queue workers. Copy assets mới trước, thay index.html sau; giữ assets cũ trong giai đoạn chuyển release.

Checkout server đang chạy vẫn giữ metadata Git frontend riêng để bảo toàn lịch sử cũ. Khi chuyển quy trình triển khai sang monorepo main, dùng checkout/staging mới; không git pull trực tiếp vào checkout legacy mà chưa sao lưu metadata và mã local.

Đánh giá trước tối ưu: docs/frontend-assessment-2026-10-08.md.
