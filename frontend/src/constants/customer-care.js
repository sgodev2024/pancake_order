export const CUSTOMER_CARE_PAGE_SIZE = 30

export const CUSTOMER_CARE_TYPES = Object.freeze({
  today: {
    apiType: 'customer_care_today',
    title: 'KH chăm sóc hôm nay',
    description: 'Danh sách khách hàng cần được chăm sóc trong ngày hôm nay.',
    emptyMessage: 'Không có khách hàng cần chăm sóc hôm nay.',
  },
  upcoming: {
    apiType: 'customer_care_pending',
    title: 'Lịch chăm sóc sắp diễn ra',
    description: 'Theo dõi các lịch chăm sóc sau ngày hôm nay.',
    emptyMessage: 'Không có lịch chăm sóc sắp diễn ra.',
  },
  overdue: {
    apiType: 'customer_care_expire',
    title: 'Khách chăm sóc quá hạn',
    description: 'Các lịch chưa hoàn tất hoặc được chăm sóc sau hạn đã lên lịch.',
    emptyMessage: 'Không có khách hàng chăm sóc quá hạn.',
  },
  editRequests: {
    apiType: 'customer_care_edit',
    title: 'Yêu cầu sửa CSKH',
    description: 'Các lượt chăm sóc có lịch sử chỉnh sửa theo điều kiện của hệ thống.',
    emptyMessage: 'Không có yêu cầu sửa CSKH phù hợp.',
  },
})

export const CUSTOMER_CARE_STATUS_OPTIONS = Object.freeze([
  { value: '0', label: 'Chưa chăm sóc', color: 'gold' },
  { value: '1', label: 'Đã chăm sóc', color: 'green' },
])

export const CUSTOMER_CARE_APPROVAL_OPTIONS = Object.freeze([
  { value: '0', label: 'Chưa duyệt', color: 'orange' },
  { value: '1', label: 'Đã duyệt', color: 'green' },
])

const findOption = (options, value, fallbackLabel) => {
  if (value === null || value === undefined || value === '') {
    return { label: '—', color: 'default' }
  }

  const normalizedValue = String(value)
  return (
    options.find((option) => option.value === normalizedValue) ?? {
      label: `${fallbackLabel} (${normalizedValue})`,
      color: 'default',
    }
  )
}

export const getCustomerCareStatus = (value) =>
  findOption(CUSTOMER_CARE_STATUS_OPTIONS, value, 'Chưa xác định')

export const getCustomerCareApproval = (value) =>
  findOption(CUSTOMER_CARE_APPROVAL_OPTIONS, value, 'Chưa xác định')
