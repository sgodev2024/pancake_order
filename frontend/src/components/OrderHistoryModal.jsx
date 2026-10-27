import { useQuery } from '@tanstack/react-query'
import { App as AntdApp, Empty, Image, Modal, Table, Typography } from 'antd'
import dayjs from 'dayjs'
import { useEffect, useMemo, useState } from 'react'
import { getOrderHistory } from '../api/orders.api.js'

const DEFAULT_PAGE_SIZE = 30

const PRODUCT_PLACEHOLDER =
  'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%226%22 fill=%22%23f1f5f9%22/%3E%3Cpath d=%22M13 31l7-8 5 5 4-4 6 7H13z%22 fill=%22%2394a3b8%22/%3E%3Ccircle cx=%2220%22 cy=%2218%22 r=%223%22 fill=%22%2394a3b8%22/%3E%3C/svg%3E'

const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const formatVnd = (value) => {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)
  return Number.isFinite(amount) ? `${amount.toLocaleString('vi-VN')} đ` : '—'
}

const getHistoryErrorMessage = (error) => {
  const status = error?.status ?? error?.response?.status

  if (status === 403) return 'Bạn không có quyền xem lịch sử đơn hàng này.'
  if (status === 404) return 'Không tìm thấy thông tin đơn hàng.'
  if (status === 422) return 'Dữ liệu yêu cầu không hợp lệ.'
  return 'Không thể tải lịch sử đơn hàng. Vui lòng thử lại.'
}

const ProductCell = ({ products }) => {
  if (!Array.isArray(products) || products.length === 0) return '—'

  return (
    <div style={{ display: 'grid', gap: 8, padding: '4px 0' }}>
      {products.map((product, index) => {
        const name = product?.name || 'Sản phẩm'
        const image = product?.image || PRODUCT_PLACEHOLDER
        const quantity = product?.quantity ?? 0

        return (
          <div
            key={`${name}-${index}`}
            style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 0 }}
          >
            <Image
              src={image}
              fallback={PRODUCT_PLACEHOLDER}
              preview={Boolean(product?.image)}
              width={44}
              height={44}
              alt={name}
              style={{ flex: '0 0 auto', objectFit: 'cover', borderRadius: 6 }}
            />
            <div style={{ minWidth: 0, lineHeight: 1.35 }}>
              <Typography.Text ellipsis={{ tooltip: name }} style={{ display: 'block' }}>
                {name}
              </Typography.Text>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                x {quantity}
              </Typography.Text>
            </div>
          </div>
        )
      })}
    </div>
  )
}

const OrderHistoryModal = ({ open, orderId, customerName, onCancel }) => {
  const { message } = AntdApp.useApp()
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(DEFAULT_PAGE_SIZE)
  const hasOrderId = orderId !== null && orderId !== undefined && orderId !== ''

  const historyQuery = useQuery({
    queryKey: ['order-history', orderId, page, perPage],
    queryFn: () => getOrderHistory(orderId, { page, per_page: perPage }),
    enabled: open && hasOrderId,
    retry: false,
  })

  useEffect(() => {
    if (historyQuery.isError) {
      message.error(getHistoryErrorMessage(historyQuery.error))
    }
  }, [historyQuery.error, historyQuery.isError, message])

  const meta = historyQuery.data?.meta ?? {}
  const currentPage = Number(meta.current_page) || page
  const currentPerPage = Number(meta.per_page) || perPage
  const total = Number(meta.total) || 0
  const rows = historyQuery.isError ? [] : historyQuery.data?.data ?? []

  const columns = useMemo(
    () => [
      {
        title: 'STT',
        key: 'index',
        width: 64,
        align: 'center',
        render: (_, __, index) => (currentPage - 1) * currentPerPage + index + 1,
      },
      {
        title: 'Sản phẩm',
        dataIndex: 'products',
        key: 'products',
        width: 410,
        render: (products) => <ProductCell products={products} />,
      },
      {
        title: 'Số tiền',
        dataIndex: 'amount',
        key: 'amount',
        width: 140,
        align: 'right',
        render: formatVnd,
      },
      {
        title: 'Người tạo đơn hàng',
        dataIndex: 'creator',
        key: 'creator',
        width: 180,
        render: (creator) => creator?.name || '—',
      },
      {
        title: 'Ngày mua',
        dataIndex: 'created_at',
        key: 'created_at',
        width: 170,
        render: formatDateTime,
      },
    ],
    [currentPage, currentPerPage],
  )

  const handleTableChange = (nextPage, nextPerPage) => {
    setPerPage(nextPerPage)
    setPage(nextPerPage === perPage ? nextPage : 1)
  }

  return (
    <Modal
      title={`Đơn hàng của "${customerName || '—'}"`}
      open={open}
      onCancel={onCancel}
      footer={null}
      destroyOnHidden
      centered
      width="min(1000px, 95vw)"
      styles={{ body: { maxHeight: 'calc(100vh - 160px)', overflow: 'hidden' } }}
    >
      <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
        Tổng số đơn hàng: <strong>{total.toLocaleString('vi-VN')}</strong>
      </Typography.Paragraph>

      {historyQuery.isError ? (
        <Empty description={getHistoryErrorMessage(historyQuery.error)} />
      ) : (
        <Table
          className="app-table"
          size="small"
          rowKey="id"
          columns={columns}
          dataSource={rows}
          loading={historyQuery.isLoading || historyQuery.isFetching}
          scroll={{ x: 964, y: 'min(500px, calc(100vh - 260px))' }}
          pagination={{
            current: currentPage,
            pageSize: currentPerPage,
            total,
            showSizeChanger: false,
            position: ['bottomRight'],
            showTotal: (count) => `Tổng ${count.toLocaleString('vi-VN')} đơn hàng`,
            onChange: handleTableChange,
          }}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description="Không có dữ liệu đơn hàng"
              />
            ),
          }}
        />
      )}
    </Modal>
  )
}

export default OrderHistoryModal
