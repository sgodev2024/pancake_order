# AICRM Live — hướng dẫn hiện hành

Dùng [hướng dẫn theo source Java/Spring Boot](aicrm-api-key-integration.md) và
[báo cáo sửa tích hợp](aicrm-source-integration-2026-11-04.md), đối chiếu commit
`7dfb3be375e3b19e2656a014b440b07190a8bc6d` ngày 04/11/2026.

Các chỉ dẫn cũ về Bearer, tenant_mode=header/token, X-Tenant-ID,
ORDER_ID_RESPONSE_PATH và ORDER_STATUS_RESPONSE_PATH đã được thay thế.
Chỉ dùng X-API-Key; tenant nằm trong scope key. POST không trả UUID AICRM và
status=200 chỉ là kết quả API, không phải trạng thái vận chuyển.

Live và tạo thật vẫn OFF. Tạo thật vẫn cần phê duyệt đúng một đơn mới,
mốc activated_at, hợp đồng và quy trình đối soát đã xác nhận. Không tự retry POST;
unknown/processing khóa gửi lại. Chỉ thêm khả năng GET đối soát UUID cho shipment
đã thành công có tracking, không tự giải phóng unknown.

Các kết quả thăm dò trước đây được giữ như bằng chứng lịch sử trong
[aicrm-viettel-post-live-validation.md](aicrm-viettel-post-live-validation.md) và
[aicrm-api-research-2026-11-04.md](aicrm-api-research-2026-11-04.md).
Chúng không chứng minh đã kết nối có xác thực hoặc tạo vận đơn thật.
