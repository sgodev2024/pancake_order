import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Button, Card, Empty, Select, Table, Typography } from 'antd'
import { useMemo, useState } from 'react'
import { getStaffAssignmentMonitoring } from '../../api/users.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import './staff-assignment-tracking.css'

const StaffAssignmentTrackingPage = () => {
  const [draft, setDraft] = useState({ shopId: undefined, staffId: undefined })
  const [filters, setFilters] = useState({ shopId: undefined, staffId: undefined })
  const [page, setPage] = useState(1)
  const monitoringQuery = useQuery({
    queryKey: ['staff-assignment-monitoring', filters],
    queryFn: () => getStaffAssignmentMonitoring({ shop_id: filters.shopId, staff_id: filters.staffId }),
    placeholderData: keepPreviousData,
  })
  const items = monitoringQuery.data?.items ?? []
  const staffOptions = useMemo(() => items.map((staff) => ({ value: String(staff.id), label: staff.name })), [items])
  const apply = () => {
    setFilters(draft)
    setPage(1)
  }
  const reset = () => {
    const empty = { shopId: undefined, staffId: undefined }
    setDraft(empty)
    setFilters(empty)
    setPage(1)
  }
  const renderProgress = (done, total, type) => {
    const value = Number(done || 0)
    const color = value > 0 && type !== 'today' ? '#ff4d1a' : value > 0 ? '#1677ff' : '#94a3b8'
    return <span className="staff-assignment-progress"><strong style={{ color }}>{value}</strong> / {Number(total || 0)}</span>
  }
  const columns = [
    { title: 'STT', width: 90, align: 'center', render: (_, __, index) => (page - 1) * 30 + index + 1 },
    { title: 'Nhân viên', dataIndex: 'name', width: 320, render: (value) => value || '—' },
    { title: 'Chăm sóc hôm nay', align: 'center', width: 260, render: (_, item) => renderProgress(item.today_done, item.today_total, 'today') },
    { title: 'Chăm sóc sắp diễn ra', align: 'center', width: 260, render: (_, item) => renderProgress(item.upcoming_done, item.upcoming_total, 'upcoming') },
    { title: 'Chăm sóc quá hạn', align: 'center', width: 260, render: (_, item) => renderProgress(item.overdue_done, item.overdue_total, 'overdue') },
  ]

  return <main className="staff-assignment-tracking-page">
    <div className="staff-assignment-tracking-header"><Typography.Title level={3}>Theo dõi phân bổ nhân viên</Typography.Title></div>
    <Card className="staff-assignment-tracking-card" bodyStyle={{ padding: 0 }}>
      <div className="staff-assignment-toolbar">
        <ShopSelect className="staff-assignment-filter staff-assignment-filter--shop" value={draft.shopId} onChange={(shopId) => setDraft((current) => ({ ...current, shopId, staffId: undefined }))} useGlobalSelection={false} />
        <Select allowClear showSearch optionFilterProp="label" value={draft.staffId} onChange={(staffId) => setDraft((current) => ({ ...current, staffId }))} options={staffOptions} className="staff-assignment-filter" placeholder="Chọn nhân viên" />
        <Button type="primary" icon={<FilterOutlined />} onClick={apply}>Lọc</Button>
        <Button icon={<ReloadOutlined />} onClick={reset}>Làm mới</Button>
      </div>
      <div className="staff-assignment-summary">Tổng số lịch hẹn: <strong>{Number(monitoringQuery.data?.total_appointments || 0).toLocaleString('vi-VN')}</strong></div>
      {monitoringQuery.isError && <Alert type="error" showIcon message="Không thể tải thống kê phân bổ nhân viên" description={getApiErrorMessage(monitoringQuery.error)} />}
      <Table className="app-table staff-assignment-table" columns={columns} dataSource={items} rowKey="id" loading={monitoringQuery.isLoading || monitoringQuery.isFetching} scroll={{ x: 1190, y: 'calc(100vh - 390px)' }} pagination={{ current: page, pageSize: 30, showSizeChanger: false, showTotal: (total) => `Tổng: ${total}`, onChange: setPage }} locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có nhân viên CSKH" /> }} />
    </Card>
  </main>
}

export default StaffAssignmentTrackingPage
