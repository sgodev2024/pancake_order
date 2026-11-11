export const shipmentConfirmation = (order, mode, onOk) => {
  let submitted
  return {
    title: mode === 'simulation' ? `Mô phỏng TEST/Mock cho đơn ${order.reference}?` : `Tạo vận đơn Viettel Post cho đơn ${order.reference}?`,
    content: mode === 'simulation'
      ? 'TEST/Mock: chỉ kiểm tra luồng trên Pancake Local và ghi lịch sử mô phỏng. Không gọi AICRM, không tạo shipment hoặc mã vận đơn. Không thay đổi thông tin đơn.'
      : mode === 'live'
      ? 'Bạn đang tạo VẬN ĐƠN THẬT qua AICRM Live. Chỉ xác nhận với đơn đã được phê duyệt, người nhận và địa chỉ thật đã thống nhất, COD và tổng tiền đã kiểm tra. Vận đơn có thể phát sinh phí vận chuyển.'
      : mode !== 'sandbox'
      ? 'Chế độ Mock: chỉ mô phỏng trên Local. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ của đơn.'
      : 'Chế độ Sandbox: gửi dữ liệu đơn tới API AICRM đã cấu hình. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ trước khi gửi.',
    okText: mode === 'simulation' ? 'Chạy mô phỏng TEST/Mock' : mode === 'live' ? 'Xác nhận tạo vận đơn thật' : 'Tạo vận đơn',
    cancelText: 'Kiểm tra lại',
    // A confirmation is single-use, including when its result is uncertain.
    onOk: () => (submitted ??= Promise.resolve().then(onOk)),
  }
}
