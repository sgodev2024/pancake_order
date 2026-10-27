import { Tabs } from 'antd'
import { useEffect } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { preloadRemainingReports } from '../../app/reportPreload.js'
import { ROUTES } from '../../constants/routes.js'
import './reports-layout.css'

const REPORT_TABS = [
  { key: ROUTES.REVENUE, label: 'Doanh thu/nhân viên' },
  { key: ROUTES.ORDER_REPORT, label: 'Báo cáo đơn hàng' },
  { key: ROUTES.CUSTOMER_REPORT, label: 'Báo cáo khách hàng' },
  { key: ROUTES.PURCHASE_REGIONS, label: 'Khu vực mua hàng' },
  { key: ROUTES.PRODUCT_REPORT, label: 'Báo cáo sản phẩm' },
  { key: ROUTES.CUSTOMERS_UPGRADING, label: 'Khách hàng sắp thăng hạng' },
  { key: ROUTES.LOYALTY_REPORT, label: 'Báo cáo Loyalty' },
]

const ReportsLayout = () => {
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    preloadRemainingReports().catch(() => {
      // Each tab still has its normal lazy-load fallback.
    })
  }, [])

  return (
    <section className="reports-layout">
      <div className="reports-layout__tabs-shell">
        <Tabs
          activeKey={location.pathname}
          onChange={navigate}
          items={REPORT_TABS}
          tabBarGutter={8}
        />
      </div>
      <div className="reports-layout__content" key={location.pathname}>
        <Outlet />
      </div>
    </section>
  )
}

export default ReportsLayout
