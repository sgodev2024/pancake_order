import './donut-chart.css'

const COLORS = ['#1677ff', '#fa8c16', '#13a36a', '#722ed1', '#eb2f96', '#13c2c2']
const OTHER_COLOR = '#8c9bab'

const DonutChart = ({ slices, formatValue, ariaLabel }) => {
  const rows = (slices ?? []).filter((slice) => Number(slice.value) > 0)
  const total = rows.reduce((sum, slice) => sum + Number(slice.value), 0)
  const radius = 54
  const circumference = 2 * Math.PI * radius
  let consumed = 0
  const segments = rows.map((slice, index) => {
    const length = total > 0 ? (Number(slice.value) / total) * circumference : 0
    const gap = rows.length > 1 ? Math.min(3, length * 0.12) : 0
    const segment = {
      ...slice,
      color: slice.color || (slice.key === 'other' ? OTHER_COLOR : COLORS[index % COLORS.length]),
      length: Math.max(length - gap, 0),
      offset: -consumed,
      percent: total > 0 ? (Number(slice.value) / total) * 100 : 0,
    }
    consumed += length
    return segment
  })

  if (segments.length === 0) return null

  return (
    <div className="share-donut">
      <svg viewBox="0 0 160 160" role="img" aria-label={ariaLabel}>
        <circle cx="80" cy="80" r={radius} fill="none" stroke="#eef1f6" strokeWidth="22" />
        {segments.map((segment) => (
          <circle
            key={segment.key}
            cx="80"
            cy="80"
            r={radius}
            fill="none"
            stroke={segment.color}
            strokeWidth="22"
            strokeDasharray={`${segment.length} ${Math.max(circumference - segment.length, 0)}`}
            strokeDashoffset={segment.offset}
            transform="rotate(-90 80 80)"
          />
        ))}
      </svg>
      <ul className="share-donut__legend">
        {segments.map((segment) => (
          <li key={segment.key}>
            <i style={{ background: segment.color }} />
            <span title={segment.label}>{segment.label}</span>
            <b>{formatValue(segment.value)}</b>
            <em>{segment.percent.toLocaleString('vi-VN', { maximumFractionDigits: 1 })}%</em>
          </li>
        ))}
      </ul>
    </div>
  )
}

export default DonutChart
