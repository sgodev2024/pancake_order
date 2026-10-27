import {
  DownOutlined,
  EyeOutlined,
  FilterOutlined,
  MoreOutlined,
  ReloadOutlined,
  UpOutlined,
} from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  Alert,
  Avatar,
  Button,
  Card,
  DatePicker,
  Descriptions,
  Drawer,
  Dropdown,
  Empty,
  Input,
  Result,
  Select,
  Space,
  Table,
  Tag,
} from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getActivityLogs } from '../../api/activity-logs.api.js'
import { getAllUsers } from '../../api/users.api.js'
import { isAdmin } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import {
  ACTIVITY_ACTIONS,
  ACTIVITY_FIELD_LABELS,
  ACTIVITY_PAGE_SIZES,
  ACTIVITY_SOURCE_LABELS,
  ACTIVITY_STATUS_LABELS,
  ACTIVITY_TECHNICAL_FIELDS,
  DEFAULT_ACTIVITY_PAGE_SIZE,
  getActivityAction,
} from '../../constants/activity-log.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError, getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import './activity-logs.css'

const { RangePicker } = DatePicker

const createEmptyFilters = () => ({
  shopId: undefined,
  search: '',
  dateRange: null,
  action: undefined,
  actorUserId: undefined,
  targetUserId: undefined,
  orderId: '',
  customerId: '',
})

const normalizeFilters = (filters) => ({
  shop_id: filters.shopId,
  search: filters.search.trim(),
  from_date: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
  to_date: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
  action: filters.action,
  actor_user_id: filters.actorUserId,
  target_user_id: filters.targetUserId,
  order_id: filters.orderId.trim(),
  customer_id: filters.customerId.trim(),
})




const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'
const getEntityName = (entity) => entity?.name || '—'
const getActorName = (actor, source) => {
  const actorName = actor?.name?.trim()
  if (actorName?.toLocaleLowerCase('vi') === 'system' || (!actorName && source === 'system')) {
    return 'Hệ thống'
  }
  return actorName || '—'
}

const parseValues = (value) => {
  if (!value) return {}
  if (typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value !== 'string') return {}
  try {
    const parsed = JSON.parse(value)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
  } catch {
    return { value }
  }
}

const isPresent = (value) =>
  value !== null &&
  value !== undefined &&
  value !== '' &&
  !(Array.isArray(value) && value.length === 0) &&
  !(typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)

const getFieldLabel = (field) =>
  ACTIVITY_FIELD_LABELS[field] ??
  field
    .replaceAll('_', ' ')
    .replace(/^./, (letter) => letter.toLocaleUpperCase('vi'))

const formatBusinessDateTime = (value) => {
  if (!isPresent(value)) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY HH:mm') : String(value)
}

const formatBusinessDate = (value) => {
  if (!isPresent(value)) return '—'
  const dateOnly = String(value).match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (dateOnly) return `${dateOnly[3]}/${dateOnly[2]}/${dateOnly[1]}`
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY') : String(value)
}

const formatBusinessValue = (field, value) => {
  if (!isPresent(value)) return '—'
  if (field === 'status') return ACTIVITY_STATUS_LABELS[value] ?? String(value)
  if (field === 'assigned_at' || field === 'reclaimed_at') return formatBusinessDateTime(value)
  if (field === 'reclaim_eligible_on') return formatBusinessDate(value)
  if (typeof value === 'boolean') return value ? 'Có' : 'Không'
  if (Array.isArray(value)) return value.map(String).join(', ') || '—'
  if (typeof value === 'object') {
    return Object.entries(value)
      .filter(([, nestedValue]) => isPresent(nestedValue))
      .map(([key, nestedValue]) => `${getFieldLabel(key)}: ${formatBusinessValue(key, nestedValue)}`)
      .join(' · ') || '—'
  }
  return String(value)
}

const BusinessDetails = ({ title, values, excludedFields = [] }) => {
  const excluded = new Set([...ACTIVITY_TECHNICAL_FIELDS, ...excludedFields])
  const entries = Object.entries(parseValues(values)).filter(
    ([field, value]) => !excluded.has(field) && isPresent(value),
  )
  if (!entries.length) return null

  return (
    <section className="activity-detail-section activity-business-section" aria-label={title}>
      <h3>{title}</h3>
      <Descriptions column={1} size="small" bordered>
        {entries.map(([field, value]) => (
          <Descriptions.Item key={field} label={getFieldLabel(field)}>
            {formatBusinessValue(field, value)}
          </Descriptions.Item>
        ))}
      </Descriptions>
    </section>
  )
}

const firstPresent = (...values) => values.find(isPresent)

