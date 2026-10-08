import {
  CalendarOutlined,
  CustomerServiceOutlined,
  GiftOutlined,
  ShoppingOutlined,
  TeamOutlined,
  ToolOutlined,
  WarningOutlined,
} from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Alert, Card, Empty, Skeleton, Statistic, Typography } from 'antd'
import { Link } from 'react-router-dom'
import { isAdmin, isDirector } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { getLoyaltyOverview, getLoyaltyTotalDiscount, getLoyaltyUpgradeSummary, getOverview } from '../../api/overview.api.js'
import { ROUTES } from '../../constants/routes.js'
import ReportsOverviewPage from '../Reports/ReportsOverviewPage.jsx'
import './home.css'

const numberFormatter = new Intl.NumberFormat('vi-VN')
const moneyFormatter = new Intl.NumberFormat('vi-VN', {
  style: 'currency',
  currency: 'VND',
  maximumFractionDigits: 0,
})

const number = (value) => numberFormatter.format(Number(value) || 0)
const money = (value) => moneyFormatter.format(Number(value) || 0)

const MetricCard = ({ title, value, suffix, icon: Icon, tone, to }) => (
  <Link className={`home-metric home-metric--${tone}`} to={to}>
    <div className="home-metric__header">
      <span>{title}</span>
      <Icon />
    </div>
    <Statistic value={number(value)} suffix={suffix} />
  </Link>
)

const SummaryCard = ({ title, value, description, icon: Icon, tone, to }) => (
  <Link className={`home-summary home-summary--${tone}`} to={to}>
    <div className="home-summary__header">
      <span>{title}</span>
      <Icon />
    </div>
    <strong>{value}</strong>
    {description && <small>{description}</small>}
  </Link>
)

