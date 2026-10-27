import dayjs from 'dayjs'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Alert, App, Button, Card, DatePicker, Input, Modal, Col, Row, Space, Statistic, Table, Tag } from 'antd'
import apiClient from '../../api/client.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'

const time = (value) => value ? new Date(value).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' }) : 'Chưa có dữ liệu'
export default function WebhookMonitorPage() {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => { const timer = setInterval(() => setNow(Date.now()), 15000); return () => clearInterval(timer) }, [])
  const query = useQuery({ queryKey: ['webhook-monitor'], queryFn: async () => (await apiClient.get('/webhook-monitor')).data.data, refetchInterval: 15000 })
  const { message } = App.useApp()
  const [cleanupOpen, setCleanupOpen] = useState(false)
  const [beforeDate, setBeforeDate] = useState(() => dayjs().subtract(30, 'day'))
  const [preview, setPreview] = useState(null)
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const cleanup = async (action) => {
    setBusy(true)
    try {
      const response = (await apiClient.post('/webhook-monitor/cleanup', {
        action, before_date: beforeDate.format('YYYY-MM-DD'),
        ...(action === 'delete' ? { token: preview.token, confirmation } : {}),
      }, { timeout: 300000 })).data
      if (action === 'preview') setPreview(response.data)
      else { message.success(response.message); setCleanupOpen(false); setPreview(null); setConfirmation(''); query.refetch() }
    } catch (error) { message.error(error.message || 'Không thể dọn lịch sử.'); setPreview(null) }
    finally { setBusy(false) }
  }
  const data = query.data
  const snapshot = data?.snapshot
  const stale = !snapshot || now - Date.parse(snapshot.checked_at) > 150000
  const rows = (data?.shops ?? []).map(shop => ({ ...data?.events?.shops?.[String(shop.pancake_shop_id)], key: shop.id, name: shop.name, shop_id: String(shop.pancake_shop_id) }))
  for (const [id, stats] of Object.entries(data?.events?.shops ?? {})) {
    if (!rows.some(row => row.shop_id === id)) rows.push({ ...stats, key: `unknown-${id}`, name: 'Shop chưa xác định' })
  }
  const oldestAge = snapshot?.queue?.oldest_unix ? Math.max(0, Math.floor(now / 1000 - snapshot.queue.oldest_unix)) : 0
  const recentErrors = (data?.events?.recent ?? []).filter(event => event.kind === 'error' && now - Date.parse(event.at) < 900000).length
  const columns = [
    { title: 'Thời gian', key: 'last_activity', width: 190, render: (_, row) => {
      const latest = [row.last_received, row.last_processed, row.last_skipped, row.last_error]
        .filter(value => value && Number.isFinite(Date.parse(value)))
        .sort((a, b) => Date.parse(b) - Date.parse(a))[0]
      return time(latest)
    } },
    { title: 'Cửa hàng / Pancake ID', render: (_, row) => <>{row.name}<br /><small>{row.shop_id}</small></> },
    ...[['received', 'Đã nhận'], ['processed', 'Đã lưu'], ['skipped', 'Bỏ qua'], ['error', 'Lỗi / lần thử']].map(([key, title]) => ({ title, dataIndex: key, render: value => value ?? 0 })),
    { title: 'Nhận gần nhất', dataIndex: 'last_received', render: time },
    { title: 'Lưu thành công gần nhất', dataIndex: 'last_processed', render: time },
    { title: 'Tín hiệu', render: (_, row) => <Tag color={!row.last_received ? 'default' : now - Date.parse(row.last_received) > 900000 ? 'orange' : 'green'}>{!row.last_received ? 'Chưa nhận' : now - Date.parse(row.last_received) > 900000 ? 'Im lặng > 15 phút' : 'Có tín hiệu gần đây'}</Tag> },
  ]
  return <Space orientation="vertical" size="large" style={{ width: '100%' }}>
    <Space wrap><h2>Giám sát Webhook Pancake</h2><Button loading={query.isFetching} onClick={() => query.refetch()}>Làm mới</Button></Space>
    {query.isError && <QueryErrorAlert error={query.error} />}
    <Alert type={stale || !snapshot?.worker_running ? 'error' : oldestAge > 300 ? 'warning' : 'success'} showIcon title={stale ? 'Không có heartbeat mới từ bộ giám sát' : !snapshot.worker_running ? 'Worker webhook đang dừng' : oldestAge > 300 ? 'Hàng đợi đang chậm hơn 5 phút' : 'Worker webhook đang online'} description={`Heartbeat: ${time(snapshot?.checked_at)}. Kiểm tra mỗi phút; màn hình cập nhật mỗi 15 giây.`} />
    {recentErrors > 0 && <Alert type="warning" showIcon title={`Có ${recentErrors} lần xử lý lỗi trong các sự kiện gần đây (15 phút)`} description="Xem bảng lỗi bên dưới để kiểm tra. Worker online không đồng nghĩa mọi đơn đã lưu thành công." />}
    <Row gutter={[16,16]}>
      <Col xs={24} md={6}><Card><Statistic title="Job đang chờ / xử lý" value={snapshot?.queue?.pending ?? '—'} /></Card></Col>
      <Col xs={24} md={6}><Card><Statistic title="Job đã được worker nhận" value={snapshot?.queue?.reserved ?? 0} /></Card></Col>
      <Col xs={24} md={6}><Card><Statistic title="Tuổi job lâu nhất (giây)" value={oldestAge} /></Card></Col>
      <Col xs={24} md={6}><Card><Statistic title="Job lỗi lịch sử" value={snapshot?.failed?.total ?? '—'} /><Button danger size="small" style={{ marginTop: 12 }} onClick={() => { setPreview(null); setConfirmation(''); setCleanupOpen(true) }}>Dọn lịch sử job lỗi</Button></Card></Col>
    </Row>
    <Alert type="info" title={`Số liệu nhận / lưu / bỏ qua / lỗi bắt đầu từ ${time(data?.events?.started_at)}`} description="Không nhận sự kiện hơn 15 phút là tín hiệu cần kiểm tra, chưa khẳng định mất kết nối. Lỗi được đếm theo lần thử; chỉ Đã lưu xác nhận transaction lưu đơn hoàn tất. Dữ liệu lịch sử không được cộng vào các bộ đếm này." />
    {snapshot?.retry_after <= snapshot?.worker_timeout && <Alert type="warning" title="Thời gian nhận lại job ngắn hơn timeout worker" description={`retry_after=${snapshot.retry_after}s, timeout=${snapshot.worker_timeout}s. Job chạy lâu có nguy cơ bị xử lý đồng thời; cần rà soát cấu hình riêng.`} />}
    <Card title="Theo dõi từng cửa hàng"><Table columns={columns} dataSource={rows} loading={query.isPending} scroll={{ x: 1300 }} pagination={{ pageSize: 10 }} /></Card>
    <Card title="Lỗi và lý do bỏ qua gần đây"><Table rowKey={(_, index) => index} dataSource={data?.events?.recent ?? []} columns={[{ title: 'Thời gian', dataIndex: 'at', render: time }, { title: 'Pancake shop ID', dataIndex: 'shop_id' }, { title: 'Loại', dataIndex: 'kind' }, { title: 'Lý do', dataIndex: 'reason' }]} /></Card>
    <Modal title="Dọn lịch sử job lỗi webhook" open={cleanupOpen} onCancel={() => { if (!busy) setCleanupOpen(false) }} closable={!busy} maskClosable={!busy} footer={null}>
      <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
        <Alert type="warning" showIcon title="Chỉ xóa lịch sử lỗi webhook Pancake" description="Không chạy lại job, không xóa đơn hàng/khách hàng, không tác động job đang chờ. Sao lưu riêng trên server trước khi xóa. Mỗi lượt tối đa 5.000 bản ghi." />
        <span>Xóa lỗi trước 00:00 ngày (giờ Việt Nam):</span>
        <DatePicker value={beforeDate} allowClear={false} disabled={busy} format="DD/MM/YYYY" disabledDate={date => date.isAfter(dayjs(), 'day')} onChange={value => { setBeforeDate(value); setPreview(null); setConfirmation('') }} />
        <Space>{[7,30].map(days => <Button key={days} disabled={busy} onClick={() => { setBeforeDate(dayjs().subtract(days, 'day')); setPreview(null); setConfirmation('') }}>Cũ hơn {days} ngày</Button>)}</Space>
        <Button loading={busy} disabled={!beforeDate} onClick={() => cleanup('preview')}>Xem trước</Button>
        {preview && <>
          <Alert type="info" title={`${Number(preview.stats.total).toLocaleString('vi-VN')} bản ghi sẽ được xóa`} description={`Tổng phù hợp: ${Number(preview.stats.eligible).toLocaleString('vi-VN')}. Khoảng lỗi phù hợp: từ ${time(preview.stats.oldest)} đến ${time(preview.stats.newest)}. Xem trước có hiệu lực 10 phút.`} />
          <Input placeholder="Nhập XÓA để xác nhận" value={confirmation} disabled={busy} onChange={event => setConfirmation(event.target.value)} />
          <Button danger type="primary" loading={busy} disabled={confirmation !== 'XÓA' || !preview.stats.total} onClick={() => cleanup('delete')}>Sao lưu và xóa lịch sử</Button>
        </>}
        <span>Giữ cửa sổ mở khi xử lý. Nếu báo gián đoạn, kiểm tra trạng thái trước khi thử lại.</span>
      </Space>
    </Modal>
    <small>Lần job lỗi lịch sử gần nhất: {time(snapshot?.failed?.latest)}. Chi tiết kỹ thuật và payload vẫn nằm trong log server; màn hình không hiển thị dữ liệu khách hàng.</small>
  </Space>
}
