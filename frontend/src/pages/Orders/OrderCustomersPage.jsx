import {
  CheckCircleOutlined,
  ClockCircleOutlined,
  FieldTimeOutlined,
  PhoneOutlined,
  ReloadOutlined,
  SearchOutlined,
  TeamOutlined,
} from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  Button,
  Card,
  Input,
  Result,
  Segmented,
  Select,
  Tag,
} from 'antd'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getOrderCustomers } from '../../api/customers.api.js'
import { hasPermission } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import ShopSelect from '../../components/ShopSelect.jsx'
import { DEFAULT_CUSTOMER_PAGE_SIZE } from '../../constants/customer.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError } from '../../utils/response.js'
import './order-customers.css'

const GROUP_OPTIONS = [
  { label: 'Tất cả', value: 'all' },
  { label: '0–7 ngày', value: '0_7' },
  { label: '8–14 ngày', value: '8_14' },
  { label: '15–30 ngày', value: '15_30' },
  { label: 'Trên 90 ngày', value: 'over_90' },
]

const SORT_OPTIONS = [
  { label: 'Số ngày chưa quay lại mua', value: 'inactive_days' },
  { label: 'Ngày mua gần nhất', value: 'last_purchase_at' },
  { label: 'Tổng số đơn', value: 'total_orders' },
  { label: 'Tổng giá trị đã mua', value: 'total_value' },
]

const formatNumber = (value) => {
  const amount = Number(value)
  return Number.isFinite(amount) ? amount.toLocaleString('vi-VN') : '—'
}

const OverviewCard = ({ label, value, tone, icon: Icon, active, onClick, note }) => (
  <button
    type="button"
    className={`order-customers-overview__card is-${tone}${active ? ' is-active' : ''}`}
    onClick={onClick}
  >
    <div className="order-customers-overview__head">
      <span>{label}</span>
      <i aria-hidden="true"><Icon /></i>
    </div>
    <strong>{formatNumber(value)}</strong>
    {note && <small>{note}</small>}
  </button>
)

const getSmoothChartPath = (points) => {
  if (points.length === 0) return ''
  return points.slice(1).reduce((path, point, index) => {
    const previous = points[index]
    const offset = (point.x - previous.x) / 2
    return `${path} C ${previous.x + offset} ${previous.y}, ${point.x - offset} ${point.y}, ${point.x} ${point.y}`
  }, `M ${points[0].x} ${points[0].y}`)
}