const CareHome = () => {
  const user = useAuthStore((state) => state.user)
  const queryOptions = { staleTime: 60_000, refetchOnWindowFocus: false }
  const overviewQuery = useQuery({ queryKey: ['overview'], queryFn: getOverview, ...queryOptions })
  const loyaltyQuery = useQuery({ queryKey: ['loyalty-overview'], queryFn: getLoyaltyOverview, ...queryOptions })
  const discountQuery = useQuery({ queryKey: ['loyalty-total-discount'], queryFn: getLoyaltyTotalDiscount, ...queryOptions })
  const upgradeSummaryQuery = useQuery({
    queryKey: ['loyalty-upgrade-summary'],
    queryFn: getLoyaltyUpgradeSummary,
    ...queryOptions,
  })

  const overview = overviewQuery.data ?? {}
  const tiers = loyaltyQuery.data ?? []
  const totalCustomersInTiers = tiers.reduce((total, tier) => total + Number(tier.customers_count || 0), 0)
  const opportunityCounts = (upgradeSummaryQuery.data?.loyalty_tier_detail ?? []).reduce((counts, tier) => {
    counts[tier.id] = Number(tier.about_to_upgrade_count) || 0
    return counts
  }, {})
  const hasError = overviewQuery.isError || loyaltyQuery.isError || discountQuery.isError || upgradeSummaryQuery.isError
  const roleName = user?.role?.name || user?.role?.slug || 'Chưa có thông tin'

  return (
    <div className="home-page">
      <div className="home-page__heading">
        <div>
          <Typography.Title level={2}>Tổng quan</Typography.Title>
          <Typography.Text type="secondary">
            Xin chào {user?.name || user?.email || 'bạn'} · {roleName}
          </Typography.Text>
        </div>
      </div>

      {hasError && (
        <Alert
          type="error"
          showIcon
          message="Một số dữ liệu tổng quan chưa thể tải"
          description="Vui lòng thử lại sau. Các số liệu được lấy trực tiếp từ dữ liệu hiện có trên máy chủ."
        />
      )}

      <div className="dashboard-metrics">
        <div>
          <div className="home-metrics customer-metrics">
            <MetricCard
              title="Tổng khách hàng chăm sóc"
              value={overview.total_customer_care}
              icon={TeamOutlined}
              tone="blue"
              to={ROUTES.CUSTOMERS}
            />
          </div>
        </div>

        <div className="metrics-section">
          <div className="metrics-section-title">
            Khách hàng
          </div>
          <div className="home-metrics customer-metrics">
            <MetricCard
              title="KH chăm sóc hôm nay"
              value={overview.customer_care_today_done}
              suffix={`/${number(overview.customer_care_today)}`}
              icon={CustomerServiceOutlined}
              tone="blue"
              to={ROUTES.CARE_TODAY}
            />

            <MetricCard
              title="Lịch chăm sóc sắp diễn ra"
              value={overview.customer_care_pending_done}
              suffix={`/${number(overview.customer_care_pending)}`}
              icon={CalendarOutlined}
              tone="green"
              to={ROUTES.CARE_UPCOMING}
            />

            <MetricCard
              title="Khách chăm sóc quá hạn"
              value={overview.customer_care_expire_done}
              suffix={`/${number(overview.customer_care_expire)}`}
              icon={WarningOutlined}
              tone="red"
              to={ROUTES.CARE_OVERDUE}
            />

            <MetricCard
              title="Yêu cầu sửa CSKH"
              value={overview.customer_care_edit_accepted}
              suffix={`/${number(overview.customer_care_edit)}`}
              icon={CustomerServiceOutlined}
              tone="purple"
              to={ROUTES.CARE_FIX_REQUESTS}
            />
          </div>
        </div>

        <div className="metrics-section order-section">
          <div className="metrics-section-title">
            Đơn hàng
          </div>

          <div className="home-metrics order-metrics">
            <MetricCard
              title=" Đơn hàng ( hôm nay) "
              value={overview.total_order_today}
              suffix=" đơn"
              icon={ShoppingOutlined}
              tone="orange"
              to={ROUTES.ORDERS}
            />
          </div>
        </div>
      </div>
      <div className="home-summary-grid">
        <SummaryCard
          title="Tổng giảm giá Loyalty"
          value={discountQuery.isLoading ? '...' : money(discountQuery.data?.total_discount)}
          description={`Chiếm ${number(discountQuery.data?.discount_rate_percent)}% tổng doanh thu`}
          icon={GiftOutlined}
          tone="pink"
          to={ROUTES.LOYALTY}
        />
        <SummaryCard
          title="Khách hàng sắp thăng hạng"
          value={loyaltyQuery.isLoading ? '...' : `${number(totalCustomersInTiers)} khách hàng`}
          icon={TeamOutlined}
          tone="gold"
          to={ROUTES.CUSTOMERS_UPGRADING}
        />
      </div>

      <div className="home-loyalty-grid">
        <Card className="home-tier-card" title="Phân Lớp Hạng Thành Viên (Đóng góp doanh số)">
          {loyaltyQuery.isLoading ? (
            <Skeleton active paragraph={{ rows: 4 }} />
          ) : tiers.length === 0 ? (
            <Empty description="Chưa có dữ liệu hạng khách hàng" />
          ) : (
            <div className="home-tier-list">
              {tiers.map((tier) => {
                const customers = Number(tier.customers_count || 0)
                const percentage = totalCustomersInTiers ? (customers / totalCustomersInTiers) * 100 : 0
                const range = tier.max_order_value
                  ? `${money(tier.min_order_value)} - ${money(tier.max_order_value)}`
                  : `Từ ${money(tier.min_order_value)}`
                return (
                  <Link
                    className="home-tier-row"
                    to={`${ROUTES.CUSTOMERS}?loyalty_tier_id=${encodeURIComponent(tier.id)}`}
                    key={tier.id}
                  >
                    <div className="home-tier-row__label">
                      <span>{tier.name} ({range} | Giảm {number(tier.discount_percent)}%)</span>
                      <strong>{money(tier.total_revenue)} ({number(customers)} Khách)</strong>
                    </div>
                    <div className="home-tier-row__track">
                      <span style={{ width: `${percentage}%` }} />
                    </div>
                  </Link>
                )
              })}
            </div>
          )}
        </Card>

        <Card className="home-opportunity-card">
          <Link className="home-opportunity-card__header" to={ROUTES.CUSTOMERS_UPGRADING}>
            <ToolOutlined />
            <strong>CƠ HỘI BỨT PHÁ DOANH THU</strong>
          </Link>
          <p>Khai thác nhóm khách tiềm năng bằng các kịch bản kích cầu tự động qua Zalo.</p>
          <div className="home-opportunity-list">
            {tiers.map((tier) => (
              <Link
                to={`${ROUTES.CUSTOMERS_UPGRADING}?next_tier_id=${encodeURIComponent(tier.id)}`}
                className="home-opportunity-row"
                key={tier.id}
              >
                <span>Lên {tier.name}</span>
                <strong>{upgradeSummaryQuery.isLoading ? '...' : number(opportunityCounts[tier.id] || 0)} khách</strong>
              </Link>
            ))}
          </div>
        </Card>
      </div>
    </div>
  )
}

const HomePage = () => {
  const user = useAuthStore((state) => state.user)

  if (!isAdmin(user) && !isDirector(user)) return <CareHome />

  return (
    <div className="home-page">
      <div className="home-page__heading">
        <div>
          <Typography.Title level={2}>Tổng quan</Typography.Title>
          <Typography.Text type="secondary">Đơn hàng, chăm sóc khách hàng và doanh thu sản phẩm</Typography.Text>
        </div>
      </div>
      <ReportsOverviewPage />
    </div>
  )
}

export default HomePage
