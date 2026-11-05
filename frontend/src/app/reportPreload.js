import dayjs from 'dayjs'
import { getCustomers, getLoyaltyTiers, getOrderCustomers } from '../api/customers.api.js'
import { getLoyaltyOverview } from '../api/loyalty.api.js'
import { getOrderTotals } from '../api/orders.api.js'
import { getProductPeriodReport } from '../api/products.api.js'
import { getProvinceCharts } from '../api/provinces.api.js'
import { queryClient } from './queryClient.js'

export const loadReportsLayout = () => import('../pages/Reports/ReportsLayout.jsx')
export const loadRevenueReport = () => import('../pages/Revenue/RevenuePage.jsx')
export const loadOrderReport = () => import('../pages/Reports/OrderReportPage.jsx')
export const loadCustomerReport = () => import('../pages/Reports/CustomerReportPage.jsx')
export const loadPurchaseRegionsReport = () => import('../pages/PurchaseRegions/PurchaseRegionsPage.jsx')
export const loadProductReport = () => import('../pages/ProductReport/ProductReportPage.jsx')
export const loadCustomersUpgradingReport = () => import('../pages/CustomersUpgrading/CustomersUpgradingPage.jsx')
export const loadLoyaltyReport = () => import('../pages/LoyaltyReport/LoyaltyReportPage.jsx')

let defaultReportPromise
let remainingReportsPromise
const REPORT_STALE_TIME = 5 * 60_000

const orderReportParams = {
  shop_id: undefined,
  order_page_id: undefined,
  search: '',
  date_from: undefined,
  date_to: undefined,
  status: undefined,
}

const customerReportParams = {
  page: 1,
  page_size: 30,
  shop_id: undefined,
  search: '',
  inactivity_group: 'all',
  sort_by: 'inactive_days',
  sort_direction: 'desc',
}

const provinceChartParams = { week_mode: 'latest' }

const productReportParams = {
  shop_id: undefined,
  view_mode: 'month',
  date_from: dayjs().startOf('year').format('YYYY-MM-DD'),
  date_to: dayjs().endOf('month').format('YYYY-MM-DD'),
  compare: 'mom',
}

const preloadQuery = (options) =>
  queryClient.prefetchQuery({ staleTime: REPORT_STALE_TIME, ...options }).catch(() => undefined)

const remainingReports = [
  {
    loadPage: loadOrderReport,
    preloadData: () => preloadQuery({
      queryKey: ['order-report', orderReportParams],
      queryFn: ({ signal }) => getOrderTotals(orderReportParams, { signal }),
    }),
  },
  {
    loadPage: loadCustomerReport,
    preloadData: () => preloadQuery({
      queryKey: ['order-customers', customerReportParams],
      queryFn: ({ signal }) => getOrderCustomers(customerReportParams, { signal }),
    }),
  },
  {
    loadPage: loadPurchaseRegionsReport,
    preloadData: () => preloadQuery({
      queryKey: ['provinces-charts', provinceChartParams],
      queryFn: () => getProvinceCharts(provinceChartParams),
    }),
  },
  {
    loadPage: loadProductReport,
    preloadData: () => preloadQuery({
      queryKey: ['product-period-report', productReportParams],
      queryFn: () => getProductPeriodReport(productReportParams),
    }),
  },
  {
    loadPage: loadCustomersUpgradingReport,
    preloadData: () => Promise.all([
      preloadQuery({
        queryKey: ['customers-upgrading', {}],
        queryFn: () => getCustomers({ page: 1, page_size: 100 }),
      }),
      preloadQuery({ queryKey: ['loyalty-tiers'], queryFn: getLoyaltyTiers }),
    ]),
  },
  {
    loadPage: loadLoyaltyReport,
    preloadData: () => preloadQuery({
      queryKey: ['loyalty-overview'],
      queryFn: getLoyaltyOverview,
    }),
  },
]

export const preloadDefaultReport = () => {
  if (!defaultReportPromise) {
    defaultReportPromise = loadReportsLayout().then(loadRevenueReport)
  }
  return defaultReportPromise
}

export const preloadRemainingReports = () => {
  if (!remainingReportsPromise) {
    remainingReportsPromise = Promise.allSettled(
      remainingReports.map(async (report) => {
        try {
          await report.loadPage()
          await report.preloadData()
        } catch {
          // Normal lazy-loading remains as fallback if preloading fails
        }
      }),
    )
  }
  return remainingReportsPromise
}
