import {
  CheckCircleOutlined,
  EyeOutlined,
  FilterOutlined,
  HistoryOutlined,
  MoreOutlined,
  ReloadOutlined,
  RollbackOutlined,
  SafetyCertificateOutlined,
  ShoppingOutlined,
  StopOutlined,
} from '@ant-design/icons'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App as AntdApp,
  Avatar,
  Button,
  Card,
  DatePicker,
  Descriptions,
  Drawer,
  Dropdown,
  Empty,
  Form,
  Input,
  Modal,
  Result,
  Select,
  Space,
  Spin,
  Table,
  Tabs,
  Tag,
  Timeline,
  Tooltip,
  Typography,
} from 'antd'
import dayjs from 'dayjs'
import { useCallback, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  acceptCustomerCare,
  getCustomerCareHistories,
  getCustomerCareOrders,
  getCustomerCares,
  markCustomerCareAsCared,
  reclaimCustomerCare,
} from '../../api/customer-care.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { hasPermission, isAdmin } from '../../auth/permissions.js'
import { getAllUsers } from '../../api/users.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import {
  CUSTOMER_CARE_APPROVAL_OPTIONS,
  CUSTOMER_CARE_PAGE_SIZE,
  CUSTOMER_CARE_STATUS_OPTIONS,
  CUSTOMER_CARE_TYPES,
  getCustomerCareApproval,
  getCustomerCareStatus,
} from '../../constants/customer-care.js'
import { getOrderStatus } from '../../constants/order.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError, getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import { getCareStaffDisplay } from './careStaffDisplay.js'
import { changeCareFilterShop, createEmptyFilters, normalizeFilters } from './customerCareFilters.js'
import { careOrderPagesQueryOptions } from './careOrderPages.js'
import { getOrderPageOptions } from '../Orders/orderFilters.js'
import CustomerCareOrderPageFilter from './CustomerCareOrderPageFilter.jsx'
import './customer-care.css'

const EMPTY_CUSTOMER_CARES = Object.freeze([])
const { RangePicker } = DatePicker


const shouldRetryCustomerCareQuery = (failureCount, error) => {
  const status = error?.status ?? error?.response?.status
  if (status != null) return false

  return failureCount < 1
}


const formatDate = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY') : '—'
}

const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const formatCompactDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY HH:mm') : '—'
}

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const formatPhones = (value) => {
  if (Array.isArray(value)) return value.map(formatPhones).filter(Boolean).join(', ')
  if (!value) return '—'
  if (typeof value !== 'string') return String(value)

  try {
    const parsed = JSON.parse(value)
    if (Array.isArray(parsed)) return parsed.map(formatPhones).filter(Boolean).join(', ') || '—'
    if (parsed && typeof parsed === 'object') {
      return parsed.phone || parsed.telephone || parsed.number || value
    }
  } catch {
    return value
  }

  return value
}

const isActionableCustomerCare = (customerCare) => {
  if (!customerCare?.pancake_order_id) return true

  const assignment = getActiveAssignment(customerCare)

  if (assignment?.source_type === 'order'
    && String(assignment.customer_care_id) === String(customerCare.id)) {
    return true
  }

  return Number(customerCare.order?.status) !== 3
}

const getActiveAssignment = (customerCare) => {
  const assignment = customerCare?.active_assignment

  return assignment?.status === 'active'
    && String(assignment.customer_care_id) === String(customerCare?.id)
    ? assignment
    : null
}

const hasCurrentActiveAssignment = (customerCare) => {
  if (customerCare?.current_assignment_ambiguous) return false

  const assignment = customerCare?.current_assignment

  return assignment?.status === 'active'
    && assignment.cared_at == null
    && String(assignment.customer_care_id) === String(customerCare?.id)
}

const canManuallyReclaim = (user, customerCare) => (
  (isAdmin(user) || user?.role?.slug === 'manager-cskh')
  && String(customerCare?.status) !== '1'
  && hasCurrentActiveAssignment(customerCare)
)

const canReviewCustomerCare = (user) => isAdmin(user) || user?.role?.slug === 'manager-cskh'

const getExactSourceOrder = (customerCare) => {
  const assignment = getActiveAssignment(customerCare)
  if (assignment?.source_type !== 'order') return null

  const sourceOrder = assignment.source_order
  return sourceOrder && String(sourceOrder.id) === String(assignment.source_id) ? sourceOrder : null
}

const CustomerCareAssignee = ({ customerCare }) => {
  const display = getCareStaffDisplay(customerCare)

  if (display.kind === 'current' || display.kind === 'completed') {
    const assignment = display.assignment
    const assigneeName = assignment.assignee?.name || `Nhân viên #${assignment.assignee_user_id || '—'}`

    return (
      <div className="customer-care-cell">
        <Tooltip title={assigneeName}>
          <span className="customer-care-cell__ellipsis">{assigneeName}</span>
        </Tooltip>
      </div>
    )
  }

  if (display.kind !== 'legacy') return '—'

  return (
    <div className="customer-care-cell">
      <Tooltip title={display.name}>
        <span className="customer-care-cell__ellipsis">{display.name}</span>
      </Tooltip>
      <small>Dữ liệu cũ</small>
    </div>
  )
}

