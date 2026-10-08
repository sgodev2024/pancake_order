import {
  CheckCircleOutlined,
  CreditCardOutlined,
  DollarOutlined,
  ShoppingOutlined,
} from "@ant-design/icons";

const GROUP_COLORS = {
  shipping: "#4f73f6",
  success: "#35b889",
  cancel: "#f2b24b",
  return: "#e87676",
  pending: "#94a3b8",
};

const formatCount = (value) => {
  const amount = Number(value);
  return Number.isFinite(amount) ? amount.toLocaleString("vi-VN") : "—";
};

const formatCompact = (value) => {
  const amount = Number(value);
  if (!Number.isFinite(amount)) return "—";
  const absolute = Math.abs(amount);
  if (absolute >= 1_000_000_000) {
    return `${(amount / 1_000_000_000).toLocaleString("vi-VN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tỷ`;
  }
  if (absolute >= 1_000_000) {
    return `${(amount / 1_000_000).toLocaleString("vi-VN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tr`;
  }
  return amount.toLocaleString("vi-VN");
};

const Delta = ({ value, label }) => {
  if (value === null || value === undefined || Number.isNaN(Number(value)))
    return null;
  const amount = Number(value);
  const positive = amount >= 0;
  return (
    <span
      className={
        positive ? "orders-board__delta is-up" : "orders-board__delta is-down"
      }
    >
      {`${positive ? "+" : ""}${amount.toLocaleString("vi-VN", { maximumFractionDigits: 1 })}% ${label}`}
    </span>
  );
};

const createSmoothPath = (points) => {
  if (points.length === 0) return "";
  return points.slice(1).reduce((path, point, index) => {
    const previous = points[index];
    const controlOffset = (point.x - previous.x) / 2;
    return `${path} C ${previous.x + controlOffset} ${previous.y}, ${point.x - controlOffset} ${point.y}, ${point.x} ${point.y}`;
  }, `M ${points[0].x} ${points[0].y}`);
};

const TrendAreaChart = ({ trend }) => {
  const current = trend?.current ?? [];
  const previous = trend?.previous ?? [];
  const previousYear = trend?.previous_year ?? [];
  const labels = trend?.labels ?? [];
  const max = Math.max(1, ...current, ...previous, ...previousYear);
  const width = 720;
  const height = 220;
  const left = 58;
  const right = 16;
  const top = 10;
  const plotWidth = width - left - right;
  const plotHeight = height - top;
  const enabledSeries = [
    {
      key: "current",
      label: "COD đơn đã nhận/đã thu tiền",
      values: current,
      color: "#4f73f6",
    },
    ...(previous.length > 0
      ? [
          {
            key: "previous",
            label: "Kỳ trước",
            values: previous,
            color: "#91a8f8",
          },
        ]
      : []),
    ...(previousYear.length > 0
      ? [
          {
            key: "year",
            label: "Cùng kỳ năm trước",
            values: previousYear,
            color: "#58c7a4",
          },
        ]
      : []),
  ];
  const seriesWithPoints = enabledSeries.map((series) => ({
    ...series,
    points: series.values.map((value, index) => ({
      x:
        left +
        (series.values.length <= 1
          ? plotWidth / 2
          : (index / (series.values.length - 1)) * plotWidth),
      y:
        top + plotHeight - (Math.max(0, Number(value) || 0) / max) * plotHeight,
    })),
  }));
  const labelStep = Math.max(1, Math.ceil(labels.length / 7));
  const labelIndexes = labels
    .map((_, index) => index)
    .filter((index) => index % labelStep === 0 || index === labels.length - 1);

  return (
    <div className="orders-board__trend">
      <svg
        viewBox={`0 0 ${width} ${height + 34}`}
        role="img"
        aria-label="COD đơn đã nhận hoặc đã thu tiền"
      >
        <defs>
          {seriesWithPoints.map((series) => (
            <linearGradient
              key={series.key}
              id={`orders-area-${series.key}`}
              x1="0"
              y1="0"
              x2="0"
              y2="1"
            >
              <stop
                offset="0%"
                stopColor={series.color}
                stopOpacity={series.key === "current" ? 0.32 : 0.18}
              />
              <stop offset="100%" stopColor={series.color} stopOpacity="0.02" />
            </linearGradient>
          ))}
        </defs>
        {[0, 0.25, 0.5, 0.75, 1].map((mark) => {
          const y = top + plotHeight - mark * plotHeight;
          return (
            <g key={mark}>
              <line
                x1={left}
                y1={y}
                x2={width - right}
                y2={y}
                className="orders-board__grid-line"
              />
              <text
                x={left - 10}
                y={y + 4}
                textAnchor="end"
                className="orders-board__axis"
              >
                {formatCompact(max * mark)}
              </text>
            </g>
          );
        })}
        {seriesWithPoints.map((series) => {
          if (series.points.length === 0) return null;
          const linePath = createSmoothPath(series.points);
          const areaPath = `${linePath} L ${series.points.at(-1).x} ${height} L ${series.points[0].x} ${height} Z`;
          return (
            <g key={series.key}>
              <path d={areaPath} fill={`url(#orders-area-${series.key})`} />
              <path
                d={linePath}
                fill="none"
                stroke={series.color}
                strokeWidth={series.key === "current" ? 3 : 2.25}
                strokeLinecap="round"
              />
            </g>
          );
        })}
        {labelIndexes.map((index) => (
          <text
            key={`${labels[index]}-${index}`}
            x={
              left +
              (labels.length <= 1
                ? plotWidth / 2
                : (index / (labels.length - 1)) * plotWidth)
            }
            y={height + 24}
            textAnchor={
              index === 0
                ? "start"
                : index === labels.length - 1
                  ? "end"
                  : "middle"
            }
            className="orders-board__axis"
          >
            {labels[index]}
          </text>
        ))}
      </svg>
      <div className="orders-board__legend-row">
        {seriesWithPoints.map((series) => (
          <span key={series.key}>
            <i style={{ background: series.color }} />
            {series.label}
          </span>
        ))}
      </div>
    </div>
  );
};

