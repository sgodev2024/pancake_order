import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Alert, App, Button, Card, DatePicker, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd'
import dayjs from 'dayjs'
import { useNavigate } from 'react-router-dom'
import apiClient from '../../api/client.js'
import { getShops } from '../../api/shops.api.js'
import { hasPermission, canUsePos } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import { ROUTES } from '../../constants/routes.js'

const initial = () => ({ months: [dayjs().startOf('month'), dayjs().endOf('month')], shops: [], products: [], match: 'any', search: '' })
const paramsFor = (filters) => ({
  shop_ids: filters.shops.length ? filters.shops : undefined,
  products: filters.products.length ? filters.products : undefined,
  month_from: filters.months[0].format('YYYY-MM'),
  month_to: filters.months[1].format('YYYY-MM'),
  match: filters.match,
  quantity_min: filters.min ?? undefined,
  quantity_max: filters.max ?? undefined,
  last_purchase_before: filters.last?.format('YYYY-MM-DD'),
  search: filters.search.trim() || undefined,
})

export default function CareSegmentsPage() {
  const user = useAuthStore((state) => state.user)
  const allowed = hasPermission(user, 'care-segment-view')
  const navigate = useNavigate()
  const { message } = App.useApp()
  const [draft, setDraft] = useState(initial)
  const [filters, setFilters] = useState(initial)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState([])
  const [detail, setDetail] = useState(null)
  const [exporting, setExporting] = useState(false)
  const shops = useQuery({ queryKey: ['shops', 'segments', user?.id], queryFn: () => getShops({ view: 'summary' }), enabled: allowed })
  const report = useQuery({
    queryKey: ['care-segments', user?.id, paramsFor(filters), page],
    queryFn: async ({ signal }) => (await apiClient.get('/care-segments', { params: { ...paramsFor(filters), page, page_size: 30 }, signal })).data.data,
    enabled: allowed, retry: false,
  })
  const update = (field, value) => setDraft((old) => ({ ...old, [field]: value }))
  const apply = () => {
    if (!draft.months?.[0] || !draft.months?.[1]) { message.error('Chọn khoảng tháng.'); return }
    if (draft.max != null && draft.min != null && draft.max < draft.min) { message.error('Số lượng tối đa phải lớn hơn hoặc bằng tối thiểu.'); return }
    setFilters({ ...draft }); setPage(1); setSelected([])
  }
  const exportFile = async () => {
    setExporting(true)
    try {
      const response = await apiClient.post('/care-segments/export', { ...paramsFor(filters), export: true, selected }, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = 'loc-cskh.xlsx'
      link.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch {
      message.error('Không xuất được danh sách. Vui lòng thử lại.')
    } finally { setExporting(false) }
  }
  if (!allowed) return <Alert type="warning" title="Bạn chưa có quyền Lọc CSKH" />
  const columns = [
    { title: 'Cửa hàng', dataIndex: 'shop_name', width: 160 },
    { title: 'Khách hàng', dataIndex: 'name', width: 180 },
    { title: 'Số điện thoại', dataIndex: 'phone', width: 150 },
    { title: 'Nhân viên phụ trách hồ sơ', dataIndex: 'staff_name', width: 180 },
    { title: 'Sản phẩm trong kỳ', key: 'products', width: 300, render: (_, row) => row.products.map((p) => <div key={p.name}>{p.name} × <strong>{p.quantity.toLocaleString('vi-VN')}</strong></div>) },
    { title: 'Số lượng', dataIndex: 'quantity', width: 100 },
    { title: 'Số đơn', dataIndex: 'order_count', width: 90 },
    { title: 'Mua gần nhất trong kỳ', dataIndex: 'last_purchase_at', width: 180, render: (date) => dayjs(date).format('DD/MM/YYYY HH:mm') },
    { title: 'Thao tác', key: 'actions', width: 220, render: (_, row) => <Space wrap>
      <Button onClick={() => setDetail(row)}>Lịch sử mua</Button>
      {canUsePos(user) && <Button onClick={() => navigate(`${ROUTES.POS}?customer_id=${row.customer_id}&shop_id=${row.shop_id}`)}>Tạo POS</Button>}
    </Space> },
  ]
  return <Card title="Lọc CSKH">
    <Typography.Paragraph type="secondary">
      Tổng hợp đơn giao thành công từ CRM theo ngày tạo đơn. Sản phẩm được nhóm theo tên, không phân biệt chữ hoa/thường.
    </Typography.Paragraph>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16, marginBottom: 16 }}>
      <div><label>Cửa hàng</label><Select aria-label="Cửa hàng Lọc CSKH" mode="multiple" style={{ width: '100%' }} allowClear
        placeholder="Tất cả cửa hàng được phân công" value={draft.shops} onChange={(v) => setDraft((old) => ({ ...old, shops: v, products: [] }))}
        loading={shops.isFetching} options={(shops.data ?? []).map((s) => ({ value: s.id, label: s.name }))} /></div>
      <div><label>Tháng mua hàng</label><DatePicker.RangePicker picker="month" value={draft.months} onChange={(v) => update('months', v)} style={{ width: '100%' }} /></div>
      <div><label>Sản phẩm đã mua</label><Select aria-label="Sản phẩm đã mua" mode="multiple" showSearch optionFilterProp="search"
        placeholder="Tên hoặc mã sản phẩm" style={{ width: '100%' }} value={draft.products} onChange={(v) => update('products', v)}
        options={(report.data?.products ?? []).map((p) => ({ ...p, search: `${p.label} ${p.code}`, label: p.code ? `${p.label} · ${p.code}` : p.label }))} /></div>
      <div><label>Điều kiện sản phẩm</label><Select style={{ width: '100%' }} value={draft.match} onChange={(v) => update('match', v)}
        options={[{value: 'any', label: 'Mua ít nhất một sản phẩm'}, {value: 'all', label: 'Mua đủ các sản phẩm đã chọn'}]} /></div>
      <div><label>Tổng số lượng trong kỳ</label><Space.Compact style={{ width: '100%' }}>
        <InputNumber min={0} placeholder="Từ" value={draft.min} onChange={(v) => update('min', v)} />
        <InputNumber min={0} placeholder="Đến" value={draft.max} onChange={(v) => update('max', v)} />
      </Space.Compact></div>
      <div><label>Lần mua gần nhất trong kỳ trước ngày</label><DatePicker style={{ width: '100%' }} value={draft.last} onChange={(v) => update('last', v)} /></div>
      <div><label>Khách hàng / số điện thoại</label><Input value={draft.search} onChange={(e) => update('search', e.target.value)} onPressEnter={apply} /></div>
    </div>
    <Space wrap style={{ marginBottom: 16 }}>
      <Button type="primary" onClick={apply} loading={report.isFetching}>Lọc danh sách</Button>
      <Button onClick={() => { const empty = initial(); setDraft(empty); setFilters(empty); setSelected([]); setPage(1) }}>Đặt lại</Button>
      {hasPermission(user, 'care-segment-export') && <Button onClick={exportFile} loading={exporting} disabled={!report.data?.total || report.isFetching}>
        {selected.length ? `Xuất Excel đã chọn (${selected.length})` : 'Xuất Excel kết quả'}
      </Button>}
      <Typography.Text>Tổng khách trong cửa hàng phù hợp: <strong>{report.data?.total ?? 0}</strong></Typography.Text>
    </Space>
    {shops.isError && <QueryErrorAlert error={shops.error} onRetry={shops.refetch} />}
    {report.isError && <QueryErrorAlert error={report.error} onRetry={report.refetch} />}
    {!!report.data?.unmatched_orders && <Alert style={{ marginBottom: 16 }} showIcon type="warning"
      title={`${report.data.unmatched_orders} đơn giao thành công trong kỳ chưa liên kết duy nhất với hồ sơ khách nên chưa được tính.`} />}
    <Table rowKey="key" columns={columns} dataSource={report.data?.customers ?? []} loading={report.isFetching}
      rowSelection={{ selectedRowKeys: selected, preserveSelectedRowKeys: true, onChange: setSelected }}
      scroll={{ x: 1560 }} pagination={{ current: page, pageSize: 30, total: report.data?.total ?? 0, onChange: setPage, showSizeChanger: false }} />
    <Modal open={!!detail} onCancel={() => setDetail(null)} footer={null} title={`Lịch sử mua trong kỳ · ${detail?.name ?? ''}`} width={850}>
      <Table rowKey="id" pagination={{ pageSize: 10 }} dataSource={detail?.orders ?? []} columns={[
        {title: 'Mã đơn', dataIndex: 'reference'}, {title: 'Ngày tạo', dataIndex: 'created_at'},
        {title: 'Sản phẩm đã lọc', render: (_, order) => order.products.map((p) => <div key={p.name}>{p.name} × {p.quantity}</div>)},
      ]} />
    </Modal>
  </Card>
}
