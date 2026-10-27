import { ReloadOutlined, SearchOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Button, Card, DatePicker, Empty, Select, Space, Table, Tag, Typography } from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { getCustomerCares } from '../../api/customer-care.api.js'
import { getAllUsers } from '../../api/users.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import './customer-assignment-tracking.css'

const { RangePicker } = DatePicker
const PAGE_SIZE = 30

const formatPhone = (value) => {
  if (Array.isArray(value)) return value.join(', ')
  if (!value) return '—'
  try {
    const parsed = JSON.parse(value)
    return Array.isArray(parsed) ? parsed.join(', ') : parsed?.phone || value
  } catch {
    return value
  }
}

const getAssignment = (care) => care.current_assignment || care.active_assignment

const getTrackingStatus = (care) => {
  if (Number(care.status) === 1) return { label: 'Đã chăm sóc', color: 'green' }
  if (care.date_care && dayjs(care.date_care).isBefore(dayjs(), 'day')) return { label: 'Quá hạn', color: 'red' }
  return { label: 'Chờ xử lý', color: 'orange' }
}

const CustomerAssignmentTrackingPage = () => {
  const [draft, setDraft] = useState({ shopId: undefined, staffId: undefined, dateRange: null })
  const [filters, setFilters] = useState({ shopId: undefined, staffId: undefined, dateRange: null })
  const [page, setPage] = useState(1)

  const params = useMemo(() => ({
    context: 'v2', type: 'assignment_tracking', shop_id: filters.shopId, page,
    date_from: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
    date_to: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
  }), [filters, page])
  const caresQuery = useQuery({
    queryKey: ['customer-cares', 'assignment-tracking', params],
    queryFn: () => getCustomerCares(params),
    placeholderData: keepPreviousData,
  })
  const usersQuery = useQuery({ queryKey: ['assignment-tracking-users'], queryFn: () => getAllUsers(), staleTime: 60_000 })

  const staffOptions = useMemo(() => (usersQuery.data ?? []).map((user) => ({
    value: String(user.id), label: user.name || `Nhân viên #${user.id}`,
  })), [usersQuery.data])
  const allRows = caresQuery.data?.customers ?? []
  const rows = useMemo(() => allRows.filter((care) => {
    const assignment = getAssignment(care)
    return assignment && (!filters.staffId || String(assignment.assignee_user_id) === String(filters.staffId))
  }), [allRows, filters.staffId])
  const total = caresQuery.data?.total_items ?? 0

  const apply = () => { setFilters(draft); setPage(1) }
  const reset = () => {
    const empty = { shopId: undefined, staffId: undefined, dateRange: null }
    setDraft(empty); setFilters(empty); setPage(1)
  }

  const columns = [
    { title: 'STT', width: 72, align: 'center', render: (_, __, index) => (page - 1) * PAGE_SIZE + index + 1 },
    { title: 'Tên nhân viên', width: 220, render: (_, care) => {
      const name = getAssignment(care)?.assignee?.name || '—'
      return <Space size={8}><div className="customer-assignment-avatar">{name.charAt(0).toLocaleUpperCase('vi')}</div><span>{name}</span></Space>
    } },
    { title: 'Khách hàng', dataIndex: 'customer_name', width: 200, render: (value) => value || '' },
    { title: 'Số điện thoại', dataIndex: 'customer_phones', width: 170, render: formatPhone },
    { title: 'Mã đơn', dataIndex: 'pancake_order_id', width: 150, render: (value) => value || '' },
    { title: 'Cửa hàng', dataIndex: ['shop', 'name'], width: 190, render: (value) => value || '' },
    { title: 'Hạn xử lý', dataIndex: 'date_care', width: 140, render: (value) => value ? dayjs(value).format('DD/MM/YYYY') : '—' },
    { title: 'Lịch chăm sóc gần nhất', dataIndex: 'time_care', width: 210, render: (value) => value ? dayjs(value).format('DD/MM/YYYY HH:mm') : '' },
    { title: 'Ngày phân công', width: 175, render: (_, care) => {
      const value = getAssignment(care)?.assigned_at
      return value ? dayjs(value).format('DD/MM/YYYY HH:mm') : ''
    } },
    { title: 'Trạng thái', width: 145, render: (_, care) => {
      const status = getTrackingStatus(care)
      return <Tag color={status.color}>{status.label}</Tag>
    } },
  ]

  return <div className="customer-assignment-tracking-page">
    <div className="customer-assignment-tracking-header"><Typography.Title level={3} className="customer-assignment-tracking-title">Theo dõi phân bổ khách hàng</Typography.Title></div>
    <Card className="customer-assignment-card" bodyStyle={{ padding: 0 }}>
      <div className="customer-assignment-toolbar">
        <ShopSelect className="assignment-filter assignment-filter--shop" value={draft.shopId} onChange={(shopId) => setDraft((value) => ({ ...value, shopId }))} useGlobalSelection={false} />
        <Select allowClear showSearch optionFilterProp="label" value={draft.staffId} onChange={(staffId) => setDraft((value) => ({ ...value, staffId }))} options={staffOptions} loading={usersQuery.isLoading} className="assignment-filter assignment-filter--staff" placeholder="Chọn nhân viên" />
        <RangePicker value={draft.dateRange} onChange={(dateRange) => setDraft((value) => ({ ...value, dateRange }))} className="assignment-filter assignment-filter--date" format="DD/MM/YYYY" placeholder={['Ngày bắt đầu', 'Ngày kết thúc']} />
        <Button type="primary" icon={<SearchOutlined />} onClick={apply}>Lọc</Button><Button icon={<ReloadOutlined />} onClick={reset}>Làm mới</Button>
      </div>
      {caresQuery.isError && <Alert type="error" showIcon message="Không thể tải dữ liệu phân công" description={getApiErrorMessage(caresQuery.error)} />}
      <div className="customer-assignment-table-wrap"><Table className="app-table" columns={columns} dataSource={rows} rowKey="id" loading={caresQuery.isLoading || caresQuery.isFetching} scroll={{ x: 1670, y: 'calc(100vh - 390px)' }} locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có khách hàng được phân công" /> }} pagination={{ current: page, pageSize: PAGE_SIZE, total, showSizeChanger: false, onChange: setPage }} /></div>
    </Card>
  </div>
}

export default CustomerAssignmentTrackingPage
