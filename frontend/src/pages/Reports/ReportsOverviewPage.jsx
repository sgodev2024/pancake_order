import { ArrowDownOutlined, ArrowUpOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Alert, Empty, Skeleton } from 'antd'
import dayjs from 'dayjs'
import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { getOverview } from '../../api/overview.api.js'
import { getOrderTotals } from '../../api/orders.api.js'
import { getProductMonthlyQuantities, getProductSales } from '../../api/products.api.js'
import DonutChart from '../../components/DonutChart.jsx'
import { ROUTES } from '../../constants/routes.js'
import { getApiErrorMessage } from '../../utils/response.js'
import { createEmptyOrderFilters, normalizeOrderFilters } from '../Orders/orderFilters.js'
import './reports-overview.css'

const numberFormatter = new Intl.NumberFormat('vi-VN')
const moneyFormatter = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 })
const number = (value) => numberFormatter.format(Number(value) || 0)
const money = (value) => moneyFormatter.format(Number(value) || 0)
const period = (from, to) => (from && to ? `${dayjs(from).format('DD/MM/YYYY')} – ${dayjs(to).format('DD/MM/YYYY')}` : '')

const ORDER_PARAMS = normalizeOrderFilters(createEmptyOrderFilters())
const LATEST_WEEK = { week_mode: 'latest' }
const queryOptions = { staleTime: 60_000, refetchOnWindowFocus: false }

