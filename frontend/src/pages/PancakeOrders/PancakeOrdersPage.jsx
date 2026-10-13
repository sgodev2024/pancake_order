import {
  CloudServerOutlined,
  EyeOutlined,
  FilterOutlined,
  ReloadOutlined,
  SearchOutlined,
} from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  Alert,
  Button,
  Card,
  DatePicker,
  Descriptions,
  Empty,
  Input,
  Modal,
  Select,
  Space,
  Table,
  Tag,
  Tooltip,
} from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { getPancakeOrderDetail, getPancakeOrders } from '../../api/pancake-orders.api.js'
import './pancake-orders.css'

const { RangePicker } = DatePicker

const ORDER_STATUS_OPTIONS = [
  { value: '', label: 'Tất cả trạng thái' },
  { value: 'pending', label: 'Chờ xử lý' },
  { value: 'confirmed', label: 'Đã xác nhận' },
  { value: 'completed', label: 'Đã hoàn thành' },
  { value: 'cancelled', label: 'Đã hủy' },
]

const PAYMENT_STATUS_OPTIONS = [
  { value: '', label: 'Tất cả TT thanh toán' },
  { value: '0', label: 'Chờ thanh toán' },
  { value: '1', label: 'Đã thanh toán' },
  { value: '2', label: 'Thanh toán đặt cọc' },
  { value: '3', label: 'Thất bại' },
  { value: '4', label: 'Đã hủy' },
]

const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const formatVnd = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? `${new Intl.NumberFormat('vi-VN').format(amount)} đ` : '—'
}

const renderOrderStatusTag = (status, text) => {
  const map = {
    pending: { color: 'warning', label: 'Chờ xử lý' },
    confirmed: { color: 'processing', label: 'Đã xác nhận' },
    completed: { color: 'success', label: 'Đã hoàn thành' },
    cancelled: { color: 'error', label: 'Đã hủy' },
  }
  const config = map[status] || { color: 'default', label: text || status || 'Chưa xác định' }
  return <Tag color={config.color}>{text || config.label}</Tag>
}

const renderPaymentStatusTag = (paymentStatus, text) => {
  const map = {
    0: { color: 'warning', label: 'Chờ thanh toán' },
    1: { color: 'success', label: 'Đã thanh toán' },
    2: { color: 'cyan', label: 'Đặt cọc' },
    3: { color: 'error', label: 'Thất bại' },
    4: { color: 'default', label: 'Đã hủy' },
    cancelled: { color: 'default', label: 'Đã hủy' },
  }
  const config = map[String(paymentStatus)] || { color: 'default', label: text || 'Chờ thanh toán' }
  return <Tag color={config.color}>{text || config.label}</Tag>
}