const ReclaimedDetails = ({ log, oldValues, newValues, metadata }) => {
  const before = {
    status: oldValues.status,
    assignee: firstPresent(
      oldValues.assignee_name,
      oldValues.assignee_user_name,
      metadata.assignee_name,
      metadata.assignee_user_name,
      log.target_user?.name,
    ),
    assigned_at: firstPresent(oldValues.assigned_at, metadata.assigned_at),
    reclaim_eligible_on: firstPresent(
      oldValues.reclaim_eligible_on,
      newValues.reclaim_eligible_on,
      metadata.reclaim_eligible_on,
    ),
  }
  const after = {
    status: newValues.status,
    reclaimed_at: firstPresent(newValues.reclaimed_at, metadata.reclaimed_at),
    reclaim_business_reason: metadata.reclaim_source === 'manual'
      ? firstPresent(metadata.manual_reason, 'Không có')
      : firstPresent(newValues.reclaim_reason, newValues.reason, metadata.reclaim_reason, metadata.reason),
  }

  return (
    <>
      <BusinessDetails title="Thông tin trước khi thu hồi" values={before} />
      <BusinessDetails title="Thông tin sau khi thu hồi" values={after} />
    </>
  )
}

const ActivityLogsPage = () => {
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const canViewActivityLogs = isAdmin(user)
  const [draftFilters, setDraftFilters] = useState(createEmptyFilters)
  const [appliedFilters, setAppliedFilters] = useState(() => normalizeFilters(createEmptyFilters()))
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_ACTIVITY_PAGE_SIZE)
  const [advancedOpen, setAdvancedOpen] = useState(false)
  const [selectedLog, setSelectedLog] = useState(null)

  const activityParams = useMemo(
    () => ({ ...appliedFilters, page, page_size: pageSize }),
    [appliedFilters, page, pageSize],
  )

  const logsQuery = useQuery({
    queryKey: ['activity-logs', activityParams],
    queryFn: () => getActivityLogs(activityParams),
    enabled: canViewActivityLogs,
    placeholderData: keepPreviousData,
  })

  const usersQuery = useQuery({
    queryKey: ['activity-log-users', draftFilters.shopId ?? 'all'],
    queryFn: () =>
      getAllUsers({
        ...(draftFilters.shopId ? { shop_id: draftFilters.shopId } : {}),
        page_name: 'activity_log',
      }),
    enabled: canViewActivityLogs && advancedOpen,
    staleTime: 60_000,
  })

  const userOptions = useMemo(
    () =>
      (usersQuery.data ?? []).map((item) => ({
        value: String(item.id),
        label: item.name || `Người dùng #${item.id}`,
      })),
    [usersQuery.data],
  )

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => ({
      ...current,
      shopId,
      actorUserId: undefined,
      targetUserId: undefined,
    }))
  }

  const applyFilters = (event) => {
    event?.preventDefault()
    setAppliedFilters(normalizeFilters(draftFilters))
    setPage(1)
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeFilters(emptyFilters))
    setPage(1)
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

  const logs = logsQuery.data?.logs ?? []
  const totalItems = logsQuery.data?.total_items ?? 0
  const permissionDenied = !canViewActivityLogs || isPermissionError(logsQuery.error)
  const selectedOldValues = parseValues(selectedLog?.old_values)
  const selectedNewValues = parseValues(selectedLog?.new_values)
  const selectedMetadata = parseValues(selectedLog?.metadata)
  const columns = useMemo(
    () => [
      {
        title: 'STT',
        key: 'index',
        width: 72,
        align: 'center',
        render: (_, __, index) => (page - 1) * pageSize + index + 1,
      },
      {
        title: 'Thời gian',
        dataIndex: 'created_at',
        key: 'created_at',
        width: 168,
        render: formatDateTime,
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 190,
        render: (shop) =>
          shop?.name ? (
            <Space size={8}>
              <Avatar size={30} className="activity-shop-avatar">
                {getInitial(shop.name)}
              </Avatar>
              <span>{shop.name}</span>
            </Space>
          ) : (
            '—'
          ),
      },
      {
        title: 'Người thao tác',
        dataIndex: 'actor',
        key: 'actor',
        width: 180,
        render: (actor, record) => getActorName(actor, record.source),
      },
      {
        title: 'Hành động',
        dataIndex: 'action',
        key: 'action',
        width: 170,
        render: (action) => {
          const actionMeta = getActivityAction(action)
          return <Tag color={actionMeta.color}>{actionMeta.label}</Tag>
        },
      },
      {
        title: 'Mã đơn',
        dataIndex: 'pancake_order_id',
        key: 'pancake_order_id',
        width: 170,
        render: (orderId) => orderId || '—',
      },
      {
        title: 'Người được phân công',
        dataIndex: 'target_user',
        key: 'target_user',
        width: 190,
        render: getEntityName,
      },
      {
        title: 'Thao tác',
        key: 'detail',
        width: 68,
        align: 'center',
        fixed: 'right',
        className: 'activity-action-column',
        render: (_, record) => (
          <Dropdown
            menu={{
              items: [{
                key: 'detail',
                icon: <EyeOutlined />,
                label: 'Xem chi tiết',
                onClick: () => setSelectedLog(record),
              }],
            }}
            trigger={['click']}
            placement="bottomRight"
          >
            <Button
              type="text"
              size="small"
              icon={<MoreOutlined />}
              aria-label={`Mở thao tác log ${record.id}`}
              aria-haspopup="menu"
            />
          </Dropdown>
        ),
      },
    ],
    [page, pageSize],
  )

  if (permissionDenied) {
    return (
      <Card className="activity-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem Quản lý Log"
          subTitle="Chỉ tài khoản admin mới được truy cập lịch sử hoạt động."
          extra={
            <Button type="primary" onClick={() => navigate(ROUTES.HOME)}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  if (logsQuery.isError && isUnauthorizedError(logsQuery.error)) {
    return (
      <Card className="activity-state-card">
        <Result
          status="403"
          title="Phiên đăng nhập không còn hiệu lực"
          subTitle="Vui lòng đăng nhập lại để tiếp tục xem lịch sử hoạt động."
        />
      </Card>
    )
  }

  return (
    <main className="activity-page">
      <header className="activity-page__intro">
        <h1>Quản lý Log</h1>
        <p>Theo dõi lịch sử thao tác trong phạm vi cửa hàng mà bạn được phép truy cập.</p>
      </header>

      <Card title="Lịch sử hoạt động" className="activity-card">
        <form className="activity-filters" onSubmit={applyFilters}>
          <div className="activity-filters__grid">
            <div className="activity-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>

            <div className="activity-field activity-field--search">
              <label htmlFor="activity-search">Tìm kiếm</label>
              <Input
                id="activity-search"
                allowClear
                value={draftFilters.search}
                onChange={(event) => updateDraft('search', event.target.value)}
                placeholder="Tìm người thao tác, người được phân công, mã đơn, mã khách hàng..."
              />
            </div>

            <div className="activity-field activity-field--date">
              <label>Thời gian</label>
              <RangePicker
                aria-label="Khoảng thời gian"
                value={draftFilters.dateRange}
                onChange={(value) => updateDraft('dateRange', value)}
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
              />
            </div>

            <div className="activity-field">
              <label>Hành động</label>
              <Select
                aria-label="Hành động"
                allowClear
                value={draftFilters.action}
                options={ACTIVITY_ACTIONS}
                placeholder="Chọn hành động"
                onChange={(value) => updateDraft('action', value)}
              />
            </div>
          </div>

          {/* <button
            type="button"
            className="activity-advanced-toggle"
            onClick={() => setAdvancedOpen((current) => !current)}
            aria-expanded={advancedOpen}
            aria-controls="activity-advanced-filters"
          >
            Bộ lọc nâng cao {advancedOpen ? <UpOutlined /> : <DownOutlined />}
          </button> */}

          {advancedOpen && (
            <div id="activity-advanced-filters" className="activity-filters__advanced">
              <div className="activity-field">
                <label>Người thao tác</label>
                <Select
                  aria-label="Người thao tác"
                  allowClear
                  showSearch
                  optionFilterProp="label"
                  value={draftFilters.actorUserId}
                  options={userOptions}
                  loading={usersQuery.isLoading}
                  status={usersQuery.isError ? 'error' : undefined}
                  placeholder="Chọn người thao tác"
                  notFoundContent={usersQuery.isLoading ? 'Đang tải...' : 'Không có người dùng'}
                  onChange={(value) => updateDraft('actorUserId', value)}
                />
              </div>

              <div className="activity-field">
                <label>Người được phân công</label>
                <Select
                  aria-label="Người được phân công"
                  allowClear
                  showSearch
                  optionFilterProp="label"
                  value={draftFilters.targetUserId}
                  options={userOptions}
                  loading={usersQuery.isLoading}
                  status={usersQuery.isError ? 'error' : undefined}
                  placeholder="Chọn người được phân công"
                  notFoundContent={usersQuery.isLoading ? 'Đang tải...' : 'Không có người dùng'}
                  onChange={(value) => updateDraft('targetUserId', value)}
                />
              </div>

              <div className="activity-field">
                <label htmlFor="activity-order-id">Order ID</label>
                <Input
                  id="activity-order-id"
                  allowClear
                  value={draftFilters.orderId}
                  onChange={(event) => updateDraft('orderId', event.target.value)}
                  placeholder="Nhập mã đơn"
                />
              </div>

              <div className="activity-field">
                <label htmlFor="activity-customer-id">Customer ID</label>
                <Input
                  id="activity-customer-id"
                  allowClear
                  value={draftFilters.customerId}
                  onChange={(event) => updateDraft('customerId', event.target.value)}
                  placeholder="Nhập mã khách hàng"
                />
              </div>
            </div>
          )}

          {usersQuery.isError && advancedOpen && (
            <Alert
              type="warning"
              showIcon
              message="Không thể tải danh sách người dùng. Bạn vẫn có thể dùng các bộ lọc khác."
              className="activity-filter-alert"
            />
          )}

          <div className="activity-filters__actions">
            <Button type="primary" htmlType="submit" icon={<FilterOutlined />} className="filter-action-button">
              Lọc
            </Button>
            <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
              Làm mới
            </Button>
          </div>
        </form>

        <div className="activity-table-summary" aria-live="polite">
          Tổng số bản ghi: <strong>{totalItems.toLocaleString('vi-VN')}</strong>
        </div>

        {logsQuery.isError && (
          <QueryErrorAlert
            error={logsQuery.error}
            fallbackTitle="Không thể tải lịch sử hoạt động"
            onRetry={() => logsQuery.refetch()}
            className="activity-query-alert"
          />
        )}

        <Table
          className="app-table"
          rowKey="id"
          columns={columns}
          dataSource={logs}
          loading={logsQuery.isLoading || logsQuery.isFetching}
          scroll={{ x: 1050 }}
          onChange={handleTableChange}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={
                  <span>
                    Không có lịch sử hoạt động phù hợp.
                    <small>Hãy thử thay đổi hoặc xóa bớt bộ lọc.</small>
                  </span>
                }
              />
            ),
          }}
          pagination={{
            current: page,
            pageSize,
            total: totalItems,
            showSizeChanger: true,
            pageSizeOptions: ACTIVITY_PAGE_SIZES.map(String),
            position: ['bottomRight'],
            showTotal: (total) => `Tổng ${total.toLocaleString('vi-VN')} bản ghi`,
          }}
        />
      </Card>

      <Drawer
        title="Chi tiết hoạt động"
        open={Boolean(selectedLog)}
        width="min(560px, 100vw)"
        onClose={() => setSelectedLog(null)}
        destroyOnHidden
        className="activity-detail-drawer"
      >
        {selectedLog && (
          <div className="activity-detail">
            <section className="activity-detail-section" aria-label="Chi tiết hoạt động">
              <h3>Chi tiết hoạt động</h3>
              <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Thời gian">
                  {formatDateTime(selectedLog.created_at)}
                </Descriptions.Item>
                <Descriptions.Item label="Hành động">
                  {getActivityAction(selectedLog.action).label}
                </Descriptions.Item>
                <Descriptions.Item label="Nguồn">
                  {ACTIVITY_SOURCE_LABELS[selectedLog.source] ?? selectedLog.source ?? '—'}
                </Descriptions.Item>
                <Descriptions.Item label="Người thao tác">
                  {getActorName(selectedLog.actor, selectedLog.source)}
                </Descriptions.Item>
                <Descriptions.Item label="Người được phân công">
                  {getEntityName(selectedLog.target_user)}
                </Descriptions.Item>
                <Descriptions.Item label="Cửa hàng">
                  {getEntityName(selectedLog.shop)}
                </Descriptions.Item>
                <Descriptions.Item label="Mã đơn">
                  {selectedLog.pancake_order_id || '—'}
                </Descriptions.Item>
                <Descriptions.Item label="Mô tả">
                  {selectedLog.description || '—'}
                </Descriptions.Item>
              </Descriptions>
            </section>

            {selectedLog.action === 'customer_care.reclaimed' ? (
              <>
                <ReclaimedDetails
                  log={selectedLog}
                  oldValues={selectedOldValues}
                  newValues={selectedNewValues}
                  metadata={selectedMetadata}
                />
                <BusinessDetails
                  title="Thông tin bổ sung"
                  values={{ ...selectedOldValues, ...selectedNewValues, ...selectedMetadata }}
                  excludedFields={[
                    'status',
                    'assignee',
                    'assignee_name',
                    'assignee_user_name',
                    'assigned_at',
                    'reclaim_eligible_on',
                    'reclaimed_at',
                    'reclaim_reason',
                    'manual_reason',
                    'reason',
                  ]}
                />
              </>
            ) : (
              <>
                <BusinessDetails title="Thông tin trước thay đổi" values={selectedOldValues} />
                <BusinessDetails title="Thông tin sau thay đổi" values={selectedNewValues} />
                <BusinessDetails title="Thông tin bổ sung" values={selectedMetadata} />
              </>
            )}

          </div>
        )}
      </Drawer>
    </main>
  )
}

export default ActivityLogsPage
