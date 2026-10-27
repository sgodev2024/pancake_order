import {
  CheckCircleOutlined,
  LoginOutlined,
  ReloadOutlined,
  RollbackOutlined,
  ShoppingCartOutlined,
  UserSwitchOutlined,
} from '@ant-design/icons'

const getName = (user) => {
  const name = user?.name?.trim()
  return name || null
}

const getSafeReclaimDescription = (metadata = {}) => {
  const manualReason = typeof metadata.manual_reason === 'string'
    ? metadata.manual_reason.trim()
    : ''
  if (manualReason) return `Lý do: ${manualReason}`

  return metadata.reclaim_reason === 'Quá 3 ngày chưa chăm sóc'
    ? metadata.reclaim_reason
    : null
}

const buildAssignedDescription = (event) => {
  const assigneeName = getName(event?.target_user)
  return assigneeName ? `Phân công chăm sóc cho ${assigneeName}` : 'Đã phân công chăm sóc'
}

const buildReassignedDescription = (event) => {
  const previousName = event?.metadata?.previous_assignee_name?.trim()
  const newName = getName(event?.target_user) || event?.metadata?.new_assignee_name?.trim()

  if (previousName && newName) return `${previousName} → ${newName}`
  if (newName) return `Phân công lại chăm sóc cho ${newName}`
  return 'Đã phân công lại chăm sóc'
}

const buildCompletedTitle = (event) => {
  const sequence = Number(event?.metadata?.care_sequence_number)
  return Number.isInteger(sequence) && sequence > 0
    ? `Hoàn thành chăm sóc lần ${sequence}`
    : 'Hoàn thành chăm sóc'
}

export const JOURNEY_ACTION_CONFIG = Object.freeze({
  'customer.entered_system': {
    label: 'Khách hàng vào hệ thống',
    icon: LoginOutlined,
    color: 'blue',
    buildDescription: () => null,
  },
  'customer_care.assigned': {
    label: 'Phân công chăm sóc',
    icon: UserSwitchOutlined,
    color: 'cyan',
    buildDescription: buildAssignedDescription,
  },
  'customer_care.reassigned': {
    label: 'Phân công lại',
    icon: ReloadOutlined,
    color: 'purple',
    buildDescription: buildReassignedDescription,
  },
  'customer_care.reclaimed': {
    label: 'Thu hồi chăm sóc',
    icon: RollbackOutlined,
    color: 'gold',
    buildDescription: (event) => getSafeReclaimDescription(event?.metadata),
  },
  'customer_care.completed': {
    label: 'Hoàn thành chăm sóc',
    icon: CheckCircleOutlined,
    color: 'green',
    buildTitle: buildCompletedTitle,
    buildDescription: () => null,
  },
  'order.created': {
    label: 'Phát sinh đơn hàng',
    icon: ShoppingCartOutlined,
    color: 'orange',
    buildDescription: () => null,
    kind: 'order',
  },
})

export const getJourneyActionConfig = (action) => JOURNEY_ACTION_CONFIG[action] ?? {
  label: 'Hoạt động khách hàng',
  icon: LoginOutlined,
  color: 'default',
  buildDescription: () => null,
}
