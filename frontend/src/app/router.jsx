import { lazy } from 'react'
import { createBrowserRouter, Navigate } from 'react-router-dom'
import { AdminOnlyRoute, GuestOnlyRoute, PasswordChangeRoute, ProtectedRoute, ReportsRoute } from '../auth/auth.guard.jsx'
import LazyRoute from './LazyRoute.jsx'
import { PLACEHOLDER_ROUTES } from '../constants/menu.js'
import { ADMIN_ONLY_ROUTES, ROUTES } from '../constants/routes.js'
import AppLayout from '../layouts/AppLayout.jsx'
import AuthLayout from '../layouts/AuthLayout.jsx'
import {
  loadCustomerReport,
  loadCustomersUpgradingReport,
  loadLoyaltyReport,
  loadOrderReport,
  loadProductReport,
  loadPurchaseRegionsReport,
  loadReportsLayout,
  loadRevenueReport,
} from './reportPreload.js'

export const router = createBrowserRouter([
  {
    element: <AuthLayout />,
    children: [
      {
        element: <GuestOnlyRoute />,
        children: [
          {
            path: ROUTES.LOGIN,
            element: <LazyRoute Page={lazy(() => import('../pages/Login/LoginPage.jsx'))} />,
          },
        ],
      },
      {
        element: <PasswordChangeRoute />,
        children: [
          {
            path: ROUTES.CHANGE_FIRST_PASSWORD,
            element: (
              <LazyRoute Page={lazy(() => import('../pages/ChangeFirstPassword/ChangeFirstPasswordPage.jsx'))} />
            ),
          },
        ],
      },
    ],
  },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          {
            path: ROUTES.HOME,
            element: <LazyRoute Page={lazy(() => import('../pages/Home/HomePage.jsx'))} />,
          },
          {
            path: ROUTES.GENERAL_SETTINGS,
            element: <LazyRoute Page={lazy(() => import('../pages/GeneralSettings/GeneralSettingsPage.jsx'))} />,
          },
          {
            element: <AdminOnlyRoute />,
            children: [
              {
                path: ROUTES.ROLES,
                element: <LazyRoute Page={lazy(() => import('../pages/RoleSettings/RoleSettingsPage.jsx'))} />,
              },
            ],
          },
          {
            element: <AdminOnlyRoute />,
            children: [
              {
                path: ROUTES.WEBHOOK_MONITOR,
                element: <LazyRoute Page={lazy(() => import('../pages/WebhookMonitor/WebhookMonitorPage.jsx'))} />,
              },
              {
                path: ROUTES.ACTIVITY_LOG,
                element: <LazyRoute Page={lazy(() => import('../pages/ActivityLogs/ActivityLogsPage.jsx'))} />,
              },
            ],
          },
          {
            element: <ReportsRoute />,
            children: [
              {
                element: <LazyRoute Page={lazy(loadReportsLayout)} />,
                children: [
                  {
                    path: ROUTES.REVENUE,
                    element: <LazyRoute Page={lazy(loadRevenueReport)} />,
                  },
                  {
                    path: ROUTES.PURCHASE_REGIONS,
                    element: <LazyRoute Page={lazy(loadPurchaseRegionsReport)} />,
                  },
                  {
                    path: ROUTES.PRODUCT_REPORT,
                    element: <LazyRoute Page={lazy(loadProductReport)} />,
                  },
                  {
                    path: ROUTES.CUSTOMERS_UPGRADING,
                    element: <LazyRoute Page={lazy(loadCustomersUpgradingReport)} />,
                  },
                  {
                    path: ROUTES.LOYALTY_REPORT,
                    element: <LazyRoute Page={lazy(loadLoyaltyReport)} />,
                  },
                  {
                    path: ROUTES.ORDER_REPORT,
                    element: <LazyRoute Page={lazy(loadOrderReport)} />,
                  },
                  {
                    path: ROUTES.CUSTOMER_REPORT,
                    element: <LazyRoute Page={lazy(loadCustomerReport)} />,
                  },
                ],
              },
            ],
          },
          {
            path: ROUTES.ORDERS,
            element: <LazyRoute Page={lazy(() => import('../pages/Orders/OrdersPage.jsx'))} />,
          },
          {
            path: ROUTES.OPPORTUNITIES,
            element: (
              <LazyRoute Page={lazy(() => import('../pages/Opportunities/OrderOpportunitiesPage.jsx'))} />
            ),
          },
          {
            path: ROUTES.OPPORTUNITIES_IMPORT,
            element: (
              <LazyRoute Page={lazy(() => import('../pages/Opportunities/ImportedOpportunitiesPage.jsx'))} />
            ),
          },
          {
            path: ROUTES.CUSTOMER_ASSIGNMENTS,
            element: (
              <LazyRoute Page={lazy(() => import('../pages/CustomerAssignmentTracking/CustomerAssignmentTrackingPage.jsx'))} />
            ),
          },
          {
            path: ROUTES.STAFF_ASSIGNMENTS,
            element: <LazyRoute Page={lazy(() => import('../pages/StaffAssignmentTracking/StaffAssignmentTrackingPage.jsx'))} />,
          },
          {
            path: ROUTES.CARE_TODAY,
            element: (
              <LazyRoute
                Page={lazy(() => import('../pages/CustomerCare/CustomerCarePage.jsx'))}
                pageProps={{ pageType: 'today' }}
              />
            ),
          },
          {
            path: ROUTES.CARE_UPCOMING,
            element: (
              <LazyRoute
                Page={lazy(() => import('../pages/CustomerCare/CustomerCarePage.jsx'))}
                pageProps={{ pageType: 'upcoming' }}
              />
            ),
          },
          {
            path: ROUTES.CARE_OVERDUE,
            element: (
              <LazyRoute
                Page={lazy(() => import('../pages/CustomerCare/CustomerCarePage.jsx'))}
                pageProps={{ pageType: 'overdue' }}
              />
            ),
          },
          {
            path: ROUTES.CARE_FIX_REQUESTS,
            element: (
              <LazyRoute
                Page={lazy(() => import('../pages/CustomerCare/CustomerCarePage.jsx'))}
                pageProps={{ pageType: 'editRequests' }}
              />
            ),
          },
          { path: ROUTES.ZALO_INBOX, element: <LazyRoute Page={lazy(() => import('../pages/ZaloChat/ZaloInboxPage.jsx'))} /> },
          { element: <AdminOnlyRoute />, children: [{ path: ROUTES.ZALO_CONNECTION, element: <LazyRoute Page={lazy(() => import('../pages/ZaloChat/ZaloConnectionPage.jsx'))} /> }] },
          ...PLACEHOLDER_ROUTES.filter(
            (route) =>
              !ADMIN_ONLY_ROUTES.includes(route.path) &&
              route.path !== ROUTES.ZALO_INBOX &&
              route.path !== ROUTES.ORDERS &&
              route.path !== ROUTES.OPPORTUNITIES &&
              route.path !== ROUTES.STAFF_ASSIGNMENTS &&
              route.path !== ROUTES.CUSTOMERS &&
              route.path !== ROUTES.SHOPS &&
              route.path !== ROUTES.PRODUCTS &&
              route.path !== ROUTES.STAFF &&
              route.path !== ROUTES.LOYALTY &&
              route.path !== ROUTES.CARE_TODAY &&
              route.path !== ROUTES.CARE_UPCOMING &&
              route.path !== ROUTES.CARE_OVERDUE &&
              route.path !== ROUTES.CARE_FIX_REQUESTS &&
              route.path !== ROUTES.ROLES &&
              route.path !== ROUTES.CUSTOMERS_UPGRADING,
          ).map((route) => ({
            path: route.path,
            element: <LazyRoute Page={lazy(() => import('../pages/Placeholder/PlaceholderPage.jsx'))} />,
          })),
          {
            path: ROUTES.CUSTOMERS,
            element: <LazyRoute Page={lazy(() => import('../pages/Customers/CustomersPage.jsx'))} />,
          },
          {
            path: ROUTES.SHOPS,
            element: <LazyRoute Page={lazy(() => import('../pages/Shops/ShopsPage.jsx'))} />,
          },
          {
            path: ROUTES.PRODUCTS,
            element: <LazyRoute Page={lazy(() => import('../pages/Products/ProductsPage.jsx'))} />,
          },
          {
            path: ROUTES.LOYALTY,
            element: <LazyRoute Page={lazy(() => import('../pages/Loyalty/LoyaltyPage.jsx'))} />,
          },
          {
            path: ROUTES.STAFF,
            element: <LazyRoute Page={lazy(() => import('../pages/Staff/StaffPage.jsx'))} />,
          },
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to={ROUTES.HOME} replace /> },
])