const DonutChart = ({ groups }) => {
  const total = groups.reduce(
    (sum, group) => sum + (Number(group.amount) || 0),
    0,
  );
  const radius = 56;
  const circumference = 2 * Math.PI * radius;
  const segments = groups.reduce(
    (result, group) => {
      const length =
        total > 0
          ? (Math.max(0, Number(group.amount) || 0) / total) * circumference
          : 0;
      result.items.push({ ...group, length, offset: -result.consumed });
      result.consumed += length;
      return result;
    },
    { items: [], consumed: 0 },
  ).items;

  return (
    <div className="orders-board__donut">
      <svg
        viewBox="0 0 160 160"
        role="img"
        aria-label="Biểu đồ tròn cơ cấu trạng thái đơn"
      >
        <circle
          cx="80"
          cy="80"
          r={radius}
          fill="none"
          stroke="#edf0f5"
          strokeWidth="24"
        />
        {segments.map((group) => (
          <circle
            key={group.key}
            cx="80"
            cy="80"
            r={radius}
            fill="none"
            stroke={GROUP_COLORS[group.key] || "#94a3b8"}
            strokeWidth="24"
            strokeDasharray={`${group.length} ${Math.max(circumference - group.length, 0)}`}
            strokeDashoffset={group.offset}
            transform="rotate(-90 80 80)"
          />
        ))}
        <text
          x="80"
          y="76"
          textAnchor="middle"
          className="orders-board__donut-value"
        >
          {formatCompact(total)}
        </text>
        <text
          x="80"
          y="94"
          textAnchor="middle"
          className="orders-board__donut-label"
        >
          Tổng COD
        </text>
      </svg>
    </div>
  );
};

const MetricCard = ({ icon: Icon, label, value, children, note }) => (
  <article className="orders-board__card">
    <div className="orders-board__card-head">
      <span>{label}</span>
      <i aria-hidden="true">
        <Icon />
      </i>
    </div>
    <strong>{value}</strong>
    {children}
    <small>{note}</small>
  </article>
);

