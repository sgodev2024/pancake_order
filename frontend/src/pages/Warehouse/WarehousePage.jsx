import { EyeOutlined, FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Descriptions, Empty, Input, Modal, Result, Space, Spin, Table, Tabs, Tag, Timeline, Tooltip, Typography } from 'antd'
import dayjs from 'dayjs'
import { useState } from 'react'
import { completePacking, createViettelPostShipment, getWarehouseOrder, getWarehouseOrders } from '../../api/warehouse.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { canPackOrders, canViewWarehouse } from '../../auth/permissions.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { ProductCell } from '../../components/OrderHistoryModal.jsx'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import { getOrderStatus } from '../../constants/order.js'
import { getApiErrorMessage, isPermissionError } from '../../utils/response.js'
import './warehouse.css'
import { ShipmentButton, ShipmentStatus } from './ViettelPostShipment.jsx'
import { shipmentConfirmation } from './shipmentConfirmation.js'

const dateTime = (value) => value ? dayjs(value).format('DD/MM/YYYY HH:mm:ss') : '—'
const sourceTag = (order) => order.origin === 'local_pos' ? null : <Tag color="purple">Pancake</Tag>
const SingleLineTooltip = ({ value, className = '' }) => (
  <Tooltip title={value}><span className={`warehouse-single-line ${className}`}>{value}</span></Tooltip>
)
const WarehousePage = () => {
  const user = useAuthStore((state) => state.user)
  const allowed = canViewWarehouse(user)
  const canPack = canPackOrders(user)
  const { modal, message } = App.useApp()
  const client = useQueryClient()
  const [status, setStatus] = useState('waiting_packing')
  const [shopId, setShopId] = useState()
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState({})
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState(null)
  const pageSize = 20
  const list = useQuery({
    queryKey: ['warehouse-orders', status, filters, page],
    queryFn: () => getWarehouseOrders({ ...filters, status, page, page_size: pageSize }),
    enabled: allowed, retry: 1,
  })
  const detail = useQuery({
    queryKey: ['warehouse-order', selectedId], queryFn: () => getWarehouseOrder(selectedId),
    enabled: allowed && !!selectedId, retry: false,
  })
  const pack = useMutation({
    mutationFn: completePacking, retry: false,
    onSuccess: (response) => {
      message.success(response.message)
      client.invalidateQueries({ queryKey: ['warehouse-orders'] })
      client.invalidateQueries({ queryKey: ['warehouse-order', response.data.id] })
      client.invalidateQueries({ queryKey: ['orders'] })
      client.invalidateQueries({ queryKey: ['order-detail'] })
    },
    onError: (error) => {
      message.error(getApiErrorMessage(error, 'Không thể hoàn tất đóng hàng.'))
      client.invalidateQueries({ queryKey: ['warehouse-orders'] })
      client.invalidateQueries({ queryKey: ['warehouse-order', selectedId] })
    },
  })
  const configuration = list.data?.module
  const shippingMode = list.data?.shipping?.mode ?? 'mock'
  const refreshShipping = () => {
    client.invalidateQueries({ queryKey: ['warehouse-orders'] })
    client.invalidateQueries({ queryKey: ['warehouse-order'] })
    client.invalidateQueries({ queryKey: ['orders'] })
    client.invalidateQueries({ queryKey: ['order-detail'] })
  }
  const shipment = useMutation({
    mutationFn: createViettelPostShipment, retry: false,
    onSuccess: (response) => { message.success(response.message); refreshShipping() },
    onError: (error) => { message.error(getApiErrorMessage(error, 'Không thể tạo vận đơn.')); refreshShipping() },
  })
  const confirmShipment = (order) => modal.confirm(shipmentConfirmation(order, shippingMode, () => shipment.mutateAsync(order.id)))
  const confirmPacking = (order) => modal.confirm({
    title: `Hoàn tất đóng hàng đơn ${order.reference}?`,
    content: 'Xác nhận đã kiểm tra đủ sản phẩm, số lượng và đóng gói thực tế. Thao tác này không xác nhận giao hàng hay thanh toán.',
    okText: 'Hoàn tất đóng hàng', cancelText: 'Kiểm tra lại',
    onOk: () => pack.mutateAsync(order.id),
  })
  const apply = () => { setFilters({ shop_id: shopId, search: search.trim() }); setPage(1) }
  const reset = () => { setShopId(undefined); setSearch(''); setFilters({}); setPage(1) }
  if (!allowed || isPermissionError(list.error)) return <Result status="403" title="Bạn chưa có quyền Kho" subTitle="Cần quyền xem Kho; hoàn tất đóng hàng cần được cấp thêm quyền xử lý. Liên hệ quản trị viên để được phân quyền theo shop." />

  const columns = [
    { title: 'Mã đơn / Nguồn', key: 'reference', width: 175, render: (_, order) => <Space orientation="vertical" size={4}><strong>{order.reference}</strong>{sourceTag(order)}</Space> },
    { title: 'Cửa hàng', key: 'shop', width: 150, render: (_, order) => {
      const name = order.shop?.name || '—'
      return <SingleLineTooltip value={name} />
    } },
    { title: 'Khách hàng', key: 'customer', width: 190, render: (_, order) => {
      const name = order.customer_name || '—'
      const phone = order.customer_phone || '—'
      return <Space className="warehouse-customer-cell" orientation="vertical" size={2}>
        <Tooltip title={name}><strong className="warehouse-single-line">{name}</strong></Tooltip>
        <Tooltip title={phone}><Typography.Text type="secondary" className="warehouse-single-line">{phone}</Typography.Text></Tooltip>
      </Space>
    } },
    { title: 'Sản phẩm', dataIndex: 'items', width: 270, render: (items = []) => <><ProductCell products={items.slice(0, 2)} />{items.length > 2 && <Typography.Text type="secondary">+{items.length - 2} sản phẩm khác · xem chi tiết</Typography.Text>}</> },
    { title: 'Tổng SL', dataIndex: 'total_quantity', width: 85, align: 'right' },
    { title: 'Ghi chú', dataIndex: 'note', width: 200, render: (value) => {
      const note = value || '—'
      return <Tooltip title={note}><span className="warehouse-note warehouse-note--table">{note}</span></Tooltip>
    } },
    ...(status === 'packed' ? [
      { title: 'Người đóng hàng', key: 'packer', width: 160, render: (_, order) => <SingleLineTooltip value={order.packed_by?.name || `ID ${order.packed_by_user_id}`} /> },
      { title: 'Hoàn tất lúc', dataIndex: 'packed_at', width: 180, render: dateTime },
      { title: 'Vận đơn Viettel Post', key: 'shipment', width: 250, render: (_, order) => <ShipmentStatus order={order} /> },
    ] : [{ title: 'Ngày tạo gốc', key: 'created', width: 180, render: (_, order) => dateTime(order.origin === 'local_pos' ? order.created_at : order.source_created_at) }]),
    { title: 'Thao tác', key: 'actions', width: status === 'packed' ? 420 : 220, fixed: 'right', render: (_, order) => <Space size={8}>
      <Button icon={<EyeOutlined />} onClick={() => { pack.reset(); setSelectedId(order.id) }}>Chi tiết</Button>
      {status === 'waiting_packing' && canPack && <Button type="primary" disabled={!configuration?.enabled || pack.isPending} onClick={() => confirmPacking(order)}>Hoàn tất</Button>}
      <ShipmentButton order={order} allowed={canPack} pending={shipment.isPending} mode={shippingMode} onCreate={confirmShipment} />
    </Space> },
  ]
  const order = detail.data
  return <main className="warehouse-page"><Card title="Quản lý Kho" extra={<Button icon={<ReloadOutlined />} onClick={() => list.refetch()} loading={list.isFetching}>Tải lại</Button>}>
    {status === 'packed' && <Alert className="warehouse-alert" showIcon type={shippingMode === 'live' ? 'warning' : 'info'} title={shippingMode === 'live'
      ? (list.data?.shipping?.live_enabled && list.data?.shipping?.live_creation_enabled ? 'AICRM Live · Tạo vận đơn thật cho đơn được phê duyệt' : 'AICRM Live · Tạo vận đơn thật đang tắt')
      : shippingMode !== 'sandbox' ? 'Vận đơn đang ở chế độ Mock trên Local' : 'Vận đơn đang ở chế độ AICRM Sandbox'}
      description="Tạo vận đơn bằng nút xác nhận của từng đơn. Nếu chưa rõ kết quả gửi, cần đối soát trước khi thử lại." />}
    {configuration && !configuration.enabled && <Alert className="warehouse-alert" type={configuration.configuration_valid ? 'info' : 'warning'} showIcon
      title={configuration.configuration_valid ? 'Module Kho đang tắt' : 'Thời điểm kích hoạt Kho chưa hợp lệ'}
      description={configuration.activated_at ? `Dự kiến kích hoạt: ${dateTime(configuration.activated_at)}. Đơn cũ không tự đưa vào Kho.` : 'Chưa kích hoạt chính thức. Không có đơn lịch sử hoặc đơn mẫu được tự đưa vào hàng đợi.'} />}
    {configuration?.enabled && <Typography.Paragraph type="secondary">Chỉ xử lý đơn phát sinh từ {dateTime(configuration.activated_at)}. Đã đóng hàng không đồng nghĩa đã giao hoặc đã thanh toán.</Typography.Paragraph>}
    <div className="warehouse-filters"><div><label>Cửa hàng</label><ShopSelect value={shopId} onChange={setShopId} useGlobalSelection={false} /></div>
      <div><label htmlFor="warehouse-search">Mã đơn / Khách hàng</label><Input id="warehouse-search" value={search} allowClear onChange={(event) => setSearch(event.target.value)} onPressEnter={apply} placeholder="Mã đơn, tên hoặc số điện thoại" /></div>
      <Space><Button type="primary" icon={<FilterOutlined />} onClick={apply}>Lọc</Button><Button onClick={reset}>Làm mới</Button></Space></div>
    <Tabs activeKey={status} onChange={(value) => { setStatus(value); setPage(1); setSelectedId(null) }} items={[{ key: 'waiting_packing', label: 'Chờ đóng hàng' }, { key: 'packed', label: 'Đã đóng hàng' }]} />
    {list.isError && <QueryErrorAlert error={list.error} onRetry={list.refetch} fallbackTitle="Không tải được danh sách Kho" />}
    <Table className="app-table" rowKey="id" columns={columns} dataSource={list.data?.orders ?? []} loading={list.isFetching} scroll={{ x: 1300 }}
      pagination={{ current: page, pageSize, total: list.data?.total_items ?? 0, showSizeChanger: false, onChange: setPage, showTotal: (total) => `${total} đơn hàng` }}
      locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={configuration?.enabled ? 'Không có đơn phù hợp' : 'Kho chưa được kích hoạt'} /> }} />
    <Modal open={!!selectedId} title={order ? `Đơn ${order.reference}` : 'Chi tiết đơn Kho'} width={820} onCancel={() => setSelectedId(null)} footer={
      <Space><Button onClick={() => setSelectedId(null)}>Đóng</Button>{canPack && order?.warehouse_status === 'waiting_packing' && <Button type="primary" disabled={detail.isError || !configuration?.enabled} loading={pack.isPending} onClick={() => confirmPacking(order)}>Hoàn tất đóng hàng</Button>}
        {!detail.isError && order && <ShipmentButton order={order} allowed={canPack} pending={shipment.isPending} mode={shippingMode} onCreate={confirmShipment} />}</Space>
    }>
      {detail.isFetching ? <Spin /> : detail.isError ? <QueryErrorAlert error={detail.error} onRetry={detail.refetch} /> : order && <>
        <Space style={{ marginBottom: 16 }}>{sourceTag(order)}<Tag color={order.warehouse_status === 'packed' ? 'green' : 'gold'}>{order.warehouse_status === 'packed' ? 'Đã đóng hàng' : 'Chờ đóng hàng'}</Tag></Space>
        <Descriptions column={1} size="small" items={[
          { key: 'shop', label: 'Cửa hàng', children: order.shop?.name },
          { key: 'customer', label: 'Khách hàng', children: `${order.customer_name || '—'} · ${order.customer_phone || '—'}` },
          { key: 'address', label: 'Địa chỉ', children: order.customer_address || '—' },
          { key: 'creator', label: 'Người tạo ban đầu', children: order.creator?.name || order.creator?.id || '—' },
          { key: 'created', label: 'Ngày tạo gốc', children: dateTime(order.origin === 'local_pos' ? order.created_at : order.source_created_at) },
          { key: 'synced', label: 'Đồng bộ lần đầu', children: dateTime(order.first_synced_at) },
          { key: 'shipping', label: 'Trạng thái đơn gốc', children: getOrderStatus(order.status).label },
          { key: 'note', label: 'Ghi chú', children: <strong className="warehouse-note">{order.note || '—'}</strong> },
          ...(order.packed_at ? [{ key: 'packed', label: 'Hoàn tất đóng hàng', children: `${order.packed_by?.name || 'ID '+order.packed_by_user_id} · ${dateTime(order.packed_at)}` }] : []),
        ]} />
        <div className="warehouse-detail-products"><ProductCell products={order.items} /></div>
        {order.warehouse_status === 'packed' && <><Typography.Title level={5}>Vận đơn Viettel Post</Typography.Title><ShipmentStatus order={order} history /></>}
        {!!order.history?.length && <><Typography.Title level={5}>Lịch sử thao tác</Typography.Title><Timeline items={order.history.map((event) => ({ key: event.id,
          content: `${event.action === 'warehouse.packed' ? 'Hoàn tất đóng hàng' : 'Loại khỏi hàng đợi'} · ${event.actor_name || 'Hệ thống'} · ${dateTime(event.created_at)}` }))} /></>}
      </>}
    </Modal>
  </Card></main>
}
export default WarehousePage