const CustomerCarePage = ({ pageType }) => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { message } = AntdApp.useApp()
  const currentUser = useAuthStore((state) => state.user)
  const pageMeta = CUSTOMER_CARE_TYPES[pageType] ?? CUSTOMER_CARE_TYPES.today
  const [draftFilters, setDraftFilters] = useState(createEmptyFilters)
  const [appliedFilters, setAppliedFilters] = useState(() => normalizeFilters(createEmptyFilters()))
  const [page, setPage] = useState(1)
  const [selectedCare, setSelectedCare] = useState(null)
  const [selectedCareTab, setSelectedCareTab] = useState('history')
  const [careConfirmation, setCareConfirmation] = useState(null)
  const [careConfirmationTime, setCareConfirmationTime] = useState(null)
  const [reclaimConfirmation, setReclaimConfirmation] = useState(null)
  const [reclaimReason, setReclaimReason] = useState('')
  const [careCompletionForm] = Form.useForm()
  const careSubmissionRef = useRef(false)
  const reclaimSubmissionRef = useRef(false)

  const careParams = useMemo(
    () => ({ type: pageMeta.apiType, context: 'v2', ...appliedFilters, page }),
    [appliedFilters, page, pageMeta.apiType],
  )

  const careQuery = useQuery({
    queryKey: ['customer-cares', careParams],
    queryFn: () => getCustomerCares(careParams),
    placeholderData: keepPreviousData,
    staleTime: 45_000,
    retry: shouldRetryCustomerCareQuery,
  })

  const orderPagesQuery = useQuery(careOrderPagesQueryOptions(pageType, draftFilters.shopId))
  const orderPageOptions = useMemo(
    () => draftFilters.shopId ? getOrderPageOptions(orderPagesQuery.data) : [],
    [draftFilters.shopId, orderPagesQuery.data],
  )

  const usersQuery = useQuery({
    queryKey: ['customer-care-users', draftFilters.shopId ?? 'all'],
    queryFn: () => getAllUsers(draftFilters.shopId ? { shop_id: draftFilters.shopId } : {}),
    staleTime: 60_000,
  })

  const historyQuery = useQuery({
    queryKey: ['customer-care-history', selectedCare?.id],
    queryFn: () => getCustomerCareHistories(selectedCare.id),
    enabled: Boolean(selectedCare?.id) && selectedCareTab === 'history',
    staleTime: 30_000,
  })

  const ordersQuery = useQuery({
    queryKey: ['customer-care-orders', selectedCare?.id],
    queryFn: () => getCustomerCareOrders(selectedCare.id),
    enabled: Boolean(selectedCare?.id) && selectedCareTab === 'orders',
    staleTime: 30_000,
  })

  const {
    isPending: isMarkingCustomerCare,
    mutateAsync: mutateMarkCustomerCare,
    variables: markCareMutationVariables,
  } = useMutation({
    mutationFn: ({ customerCareId, payload }) => markCustomerCareAsCared(customerCareId, payload),
    retry: false,
    onSuccess: async (result, variables) => {
      message.success(result.message || 'Đã cập nhật trạng thái chăm sóc')
      setCareConfirmation(null)
      setCareConfirmationTime(null)
      careCompletionForm.resetFields()
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['customer-cares'] }),
        queryClient.invalidateQueries({ queryKey: ['customer-care-history', variables.customerCareId] }),
      ])
    },
    onError: async (error) => {
      const status = error?.status ?? error?.response?.status

      if (status === 409) {
        message.error('Phiếu chăm sóc này không còn là tác vụ hiện tại. Vui lòng tải lại danh sách.')
        setCareConfirmation(null)
        setCareConfirmationTime(null)
        careCompletionForm.resetFields()
        await queryClient.invalidateQueries({ queryKey: ['customer-cares'] })
        return
      }

      message.error(getApiErrorMessage(error, 'Không thể cập nhật trạng thái chăm sóc. Vui lòng thử lại.'))
    },
    onSettled: () => {
      careSubmissionRef.current = false
    },
  })

  const {
    isPending: isAcceptingCustomerCare,
    mutateAsync: mutateAcceptCustomerCare,
    variables: acceptMutationVariables,
  } = useMutation({
    mutationFn: ({ customerCareId, payload }) => acceptCustomerCare(customerCareId, payload),
    retry: false,
    onSuccess: async (_, variables) => {
      message.success(variables.payload.is_accept === 0 ? 'Đã từ chối sửa CSKH' : 'Đã duyệt sửa CSKH')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['customer-cares'] }),
        queryClient.invalidateQueries({ queryKey: ['customer-care-history', variables.customerCareId] }),
      ])
    },
    onError: (error, variables) => {
      const fallback = variables.payload.is_accept === 0
        ? 'Không thể từ chối sửa CSKH. Vui lòng thử lại.'
        : 'Không thể duyệt sửa CSKH. Vui lòng thử lại.'
      message.error(getApiErrorMessage(error, fallback))
    },
  })

  const {
    isPending: isReclaimingCustomerCare,
    mutateAsync: mutateReclaimCustomerCare,
    variables: reclaimMutationVariables,
  } = useMutation({
    mutationFn: ({ customerCareId, payload }) => reclaimCustomerCare(customerCareId, payload),
    retry: false,
    onSuccess: async (result) => {
      message.success(result.message || 'Đã thu hồi khách hàng.')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['customer-cares'] }),
        queryClient.invalidateQueries({ queryKey: ['order-opportunities'] }),
      ])
    },
    onError: async (error) => {
      const status = error?.status ?? error?.response?.status
      if (status === 403) {
        message.error('Bạn không có quyền thu hồi khách hàng này.')
      } else if (status === 409) {
        message.error('Khách hàng không còn ở trạng thái có thể thu hồi.')
        await queryClient.invalidateQueries({ queryKey: ['customer-cares'] })
      } else {
        message.error('Không thể thu hồi khách hàng. Vui lòng thử lại.')
      }
    },
    onSettled: () => {
      reclaimSubmissionRef.current = false
    },
  })

  const userOptions = useMemo(
    () =>
      (usersQuery.data ?? [])
        .map((item) => ({
          value: String(item.pancake_user_id ?? item.id),
          label: item.name || `Người dùng #${item.pancake_user_id ?? item.id}`,
        }))
        .filter((item) => item.value !== 'undefined'),
    [usersQuery.data],
  )

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => changeCareFilterShop(current, shopId))
    setAppliedFilters((current) => ({ ...current, shop_id: shopId, user_id: undefined, order_page_id: undefined }))
    setPage(1)
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setAppliedFilters(normalizeFilters(draftFilters))
    setPage(1)
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeFilters(emptyFilters))
    setPage(1)
  }

  const openCareDrawer = useCallback((record, tab = 'history') => {
    setSelectedCareTab(tab)
    setSelectedCare(record)
  }, [])

  const closeCareDrawer = () => {
    setSelectedCare(null)
    setSelectedCareTab('history')
  }

  const openCareConfirmation = useCallback((record) => {
    if (isMarkingCustomerCare
      || pageMeta.apiType === 'customer_care_edit'
      || String(record?.status) === '1'
      || !hasCurrentActiveAssignment(record)) {
      return
    }

    const confirmedAt = dayjs()
    careCompletionForm.resetFields()
    careCompletionForm.setFieldsValue({ note: '', nextDateCare: null })
    setCareConfirmationTime(confirmedAt)
    setCareConfirmation(record)
  }, [careCompletionForm, isMarkingCustomerCare, pageMeta.apiType])

  const closeCareConfirmation = () => {
    if (isMarkingCustomerCare) return
    setCareConfirmation(null)
    setCareConfirmationTime(null)
    careCompletionForm.resetFields()
  }

  const submitCareCompletion = async () => {
    if (!careConfirmation || isMarkingCustomerCare || careSubmissionRef.current) return

    if (pageMeta.apiType === 'customer_care_edit'
      || String(careConfirmation.status) === '1'
      || !hasCurrentActiveAssignment(careConfirmation)) {
      setCareConfirmation(null)
      setCareConfirmationTime(null)
      careCompletionForm.resetFields()
      message.error('Phiếu chăm sóc này không còn là tác vụ hiện tại. Vui lòng tải lại danh sách.')
      void queryClient.invalidateQueries({ queryKey: ['customer-cares'] })
      return
    }

    let values
    try {
      values = await careCompletionForm.validateFields()
    } catch {
      return
    }

    careSubmissionRef.current = true
    try {
      await mutateMarkCustomerCare({
        customerCareId: careConfirmation.id,
        payload: {
          status: 1,
          date: (careConfirmationTime ?? dayjs()).format('YYYY-MM-DD HH:mm:00'),
          note: values.note.trim(),
          next_date_care: values.nextDateCare?.format('YYYY-MM-DD') ?? null,
        },
      })
    } catch {
      // The mutation renders the API error and keeps the completed form available for correction.
    }
  }

  const openAcceptConfirmation = useCallback((record, isAccept) => {
    const rejecting = isAccept === 0
    const requiredPermission = rejecting ? 'reject-cskh' : 'accept-schedule'
    const expectedCurrentState = rejecting ? '1' : '0'
    if (!record?.id
      || isAcceptingCustomerCare
      || !canReviewCustomerCare(currentUser)
      || !hasPermission(currentUser, requiredPermission)
      || String(record.is_accept) !== expectedCurrentState) return

    let reason = ''

    Modal.confirm({
      title: rejecting ? 'Từ chối sửa CSKH' : 'Duyệt sửa CSKH',
      content: (
        <div>
          <p>{rejecting
            ? 'Bạn có chắc chắn muốn từ chối yêu cầu sửa CSKH này không?'
            : 'Bạn có chắc chắn muốn duyệt yêu cầu sửa CSKH này không?'}</p>
          <Input.TextArea
            rows={4}
            required
            placeholder={rejecting ? 'Nhập lý do từ chối...' : 'Nhập lý do duyệt...'}
            aria-label={rejecting ? 'Lý do từ chối sửa CSKH' : 'Lý do duyệt sửa CSKH'}
            onChange={(event) => {
              reason = event.target.value
            }}
          />
        </div>
      ),
      okText: rejecting ? 'Từ chối' : 'Duyệt',
      cancelText: 'Hủy',
      okButtonProps: { loading: isAcceptingCustomerCare, danger: rejecting },
      onOk: async () => {
        if (isAcceptingCustomerCare) return
        if (!reason.trim()) {
          message.error('Vui lòng nhập lý do')
          return Promise.reject()
        }

        await mutateAcceptCustomerCare({
          customerCareId: record.id,
          payload: { is_accept: isAccept, reason: reason.trim() },
        })
      },
    })
  }, [currentUser, isAcceptingCustomerCare, message, mutateAcceptCustomerCare])

  const openReclaimConfirmation = useCallback((record) => {
    if (!canManuallyReclaim(currentUser, record) || isReclaimingCustomerCare) return
    setReclaimReason('')
    setReclaimConfirmation(record)
  }, [currentUser, isReclaimingCustomerCare])

  const closeReclaimConfirmation = useCallback(() => {
    if (isReclaimingCustomerCare) return
    setReclaimConfirmation(null)
    setReclaimReason('')
  }, [isReclaimingCustomerCare])

  const submitReclaim = useCallback(async () => {
    if (!reclaimConfirmation || reclaimSubmissionRef.current) return

    reclaimSubmissionRef.current = true
    try {
      await mutateReclaimCustomerCare({
        customerCareId: reclaimConfirmation.id,
        payload: { reason: reclaimReason.trim() },
      })
      setReclaimConfirmation(null)
      setReclaimReason('')
    } catch {
      // The mutation displays the business error and keeps the dialog open.
    }
  }, [mutateReclaimCustomerCare, reclaimConfirmation, reclaimReason])

  const rawCares = careQuery.data?.customers ?? EMPTY_CUSTOMER_CARES
  const cares = useMemo(
    () => pageMeta.apiType === 'customer_care_edit'
      ? rawCares
      : rawCares.filter(isActionableCustomerCare),
    [pageMeta.apiType, rawCares],
  )
  const totalItems = careQuery.data?.total_items ?? 0
  const perPage = careQuery.data?.per_page ?? CUSTOMER_CARE_PAGE_SIZE
  const permissionDenied = isPermissionError(careQuery.error)
  const hasAppliedFilters = Object.values(appliedFilters).some(
    (value) => value !== undefined && value !== '',
  )

  const columns = useMemo(
    () => [
      {
        title: 'STT',
        key: 'index',
        width: 60,
        align: 'center',
        render: (_, __, index) => (page - 1) * perPage + index + 1,
      },
      {
        title: 'Cửa hàng / QL',
        dataIndex: 'shop',
        key: 'shop',
        width: 220,
        render: (shop) => {
          const managerNames = (shop?.managers ?? [])
            .map((manager) => manager?.name?.trim())
            .filter(Boolean)
          const managerLabel = managerNames.join(', ')

          return (
            <div className="customer-care-cell">
              {shop?.name ? (
                <Space size={8} className="customer-care-shop">
                  <Avatar size={28} className="customer-care-shop__avatar" aria-hidden="true">
                    {getInitial(shop.name)}
                  </Avatar>
                  <Tooltip title={shop.name}>
                    <span className="customer-care-cell__ellipsis">{shop.name}</span>
                  </Tooltip>
                </Space>
              ) : (
                <span>—</span>
              )}
              {managerLabel && (
                <Tooltip title={managerLabel}>
                  <small className="customer-care-cell__ellipsis">QL: {managerLabel}</small>
                </Tooltip>
              )}
            </div>
          )
        },
      },
      {
        title: 'NV tạo',
        dataIndex: 'user_creator',
        key: 'user_creator',
        width: 150,
        render: (user) => (
          <Tooltip title={user?.name}>
            <span className="customer-care-cell__ellipsis">{user?.name || '—'}</span>
          </Tooltip>
        ),
      },
      {
        title: 'NV chăm sóc',
        key: 'active_assignee',
        width: 220,
        render: (_, record) => <CustomerCareAssignee customerCare={record} />,
      },
      {
        title: 'Lịch / thời điểm',
        key: 'care_schedule',
        width: 190,
        render: (_, record) => (
          <div className="customer-care-cell customer-care-cell--dates">
            <span>{formatDate(record.date_care)}</span>
            {String(record.status) === '1' && record.time_care && (
              <small>Đã CS: {formatCompactDateTime(record.time_care)}</small>
            )}
          </div>
        ),
      },
      {
        title: 'Khách hàng / SĐT',
        key: 'customer',
        width: 240,
        render: (_, record) => {
          const phones = formatPhones(record.customer_phones)
          return (
            <div className="customer-care-cell">
              <Tooltip title={record.customer_name}>
                <span className="customer-care-cell__ellipsis">{record.customer_name || '—'}</span>
              </Tooltip>
              <small className="customer-care-cell__ellipsis">{phones}</small>
            </div>
          )
        },
      },
      {
        title: 'Đơn hàng',
        key: 'order',
        width: 180,
        render: (_, record) => {
          const assignment = getActiveAssignment(record)
          const order = assignment?.source_type === 'order' ? getExactSourceOrder(record) : record.order
          const status = getOrderStatus(order?.status)
          const internalOrderId = assignment?.source_type === 'order' ? order?.id : record.order?.id

          return (
            <div className="customer-care-cell">
              {record.pancake_order_id ? (
                <Tooltip title={internalOrderId ? `ID nội bộ: ${internalOrderId}` : undefined}>
                  <Typography.Text className="customer-care-code customer-care-cell__ellipsis">
                    {record.pancake_order_id}
                  </Typography.Text>
                </Tooltip>
              ) : (
                <span>—</span>
              )}
              <div><Tag color={status.color}>{status.label}</Tag></div>
            </div>
          )
        },
      },
      {
        title: 'Nguồn đơn',
        dataIndex: 'order_page_name',
        key: 'order_page_name',
        width: 180,
        render: (_, record) => (
          <Tooltip title={record.order_page_name ?? undefined}>
            <span className="customer-care-cell__ellipsis customer-care-order-page">
              {record.order_page_name ?? '—'}
            </span>
          </Tooltip>
        ),
      },
      {
        title: 'Địa chỉ',
        dataIndex: 'customer_addresss',
        key: 'customer_addresss',
        width: 240,
        render: (address) => (
          <Tooltip title={address}>
            <span className="customer-care-cell__clamp">{address || '—'}</span>
          </Tooltip>
        ),
      },
      {
        title: 'Nội dung / lý do',
        key: 'care_content',
        width: 280,
        render: (_, record) => (
          <div className="customer-care-cell">
            <Tooltip title={record.note}>
              <span className="customer-care-cell__clamp">{record.note || '—'}</span>
            </Tooltip>
            {record.reason && (
              <Tooltip title={record.reason}>
                <small className="customer-care-cell__clamp">Lý do: {record.reason}</small>
              </Tooltip>
            )}
          </div>
        ),
      },
      {
        title: 'Trạng thái',
        key: 'statuses',
        width: 190,
        render: (_, record) => {
          const careStatus = getCustomerCareStatus(record.status)
          const approval = getCustomerCareApproval(record.is_accept)
          return (
            <div className="customer-care-statuses">
              <Tag color={careStatus.color}>{careStatus.label}</Tag>
              <Tag color={approval.color}>{approval.label}</Tag>
            </div>
          )
        },
      },
      {
        title: 'Thao tác',
        key: 'action',
        width: 68,
        align: 'center',
        fixed: 'right',
        className: 'customer-care-action-column',
        render: (_, record) => {
          const canMarkAsCared = pageType !== 'editRequests'
            && String(record.status) !== '1'
            && hasCurrentActiveAssignment(record)
          const canAccept = canReviewCustomerCare(currentUser)
            && hasPermission(currentUser, 'accept-schedule')
            && String(record.is_accept) === '0'
          const canReject = canReviewCustomerCare(currentUser)
            && hasPermission(currentUser, 'reject-cskh')
            && String(record.is_accept) === '1'
          const canReclaim = canManuallyReclaim(currentUser, record)
          const isCurrentCareMutation = isMarkingCustomerCare
            && String(markCareMutationVariables?.customerCareId) === String(record.id)
          const isCurrentAcceptMutation = isAcceptingCustomerCare
            && String(acceptMutationVariables?.customerCareId) === String(record.id)
          const isCurrentReclaimMutation = isReclaimingCustomerCare
            && String(reclaimMutationVariables?.customerCareId) === String(record.id)
          const actionItems = [
            {
              key: 'detail',
              icon: <EyeOutlined />,
              label: 'Xem chi tiết',
              onClick: () => openCareDrawer(record),
            },
          ]

          if (canMarkAsCared) {
            actionItems.push({
              key: 'complete-care',
              icon: <CheckCircleOutlined />,
              label: 'Xác nhận đã chăm sóc',
              disabled: isMarkingCustomerCare,
              onClick: () => openCareConfirmation(record),
            })
          }

          if (canAccept) {
            actionItems.push({
              key: 'accept',
              icon: <SafetyCertificateOutlined />,
              label: 'Duyệt sửa CSKH',
              disabled: isAcceptingCustomerCare,
              onClick: () => openAcceptConfirmation(record, 1),
            })
          }

          if (canReject) {
            actionItems.push({
              key: 'reject',
              icon: <StopOutlined />,
              label: 'Từ chối sửa CSKH',
              danger: true,
              disabled: isAcceptingCustomerCare,
              onClick: () => openAcceptConfirmation(record, 0),
            })
          }

          if (canReclaim) {
            actionItems.push({
              key: 'reclaim',
              icon: <RollbackOutlined />,
              label: 'Thu hồi',
              danger: true,
              disabled: isReclaimingCustomerCare,
              onClick: () => openReclaimConfirmation(record),
            })
          }

          actionItems.push(
            { type: 'divider' },
            {
              key: 'order-history',
              icon: <ShoppingOutlined />,
              label: 'Lịch sử đơn hàng',
              onClick: () => openCareDrawer(record, 'orders'),
            },
            {
              key: 'care-history',
              icon: <HistoryOutlined />,
              label: 'Lịch sử chăm sóc',
              onClick: () => openCareDrawer(record, 'history'),
            },
          )

          return (
            <Dropdown menu={{ items: actionItems }} trigger={['click']} placement="bottomRight">
              <Button
                type="text"
                size="small"
                icon={<MoreOutlined />}
                loading={isCurrentCareMutation || isCurrentAcceptMutation || isCurrentReclaimMutation}
                aria-label={`Mở thao tác chăm sóc khách hàng ${record.customer_name || record.id}`}
                aria-haspopup="menu"
              />
            </Dropdown>
          )
        },
      },
    ],
    [
      acceptMutationVariables?.customerCareId,
      currentUser,
      isAcceptingCustomerCare,
      isMarkingCustomerCare,
      isReclaimingCustomerCare,
      markCareMutationVariables?.customerCareId,
      openAcceptConfirmation,
      openCareConfirmation,
      openCareDrawer,
      openReclaimConfirmation,
      page,
      pageType,
      perPage,
      reclaimMutationVariables?.customerCareId,
    ],
  )

  const historyItems = useMemo(
    () => (historyQuery.data ?? []).map((item) => {
      const status = getCustomerCareStatus(item.status)
      return {
        color: status.color === 'green' ? 'green' : 'gray',
        content: (
          <div className="customer-care-history-item">
            <strong>
              {item.time_care
                ? `Đã chăm sóc: ${formatDateTime(item.time_care)}`
                : `Ngày hẹn: ${formatDate(item.date_care)}`}
            </strong>
            <span>{item.note || 'Chưa có nội dung chăm sóc.'}</span>
            <small>
              {status.label} · {item.user_creator?.name || 'Không rõ người tạo'} · {item.shop?.name || '—'}
            </small>
          </div>
        ),
      }
    }),
    [historyQuery.data],
  )

  const orderColumns = useMemo(
    () => [
      { title: 'Mã đơn', dataIndex: 'pancake_order_id', key: 'pancake_order_id', width: 155 },
      {
        title: 'Trạng thái',
        dataIndex: 'status',
        key: 'status',
        width: 150,
        render: (value) => {
          const status = getOrderStatus(value)
          return <Tag color={status.color}>{status.label}</Tag>
        },
      },
      { title: 'SL', dataIndex: 'total_quantity', key: 'total_quantity', width: 72, align: 'right' },
      {
        title: 'COD',
        dataIndex: 'cod',
        key: 'cod',
        width: 120,
        align: 'right',
        render: (value) => `${Number(value || 0).toLocaleString('vi-VN')} ₫`,
      },
      {
        title: 'Người tạo',
        dataIndex: ['user_creator', 'name'],
        key: 'user_creator',
        width: 150,
        render: (value) => value || '—',
      },
      {
        title: 'Ghi chú',
        dataIndex: 'note',
        key: 'note',
        width: 220,
        ellipsis: true,
        render: (value) => value || '—',
      },
      { title: 'Tạo lúc', dataIndex: 'created_at', key: 'created_at', width: 165, render: formatDateTime },
      { title: 'Cửa hàng', dataIndex: ['shop', 'name'], key: 'shop', width: 170, render: (value) => value || '—' },
    ],
    [],
  )

  if (permissionDenied) {
    return (
      <Card className="customer-care-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem danh sách CSKH"
          subTitle="Tài khoản hiện tại không có quyền truy cập dữ liệu trong phạm vi cửa hàng đã chọn."
          extra={
            <Button type="primary" onClick={() => navigate(ROUTES.HOME)}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  if (careQuery.isError && isUnauthorizedError(careQuery.error)) {
    return (
      <Card className="customer-care-state-card">
        <Result
          status="403"
          title="Phiên đăng nhập không còn hiệu lực"
          subTitle="Vui lòng đăng nhập lại để tiếp tục xem danh sách chăm sóc khách hàng."
        />
      </Card>
    )
  }

  return (
    <main className="customer-care-page">
      <header className="customer-care-page__intro">
        <h1>{pageMeta.title}</h1>
        <p>{pageMeta.description}</p>
      </header>

      <Card title="Danh sách chăm sóc khách hàng" className="customer-care-card">
        <form className="customer-care-filters" onSubmit={applyFilters}>
          <div className="customer-care-filters__grid">
            <div className="customer-care-field customer-care-field--search">
              <label htmlFor="customer-care-search">Tìm kiếm</label>
              <Input
                id="customer-care-search"
                allowClear
                value={draftFilters.search}
                onChange={(event) => updateDraft('search', event.target.value)}
                placeholder="Tên KH, số điện thoại hoặc mã đơn"
              />
            </div>

            <div className="customer-care-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>

            <CustomerCareOrderPageFilter
              shopId={draftFilters.shopId}
              value={draftFilters.orderPageId}
              options={orderPageOptions}
              query={orderPagesQuery}
              onChange={(value) => updateDraft('orderPageId', value)}
            />

            <div className="customer-care-field">
              <label htmlFor="customer-care-employee">Nhân viên</label>
              <Select
                id="customer-care-employee"
                aria-label="Nhân viên"
                allowClear
                showSearch
                optionFilterProp="label"
                value={draftFilters.userId}
                options={userOptions}
                loading={usersQuery.isLoading}
                status={usersQuery.isError ? 'error' : undefined}
                placeholder="Chọn nhân viên"
                notFoundContent={usersQuery.isLoading ? 'Đang tải...' : 'Không có nhân viên'}
                onChange={(value) => updateDraft('userId', value)}
              />
            </div>

            <div className="customer-care-field customer-care-field--date">
              <label>Thời gian</label>
              <RangePicker
                aria-label="Khoảng thời gian chăm sóc"
                allowClear
                value={draftFilters.dateRange}
                onChange={(value) => updateDraft('dateRange', value)}
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
              />
            </div>

            <div className="customer-care-field">
              <label htmlFor="customer-care-status">Trạng thái</label>
              <Select
                id="customer-care-status"
                aria-label="Trạng thái"
                allowClear
                value={draftFilters.status}
                options={CUSTOMER_CARE_STATUS_OPTIONS}
                placeholder="Tất cả trạng thái"
                onChange={(value) => updateDraft('status', value)}
              />
            </div>

            <div className="customer-care-field">
              <label htmlFor="customer-care-approval">Trạng thái duyệt</label>
              <Select
                id="customer-care-approval"
                aria-label="Trạng thái duyệt"
                allowClear
                value={draftFilters.isAccept}
                options={CUSTOMER_CARE_APPROVAL_OPTIONS}
                placeholder="Tất cả trạng thái"
                onChange={(value) => updateDraft('isAccept', value)}
              />
            </div>

            <div className="customer-care-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />} className="filter-action-button">
                Lọc
              </Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
                Làm mới
              </Button>
            </div>
          </div>

          {usersQuery.isError && (
            <Alert
              type="warning"
              showIcon
              message="Không thể tải danh sách người dùng. Bạn vẫn có thể dùng các bộ lọc khác."
              className="customer-care-filter-alert"
            />
          )}
        </form>

        <div className="customer-care-summary" aria-live="polite">
          Tổng số lịch: <strong>{totalItems.toLocaleString('vi-VN')}</strong>
          <span> · {perPage} bản ghi mỗi trang</span>
        </div>

        {careQuery.isError && (
          <QueryErrorAlert
            error={careQuery.error}
            fallbackTitle="Không thể tải danh sách chăm sóc khách hàng"
            onRetry={() => careQuery.refetch()}
            className="customer-care-query-alert"
          />
        )}

        <Table
          className="app-table"
          rowKey="id"
          columns={columns}
          dataSource={cares}
          size="small"
          loading={careQuery.isLoading || careQuery.isFetching}
          scroll={{ x: 'max-content' }}
          onChange={(pagination) => setPage(pagination.current ?? 1)}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={
                  <span>
                    {pageMeta.emptyMessage}
                    {hasAppliedFilters && <small>Hãy thử thay đổi hoặc xóa bớt bộ lọc.</small>}
                  </span>
                }
              />
            ),
          }}
          pagination={{
            current: page,
            pageSize: perPage,
            total: totalItems,
            showSizeChanger: false,
            placement: ['bottomEnd'],
            showTotal: (total) => `Tổng ${total.toLocaleString('vi-VN')} lịch`,
          }}
        />
      </Card>

      <Drawer
        title="Chi tiết chăm sóc khách hàng"
        open={Boolean(selectedCare)}
        size="min(720px, 100vw)"
        onClose={closeCareDrawer}
        destroyOnHidden
        className="customer-care-detail-drawer"
      >
        {selectedCare && (
          <div className="customer-care-detail">
            <Descriptions column={1} size="small" bordered>
              <Descriptions.Item label="Khách hàng">{selectedCare.customer_name || '—'}</Descriptions.Item>
              <Descriptions.Item label="Mã khách hàng">
                {selectedCare.pancake_customer_id || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Số điện thoại">
                {formatPhones(selectedCare.customer_phones)}
              </Descriptions.Item>
              <Descriptions.Item label="Địa chỉ">
                {selectedCare.customer_addresss || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Mã đơn">{selectedCare.pancake_order_id || '—'}</Descriptions.Item>
              <Descriptions.Item label="Ngày hẹn">{formatDate(selectedCare.date_care)}</Descriptions.Item>
              <Descriptions.Item label="Thời điểm đã chăm sóc">
                {formatDateTime(selectedCare.time_care)}
              </Descriptions.Item>
              <Descriptions.Item label="Người tạo">
                {selectedCare.user_creator?.name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Nhân viên chăm sóc">
                <CustomerCareAssignee customerCare={selectedCare} />
              </Descriptions.Item>
              <Descriptions.Item label="Người phân công">
                {selectedCare.legacy_user_assigning?.name || selectedCare.user_assigning?.name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Nội dung chăm sóc">{selectedCare.note || '—'}</Descriptions.Item>
              <Descriptions.Item label="Lý do duyệt/hủy">
                {selectedCare.reason || '—'}
              </Descriptions.Item>
            </Descriptions>

            <Tabs
              activeKey={selectedCareTab}
              onChange={setSelectedCareTab}
              items={[
                {
                  key: 'history',
                  label: `Lịch sử (${historyQuery.data?.length ?? 0})`,
                  children: historyQuery.isError ? (
                    <Alert type="warning" showIcon message={getApiErrorMessage(historyQuery.error)} />
                  ) : (
                    <Spin spinning={historyQuery.isLoading}>
                      <Timeline items={historyItems} />
                    </Spin>
                  ),
                },
                {
                  key: 'orders',
                  label: `Đơn hàng (${ordersQuery.data?.length ?? 0})`,
                  children: ordersQuery.isError ? (
                    <Alert type="warning" showIcon message={getApiErrorMessage(ordersQuery.error)} />
                  ) : (
                    <Table
                      className="app-table"
                      size="small"
                      rowKey={(record) => record.id ?? record.pancake_order_id}
                      columns={orderColumns}
                      dataSource={ordersQuery.data ?? []}
                      loading={ordersQuery.isLoading}
                      pagination={false}
                      scroll={{ x: 640 }}
                      locale={{ emptyText: 'Chưa có đơn hàng liên quan.' }}
                    />
                  ),
                },
              ]}
            />
          </div>
        )}
      </Drawer>

      <Modal
        title="Xác nhận"
        open={Boolean(careConfirmation)}
        onCancel={closeCareConfirmation}
        onOk={submitCareCompletion}
        okText="Cập nhật"
        cancelText="Hủy"
        confirmLoading={isMarkingCustomerCare}
        okButtonProps={{ disabled: isMarkingCustomerCare }}
        cancelButtonProps={{ disabled: isMarkingCustomerCare }}
        closable={!isMarkingCustomerCare}
        mask={{ closable: !isMarkingCustomerCare }}
        destroyOnHidden
        width={416}
        centered
        className="customer-care-completion-modal"
      >
        <div className="customer-care-completion-modal__prompt">
          Bạn có chắc chắn muốn cập nhật thành &quot;Đã chăm sóc&quot; không?
        </div>

        <div className="customer-care-completion-modal__highlight">
          Thời gian xác nhận:{' '}
          <span className="customer-care-completion-modal__highlight-time">
            {careConfirmationTime?.format('DD/MM/YYYY HH:mm') ?? ''}
          </span>
        </div>

        <Form
          form={careCompletionForm}
          layout="vertical"
          preserve={false}
          requiredMark={false}
          className="customer-care-completion-form"
        >
          <Form.Item
            label="Thời gian xác nhận"
            className="customer-care-completion-modal__hidden-field"
            hidden
            aria-hidden="true"
          >
            <Input
              value={careConfirmationTime?.format('DD/MM/YYYY HH:mm') ?? ''}
              readOnly
              aria-label="Thời gian xác nhận"
            />
          </Form.Item>

          <Form.Item
            name="note"
            // label="Nội dung chăm sóc"
            rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập nội dung chăm sóc' }]}
            className="customer-care-completion-form__item"
          >
            <Input.TextArea
              rows={4}
              maxLength={2000}
              placeholder="Nhập nội dung chăm sóc..."
              disabled={isMarkingCustomerCare}
              className="customer-care-completion-modal__textarea"
            />
          </Form.Item>

          <Form.Item
            name="nextDateCare"
            // label="Ngày chăm sóc tiếp theo"
            className="customer-care-completion-form__item customer-care-completion-form__item--last"
          >
            <DatePicker
              format="DD/MM/YYYY"
              placeholder="Ngày chăm sóc tiếp theo"
              disabled={isMarkingCustomerCare}
              allowClear
              style={{ width: '100%' }}
              className="customer-care-completion-modal__datepicker"
            />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="Thu hồi khách hàng?"
        open={Boolean(reclaimConfirmation)}
        onCancel={closeReclaimConfirmation}
        onOk={submitReclaim}
        okText="Xác nhận thu hồi"
        cancelText="Hủy"
        confirmLoading={isReclaimingCustomerCare}
        okButtonProps={{ danger: true, disabled: isReclaimingCustomerCare }}
        cancelButtonProps={{ disabled: isReclaimingCustomerCare }}
        closable={!isReclaimingCustomerCare}
        mask={{ closable: !isReclaimingCustomerCare }}
        destroyOnHidden
      >
        <p>Khách hàng sẽ được đưa về danh sách chờ phân công và có thể giao cho nhân viên khác.</p>
        <label htmlFor="customer-care-reclaim-reason">Lý do thu hồi</label>
        <Input.TextArea
          id="customer-care-reclaim-reason"
          value={reclaimReason}
          maxLength={500}
          showCount
          autoSize={{ minRows: 3, maxRows: 6 }}
          placeholder="Nhập lý do thu hồi (không bắt buộc)"
          onChange={(event) => setReclaimReason(event.target.value)}
          disabled={isReclaimingCustomerCare}
        />
      </Modal>

    </main>
  )
}

export default CustomerCarePage
