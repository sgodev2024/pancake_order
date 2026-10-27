export const ACTIVITY_ACTIONS = Object.freeze([
  { value: 'customer.entered_system', label: 'Khách hàng vào hệ thống', color: 'blue' },
  { value: 'customer_care.assigned', label: 'Phân công CSKH', color: 'blue' },
  { value: 'customer_care.reassigned', label: 'Phân công lại CSKH', color: 'purple' },
  { value: 'customer_care.reclaimed', label: 'Thu hồi CSKH', color: 'gold' },
  { value: 'customer_care.completed', label: 'Hoàn thành chăm sóc', color: 'green' },
  { value: 'order.created', label: 'Phát sinh đơn hàng', color: 'orange' },
])

export const ACTIVITY_PAGE_SIZES = Object.freeze([10, 20, 30, 50, 100])

export const DEFAULT_ACTIVITY_PAGE_SIZE = 30

export const ACTIVITY_FIELD_LABELS = Object.freeze({
  status: 'Trạng thái',
  assignee: 'Người được phân công',
  assigned_at: 'Thời gian phân công',
  reclaim_eligible_on: 'Ngày bắt đầu đủ điều kiện thu hồi',
  reclaimed_at: 'Thời gian thu hồi',
  reclaim_business_reason: 'Lý do thu hồi',
  manual_reason: 'Lý do thu hồi',
  reason: 'Lý do',
})

export const ACTIVITY_STATUS_LABELS = Object.freeze({
  active: 'Đang phân công',
  reclaimed: 'Đã thu hồi',
})

export const ACTIVITY_SOURCE_TYPE_LABELS = Object.freeze({
  order: 'Đơn hàng',
  imported_opportunity: 'Cơ hội import',
})

export const ACTIVITY_SOURCE_LABELS = Object.freeze({
  system: 'Hệ thống',
  user: 'Người dùng',
})

export const ACTIVITY_TECHNICAL_FIELDS = Object.freeze([
  'assignment_id',
  'customer_care_id',
  'shop_id',
  'source_type',
  'source_id',
  'actor_user_id',
  'assignee_user_id',
  'assignee_pancake_user_id',
  'previous_assignee_user_id',
  'new_assignee_user_id',
  'previous_assignment_id',
  'new_assignment_id',
  'reclaim_source',
  'reclaim_reason',
])

export const getActivityAction = (action) =>
  ACTIVITY_ACTIONS.find((item) => item.value === action) ?? {
    value: action,
    label: 'Hoạt động khách hàng',
    color: 'default',
  }