const CustomerRecencyChart = ({ overview, loading }) => {
  const [threshold, setThreshold] = useState('all')
  const groups = [
    { key: '0_7', label: '0–7 ngày', shortLabel: '0–7', value: Number(overview.days_0_7) || 0, color: '#35b889' },
    { key: '8_14', label: '8–14 ngày', shortLabel: '8–14', value: Number(overview.days_8_14) || 0, color: '#4f73f6' },
    { key: '15_30', label: '15–30 ngày', shortLabel: '15–30', value: Number(overview.days_15_30) || 0, color: '#f2b24b' },
    { key: 'over_90', label: 'Trên 90 ngày', shortLabel: '> 90', value: Number(overview.over_90) || 0, color: '#e87676' },
    { key: 'never', label: 'Chưa phát sinh đơn', shortLabel: 'Chưa mua', value: Number(overview.never_purchased) || 0, color: '#94a3b8' },
  ]
  const visibleGroups = threshold === 'all'
    ? groups
    : groups.filter((item) => item.key === threshold)
  const visibleTotal = visibleGroups.reduce((sum, item) => sum + item.value, 0)
  const max = Math.max(1, ...visibleGroups.map((item) => item.value))
  const width = 720
  const height = 220
  const left = 62
  const right = 28
  const top = 18
  const plotHeight = height - top
  const plotWidth = width - left - right
  const chartPoints = visibleGroups.map((item, index) => ({
    ...item,
    x: left + (visibleGroups.length <= 1 ? plotWidth / 2 : (index / (visibleGroups.length - 1)) * plotWidth),
    y: top + plotHeight - (item.value / max) * plotHeight,
  }))
  const linePoints = chartPoints.length === 1
    ? [{ ...chartPoints[0], x: left }, { ...chartPoints[0], x: width - right }]
    : chartPoints
  const linePath = getSmoothChartPath(linePoints)
  const areaPath = linePoints.length
    ? `${linePath} L ${linePoints.at(-1).x} ${height} L ${linePoints[0].x} ${height} Z`
    : ''
  const radius = 48
  const circumference = 2 * Math.PI * radius
  const donutSegments = visibleGroups.reduce(
    (result, item) => {
      const length = visibleTotal > 0 ? (item.value / visibleTotal) * circumference : 0
      result.items.push({ ...item, length, offset: -result.consumed })
      result.consumed += length
      return result
    },
    { items: [], consumed: 0 },
  ).items

  return (
    <section className="customer-recency-chart" aria-label="Biểu đồ thời gian khách hàng chưa quay lại mua">
      <div className="customer-recency-chart__head">
        <div>
          <h3>Khách hàng đã bao lâu chưa quay lại mua?</h3>
          <p>Tính từ lần mua gần nhất của khách hàng đến hôm nay.</p>
        </div>
        <Segmented
          size="small"
          value={threshold}
          onChange={setThreshold}
          options={[
            { label: 'Tất cả', value: 'all' },
            { label: '7 ngày', value: '0_7' },
            { label: '14 ngày', value: '8_14' },
            { label: '30 ngày', value: '15_30' },
            { label: '60 ngày', value: '31_60' },
            { label: '90 ngày', value: '61_90' },
            { label: 'Trên 90 ngày', value: 'over_90' },
          ]}
        />
      </div>
      {loading ? (
        <div className="customer-recency-chart__placeholder" />
      ) : (
        <div className="customer-recency-chart__grid">
          <article className="customer-recency-chart__panel customer-recency-chart__panel--trend">
            <div className="customer-recency-chart__panel-head">
              <div>
                <h4>Số lượng khách hàng</h4>
                <span>{formatNumber(visibleTotal)} khách hàng trong phạm vi đang chọn</span>
              </div>
              <b aria-hidden="true">⋮</b>
            </div>
            <svg viewBox={`0 0 ${width} ${height + 38}`} role="img">
              <defs>
                <linearGradient id="customer-recency-area" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="#4f73f6" stopOpacity="0.3" />
                  <stop offset="100%" stopColor="#4f73f6" stopOpacity="0.03" />
                </linearGradient>
              </defs>
              {[0, 0.25, 0.5, 0.75, 1].map((mark) => {
                const y = top + plotHeight - mark * plotHeight
                return (
                  <g key={mark}>
                    <line x1={left} y1={y} x2={width - right} y2={y} className="customer-recency-chart__grid-line" />
                    <text x={left - 10} y={y + 4} textAnchor="end" className="customer-recency-chart__axis">
                      {formatNumber(Math.round(max * mark))}
                    </text>
                  </g>
                )
              })}
              <path d={areaPath} fill="url(#customer-recency-area)" />
              <path d={linePath} fill="none" stroke="#4f73f6" strokeWidth="3" strokeLinecap="round" />
              {chartPoints.map((item, index) => (
                <g key={item.key}>
                  <circle cx={item.x} cy={item.y} r="4" fill="#fff" stroke="#4f73f6" strokeWidth="2.5">
                    <title>{`${item.label}: ${formatNumber(item.value)} khách hàng`}</title>
                  </circle>
                  <text
                    x={item.x}
                    y={height + 25}
                    textAnchor={index === 0 ? 'start' : index === chartPoints.length - 1 ? 'end' : 'middle'}
                    className="customer-recency-chart__axis"
                  >
                    {item.shortLabel}
                  </text>
                </g>
              ))}
            </svg>
            <div className="customer-recency-chart__legend">
              <span><i className="is-standard" />Số khách hàng theo nhóm</span>
            </div>
          </article>

          <article className="customer-recency-chart__panel customer-recency-chart__panel--structure">
            <div className="customer-recency-chart__panel-head">
              <div>
                <h4>Cơ cấu khách hàng</h4>
                <span>Theo thời gian chưa quay lại mua</span>
              </div>
              <b aria-hidden="true">⋮</b>
            </div>
            <div className="customer-recency-chart__donut">
              <svg viewBox="0 0 140 140" role="img">
                <circle cx="70" cy="70" r={radius} fill="none" stroke="#edf0f4" strokeWidth="20" />
                {donutSegments.map((item) => (
                  <circle
                    key={item.key}
                    cx="70"
                    cy="70"
                    r={radius}
                    fill="none"
                    stroke={item.color}
                    strokeWidth="20"
                    strokeDasharray={`${item.length} ${Math.max(circumference - item.length, 0)}`}
                    strokeDashoffset={item.offset}
                    transform="rotate(-90 70 70)"
                  />
                ))}
                <text x="70" y="67" textAnchor="middle" className="customer-recency-chart__donut-value">{formatNumber(visibleTotal)}</text>
                <text x="70" y="84" textAnchor="middle" className="customer-recency-chart__donut-label">Khách hàng</text>
              </svg>
            </div>
            <div className="customer-recency-chart__table">
              <div className="customer-recency-chart__table-head">
                <span>Nhóm</span>
                <span>Số khách</span>
                <span>Tỷ lệ</span>
              </div>
              {visibleGroups.map((item) => (
                <div className="customer-recency-chart__table-row" key={item.key}>
                  <span><i style={{ background: item.color }} />{item.label}</span>
                  <strong>{formatNumber(item.value)}</strong>
                  <span>{visibleTotal > 0 ? (item.value > 0 && (item.value / visibleTotal) * 100 < 0.05 ? '< 0,1%' : `${((item.value / visibleTotal) * 100).toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%`) : '0%'}</span>
                </div>
              ))}
            </div>
          </article>
        </div>
      )}
    </section>
  )
}

