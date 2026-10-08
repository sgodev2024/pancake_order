import { EyeOutlined, FilterOutlined, MoreOutlined, ReloadOutlined, UserSwitchOutlined } from '@ant-design/icons'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App as AntdApp,
  Avatar,
  Button,
  Card,
  DatePicker,
  Dropdown,
  Empty,
  Input,
  Result,
  Select,
  Space,
  Table,
  Tag,
  Tooltip,
  Typography,
} from 'antd'
import dayjs from 'dayjs'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { assignCustomerCare } from '../../api/customer-care.api.js'
import { getOrderOpportunities } from '../../api/opportunities.api.js'
import { getOrderPages } from '../../api/orders.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { hasPermission } from '../../auth/permissions.js'
import CustomerCareAssignmentModal from '../../components/CustomerCareAssignmentModal.jsx'
import OrderHistoryModal from '../../components/OrderHistoryModal.jsx'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getOrderStatus } from '../../constants/order.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError, getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import {
  changeOpportunityFilterShop,
  createEmptyOpportunityFilters,
  getOpportunityOrderPageOptions,
  normalizeOpportunityFilters,
} from './orderOpportunityFilters.js'
import './order-opportunities.css'

const { RangePicker } = DatePicker
const OPPORTUNITY_PAGE_SIZE = 30




