import { EyeOutlined, FilterOutlined, MoreOutlined, ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Avatar, Button, Card, DatePicker, Descriptions, Dropdown, Empty, Input, InputNumber, Modal, Result, Select, Space, Table, Tag, Tooltip, Typography } from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getOrders, getOrderPages, getOrderTotals } from '../../api/orders.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import {
  DEFAULT_ORDER_PAGE_SIZE,
  getOrderStatus,
  ORDER_PAGE_SIZES,
  ORDER_STATUSES,
} from '../../constants/order.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import {
  changeOrderFilterShop,
  createEmptyOrderFilters,
  getOrderSourceDisplayName,
  getOrderPageOptions,
  normalizeOrderFilters,
} from './orderFilters.js'
import './orders.css'

const { RangePicker } = DatePicker




const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const formatVnd = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? new Intl.NumberFormat('vi-VN').format(amount) : '—'
}

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const OrdersPage = () => {
  const navigate = useNavigate()
  const [draftFilters, setDraftFilters] = useState(createEmptyOrderFilters)
  const [appliedFilters, setAppliedFilters] = useState(() => normalizeOrderFilters(createEmptyOrderFilters()))
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_ORDER_PAGE_SIZE)
  const [selectedOrder, setSelectedOrder] = useState(null)

  const orderParams = useMemo(
    () => ({ ...appliedFilters, page, page_size: pageSize }),
    [appliedFilters, page, pageSize],
  )

  const totalsParams = useMemo(() => ({ ...appliedFilters }), [appliedFilters])

  const ordersQuery = useQuery({
    queryKey: ['orders', orderParams],
    queryFn: ({ signal }) => getOrders({ ...orderParams, include_totals: false }, { signal }),
    placeholderData: keepPreviousData,
  })

  const orderTotalsQuery = useQuery({
    queryKey: ['order-totals', totalsParams],
    queryFn: ({ signal }) => getOrderTotals(totalsParams, { signal }),
    enabled: Boolean(ordersQuery.data) && !ordersQuery.isPlaceholderData,
    staleTime: 30_000,
  })

  const orderPagesQuery = useQuery({
    queryKey: ['order-pages', draftFilters.shopId ?? null],
    queryFn: ({ signal }) => getOrderPages({ shop_id: draftFilters.shopId }, { signal }),
    enabled: Boolean(draftFilters.shopId),
    // Page snapshots are changed by data sync, not by normal list browsing.
    // Reusing them avoids an additional request whenever this screen remounts.
    staleTime: 5 * 60_000,
  })

  const orderPageOptions = useMemo(
    () => getOrderPageOptions(orderPagesQuery.data),
    [orderPagesQuery.data],
  )

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setAppliedFilters(normalizeOrderFilters(draftFilters))
    setPage(1)
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyOrderFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeOrderFilters(emptyFilters))
    setPage(1)
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => changeOrderFilterShop(current, shopId))
    setAppliedFilters((current) => ({ ...current, shop_id: shopId, order_page_id: undefined }))
    setPage(1)
  }

  const handleOrderPageChange = (orderPageId) => {
    updateDraft('orderPageId', orderPageId)
  }

  const handleTableChange = (pagination) => {
    const nextPageSize = pagination.pageSize ?? pageSize
    if (nextPageSize !== pageSize) {
      setPageSize(nextPageSize)
      setPage(1)
      return
    }

    setPage(pagination.current ?? 1)
  }

  const orders = ordersQuery.data?.orders ?? []
  const totalItems = orderTotalsQuery.data?.total_items
  const totalRevenue = orderTotalsQuery.data?.total_revenue
  const hasMore = ordersQuery.data?.has_more === true
  const hasAppliedFilters = Object.values(appliedFilters).some((value) => value !== undefined && value !== '')
  const permissionDenied = isPermissionError(ordersQuery.error)

  const columns = useMemo(
    () => [
      {
        title: 'STT',
        key: 'index',
        width: 68,
        align: 'center',
        render: (_, __, index) => (page - 1) * pageSize + index + 1,
      },
      {
        title: 'ID',
        dataIndex: 'pancake_order_id',
        key: 'pancake_order_id',
        width: 144,
        render: (orderId, record) =>
          orderId ? (
            <Tooltip title={record.id ? `ID nội bộ: ${record.id}` : undefined}>
              <span className="orders-id">{orderId}</span>
            </Tooltip>
          ) : (
            '—'
          ),
      },
      {
        title: 'Trạng thái',
        dataIndex: 'status',
        key: 'status',
        width: 150,
        render: (status) => {
          const statusMeta = getOrderStatus(status)
          return <Tag color={statusMeta.color}>{statusMeta.label}</Tag>
        },
      },
      // Tạm thời ẩn cột Trạng thái VTP theo yêu cầu (ẩn chứ không xóa, đổi hidden thành false để hiển thị lại)
      {
        title: 'Trạng thái VTP',
        dataIndex: 'status_vtp',
        key: 'status_vtp',
        width: 140,
        hidden: true,
        render: (status) => status ?? '—',
      },
      {
        title: 'Mã vận đơn',
        dataIndex: 'order_number_vtp',
        key: 'order_number_vtp',
        width: 170,
        render: (trackingCode) => trackingCode || '—',
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 205,
        render: (shop) =>
          shop?.name ? (
            <Space size={8} className="orders-shop">
              <Avatar size={32} className="orders-shop__avatar" aria-hidden="true">
                {getInitial(shop.name)}
              </Avatar>
              <span>{shop.name}</span>
            </Space>
          ) : (
            '—'
          ),
      },
      {
        title: 'Nguồn đơn',
        dataIndex: 'order_page_name',
        key: 'order_page_name',
        width: 180,
        render: (pageName, record) => {
          const displayName = getOrderSourceDisplayName(pageName, record.order_source_name)
          if (displayName === '—') return displayName

          return (
            <Tooltip title={displayName}>
              <span className="orders-source">{displayName}</span>
            </Tooltip>
          )
        },
      },
      {
        title: 'Số lượng',
        dataIndex: 'total_quantity',
        key: 'total_quantity',
        width: 118,
        align: 'center',
        render: (quantity) => {
          if (quantity === null || quantity === undefined || quantity === '') return '—'
          return <Tag color="green">{quantity} sản phẩm</Tag>
        },
      },
      {
        title: 'Tổng giá trị (VND)',
        key: 'total_amount',
        width: 150,
        align: 'right',
        render: (_, order) => (
          <span className="orders-money">
            {formatVnd(Number(order.cod ?? 0) + Number(order.prepaid_amount ?? 0))}
          </span>
        ),
      },
      {
        title: 'COD (VND)',
        dataIndex: 'cod',
        key: 'cod',
        width: 190,
        align: 'right',
        render: (cod) => <span className="orders-money">{formatVnd(cod)}</span>,
      },
      {
        title: 'Khách đã trả (VND)',
        dataIndex: 'prepaid_amount',
        key: 'customer_paid',
        width: 190,
        align: 'right',
        render: (prepaidAmount) => <span className="orders-money">{formatVnd(prepaidAmount)}</span>,
      },
      {
        title: 'Phí vận chuyển (VND)',
        dataIndex: 'shipping_fee',
        key: 'shipping_fee',
        width: 190,
        align: 'right',
        render: (shippingFee) => <span className="orders-money">{formatVnd(shippingFee)}</span>,
      },
      {
        title: 'Khách hàng',
        dataIndex: 'customer_name',
        key: 'customer_name',
        width: 190,
        render: (customerName) => customerName || '—',
      },
      // Tạm thời ẩn cột Thành phố theo yêu cầu (ẩn chứ không xóa, đổi hidden thành false để hiển thị lại)
      {
        title: 'Th\u00e0nh ph\u1ed1',
        dataIndex: 'province_name',
        key: 'province_name',
        width: 150,
        hidden: true,
        render: (provinceName) => provinceName || '\u2014',
      },
      {
        title: 'Thao tác',
        key: 'actions',
        width: 68,
        align: 'center',
        fixed: 'right',
        className: 'orders-action-column',
        render: (_, order) => (
          <Dropdown
            menu={{
              items: [{
                key: 'detail',
                icon: <EyeOutlined />,
                label: 'Xem chi tiết',
                onClick: () => setSelectedOrder(order),
              }],
            }}
            trigger={['click']}
            placement="bottomRight"
          >
            <Button
              type="text"
              size="small"
              icon={<MoreOutlined />}
              aria-label={`Mở thao tác đơn hàng ${order.pancake_order_id || order.id}`}
              aria-haspopup="menu"
            />
          </Dropdown>
        ),
      },
    ].filter((col) => !col.hidden),
    [page, pageSize],
  )

  const tableScrollX = useMemo(
    () => columns.reduce((acc, col) => acc + (typeof col.width === 'number' ? col.width : 150), 0),
    [columns],
  )

  if (permissionDenied) {
    return (
      <Card className="orders-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem đơn hàng"
          subTitle="Tài khoản hiện tại không có quyền truy cập dữ liệu đơn hàng trong phạm vi đã chọn."
          extra={
            <Button type="primary" onClick={() => navigate(ROUTES.HOME)}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  if (ordersQuery.isError && isUnauthorizedError(ordersQuery.error)) {
    return (
      <Card className="orders-state-card">
        <Result
          status="403"
          title="Phiên đăng nhập không còn hiệu lực"
          subTitle="Vui lòng đăng nhập lại để tiếp tục xem danh sách đơn hàng."
        />
      </Card>
    )
  }

  return (
    <main className="orders-page">
      <Card title="Danh sách đơn hàng" className="orders-card">
        <form className="orders-filters" onSubmit={applyFilters}>
          <div className="orders-filters__grid">
            <div className="orders-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>

            <div className="orders-field">
              <label htmlFor="orders-source">Nguồn đơn</label>
              <Select
                id="orders-source"
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
                onChange={handleOrderPageChange}
                notFoundContent={
                  orderPagesQuery.isLoading
                    ? 'Đang tải...'
                    : orderPagesQuery.isError
                      ? 'Không thể tải nguồn đơn'
                      : 'Không có nguồn đơn'
                }
              />
              {orderPagesQuery.isError && (
                <Typography.Text type="danger" className="orders-field__error" aria-live="polite">
                  Không thể tải danh sách nguồn đơn.
                </Typography.Text>
              )}
            </div>

            <div className="orders-field">
              <label htmlFor="orders-search">Tìm kiếm</label>
              <Input
                id="orders-search"
                allowClear
                value={draftFilters.search}
                onChange={(event) => updateDraft('search', event.target.value)}
                placeholder="Tìm kiếm đơn hàng..."
              />
            </div>

            <div className="orders-field orders-field--date">
              <label>Thời gian</label>
              <RangePicker
                aria-label="Khoảng thời gian tạo đơn"
                allowClear
                value={draftFilters.dateRange}
                onChange={(value) => updateDraft('dateRange', value)}
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
              />
            </div>

            <div className="orders-field">
              <label>Trạng thái</label>
              <Select
                aria-label="Trạng thái đơn hàng"
                allowClear
                showSearch
                optionFilterProp="label"
                value={draftFilters.status}
                options={ORDER_STATUSES}
                placeholder="Chọn trạng thái"
                onChange={(value) => updateDraft('status', value)}
              />
            </div>

            <div className="orders-field">
              <label htmlFor="orders-cod">COD (VND)</label>
              <InputNumber
                id="orders-cod"
                aria-label="Lọc theo số tiền COD"
                min={0}
                precision={0}
                value={draftFilters.cod}
                onChange={(value) => updateDraft('cod', value)}
                placeholder="Nhập số tiền COD"
                style={{ width: '100%' }}
              />
            </div>

            <div className="orders-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />} className="filter-action-button">
                Lọc
              </Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
                Làm mới
              </Button>
            </div>
          </div>
        </form>

        <div className="orders-summary" aria-live="polite">
          Tổng số đơn hàng: <strong>{totalItems === undefined || totalItems === null ? 'Đang tính…' : totalItems.toLocaleString('vi-VN')}</strong>
          {totalRevenue !== undefined && totalRevenue !== null && (
            <>
              <span aria-hidden="true"> – </span>
              Tổng tiền (VND): <strong>{formatVnd(totalRevenue)}</strong>
            </>
          )}
        </div>

        {ordersQuery.isError && (
          <QueryErrorAlert
            error={ordersQuery.error}
            fallbackTitle="Không thể tải danh sách đơn hàng"
            onRetry={() => ordersQuery.refetch()}
            className="orders-query-alert"
          />
        )}

        <Table
          className="app-table"
          rowKey={(record) => record.id ?? record.pancake_order_id}
          tableLayout="fixed"
          columns={columns}
          dataSource={orders}
          loading={ordersQuery.isLoading}
          scroll={{ x: tableScrollX }}
          onChange={handleTableChange}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={
                  <span>
                    Không có đơn hàng phù hợp.
                    {hasAppliedFilters && <small>Hãy thử thay đổi hoặc xóa bớt bộ lọc.</small>}
                  </span>
                }
              />
            ),
          }}
          pagination={{
            current: page,
            pageSize,
            total: totalItems ?? (hasMore ? page * pageSize + 1 : (page - 1) * pageSize + orders.length),
            showSizeChanger: true,
            pageSizeOptions: ORDER_PAGE_SIZES.map(String),
            position: ['bottomRight'],
            showTotal: (total) => totalItems === undefined || totalItems === null
              ? 'Đang tính tổng số đơn hàng…'
              : `Tổng ${total.toLocaleString('vi-VN')} đơn hàng`,
          }}
        />

        <Modal
          title="Chi tiết đơn hàng"
          open={selectedOrder !== null}
          onCancel={() => setSelectedOrder(null)}
          footer={null}
          width={720}
          destroyOnHidden
        >
          {selectedOrder && (
            <Descriptions bordered column={1} size="small" className="orders-details">
              <Descriptions.Item label="Mã đơn">
                {selectedOrder.pancake_order_id || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Tạo lúc">
                {formatDateTime(selectedOrder.created_at)}
              </Descriptions.Item>
              <Descriptions.Item label="Nhân viên tạo">
                {selectedOrder.user_creator?.name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Trạng thái">
                {getOrderStatus(selectedOrder.status).label}
              </Descriptions.Item>
              <Descriptions.Item label="Mã vận đơn">
                {selectedOrder.order_number_vtp || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Cửa hàng">
                {selectedOrder.shop?.name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Nguồn đơn">
                {getOrderSourceDisplayName(selectedOrder.order_page_name, selectedOrder.order_source_name)}
              </Descriptions.Item>
              <Descriptions.Item label="Số lượng">
                {selectedOrder.total_quantity ?? '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Tổng giá trị (VND)">
                {formatVnd(Number(selectedOrder.cod ?? 0) + Number(selectedOrder.prepaid_amount ?? 0))}
              </Descriptions.Item>
              <Descriptions.Item label="COD (VND)">
                {formatVnd(selectedOrder.cod)}
              </Descriptions.Item>
              <Descriptions.Item label="Khách đã trả (VND)">
                {formatVnd(selectedOrder.prepaid_amount)}
              </Descriptions.Item>
              <Descriptions.Item label="Khách hàng">
                {selectedOrder.customer_name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Số điện thoại">
                {selectedOrder.customer_phone || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Địa chỉ">
                {selectedOrder.customer_address || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Thành phố">
                {selectedOrder.province_name || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Ghi chú">
                <span className="orders-detail-note">{selectedOrder.note || '—'}</span>
              </Descriptions.Item>
            </Descriptions>
          )}
        </Modal>
      </Card>
    </main>
  )
}

export default OrdersPage