const OrderSummaryChart = ({
  createdAmount,
  successAmount,
  successCount,
  prepaidAmount,
  breakdown = [],
  trend,
  compare,
  loading = false,
  error = false,
  onRetry,
}) => {
  const groups = Array.isArray(breakdown) ? breakdown : [];
  const orderCount = groups.reduce(
    (sum, group) => sum + (Number(group.count) || 0),
    0,
  );

  return (
    <section className="orders-board" aria-label="Tổng quan đơn hàng">
      <div className="orders-board__cards">
        <MetricCard
          icon={DollarOutlined}
          label="Tổng COD trên đơn"
          value={loading ? "…" : formatCompact(createdAmount)}
          note="Tổng COD của tất cả đơn theo bộ lọc"
        >
          <Delta value={compare?.created_vs_previous} label="so kỳ trước" />
          <Delta value={compare?.created_vs_year} label="so năm trước" />
        </MetricCard>
        <MetricCard
          icon={CheckCircleOutlined}
          label="COD đơn đã nhận/đã thu tiền"
          value={loading ? "…" : formatCompact(successAmount)}
          note="Tổng COD của đơn ở trạng thái Đã nhận hoặc Đã thu tiền"
        >
          <Delta value={compare?.success_vs_previous} label="so kỳ trước" />
          <Delta value={compare?.success_vs_year} label="so năm trước" />
        </MetricCard>
        <MetricCard
          icon={ShoppingOutlined}
          label="Số đơn đã nhận/đã thu tiền"
          value={loading ? "…" : formatCount(successCount)}
          note="Đếm đơn ở trạng thái Đã nhận hoặc Đã thu tiền"
        />
        <MetricCard
          icon={CreditCardOutlined}
          label="Thanh toán trả trước"
          value={loading ? "…" : formatCompact(prepaidAmount)}
          note="Tổng tiền trả trước của tất cả đơn theo bộ lọc"
        />
      </div>

      {error ? (
        <div className="orders-board__empty">
          <span>Chưa tính được số liệu đơn hàng.</span>
          {onRetry && (
            <button type="button" onClick={onRetry}>
              Thử lại
            </button>
          )}
        </div>
      ) : (
        <div className="orders-board__grid">
          <article className="orders-board__panel">
            <div className="orders-board__panel-head">
              <h3>COD đơn giao thành công theo ngày tạo đơn</h3>
              <span aria-hidden="true">⋮</span>
            </div>
            <p>
              {compare
                ? "So sánh COD của đơn đã nhận hoặc đã thu tiền với kỳ trước và cùng kỳ năm trước, tính theo ngày tạo đơn."
                : "COD của đơn đã nhận hoặc đã thu tiền, tính theo ngày tạo đơn."}
            </p>
            {loading ? (
              <div className="orders-board__placeholder" />
            ) : (
              <TrendAreaChart trend={trend} />
            )}
          </article>

          <article className="orders-board__panel">
            <div className="orders-board__panel-head">
              <h3>Cơ cấu trạng thái đơn</h3>
              <span aria-hidden="true">⋮</span>
            </div>
            <p>
              {loading
                ? "Đang tính…"
                : `${formatCount(orderCount)} đơn · ${formatCompact(createdAmount)} COD`}
            </p>
            {loading ? (
              <div className="orders-board__placeholder" />
            ) : (
              <DonutChart groups={groups} />
            )}
            <div className="orders-board__status-table">
              <div className="orders-board__status-head">
                <span>Trạng thái</span>
                <span>Số đơn</span>
                <span>COD</span>
              </div>
              {groups.map((group) => (
                <div className="orders-board__status-row" key={group.key}>
                  <span>
                    <i
                      style={{
                        background: GROUP_COLORS[group.key] || "#94a3b8",
                      }}
                    />
                    {group.label}
                  </span>
                  <span>{formatCount(group.count)}</span>
                  <strong>{loading ? "…" : formatCompact(group.amount)}</strong>
                </div>
              ))}
            </div>
          </article>
        </div>
      )}
    </section>
  );
};

export default OrderSummaryChart;