const PancakeOrdersPage = () => {
  const [draftFilters, setDraftFilters] = useState({
    search: '',
    status: '',
    payment_status: '',
    dateRange: null,
  })

  const [appliedFilters, setAppliedFilters] = useState({
    search: '',
    status: '',
    payment_status: '',
    start_date: '',
    end_date: '',
  })

  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [selectedOrder, setSelectedOrder] = useState(null)
  const [detailModalOpen, setDetailModalOpen] = useState(false)

  const queryParams = useMemo(() => ({
    page,
    per_page: pageSize,
    search: appliedFilters.search || undefined,
    status: appliedFilters.status || undefined,
    payment_status: appliedFilters.payment_status !== '' ? appliedFilters.payment_status : undefined,
    start_date: appliedFilters.start_date || undefined,
    end_date: appliedFilters.end_date || undefined,
  }), [appliedFilters, page, pageSize])

  const {
    data: queryResult,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    queryKey: ['pancake-orders', queryParams],
    queryFn: ({ signal }) => getPancakeOrders(queryParams, { signal }),
    placeholderData: keepPreviousData,
  })

  const orders = useMemo(() => {
    return Array.isArray(queryResult?.data) ? queryResult.data : []
  }, [queryResult?.data])

  const pagination = queryResult?.pagination || {
    current_page: page,
    per_page: pageSize,
    total: orders.length,
    last_page: 1,
  }

  const handleSearch = (e) => {
    e?.preventDefault?.()
    setAppliedFilters({
      search: draftFilters.search.trim(),
      status: draftFilters.status,
      payment_status: draftFilters.payment_status,
      start_date: draftFilters.dateRange?.[0] ? dayjs(draftFilters.dateRange[0]).format('YYYY-MM-DD') : '',
      end_date: draftFilters.dateRange?.[1] ? dayjs(draftFilters.dateRange[1]).format('YYYY-MM-DD') : '',
    })
    setPage(1)
  }

  const handleReset = () => {
    const emptyDraft = {
      search: '',
      status: '',
      payment_status: '',
      dateRange: null,
    }
    setDraftFilters(emptyDraft)
    setAppliedFilters({
      search: '',
      status: '',
      payment_status: '',
      start_date: '',
      end_date: '',
    })
    setPage(1)
  }

  const handleViewDetail = async (record) => {
    setSelectedOrder(record)
    setDetailModalOpen(true)
    try {
      const fullDetail = await getPancakeOrderDetail(record.id)
      if (fullDetail?.data) {
        setSelectedOrder(fullDetail.data)
      }
    } catch {
      // Keep summary record if detail fails
    }
  }

  const columns = useMemo(() => [
    {
      title: 'Mã đơn',
      dataIndex: 'code',
      key: 'code',
      width: 140,
      render: (code, record) => (
        <span className="pancake-orders-code">{code || `HC-${record.id}`}</span>
      ),
    },
    {
      title: 'Khách hàng',
      key: 'customer',
      width: 220,
      render: (_, record) => (
        <div>
          <div className="pancake-orders-customer-name">{record.customer_name || 'Khách vãng lai'}</div>
          {record.email && <div className="pancake-orders-customer-sub">{record.email}</div>}
        </div>
      ),
    },
    {
      title: 'Số điện thoại',
      dataIndex: 'phone',
      key: 'phone',
      width: 150,
      render: (phone) => phone || '—',
    },
    {
      title: 'Ngày tạo đơn',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 170,
      render: (val) => formatDateTime(val),
    },
    {
      title: 'Tổng tiền',
      dataIndex: 'total_price',
      key: 'total_price',
      width: 160,
      align: 'right',
      render: (val) => <span className="pancake-orders-money">{formatVnd(val)}</span>,
    },
    {
      title: 'Phương thức thanh toán',
      dataIndex: 'payment_method_text',
      key: 'payment_method_text',
      width: 220,
      render: (text) => text || 'Chưa xác định',
    },
    {
      title: 'Trạng thái đơn hàng',
      dataIndex: 'status',
      key: 'status',
      width: 160,
      align: 'center',
      render: (status, record) => renderOrderStatusTag(status, record.status_text),
    },
    {
      title: 'Trạng thái thanh toán',
      dataIndex: 'payment_status',
      key: 'payment_status',
      width: 170,
      align: 'center',
      render: (paymentStatus, record) => renderPaymentStatusTag(paymentStatus, record.payment_status_text),
    },
    {
      title: 'Thao tác',
      key: 'actions',
      width: 100,
      align: 'center',
      fixed: 'right',
      render: (_, record) => (
        <Tooltip title="Xem chi tiết đơn hàng">
          <Button
            type="text"
            size="small"
            icon={<EyeOutlined />}
            onClick={() => handleViewDetail(record)}
          >
            Chi tiết
          </Button>
        </Tooltip>
      ),
    },
  ], [])

  return (
    <div className="pancake-orders-page">
      <Card
        className="pancake-orders-card"
        title={
          <Space>
            <span>Danh sách đơn hàng Pancake (Hương Chất)</span>
            <Tag color="blue" icon={<CloudServerOutlined />} className="pancake-orders-source-tag">
              Nguồn: Hương Chất API
            </Tag>
          </Space>
        }
        extra={
          <div className="pancake-orders-header-actions">
            <Button
              icon={<ReloadOutlined spin={isFetching} />}
              onClick={() => refetch()}
              disabled={isLoading}
            >
              Làm mới
            </Button>
          </div>
        }
      >
        {error && (
          <Alert
            className="pancake-orders-alert"
            type="error"
            showIcon
            message="Không thể kết nối đến hệ thống Hương Chất"
            description={error.message || 'Đã xảy ra lỗi khi gọi API đơn hàng từ Hương Chất. Vui lòng kiểm tra kết nối mạng và thử lại.'}
            action={
              <Button size="small" danger onClick={() => refetch()}>
                Thử lại
              </Button>
            }
          />
        )}

        <form onSubmit={handleSearch} className="pancake-orders-filters">
          <div className="pancake-orders-filters__grid">
            <div className="pancake-orders-field">
              <label htmlFor="pancake-filter-search">Tìm kiếm</label>
              <Input
                id="pancake-filter-search"
                placeholder="Mã đơn, khách hàng, SĐT..."
                prefix={<SearchOutlined />}
                allowClear
                value={draftFilters.search}
                onChange={(e) => setDraftFilters((prev) => ({ ...prev, search: e.target.value }))}
              />
            </div>

            <div className="pancake-orders-field">
              <label htmlFor="pancake-filter-status">Trạng thái đơn</label>
              <Select
                id="pancake-filter-status"
                value={draftFilters.status}
                options={ORDER_STATUS_OPTIONS}
                onChange={(val) => setDraftFilters((prev) => ({ ...prev, status: val }))}
              />
            </div>

            <div className="pancake-orders-field">
              <label htmlFor="pancake-filter-payment">TT thanh toán</label>
              <Select
                id="pancake-filter-payment"
                value={draftFilters.payment_status}
                options={PAYMENT_STATUS_OPTIONS}
                onChange={(val) => setDraftFilters((prev) => ({ ...prev, payment_status: val }))}
              />
            </div>

            <div className="pancake-orders-field pancake-orders-field--date">
              <label htmlFor="pancake-filter-daterange">Thời gian tạo đơn</label>
              <RangePicker
                id="pancake-filter-daterange"
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
                value={draftFilters.dateRange}
                onChange={(dates) => setDraftFilters((prev) => ({ ...prev, dateRange: dates }))}
              />
            </div>

            <div className="pancake-orders-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />}>
                Lọc
              </Button>
              <Button onClick={handleReset}>Đặt lại</Button>
            </div>
          </div>
        </form>

        <Table
          rowKey="id"
          columns={columns}
          dataSource={orders}
          loading={isLoading}
          scroll={{ x: 1200 }}
          locale={{
            emptyText: <Empty description="Không có đơn hàng Pancake nào từ Hương Chất" />,
          }}
          pagination={{
            current: page,
            pageSize,
            total: pagination.total,
            showSizeChanger: true,
            pageSizeOptions: ['10', '20', '50', '100'],
            showTotal: (total, range) => `${range[0]}-${range[1]} / ${total} đơn hàng`,
            onChange: (newPage, newPageSize) => {
              setPage(newPage)
              setPageSize(newPageSize)
            },
          }}
        />
      </Card>

      <Modal
        title={`Chi tiết đơn hàng ${selectedOrder?.code || (selectedOrder?.id ? `HC-${selectedOrder.id}` : '')}`}
        open={detailModalOpen}
        onCancel={() => setDetailModalOpen(false)}
        footer={[
          <Button key="close" type="primary" onClick={() => setDetailModalOpen(false)}>
            Đóng
          </Button>,
        ]}
        width={720}
      >
        {selectedOrder && (
          <div>
            <Descriptions bordered size="small" column={{ xs: 1, sm: 2 }}>
              <Descriptions.Item label="Mã đơn">
                <strong>{selectedOrder.code || `HC-${selectedOrder.id}`}</strong>
              </Descriptions.Item>
              <Descriptions.Item label="Ngày tạo">
                {formatDateTime(selectedOrder.created_at)}
              </Descriptions.Item>
              <Descriptions.Item label="Khách hàng">
                {selectedOrder.customer_name || 'Khách vãng lai'}
              </Descriptions.Item>
              <Descriptions.Item label="Số điện thoại">
                {selectedOrder.phone || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Email">
                {selectedOrder.email || '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Phương thức TT">
                {selectedOrder.payment_method_text || selectedOrder.payment_method || 'Chưa xác định'}
              </Descriptions.Item>
              <Descriptions.Item label="Trạng thái đơn">
                {renderOrderStatusTag(selectedOrder.status, selectedOrder.status_text)}
              </Descriptions.Item>
              <Descriptions.Item label="Trạng thái TT">
                {renderPaymentStatusTag(selectedOrder.payment_status, selectedOrder.payment_status_text)}
              </Descriptions.Item>
              <Descriptions.Item label="Tổng tiền" span={2}>
                <span className="pancake-orders-money" style={{ fontSize: '16px' }}>
                  {formatVnd(selectedOrder.total_price)}
                </span>
                {selectedOrder.deposit_amount > 0 && (
                  <span style={{ marginLeft: 12, color: '#6b7280' }}>
                    (Đặt cọc: {formatVnd(selectedOrder.deposit_amount)})
                  </span>
                )}
              </Descriptions.Item>
              <Descriptions.Item label="Địa chỉ" span={2}>
                {selectedOrder.address || '—'}
              </Descriptions.Item>
              {selectedOrder.notes && (
                <Descriptions.Item label="Ghi chú" span={2}>
                  {selectedOrder.notes}
                </Descriptions.Item>
              )}
            </Descriptions>

            {Array.isArray(selectedOrder.products) && selectedOrder.products.length > 0 && (
              <div style={{ marginTop: 20 }}>
                <h4 style={{ marginBottom: 8 }}>Sản phẩm trong đơn ({selectedOrder.products.length})</h4>
                <Table
                  size="small"
                  rowKey="id"
                  pagination={false}
                  dataSource={selectedOrder.products}
                  columns={[
                    {
                      title: 'Sản phẩm',
                      dataIndex: 'name',
                      key: 'name',
                      render: (name, item) => (
                        <Space>
                          {item.image && (
                            <img
                              src={item.image}
                              alt={name}
                              style={{ width: 36, height: 36, objectFit: 'cover', borderRadius: 4 }}
                            />
                          )}
                          <span>{name}</span>
                        </Space>
                      ),
                    },
                    {
                      title: 'Đơn giá',
                      dataIndex: 'price',
                      key: 'price',
                      align: 'right',
                      render: (val) => formatVnd(val),
                    },
                    {
                      title: 'SL',
                      dataIndex: 'quantity',
                      key: 'quantity',
                      align: 'center',
                      width: 70,
                    },
                    {
                      title: 'Thành tiền',
                      key: 'amount',
                      align: 'right',
                      render: (_, item) => formatVnd(Number(item.price || 0) * Number(item.quantity || 1)),
                    },
                  ]}
                />
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

export default PancakeOrdersPage
