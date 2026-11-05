import { ArrowDownOutlined, ArrowUpOutlined, FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Card, Empty, Skeleton, Space, Table, Tag, Tooltip } from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { getProductPeriodReport } from '../../api/products.api.js'
import DonutChart from '../../components/DonutChart.jsx'
import ReportDateRange from '../../components/ReportDateRange.jsx'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import './product-report.css'

const formatCount = (value) => Number(value || 0).toLocaleString('vi-VN')
const formatMoney = (value) =>
  new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(
    Number(value) || 0,
  )
const compactMoney = (value) => {
  const amount = Number(value) || 0
  const abs = Math.abs(amount)
  if (abs >= 1_000_000_000) return `${(amount / 1_000_000_000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ`
  if (abs >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tr`
  return amount.toLocaleString('vi-VN')
}

// Default to current year (from Jan 1 to current day/month)
const getDefaultRange = () => [dayjs().startOf('year'), dayjs().endOf('month')]

// --- KPI Summary Cards ---
const SummaryCards = ({ summary, loading }) => {
  if (loading) return <Skeleton active paragraph={{ rows: 1 }} />
  const revenue = Number(summary?.total_revenue) || 0
  const previousRevenue = summary?.previous_revenue != null ? Number(summary.previous_revenue) : null
  const changeAmount = summary?.change_amount != null ? Number(summary.change_amount) : null
  const changePercent = summary?.change_percent
  const isPositive = changePercent != null && changePercent >= 0
  const isNegative = changePercent != null && changePercent < 0

  return (
    <div className="pr-summary">
      <div className="pr-summary__card pr-summary__card--main">
        <span>Tổng doanh thu kỳ này</span>
        <strong>{formatMoney(revenue)}</strong>
        <small>{formatCount(summary?.total_orders)} đơn · {formatCount(summary?.total_quantity)} sản phẩm</small>
      </div>
      {previousRevenue != null && (
        <div className={`pr-summary__card ${isPositive ? 'pr-summary__card--up' : 'pr-summary__card--down'}`}>
          <span>So với kỳ trước</span>
          <strong className={isNegative ? 'pr-negative' : 'pr-positive'}>
            {changeAmount != null ? `${changeAmount >= 0 ? '+' : ''}${compactMoney(changeAmount)}` : '—'}
          </strong>
          {changePercent != null ? (
            <Tag color={isPositive ? 'success' : 'error'} icon={isPositive ? <ArrowUpOutlined /> : <ArrowDownOutlined />}>
              {Math.abs(changePercent).toFixed(1)}%
            </Tag>
          ) : (
            <Tag>Mới</Tag>
          )}
        </div>
      )}
    </div>
  )
}

// --- Doanh số theo tháng (Cân đối, trực quan) ---
const RevenueColumnChart = ({ data, loading }) => {
  if (loading) return <Skeleton active paragraph={{ rows: 4 }} />
  if (!data || data.length === 0) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có dữ liệu" />
  const maxValue = Math.max(...data.map((d) => Number(d.revenue) || 0), 1)
  const barWidth = 32
  const chartHeight = 220
  const padding = { top: 20, right: 20, bottom: 45, left: 70 }
  const slotWidth = Math.max(barWidth + 24, 48)
  const usableWidth = Math.max(data.length * slotWidth, 360)
  const totalWidth = usableWidth + padding.left + padding.right
  const totalHeight = chartHeight + padding.top + padding.bottom

  const yTicks = 4
  const yStep = maxValue / yTicks

  return (
    <div className="pr-chart-scroll">
      <svg className="pr-chart-svg" viewBox={`0 0 ${totalWidth} ${totalHeight}`} width={totalWidth} height={totalHeight}>
        {Array.from({ length: yTicks + 1 }, (_, i) => {
          const y = padding.top + (chartHeight - (i / yTicks) * chartHeight)
          return (
            <g key={`y-${i}`}>
              <line x1={padding.left} y1={y} x2={totalWidth - padding.right} y2={y} stroke="#f1f5f9" strokeWidth="1" strokeDasharray="3 3" />
              <text x={padding.left - 8} y={y + 4} textAnchor="end" fill="#94a3b8" fontSize="11">{compactMoney(yStep * i)}</text>
            </g>
          )
        })}
        {data.map((item, index) => {
          const x = padding.left + index * slotWidth + (slotWidth - barWidth) / 2
          const currentHeight = maxValue > 0 ? (Number(item.revenue) / maxValue) * chartHeight : 0
          const currentY = padding.top + chartHeight - currentHeight
          const changeStr = item.change_percent != null ? `${item.change_percent >= 0 ? '+' : ''}${item.change_percent.toFixed(1)}%` : ''
          const tooltipText = `${item.label}: ${compactMoney(item.revenue)} · ${formatCount(item.orders_count)} đơn${changeStr ? ` · ${changeStr}` : ''}`
          return (
            <g key={item.label}>
              <Tooltip title={tooltipText}>
                <rect
                  x={x}
                  y={currentY}
                  width={barWidth}
                  height={Math.max(currentHeight, 1)}
                  rx={4}
                  fill="#1677ff"
                  style={{ cursor: 'pointer', transition: 'opacity 0.15s' }}
                />
              </Tooltip>
              <text
                x={x + barWidth / 2}
                y={totalHeight - padding.bottom + 18}
                textAnchor="middle"
                fill="#64748b"
                fontSize="11"
              >
                {item.label}
              </text>
            </g>
          )
        })}
      </svg>
    </div>
  )
}

// --- Growth Combo Chart (Dual Bar + Line Chart theo Hình số 5) ---
const ProductGrowthComboChart = ({ data, loading }) => {
  if (loading) return <Skeleton active paragraph={{ rows: 6 }} />
  if (!data || data.length === 0) return null

  const maxRevenue = Math.max(
    ...data.map((d) => Math.max(Number(d.revenue) || 0, Number(d.previous_revenue) || 0)),
    1,
  )

  const chartHeight = 240
  const padding = { top: 32, right: 30, bottom: 45, left: 70 }
  const barWidth = 16
  const barGap = 4
  const groupWidth = barWidth * 2 + barGap
  const slotWidth = Math.max(groupWidth + 26, 60)
  const totalContentWidth = data.length * slotWidth
  const totalWidth = Math.max(totalContentWidth + padding.left + padding.right, 660)
  const totalHeight = chartHeight + padding.top + padding.bottom

  const yTicks = 4
  const yStep = maxRevenue / yTicks

  const linePoints = data.map((item, idx) => {
    const x = padding.left + idx * slotWidth + slotWidth / 2
    const currentH = maxRevenue > 0 ? (Number(item.revenue) / maxRevenue) * chartHeight : 0
    const y = padding.top + chartHeight - currentH - 12
    return { x, y: Math.max(y, padding.top + 6), item }
  })

  const pathD = linePoints.length > 0
    ? linePoints.reduce((acc, pt, idx) => `${acc} ${idx === 0 ? 'M' : 'L'} ${pt.x} ${pt.y}`, '')
    : ''

  return (
    <div className="pr-growth-chart-card">
      <div className="pr-growth-chart-header">
        <h4>Biểu đồ tăng trưởng doanh số theo tháng (Kỳ này vs Kỳ trước)</h4>
        <div className="pr-growth-legend">
          <span className="pr-legend-item">
            <i className="pr-legend-color pr-legend-color--prev" /> Doanh thu kỳ trước
          </span>
          <span className="pr-legend-item">
            <i className="pr-legend-color pr-legend-color--curr" /> Doanh thu kỳ này
          </span>
          <span className="pr-legend-item">
            <i className="pr-legend-line" /> Tăng trưởng so với cùng kỳ (%)
          </span>
        </div>
      </div>

      <div className="pr-chart-scroll">
        <svg
          className="pr-combo-svg"
          viewBox={`0 0 ${totalWidth} ${totalHeight}`}
          width={totalWidth}
          height={totalHeight}
        >
          {/* Y axis grid lines */}
          {Array.from({ length: yTicks + 1 }, (_, i) => {
            const y = padding.top + (chartHeight - (i / yTicks) * chartHeight)
            return (
              <g key={`y-${i}`}>
                <line
                  x1={padding.left}
                  y1={y}
                  x2={totalWidth - padding.right}
                  y2={y}
                  stroke="#f1f5f9"
                  strokeWidth="1"
                  strokeDasharray="4 4"
                />
                <text
                  x={padding.left - 10}
                  y={y + 4}
                  textAnchor="end"
                  fill="#94a3b8"
                  fontSize="11"
                >
                  {compactMoney(yStep * i)}
                </text>
              </g>
            )
          })}

          {/* Dual Bars */}
          {data.map((item, index) => {
            const groupX = padding.left + index * slotWidth + (slotWidth - groupWidth) / 2
            const prevVal = Number(item.previous_revenue) || 0
            const currVal = Number(item.revenue) || 0
            const prevH = maxRevenue > 0 ? (prevVal / maxRevenue) * chartHeight : 0
            const currH = maxRevenue > 0 ? (currVal / maxRevenue) * chartHeight : 0
            const prevY = padding.top + chartHeight - prevH
            const currY = padding.top + chartHeight - currH

            const tooltipText = `${item.label}: Kỳ này ${formatMoney(currVal)} · Kỳ trước ${formatMoney(prevVal)}${item.change_percent != null ? ` · Tăng trưởng ${item.change_percent >= 0 ? '+' : ''}${item.change_percent}%` : ''}`

            return (
              <g key={item.label} className="pr-bar-group">
                <Tooltip title={tooltipText}>
                  <g style={{ cursor: 'pointer' }}>
                    <rect
                      x={groupX}
                      y={prevY}
                      width={barWidth}
                      height={Math.max(prevH, 1)}
                      rx={3}
                      fill="#cbd5e1"
                    />
                    <rect
                      x={groupX + barWidth + barGap}
                      y={currY}
                      width={barWidth}
                      height={Math.max(currH, 1)}
                      rx={3}
                      fill="#f97316"
                    />
                  </g>
                </Tooltip>
                <text
                  x={groupX + groupWidth / 2}
                  y={padding.top + chartHeight + 20}
                  textAnchor="middle"
                  fill="#64748b"
                  fontSize="12"
                  fontWeight="500"
                >
                  {item.label}
                </text>
              </g>
            )
          })}

          {/* Growth Line (Green line with circles and labels like Hình 5) */}
          {pathD && (
            <path
              d={pathD}
              fill="none"
              stroke="#16a34a"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          )}

          {/* Growth points and % labels */}
          {linePoints.map(({ x, y, item }) => {
            const changePercent = item.change_percent
            if (changePercent == null && Number(item.revenue) === 0) return null
            const labelStr = changePercent != null ? `${changePercent >= 0 ? '+' : ''}${changePercent.toFixed(1)}%` : 'Mới'

            return (
              <g key={`pt-${item.label}`}>
                <circle
                  cx={x}
                  cy={y}
                  r="4.5"
                  fill="#16a34a"
                  stroke="#ffffff"
                  strokeWidth="2"
                />
                <text
                  x={x}
                  y={y - 8}
                  textAnchor="middle"
                  fill="#15803d"
                  fontSize="10.5"
                  fontWeight="700"
                >
                  {labelStr}
                </text>
              </g>
            )
          })}
        </svg>
      </div>
    </div>
  )
}

// --- Product Performance Table Columns ---
const productColumns = [
  {
    title: 'STT',
    dataIndex: 'rank',
    key: 'rank',
    width: 56,
    align: 'center',
    render: (rank) => <span className="pr-table__rank">{rank}</span>,
  },
  {
    title: 'Tên sản phẩm',
    dataIndex: 'name',
    key: 'name',
    ellipsis: true,
    render: (name) => <strong>{name}</strong>,
  },
  {
    title: 'SL bán kỳ này',
    dataIndex: 'quantity',
    key: 'quantity',
    width: 130,
    align: 'right',
    sorter: (a, b) => (Number(a.quantity) || 0) - (Number(b.quantity) || 0),
    render: (val) => formatCount(val),
  },
  {
    title: 'Doanh số kỳ này',
    dataIndex: 'revenue',
    key: 'revenue',
    width: 170,
    align: 'right',
    sorter: (a, b) => (Number(a.revenue) || 0) - (Number(b.revenue) || 0),
    defaultSortOrder: 'descend',
    render: (val) => formatMoney(val),
  },
  {
    title: 'Doanh số kỳ trước',
    dataIndex: 'previous_revenue',
    key: 'previous_revenue',
    width: 170,
    align: 'right',
    render: (val) => (val != null ? formatMoney(val) : '—'),
  },
  {
    title: 'Chênh lệch',
    dataIndex: 'change_amount',
    key: 'change_amount',
    width: 140,
    align: 'right',
    render: (val) => {
      if (val == null) return '—'
      const n = Number(val)
      return <span className={n >= 0 ? 'pr-positive' : 'pr-negative'}>{n >= 0 ? '+' : ''}{compactMoney(n)}</span>
    },
  },
  {
    title: '% Tăng/Giảm',
    dataIndex: 'change_percent',
    key: 'change_percent',
    width: 130,
    align: 'center',
    sorter: (a, b) => (Number(a.change_percent) || 0) - (Number(b.change_percent) || 0),
    render: (val) => {
      if (val == null) return <Tag>Mới</Tag>
      const n = Number(val)
      return (
        <Tag color={n >= 0 ? 'success' : 'error'} icon={n >= 0 ? <ArrowUpOutlined /> : <ArrowDownOutlined />}>
          {Math.abs(n).toFixed(1)}%
        </Tag>
      )
    },
  },
  {
    title: 'Tỷ trọng',
    dataIndex: 'contribution_percent',
    key: 'contribution_percent',
    width: 100,
    align: 'right',
    render: (val) => (val != null ? `${Number(val).toFixed(1)}%` : '—'),
  },
]

// --- Donut chart slices helper ---
const buildRevenueSlices = (items, totalRevenue) => {
  const leaders = [...(items || [])].sort((a, b) => (Number(b.revenue) || 0) - (Number(a.revenue) || 0)).slice(0, 5)
  const other = Math.max(0, totalRevenue - leaders.reduce((sum, item) => sum + (Number(item.revenue) || 0), 0))
  return [
    ...leaders.map((item) => ({ key: item.name, label: item.name, value: Number(item.revenue) || 0 })),
    ...(other > 0 ? [{ key: 'other', label: 'Sản phẩm khác', value: other }] : []),
  ].filter((s) => s.value > 0)
}

// --- Main Page Component ---
const ProductReportPage = () => {
  const [draftShopId, setDraftShopId] = useState(undefined)
  const [draftRange, setDraftRange] = useState(getDefaultRange)

  const [appliedShopId, setAppliedShopId] = useState(undefined)
  const [appliedRange, setAppliedRange] = useState(getDefaultRange)

  const selectedFrom = appliedRange?.[0]?.format('YYYY-MM-DD')
  const selectedTo = appliedRange?.[1]?.format('YYYY-MM-DD')

  // Auto detect period mode based on selected duration
  const diffDays = useMemo(() => {
    if (!appliedRange?.[0] || !appliedRange?.[1]) return 365
    return appliedRange[1].diff(appliedRange[0], 'day')
  }, [appliedRange])

  const viewMode = diffDays <= 31 ? 'day' : diffDays <= 366 ? 'month' : 'year'

  const reportParams = useMemo(() => ({
    shop_id: appliedShopId,
    view_mode: viewMode,
    date_from: selectedFrom,
    date_to: selectedTo,
    compare: 'mom',
  }), [appliedShopId, viewMode, selectedFrom, selectedTo])

  const reportQuery = useQuery({
    queryKey: ['product-period-report', reportParams],
    queryFn: () => getProductPeriodReport(reportParams),
    staleTime: 5 * 60_000,
    enabled: Boolean(selectedFrom && selectedTo),
  })

  const report = reportQuery.data ?? {}
  const totalRevenue = Number(report.summary?.total_revenue) || 0
  const donutSlices = useMemo(
    () => buildRevenueSlices(report.products, totalRevenue),
    [report.products, totalRevenue],
  )
  const periodLabel = report.from && report.to
    ? `${dayjs(report.from).format('DD/MM/YYYY')} – ${dayjs(report.to).format('DD/MM/YYYY')}`
    : ''

  const isFetching = reportQuery.isFetching
  const isLoading = reportQuery.isLoading
  const failed = reportQuery.isError
  const errorMsg = reportQuery.isError ? getApiErrorMessage(reportQuery.error) : null

  const apply = () => {
    setAppliedShopId(draftShopId)
    setAppliedRange(draftRange?.[0] && draftRange?.[1] ? draftRange : getDefaultRange())
  }

  const reset = () => {
    setDraftShopId(undefined)
    setDraftRange(getDefaultRange())
    setAppliedShopId(undefined)
    setAppliedRange(getDefaultRange())
  }

  return (
    <main className="product-report-page">
      <Card title="Báo cáo sản phẩm" className="product-report-card">
        {/* Unified Filter Bar with System Standard ReportDateRange */}
        <div className="product-report-filters">
          <div className="product-report-field product-report-field--shop">
            <label>Cửa hàng</label>
            <ShopSelect
              value={draftShopId}
              onChange={setDraftShopId}
              useGlobalSelection={false}
              placeholder="Tất cả cửa hàng"
            />
          </div>
          <div className="product-report-field product-report-field--date">
            <label>Thời gian tạo đơn</label>
            <ReportDateRange value={draftRange} onChange={setDraftRange} />
          </div>
          <Space className="product-report-actions">
            <Button
              type="primary"
              icon={<FilterOutlined />}
              onClick={apply}
              loading={isFetching}
            >
              Lọc
            </Button>
            <Button
              icon={<ReloadOutlined />}
              onClick={reset}
              disabled={isFetching}
            >
              Làm mới
            </Button>
          </Space>
        </div>

        {failed && (
          <Alert
            type="error"
            showIcon
            message="Không thể tải báo cáo sản phẩm"
            description={errorMsg}
            style={{ marginBottom: 16 }}
          />
        )}

        {/* KPI Summary Cards */}
        <SummaryCards summary={report.summary} loading={isLoading} />

        {/* Phần 1: Doanh số theo tháng + Cơ cấu doanh thu */}
        <div className="pr-charts-row">
          {/* Doanh số theo tháng (Cân đối, xu hướng rõ ràng) */}
          <section className="pr-section pr-section--chart">
            <h3>Doanh số theo tháng</h3>
            {periodLabel && <p>{periodLabel}</p>}
            <RevenueColumnChart data={report.chart ?? []} loading={isLoading} />
          </section>

          {/* Cơ cấu doanh thu (Donut cân đối, không khoảng trắng thừa) */}
          <section className="pr-section pr-section--donut">
            <h3>Cơ cấu doanh thu</h3>
            {totalRevenue > 0 && <p>Tổng <strong>{formatMoney(totalRevenue)}</strong></p>}
            {isLoading ? (
              <Skeleton active paragraph={{ rows: 4 }} />
            ) : donutSlices.length === 0 ? (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có doanh thu" />
            ) : (
              <DonutChart slices={donutSlices} formatValue={compactMoney} ariaLabel="Biểu đồ cơ cấu doanh thu sản phẩm" />
            )}
          </section>
        </div>

        {/* Phần 2: Báo cáo chi tiết sản phẩm kèm Sơ đồ tăng trưởng (Hình số 5) */}
        <section className="pr-section pr-section--table">
          <h3>Báo cáo chi tiết sản phẩm</h3>
          {periodLabel && <p>{periodLabel}</p>}

          {/* Sơ đồ trực quan bám sát Hình số 5 */}
          <ProductGrowthComboChart data={report.chart ?? []} loading={isLoading} />

          {/* Bảng chi tiết sản phẩm */}
          <Table
            dataSource={(report.products ?? []).map((item, idx) => ({ ...item, key: `${idx}-${item.name}` }))}
            columns={productColumns}
            loading={isLoading}
            pagination={{ pageSize: 20, showSizeChanger: true, showTotal: (total) => `Tổng ${total} sản phẩm` }}
            scroll={{ x: 1050 }}
            size="middle"
            locale={{ emptyText: <Empty description="Chưa có sản phẩm trong kỳ này" /> }}
          />
        </section>
      </Card>
    </main>
  )
}

export default ProductReportPage
