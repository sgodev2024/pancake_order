import { Alert, Empty, Progress } from 'antd'
import dayjs from 'dayjs'
import { useState } from 'react'

const SLICE_COLORS = ['#4f73f6', '#35b889', '#f2b24b', '#7c97f8', '#e87676', '#94a3b8']
const currency = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 })

const formatCount = (value) => Number(value || 0).toLocaleString('vi-VN')

const formatCompact = (value) => {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  const absolute = Math.abs(amount)
  if (absolute >= 1_000_000_000) {
    return `${(amount / 1_000_000_000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tỷ`
  }
  if (absolute >= 1_000_000) {
    return `${(amount / 1_000_000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tr`
  }
  return amount.toLocaleString('vi-VN')
}

const monthLabel = (from, to) => {
  if (!from || !to) return ''
  return `${dayjs(from).format('DD/MM/YYYY')} – ${dayjs(to).format('DD/MM/YYYY')}`
}

const COLUMN_COLOR = '#1677ff'
const ORDER_COLOR = '#fa8c16'
const COLLECTED_COLOR = '#13a36a'

const amountOf = (values) => (values ?? []).map((value) => Math.max(0, Number(value) || 0))

const niceMax = (value) => {
  const million = Math.max(value, 0) / 1_000_000
  if (million <= 0) return 1_000_000
  const magnitude = 10 ** Math.floor(Math.log10(million))
  const normalized = million / magnitude
  const nice = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10
  return nice * magnitude * 1_000_000
}

const formatMillion = (value) => {
  const million = value / 1_000_000
  return million.toLocaleString('vi-VN', { maximumFractionDigits: million >= 10 ? 0 : 1 })
}

const TrendChart = ({ trend }) => {
  const [active, setActive] = useState(null)
  const labels = trend?.labels ?? []
  const delivered = amountOf(trend?.current)
  const orderValue = amountOf(trend?.order_value)
  const collected = amountOf(trend?.collected)
  const max = niceMax(Math.max(1, ...delivered, ...orderValue, ...collected))
  const width = 720
  const height = 248
  const left = 46
  const right = 16
  const top = 12
  const plotWidth = width - left - right
  const plotHeight = height - top
  const slot = labels.length > 0 ? plotWidth / labels.length : plotWidth
  const barWidth = Math.min(42, slot * 0.42)
  const pointAt = (series, index) => ({
    x: left + slot * index + slot / 2,
    y: top + plotHeight - ((series[index] || 0) / max) * plotHeight,
  })
  const linePath = (series) => series.map((_, index) => {
    const point = pointAt(series, index)
    return `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`
  }).join(' ')
  const activePoint = active == null ? null : pointAt(delivered, active)

  return (
    <div className="region-board__trend">
      <p className="region-board__unit">Đơn vị tính: triệu đồng</p>
      <svg
        viewBox={`0 0 ${width} ${height + 28}`}
        role="img"
        aria-label="Doanh số giao thành công theo tuần"
        onMouseLeave={() => setActive(null)}
      >
        {[0, 0.25, 0.5, 0.75, 1].map((mark) => {
          const y = top + plotHeight - mark * plotHeight
          return (
            <g key={mark}>
              <line x1={left} y1={y} x2={width - right} y2={y} className="region-board__grid-line" />
              <text x={left - 8} y={y + 4} textAnchor="end" className="region-board__axis">{formatMillion(max * mark)}</text>
            </g>
          )
        })}
        {delivered.map((value, index) => {
          const barHeight = (value / max) * plotHeight
          const x = left + slot * index + (slot - barWidth) / 2
          const y = top + plotHeight - barHeight
          return (
            <rect
              key={`bar-${labels[index] ?? index}`}
              x={x}
              y={value > 0 ? y : top + plotHeight}
              width={barWidth}
              height={value > 0 ? Math.max(barHeight, 2) : 0}
              rx="3"
              fill={COLUMN_COLOR}
              opacity={active == null || active === index ? 1 : 0.45}
            />
          )
        })}
        {orderValue.length > 0 && <path d={linePath(orderValue)} fill="none" stroke={ORDER_COLOR} strokeWidth="2.5" strokeLinejoin="round" />}
        {collected.length > 0 && <path d={linePath(collected)} fill="none" stroke={COLLECTED_COLOR} strokeWidth="2.5" strokeLinejoin="round" />}
        {orderValue.map((_, index) => {
          const point = pointAt(orderValue, index)
          return <circle key={`order-${labels[index] ?? index}`} cx={point.x} cy={point.y} r="4" fill="#fff" stroke={ORDER_COLOR} strokeWidth="2.5" />
        })}
        {collected.map((_, index) => {
          const point = pointAt(collected, index)
          return <circle key={`collected-${labels[index] ?? index}`} cx={point.x} cy={point.y} r="4" fill="#fff" stroke={COLLECTED_COLOR} strokeWidth="2.5" />
        })}
        {labels.map((label, index) => (
          <g key={`${label}-${index}`}>
            <text x={left + slot * index + slot / 2} y={height + 20} textAnchor="middle" className="region-board__axis">{label}</text>
            <rect
              x={left + slot * index}
              y={top}
              width={slot}
              height={plotHeight}
              fill="transparent"
              onMouseEnter={() => setActive(index)}
            />
          </g>
        ))}
      </svg>
      {active != null && (
        <div
          className="region-board__tooltip"
          style={{ left: `${((activePoint?.x ?? 0) / width) * 100}%` }}
        >
          <strong>{labels[active]}</strong>
          <span><i className="region-board__swatch" style={{ background: COLUMN_COLOR }} />Giao thành công: {currency.format(delivered[active] || 0)}</span>
          <span><i style={{ background: ORDER_COLOR }} />Giá trị đơn: {currency.format(orderValue[active] || 0)}</span>
          <span><i style={{ background: COLLECTED_COLOR }} />Đã thu tiền: {currency.format(collected[active] || 0)}</span>
        </div>
      )}
      <ul className="region-board__legend">
        <li><i className="region-board__swatch" style={{ background: COLUMN_COLOR }} />Giao thành công</li>
        <li><i className="region-board__legend-line" style={{ background: ORDER_COLOR }} />Giá trị đơn</li>
        <li><i className="region-board__legend-line" style={{ background: COLLECTED_COLOR }} />Đã thu tiền</li>
      </ul>
    </div>
  )
}

