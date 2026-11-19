export const shipmentConfirmation = (order, mode, onOk) => {
  let submitted
  return {
    title: mode === 'simulation' ? `Xem trước payload TEST cho đơn ${order.reference}?` : `Tạo vận đơn Viettel Post cho đơn ${order.reference}?`,
    content: mode === 'simulation'
      ? 'Xem dữ liệu lấy từ đơn trên Pancake Local. Hệ thống ghi lịch sử xem trước.'
      : mode === 'live'
      ? 'Bạn đang tạo VẬN ĐƠN THẬT qua AICRM Live. Chỉ xác nhận với đơn đã được phê duyệt, người nhận và địa chỉ thật đã thống nhất, COD và tổng tiền đã kiểm tra. Vận đơn có thể phát sinh phí vận chuyển.'
      : mode === 'local'
      ? 'Gửi đơn tới API AICRM DEV đã xác minh dùng Sandbox/mock. Hãy kiểm tra người nhận, sản phẩm, COD và tổng tiền trước khi gửi.'
      : mode !== 'sandbox'
      ? 'Chế độ Mock: chỉ mô phỏng trên Local. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ của đơn.'
      : 'Chế độ Sandbox: gửi dữ liệu đơn tới API AICRM đã cấu hình. Xác nhận thông tin người nhận, sản phẩm và tiền thu hộ trước khi gửi.',
    okText: mode === 'simulation' ? 'Xem payload' : mode === 'live' ? 'Xác nhận tạo vận đơn thật'
      : mode === 'local' ? 'Gửi tới AICRM DEV' : 'Tạo vận đơn',
    cancelText: 'Kiểm tra lại',
    // A confirmation is single-use, including when its result is uncertain.
    onOk: () => (submitted ??= Promise.resolve().then(onOk)),
  }
}
