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

const reportPages = [loadOrderReport, loadCustomerReport, loadPurchaseRegionsReport,
  loadProductReport, loadCustomersUpgradingReport, loadLoyaltyReport]

// Warm route code only. Each mounted report owns its data requests.
export const preloadDefaultReport = () => {
  defaultReportPromise ??= loadReportsLayout().then(loadRevenueReport)
  return defaultReportPromise
}

export const preloadRemainingReports = () => {
  remainingReportsPromise ??= Promise.allSettled(reportPages.map((loadPage) => loadPage()))
  return remainingReportsPromise
}