const ShareChart = ({ slices, total }) => {
  const radius = 56
  const circumference = 2 * Math.PI * radius
  const segments = slices.reduce((result, slice, index) => {
    const length = total > 0 ? (Math.max(0, slice.revenue) / total) * circumference : 0
    result.items.push({ ...slice, length, offset: -result.consumed, color: SLICE_COLORS[index % SLICE_COLORS.length] })
    result.consumed += length
    return result
  }, { items: [], consumed: 0 }).items

  return (
    <div className="region-board__share">
      <svg viewBox="0 0 160 160" role="img" aria-label="Cơ cấu doanh thu theo tỉnh">
        <circle cx="80" cy="80" r={radius} fill="none" stroke="#edf0f5" strokeWidth="24" />
        {segments.map((slice) => (
          <circle
            key={slice.name}
            cx="80"
            cy="80"
            r={radius}
            fill="none"
            stroke={slice.color}
            strokeWidth="24"
            strokeDasharray={`${slice.length} ${Math.max(circumference - slice.length, 0)}`}
            strokeDashoffset={slice.offset}
            transform="rotate(-90 80 80)"
          />
        ))}
        <text x="80" y="76" textAnchor="middle" className="region-board__donut-value">{formatCompact(total)}</text>
        <text x="80" y="94" textAnchor="middle" className="region-board__donut-label">Tổng COD</text>
      </svg>
      <div className="region-board__share-list">
        {segments.map((slice) => (
          <div key={slice.name}>
            <span><i style={{ background: slice.color }} />{slice.name}</span>
            <b>{currency.format(slice.revenue)}</b>
          </div>
        ))}
      </div>
    </div>
  )
}

const changeText = (value, label) => {
  if (value === null || value === undefined) return null
  const amount = Number(value)
  const positive = amount >= 0
  return `${positive ? '+' : ''}${amount.toLocaleString('vi-VN', { maximumFractionDigits: 1 })}% ${label}`
}

