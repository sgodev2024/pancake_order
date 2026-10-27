export const ORDER_STATUSES = Object.freeze([
  { value: '0', label: 'Mới', color: 'blue' },
  { value: '17', label: 'Chờ xác nhận', color: 'gold' },
  { value: '11', label: 'Chờ hàng', color: 'orange' },
  { value: '12', label: 'Chờ in', color: 'cyan' },
  { value: '13', label: 'Đã in', color: 'geekblue' },
  { value: '20', label: 'Đã đặt hàng', color: 'purple' },
  { value: '1', label: 'Đã xác nhận', color: 'green' },
  { value: '8', label: 'Đang đóng hàng', color: 'processing' },
  { value: '9', label: 'Chờ chuyển hàng', color: 'orange' },
  { value: '2', label: 'Đã gửi hàng', color: 'lime' },
  { value: '3', label: 'Đã nhận', color: 'success' },
  { value: '16', label: 'Đã thu tiền', color: 'gold' },
  { value: '4', label: 'Đang hoàn', color: 'magenta' },
  { value: '15', label: 'Hoàn một phần', color: 'volcano' },
  { value: '5', label: 'Đã hoàn', color: 'red' },
  { value: '6', label: 'Đã hủy', color: 'red' },
  { value: '7', label: 'Đã xóa', color: 'default' },
])

export const ORDER_PAGE_SIZES = Object.freeze([10, 20, 30, 50, 100])

export const DEFAULT_ORDER_PAGE_SIZE = 30

export const getOrderStatus = (status) => {
  if (status === null || status === undefined || status === '') {
    return { value: '', label: '—', color: 'default' }
  }

  const value = String(status)
  return ORDER_STATUSES.find((item) => item.value === value) ?? {
    value,
    label: `Chưa xác định (${value})`,
    color: 'default',
  }
}