const compactMoney = (value) => {
  const amount = Number(value) || 0
  const absolute = Math.abs(amount)
  if (absolute >= 1_000_000_000) return `${(amount / 1_000_000_000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tỷ`
  if (absolute >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('vi-VN', { maximumFractionDigits: 1 })} tr`
  return amount.toLocaleString('vi-VN')
}

const revenueSlices = (items, totalRevenue) => {
  const leaders = [...items].sort((left, right) => Number(right.revenue || 0) - Number(left.revenue || 0)).slice(0, 5)
  const other = Math.max(0, totalRevenue - leaders.reduce((sum, item) => sum + (Number(item.revenue) || 0), 0))
  return [
    ...leaders.map((item) => ({ key: item.name, label: item.name, value: Number(item.revenue) || 0 })),
    ...(other > 0 ? [{ key: 'other', label: 'Sản phẩm khác', value: other }] : []),
  ].filter((slice) => slice.value > 0)
}

const Kpi = ({ label, value, note, to, children }) => (
  <Link className="ov-kpi" to={to}>
    <span>{label}</span>
    <strong>{value}</strong>
    {children}
    {note ? <small>{note}</small> : null}
    <em className="ov-kpi__link">Mở báo cáo</em>
  </Link>
)

const MonthlyQuantityChart = ({ data }) => {
  const months = data?.months ?? []
  const summary = data?.summary ?? { up_count: 0, up_qty_total: 0, down_count: 0, down_qty_total: 0 }
  const max = Math.max(...months.map((m) => Number(m.quantity) || 0), 1)

  return (
    <div className="ov-monthly-qty">
      {/* 2 phần chính: Tăng và Giảm sau mỗi tháng */}
      <div className="ov-qty-summary">
        <div className="ov-qty-summary__item ov-qty-summary__item--up">
          <span className="ov-qty-summary__badge">
            <ArrowUpOutlined /> Tăng sau mỗi tháng
          </span>
          <div className="ov-qty-summary__metrics">
            <strong>{summary.up_count} tháng</strong>
            <small>+{compactMoney(summary.up_qty_total).replace(' đ', '')} SP</small>
          </div>
        </div>
        <div className="ov-qty-summary__item ov-qty-summary__item--down">
          <span className="ov-qty-summary__badge">
            <ArrowDownOutlined /> Giảm sau mỗi tháng
          </span>
          <div className="ov-qty-summary__metrics">
            <strong>{summary.down_count} tháng</strong>
            <small>-{compactMoney(summary.down_qty_total).replace(' đ', '')} SP</small>
          </div>
        </div>
      </div>

      {/* 12 cột cho 12 tháng */}
      <div className="ov-columns ov-columns--12" role="img" aria-label="Số lượng sản phẩm theo 12 tháng">
        {months.map((item) => {
          const qty = Number(item.quantity) || 0
          const heightPercent = max > 0 ? (qty / max) * 100 : 0
          const isUp = item.trend === 'up'
          const isDown = item.trend === 'down'
          const tooltip = item.month === 1
            ? `Tháng 1: ${number(qty)} SP (Mốc đầu kỳ)`
            : isUp
            ? `Tháng ${item.month}: ${number(qty)} SP (+${number(item.diff)} SP, +${item.percent}%)`
            : isDown
            ? `Tháng ${item.month}: ${number(qty)} SP (${number(item.diff)} SP, ${item.percent}%)`
            : `Tháng ${item.month}: ${number(qty)} SP`

          return (
            <div key={item.label} title={tooltip} className={`ov-col-item ov-col-item--${item.trend}`}>
              <span className="ov-col-val">{qty > 0 ? (qty >= 1000 ? `${(qty / 1000).toFixed(1)}k` : number(qty)) : '0'}</span>
              <div className="ov-columns__plot">
                <i
                  style={{ height: `${heightPercent}%` }}
                  className={isUp ? 'ov-bar--up' : isDown ? 'ov-bar--down' : ''}
                />
              </div>
              <em>{item.label}</em>
              <span className={`ov-col-diff ov-col-diff--${item.trend}`}>
                {item.month === 1
                  ? 'Gốc'
                  : isUp
                  ? `+${Math.round(item.percent)}%`
                  : isDown
                  ? `${Math.round(item.percent)}%`
                  : '—'}
              </span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

const RevenueBars = ({ items }) => {
  const max = Math.max(...items.map((item) => Number(item.revenue) || 0), 1)
  return (
    <div className="ov-bars">
      {items.map((item) => (
        <div key={item.name}>
          <span title={item.name}>{item.name}</span>
          <span className="ov-bars__track">
            <span style={{ width: `${((Number(item.revenue) || 0) / max) * 100}%` }} />
          </span>
          <strong>{compactMoney(item.revenue)}</strong>
        </div>
      ))}
    </div>
  )
}

const ChartCard = ({ title, note, action, children }) => (
  <section className="ov-block">
    <header>
      <div>
        <h2>{title}</h2>
        {note ? <p>{note}</p> : null}
      </div>
      {action}
    </header>
    {children}
  </section>
)

const ReportsOverviewPage = () => {
  const todayDate = dayjs().format('YYYY-MM-DD')
  const todayOrderParams = useMemo(() => ({
    date_from: todayDate,
    date_to: todayDate,
  }), [todayDate])

  const todayOrderQuery = useQuery({
    queryKey: ['order-report-today', todayOrderParams],
    queryFn: ({ signal }) => getOrderTotals(todayOrderParams, { signal }),
    ...queryOptions,
  })
  const orderQuery = useQuery({
    queryKey: ['order-report', ORDER_PARAMS],
    queryFn: ({ signal }) => getOrderTotals(ORDER_PARAMS, { signal }),
    ...queryOptions,
  })
  const careQuery = useQuery({ queryKey: ['overview'], queryFn: getOverview, ...queryOptions })
  const productQuery = useQuery({
    queryKey: ['product-sales', LATEST_WEEK],
    queryFn: () => getProductSales(LATEST_WEEK),
    ...queryOptions,
  })
  const monthlyQtyQuery = useQuery({
    queryKey: ['product-monthly-quantities'],
    queryFn: () => getProductMonthlyQuantities(),
    ...queryOptions,
  })

  const todayOrders = todayOrderQuery.data ?? {}
  const todayCod = Number(todayOrders.total_revenue) || 0
  const todayPrepaid = Number(todayOrders.total_prepaid_amount) || 0

  const orders = orderQuery.data ?? {}
  const care = careQuery.data ?? {}
  const product = productQuery.data ?? {}
  const cod = Number(orders.total_revenue) || 0
  const prepaid = Number(orders.total_prepaid_amount) || 0
  const productItems = product.items ?? []
  const revenueItems = [...productItems].sort((left, right) => Number(right.revenue || 0) - Number(left.revenue || 0)).slice(0, 8)
  const productPeriod = period(product.from, product.to)
  const slices = revenueSlices(productItems, Number(product.total_revenue) || 0)
  const paymentSlices = [
    { key: 'cod', label: 'COD', value: cod, color: '#1677ff' },
    { key: 'prepaid', label: 'Trả trước', value: prepaid, color: '#fa8c16' },
  ]
  const failed = [todayOrderQuery, orderQuery, careQuery, productQuery, monthlyQtyQuery].find((query) => query.isError)

  return (
    <main className="ov-page">
      {failed ? <Alert type="error" showIcon message="Một phần tổng quan chưa tải được" description={getApiErrorMessage(failed.error)} /> : null}

      <section className="ov-kpis">
        {todayOrderQuery.isLoading ? <Skeleton active /> : (
          <Kpi to={ROUTES.ORDER_REPORT} label="Tổng giá trị đơn hôm nay" value={money(todayCod + todayPrepaid)} note={`${number(todayOrders.total_items)} đơn`}>
            <ul>
              <li><span>COD</span><b>{money(todayCod)}</b></li>
              <li><span>Trả trước</span><b>{money(todayPrepaid)}</b></li>
            </ul>
          </Kpi>
        )}
        {careQuery.isLoading ? <Skeleton active /> : (
          <>
            <Kpi
              to={ROUTES.CARE_TODAY}
              label="Hôm nay"
              value={`${number(care.customer_care_today_done)}/${number(care.customer_care_today)}`}
              note="Đã chăm sóc / lịch trong ngày"
            />
            <Kpi
              to={ROUTES.CARE_UPCOMING}
              label="Sắp diễn ra"
              value={`${number(care.customer_care_pending_done)}/${number(care.customer_care_pending)}`}
              note="Đã chăm sóc / lịch phía trước"
            />
            <Kpi
              to={ROUTES.CARE_OVERDUE}
              label="Quá hạn"
              value={`${number(care.customer_care_expire_done)}/${number(care.customer_care_expire)}`}
              note="Đã chăm sóc muộn / lịch quá ngày"
            />
          </>
        )}
      </section>

      <section className="ov-charts">
        <ChartCard
          title="Số lượng sản phẩm"
          note={monthlyQtyQuery.data?.year ? `Năm ${monthlyQtyQuery.data.year} · Tổng ${number(monthlyQtyQuery.data.total_quantity)} sản phẩm` : 'Theo 12 tháng'}
          action={<Link to={ROUTES.PRODUCT_REPORT}>Mở báo cáo</Link>}
        >
          {monthlyQtyQuery.isLoading ? <Skeleton active /> : !monthlyQtyQuery.data?.months?.length ? (
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có dữ liệu sản phẩm trong năm" />
          ) : <MonthlyQuantityChart data={monthlyQtyQuery.data} />}
        </ChartCard>

        <ChartCard
          title="Cơ cấu đơn hàng"
          note="COD và trả trước"
          action={<Link to={ROUTES.ORDER_REPORT}>Mở báo cáo</Link>}
        >
          {orderQuery.isLoading ? <Skeleton active /> : cod + prepaid <= 0 ? (
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có giá trị đơn" />
          ) : (
            <DonutChart slices={paymentSlices} formatValue={compactMoney} ariaLabel="Biểu đồ tròn COD và trả trước" />
          )}
        </ChartCard>

        <ChartCard
          title="Doanh thu sản phẩm"
          note={productPeriod ? `Tổng ${money(product.total_revenue)} · ${productPeriod}` : 'Đơn đã nhận'}
          action={<Link to={ROUTES.PRODUCT_REPORT}>Mở báo cáo</Link>}
        >
          {productQuery.isLoading ? <Skeleton active /> : slices.length === 0 ? (
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có doanh thu sản phẩm" />
          ) : (
            <DonutChart slices={slices} formatValue={compactMoney} ariaLabel="Biểu đồ tròn doanh thu sản phẩm" />
          )}
        </ChartCard>
      </section>

      <ChartCard title="Doanh thu theo sản phẩm" note={productPeriod || 'Top sản phẩm đã nhận'}>
        {productQuery.isLoading ? <Skeleton active /> : revenueItems.length === 0 ? (
          <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có doanh thu sản phẩm" />
        ) : <RevenueBars items={revenueItems} />}
      </ChartCard>
    </main>
  )
}

export default ReportsOverviewPage