const ProvinceSalesBoard = ({ data, loading, error, errorMessage, onRetry }) => {
  const items = [...(data?.items ?? [])].sort((left, right) => Number(right.delivered_value || 0) - Number(left.delivered_value || 0))
  const rankedItems = items.filter((item) => Number(item.delivered_value || 0) > 0).slice(0, 10)
  const leader = Number(rankedItems[0]?.delivered_value) || 0
  const ranking = rankedItems.map((item, index) => ({
    ...item,
    rank: index + 1,
    percent: leader > 0 ? Math.round((Number(item.delivered_value) / leader) * 100) : 0,
  }))
  const period = monthLabel(data?.from, data?.to)
  const listed = ranking.slice(0, 5)
  const listedRevenue = listed.reduce((sum, item) => sum + Number(item.delivered_value || 0), 0)
  const otherRevenue = Math.max(0, Number(data?.delivered_value || 0) - listedRevenue)
  const slices = [
    ...listed.map((item) => ({ name: item.name || 'Chưa rõ tỉnh', revenue: Number(item.delivered_value || 0) })),
    ...(otherRevenue > 0 ? [{ name: 'Các tỉnh khác', revenue: otherRevenue }] : []),
  ]
  const previousChange = changeText(data?.delivered_vs_previous, 'so tuần trước')
  const yearChange = changeText(data?.delivered_vs_year, 'so cùng kỳ năm trước')

  return (
    <>
      <div className="region-board">
        <article className="region-board__panel">
          <h3>Giao thành công theo tuần</h3>
          <p>{period ? `Cột là doanh số đã nhận. Đường là giá trị đơn và số đã thu, 6 tuần kết thúc tại ${period}.` : 'Doanh số đã nhận theo tuần.'}</p>
          {loading ? <div className="region-board__placeholder" /> : <TrendChart trend={data?.trend} />}
        </article>
        <article className="region-board__panel">
          <h3>Cơ cấu giao thành công theo tỉnh</h3>
          <p>
            {loading
              ? 'Đang tính…'
              : `${formatCount(data?.province_count)} tỉnh · ${data?.delivered_value == null ? 'Chưa đủ trả trước để tính' : currency.format(Number(data.delivered_value))} · Bao phủ trả trước ${data?.prepaid_coverage == null ? '—' : `${Number(data.prepaid_coverage).toLocaleString('vi-VN')}%`}`}
          </p>
          {loading ? <div className="region-board__placeholder" /> : slices.length === 0 ? (
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có đơn đã nhận trong tuần này" />
          ) : (
            <ShareChart slices={slices} total={Number(data?.delivered_value || 0)} />
          )}
        </article>
      </div>

      <section className="region-ranking" aria-label="Top doanh thu theo tỉnh">
        <div className="region-ranking__head">
          <div>
            <h3>Top giao thành công theo tỉnh</h3>
            <p>
              {period
                ? `Giá trị COD + trả trước của đơn Đã nhận, ${period}.${data?.truncated ? ' Kỳ đang diễn ra, so tới cùng thời điểm.' : ''}`
                : 'Đang xác định tuần có đơn gần nhất.'}
            </p>
            {(previousChange || yearChange) && (
              <p>{[previousChange, yearChange].filter(Boolean).join(' · ')}</p>
            )}
          </div>
        </div>
        {error ? (
          <Alert type="error" showIcon message="Không thể tải top doanh thu theo tỉnh" description={errorMessage} />
        ) : loading ? (
          <div className="region-ranking__loading">Đang tính top doanh thu...</div>
        ) : ranking.length === 0 ? (
          <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description={data?.latest_order_at
                ? `Không có đơn đã nhận trong tuần này. Đơn gần nhất là ${dayjs(data.latest_order_at).format('DD/MM/YYYY')}.`
                : 'Không có đơn đã nhận trong tuần này'}
          />
        ) : (
          <ol className="region-ranking__list">
            {ranking.map((item) => (
              <li key={item.id ?? item.name}>
                <span className="region-ranking__rank">{item.rank}</span>
                <strong className="region-ranking__name">{item.name || 'Chưa rõ tỉnh'}</strong>
                <Progress percent={item.percent} showInfo={false} strokeColor="#1677ff" />
                <div className="region-ranking__metrics">
                  <b>{currency.format(Number(item.delivered_value || 0))}</b>
                  <span>{formatCount(item.orders_count)} đơn</span>
                </div>
              </li>
            ))}
          </ol>
        )}
      </section>
      {error && onRetry ? (
        <button type="button" className="region-board__retry" onClick={onRetry}>Thử lại</button>
      ) : null}
    </>
  )
}

export default ProvinceSalesBoard