const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

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

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const OrderOpportunitiesPage = () => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { message } = AntdApp.useApp()
  const currentUser = useAuthStore((state) => state.user)
  const canView = hasPermission(currentUser, 'view-chance')
  const canAssign = hasPermission(currentUser, 'asign-cskh')
  const [draftFilters, setDraftFilters] = useState(createEmptyOpportunityFilters)
  const [appliedFilters, setAppliedFilters] = useState(() =>
    normalizeOpportunityFilters(createEmptyOpportunityFilters()),
  )
  const [page, setPage] = useState(1)
  const [selectedRowKeys, setSelectedRowKeys] = useState([])
  const [selectedRows, setSelectedRows] = useState([])
  const [assignmentRows, setAssignmentRows] = useState([])
  const [assignmentUserId, setAssignmentUserId] = useState(undefined)
  const [assignmentError, setAssignmentError] = useState(null)
  const [historyOrder, setHistoryOrder] = useState(null)

  const opportunityParams = useMemo(
    () => ({ ...appliedFilters, page }),
    [appliedFilters, page],
  )

  const opportunitiesQuery = useQuery({
    queryKey: ['order-opportunities', opportunityParams],
    queryFn: () => getOrderOpportunities(opportunityParams),
    enabled: canView,
    placeholderData: keepPreviousData,
  })

  const orderPagesQuery = useQuery({
    queryKey: ['order-pages', 'opportunity', draftFilters.shopId ?? null],
    queryFn: () => getOrderPages({ shop_id: draftFilters.shopId, context: 'opportunity' }),
    enabled: canView && Boolean(draftFilters.shopId),
    staleTime: 60_000,
  })

  const orderPageOptions = useMemo(
    () => getOpportunityOrderPageOptions(orderPagesQuery.data),
    [orderPagesQuery.data],
  )

  const clearSelection = () => {
    setSelectedRowKeys([])
    setSelectedRows([])
  }

  const assignMutation = useMutation({
    mutationFn: ({ orderIds, pancakeUserId }) =>
      assignCustomerCare(orderIds[0], {
        pancake_user_ids: [pancakeUserId],
        order_ids: orderIds,
        is_multiple: orderIds.length > 1,
      }),
    retry: false,
    onSuccess: async (result) => {
      message.success(result.message || 'Phân công CSKH thành công')
      setAssignmentRows([])
      setAssignmentUserId(undefined)
      setAssignmentError(null)
      clearSelection()
      await queryClient.invalidateQueries({ queryKey: ['order-opportunities'] })
      await queryClient.invalidateQueries({ queryKey: ['customer-cares'] })
      navigate(ROUTES.CUSTOMER_ASSIGNMENTS)
    },
    onError: (error) => {
      setAssignmentError(getApiErrorMessage(error, 'Không thể phân công CSKH. Vui lòng thử lại.'))
    },
  })

  const closeAssignmentModal = () => {
    if (assignMutation.isPending) return
    setAssignmentRows([])
    setAssignmentUserId(undefined)
    setAssignmentError(null)
  }

  const openOrderHistory = useCallback(
    (order) => {
      if (order?.id === null || order?.id === undefined || order.id === '') {
        message.error('Không tìm thấy đơn hàng nội bộ để xem chi tiết.')
        return
      }

      setHistoryOrder({
        id: order.id,
        customerName: order.customer_name,
      })
    },
    [message],
  )

  const closeOrderHistory = () => {
    setHistoryOrder(null)
  }

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => changeOpportunityFilterShop(current, shopId))
    setAppliedFilters((current) => ({ ...current, shop_id: shopId, order_page_id: undefined }))
    setPage(1)
    clearSelection()
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setAppliedFilters(normalizeOpportunityFilters(draftFilters))
    setPage(1)
    clearSelection()
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyOpportunityFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeOpportunityFilters(emptyFilters))
    setPage(1)
    clearSelection()
  }

  const openAssignmentModal = useCallback(
    (rows) => {
      if (!canAssign || rows.length === 0) return
      setAssignmentRows(rows)
      setAssignmentUserId(undefined)
      setAssignmentError(null)
    },
    [canAssign],
  )

  const submitAssignment = () => {
    const orderIds = assignmentRows.map((order) => order.id).filter(Boolean)

    if (orderIds.length === 0) {
      setAssignmentError('Không tìm thấy đơn hàng nội bộ để phân công.')
      return
    }

    if (!assignmentUserId) {
      setAssignmentError('Vui lòng chọn nhân viên chăm sóc khách hàng.')
      return
    }

    setAssignmentError(null)
    assignMutation.mutate({ orderIds, pancakeUserId: assignmentUserId })
  }

  const orders = opportunitiesQuery.data?.orders ?? []
  const totalItems = opportunitiesQuery.data?.total_items ?? 0
  const perPage = opportunitiesQuery.data?.per_page ?? OPPORTUNITY_PAGE_SIZE
  const permissionDenied = !canView || isPermissionError(opportunitiesQuery.error)
  const hasAppliedFilters = Object.values(appliedFilters).some(
    (value) => value !== undefined && value !== '',
  )

  const columns = useMemo(() => {
    const baseColumns = [
      {
        title: 'STT',
        key: 'index',
        width: 68,
        align: 'center',
        render: (_, __, index) => (page - 1) * perPage + index + 1,
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 205,
        render: (shop) =>
          shop?.name ? (
            <Space size={8} className="order-opportunities-shop">
              <Avatar size={32} className="order-opportunities-shop__avatar" aria-hidden="true">
                {getInitial(shop.name)}
              </Avatar>
              <span>{shop.name}</span>
            </Space>
          ) : (
            '—'
          ),
      },
      {
        title: 'Khách hàng',
        dataIndex: 'customer_name',
        key: 'customer_name',
        width: 190,
        render: (name) => name || '—',
      },
      {
        title: 'Số điện thoại',
        dataIndex: 'customer_phone',
        key: 'customer_phone',
        width: 170,
        render: formatPhones,
      },
      {
        title: 'Mã đơn',
        dataIndex: 'pancake_order_id',
        key: 'pancake_order_id',
        width: 158,
        render: (orderCode, record) =>
          orderCode ? (
            <Tooltip title={record.id ? `ID nội bộ: ${record.id}` : undefined}>
              <Typography.Text className="order-opportunities-code">{orderCode}</Typography.Text>
            </Tooltip>
          ) : (
            '—'
          ),
      },
      {
        title: 'Nguồn đơn',
        dataIndex: 'order_page_name',
        key: 'order_page_name',
        width: 180,
        render: (_, record) => {
          const orderPageName = record.order_page_name ?? '—'

          if (orderPageName === '—') return orderPageName

          return (
            <Tooltip title={orderPageName}>
              <span className="order-opportunities-source">{orderPageName}</span>
            </Tooltip>
          )
        },
      },
      {
        title: 'Ngày tạo',
        dataIndex: 'created_at',
        key: 'created_at',
        width: 168,
        render: formatDateTime,
      },
      {
        title: 'Trạng thái đơn',
        dataIndex: 'status',
        key: 'status',
        width: 155,
        render: (status) => {
          const statusMeta = getOrderStatus(status)
          return <Tag color={statusMeta.color}>{statusMeta.label}</Tag>
        },
      },
      {
        title: 'Địa chỉ',
        dataIndex: 'customer_address',
        key: 'customer_address',
        width: 240,
        render: (address) =>
          address ? (
            <Tooltip title={address}>
              <span className="order-opportunities-address">{address}</span>
            </Tooltip>
          ) : (
            '—'
          ),
      },
    ]

    return [
      ...baseColumns,
      {
        title: 'Thao tác',
        key: 'action',
        width: 68,
        fixed: 'right',
        render: (_, record) => {
          const items = [
            ...(canAssign
              ? [
                  {
                    key: 'assign',
                    icon: <UserSwitchOutlined />,
                    label: 'Phân công',
                  },
                ]
              : []),
            ...(record?.id !== null && record?.id !== undefined && record.id !== ''
              ? [
                  {
                    key: 'detail',
                    icon: <EyeOutlined />,
                    label: 'Lịch sử đơn hàng',
                  },
                ]
              : []),
          ]

          if (items.length === 0) return '—'

          return (
            <Dropdown
              menu={{
                items,
                onClick: ({ key }) => {
                  if (key === 'assign') openAssignmentModal([record])
                  if (key === 'detail') openOrderHistory(record)
                },
              }}
              trigger={['click']}
              placement="bottomRight"
            >
              <Tooltip title="Thao tác">
                <Button
                  type="text"
                  icon={<MoreOutlined />}
                  aria-label={`Thao tác đơn hàng ${record.pancake_order_id || record.id}`}
                />
              </Tooltip>
            </Dropdown>
          )
        },
      },
    ]
  }, [canAssign, openAssignmentModal, openOrderHistory, page, perPage])

  const assignmentShopIds = [...new Set(assignmentRows.map((order) => order.shop_id).filter(Boolean))]
  const assignmentShopId = assignmentShopIds.length === 1 ? assignmentShopIds[0] : undefined
  const assignmentShopName = assignmentShopId ? assignmentRows[0]?.shop?.name : undefined
  const assignmentOrderCode = assignmentRows.length === 1 ? assignmentRows[0]?.pancake_order_id : undefined
  const assignmentCustomerName = assignmentRows.length === 1 ? assignmentRows[0]?.customer_name : undefined

  if (permissionDenied) {
    return (
      <Card className="order-opportunities-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem cơ hội"
          subTitle="Tài khoản hiện tại không có quyền truy cập danh sách cơ hội theo đơn hàng."
          extra={
            <Button type="primary" onClick={() => navigate(ROUTES.HOME)}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  if (opportunitiesQuery.isError && isUnauthorizedError(opportunitiesQuery.error)) {
    return (
      <Card className="order-opportunities-state-card">
        <Result
          status="403"
          title="Phiên đăng nhập không còn hiệu lực"
          subTitle="Vui lòng đăng nhập lại để tiếp tục xem danh sách cơ hội."
        />
      </Card>
    )
  }

  return (
    <main className="order-opportunities-page">
      <Card title="Cơ hội theo đơn hàng" className="order-opportunities-card">
        <form className="order-opportunities-filters" onSubmit={applyFilters}>
          <div className="order-opportunities-filters__grid">
            <div className="order-opportunities-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>

            <div className="order-opportunities-field">
              <label htmlFor="order-opportunities-source">Nguồn đơn</label>
              <Select
                id="order-opportunities-source"
                aria-label="Nguồn đơn"
                allowClear
                showSearch
                optionFilterProp="label"
                disabled={!draftFilters.shopId}
                loading={orderPagesQuery.isLoading || orderPagesQuery.isFetching}
                status={orderPagesQuery.isError ? 'error' : undefined}
                value={draftFilters.orderPageId}
                options={orderPageOptions}
                placeholder="Chọn nguồn đơn"
                onChange={(value) => updateDraft('orderPageId', value)}
                notFoundContent={
                  orderPagesQuery.isLoading
                    ? 'Đang tải...'
                    : orderPagesQuery.isError
                      ? 'Không thể tải nguồn đơn'
                      : 'Không có nguồn đơn'
                }
              />
              {orderPagesQuery.isError && (
                <Typography.Text
                  type="danger"
                  className="order-opportunities-field__error"
                  aria-live="polite"
                >
                  Không thể tải danh sách nguồn đơn.
                </Typography.Text>
              )}
            </div>

            <div className="order-opportunities-field">
              <label htmlFor="order-opportunities-phone">Số điện thoại</label>
              <Input
                id="order-opportunities-phone"
                type="tel"
                inputMode="tel"
                allowClear
                value={draftFilters.phone}
                onChange={(event) => updateDraft('phone', event.target.value)}
                placeholder="Nhập số điện thoại"
              />
            </div>

            <div className="order-opportunities-field order-opportunities-field--date">
              <label>Thời gian tạo đơn</label>
              <RangePicker
                aria-label="Khoảng thời gian tạo đơn"
                allowClear
                value={draftFilters.dateRange}
                onChange={(value) => updateDraft('dateRange', value)}
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
              />
            </div>

            <div className="order-opportunities-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />} className="filter-action-button">
                Lọc
              </Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
                Làm mới
              </Button>
            </div>
          </div>
        </form>

        <div className="order-opportunities-toolbar">
          <div className="order-opportunities-summary" aria-live="polite">
            Cơ hội chưa phân công: <strong>{totalItems.toLocaleString('vi-VN')}</strong>
            <span> · {perPage} bản ghi mỗi trang</span>
          </div>

          {canAssign && selectedRows.length > 0 && (
            <Button
              type="primary"
              icon={<UserSwitchOutlined />}
              onClick={() => openAssignmentModal(selectedRows)}
            >
              Phân công ({selectedRows.length.toLocaleString('vi-VN')})
            </Button>
          )}
        </div>

        {opportunitiesQuery.isError && (
          <QueryErrorAlert
            error={opportunitiesQuery.error}
            fallbackTitle="Không thể tải danh sách cơ hội"
            onRetry={() => opportunitiesQuery.refetch()}
            className="order-opportunities-query-alert"
          />
        )}

        <Table
          className="app-table"
          rowKey="id"
          columns={columns}
          dataSource={orders}
          loading={opportunitiesQuery.isLoading || opportunitiesQuery.isFetching}
          scroll={{ x: 1668 }}
          rowSelection={
            canAssign
              ? {
                  selectedRowKeys,
                  onChange: (keys, rows) => {
                    setSelectedRowKeys(keys)
                    setSelectedRows(rows)
                  },
                }
              : undefined
          }
          onChange={(pagination) => {
            setPage(pagination.current ?? 1)
            clearSelection()
          }}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={
                  <span>
                    Không có cơ hội chưa phân công
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
            position: ['bottomRight'],
            showTotal: (total) => `Tổng ${total.toLocaleString('vi-VN')} cơ hội`,
          }}
        />
      </Card>

      <CustomerCareAssignmentModal
        open={assignmentRows.length > 0}
        shopId={assignmentShopId}
        customerName={assignmentCustomerName}
        shopName={assignmentShopName}
        orderCode={assignmentOrderCode}
        selectedCount={assignmentRows.length}
        value={assignmentUserId}
        error={assignmentError}
        isSubmitting={assignMutation.isPending}
        onCancel={closeAssignmentModal}
        onSubmit={submitAssignment}
        onChange={(value) => {
          setAssignmentUserId(value)
          setAssignmentError(null)
        }}
      />

      <OrderHistoryModal
        key={historyOrder?.id ?? 'closed'}
        open={historyOrder !== null}
        orderId={historyOrder?.id ?? null}
        customerName={historyOrder?.customerName}
        onCancel={closeOrderHistory}
      />
    </main>
  )
}

export default OrderOpportunitiesPage
