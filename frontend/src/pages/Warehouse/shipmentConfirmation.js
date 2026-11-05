export const shipmentConfirmation = (order, mode, onOk) => ({
  title: `Tạo vận đơn Viettel Post cho đơn ${order.reference}?`,
  content: mode === 'live'
    ? 'Bạn đang tạo VẬN ĐƠN THẬT qua AICRM Live. Chỉ xác nhận với đơn đã được phê duyệt, người nhận và địa chỉ thật đã thống nhất, COD và tổng tiền đã kiểm tra. Vận đơn có thể phát sinh phí vận chuyển.'
    : mode !== 'sandbox'
    ? 'Chế độ Mock: chỉ mô phỏng trên Local. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ của đơn.'
    : 'Chế độ Sandbox: gửi dữ liệu đơn tới API AICRM đã cấu hình. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ trước khi gửi.',
  okText: mode === 'live' ? 'Xác nhận tạo vận đơn thật' : 'Tạo vận đơn', cancelText: 'Kiểm tra lại', onOk,
})