const OrderCustomersPage = ({ adminReport = false }) => {
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const canViewCustomers = adminReport || hasPermission(user, 'list-customer')
  const [searchDraft, setSearchDraft] = useState('')
  const [search, setSearch] = useState('')
  const [shopId, setShopId] = useState()
  const [group, setGroup] = useState('all')
  const [sortBy, setSortBy] = useState('inactive_days')
  const [sortDirection, setSortDirection] = useState('desc')
  const [page, setPage] = useState(1)
  const [pageSize] = useState(DEFAULT_CUSTOMER_PAGE_SIZE)

  const params = useMemo(
    () => ({
      page,
      page_size: pageSize,
      shop_id: shopId,
      search,
      inactivity_group: group,
      sort_by: sortBy,
      sort_direction: sortDirection,
    }),
    [group, page, pageSize, search, shopId, sortBy, sortDirection],
  )

  const customersQuery = useQuery({
    queryKey: ['order-customers', params],
    queryFn: ({ signal }) => getOrderCustomers(params, { signal }),
    enabled: canViewCustomers,
    placeholderData: keepPreviousData,
    staleTime: adminReport ? 5 * 60_000 : 30_000,
  })


  const overview = customersQuery.data?.overview ?? {}
  const totalItems = customersQuery.data?.total_items ?? 0

  const selectGroup = (nextGroup) => {
    setGroup(nextGroup)
    setPage(1)
  }

  const applySearch = (event) => {
    event.preventDefault()
    setSearch(searchDraft.trim())
    setPage(1)
  }

  const resetFilters = () => {
    setSearchDraft('')
    setSearch('')
    setShopId(undefined)
    setGroup('all')
    setSortBy('inactive_days')
    setSortDirection('desc')
    setPage(1)
  }

  if (!canViewCustomers || isPermissionError(customersQuery.error)) {
    return (
      <Card className="order-customers-state">
        <Result
          status="403"
          title="Bạn không có quyền xem khách hàng"
          subTitle="Tài khoản cần quyền “list-customer” để truy cập báo cáo này."
          extra={<Button type="primary" onClick={() => navigate(ROUTES.HOME)}>Về Tổng quan</Button>}
        />
      </Card>
    )
  }

  if (customersQuery.isError && isUnauthorizedError(customersQuery.error)) {
    return <Card><Result status="403" title="Phiên đăng nhập không còn hiệu lực" /></Card>
  }

  return (
    <main className="order-customers-page">
      <Card title="Danh sách khách hàng theo thời gian chưa quay lại mua" className="order-customers-card">
        <form className="order-customers-filters" onSubmit={applySearch}>
          <div className="order-customers-filters__top">
            <div className="order-customers-field">
              <label>Cửa hàng</label>
              <ShopSelect
                value={shopId}
                onChange={(value) => {
                  setShopId(value)
                  setPage(1)
                }}
                useGlobalSelection={false}
              />
            </div>
            <div className="order-customers-field order-customers-field--search">
              <label htmlFor="order-customers-search">Tìm kiếm khách hàng</label>
              <Input
                id="order-customers-search"
                allowClear
                prefix={<SearchOutlined />}
                value={searchDraft}
                onChange={(event) => setSearchDraft(event.target.value)}
                placeholder="Tên, số điện thoại, email hoặc mã khách hàng"
              />
            </div>
            <div className="order-customers-field">
              <label>Sắp xếp theo</label>
              <Select
                value={sortBy}
                options={SORT_OPTIONS}
                onChange={(value) => {
                  setSortBy(value)
                  setPage(1)
                }}
              />
            </div>
            <div className="order-customers-field">
              <label>Thứ tự</label>
              <Select
                value={sortDirection}
                options={[
                  { label: 'Giảm dần', value: 'desc' },
                  { label: 'Tăng dần', value: 'asc' },
                ]}
                onChange={(value) => {
                  setSortDirection(value)
                  setPage(1)
                }}
              />
            </div>
            <div className="order-customers-filters__actions">
              <Button type="primary" htmlType="submit" icon={<SearchOutlined />} className="filter-action-button">
                Tìm kiếm
              </Button>
              <Button icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
                Đặt lại
              </Button>
            </div>
          </div>

          <div className="order-customers-group-filter">
            <span>Thời gian chưa quay lại mua:</span>
            <Segmented value={group} options={GROUP_OPTIONS} onChange={selectGroup} />
          </div>
        </form>

        <div className="order-customers-summary" aria-live="polite">
          Tìm thấy <strong>{formatNumber(totalItems)}</strong> khách hàng
          {group === 'over_90' && <Tag color="red">Cần chăm sóc khách hàng</Tag>}
        </div>

        {customersQuery.isError && (
          <QueryErrorAlert
            error={customersQuery.error}
            fallbackTitle="Không thể tải danh sách khách hàng"
            onRetry={() => customersQuery.refetch()}
          />
        )}
      </Card>

      <section className="order-customers-overview" aria-label="Tổng quan thời gian khách hàng chưa quay lại mua">
        <OverviewCard
          label="Tổng khách hàng"
          value={overview.total_customers}
          tone="blue"
          icon={TeamOutlined}
          active={group === 'all'}
          onClick={() => selectGroup('all')}
          note={`Chưa phát sinh đơn: ${formatNumber(overview.never_purchased)}`}
        />
        <OverviewCard
          label="Mua trong 7 ngày gần đây"
          value={overview.days_0_7}
          tone="green"
          icon={CheckCircleOutlined}
          active={group === '0_7'}
          onClick={() => selectGroup('0_7')}
          note="Vừa phát sinh mua hàng"
        />
        <OverviewCard
          label="Chưa quay lại 8–14 ngày"
          value={overview.days_8_14}
          tone="cyan"
          icon={ClockCircleOutlined}
          active={group === '8_14'}
          onClick={() => selectGroup('8_14')}
          note="Theo dõi khả năng quay lại"
        />
        <OverviewCard
          label="Chưa quay lại 15–30 ngày"
          value={overview.days_15_30}
          tone="orange"
          icon={FieldTimeOutlined}
          active={group === '15_30'}
          onClick={() => selectGroup('15_30')}
          note="Nên chủ động liên hệ"
        />
        <OverviewCard
          label="Chưa quay lại trên 90 ngày"
          value={overview.over_90}
          tone="red"
          icon={PhoneOutlined}
          active={group === 'over_90'}
          onClick={() => selectGroup('over_90')}
          note="Cần ưu tiên chăm sóc lại"
        />
      </section>

      {adminReport && (
        <CustomerRecencyChart
          overview={overview}
          loading={customersQuery.isLoading && !customersQuery.data}
        />
      )}
    </main>
  )
}

export default OrderCustomersPage
