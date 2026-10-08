import {
  BarChartOutlined,
  CalendarOutlined,
  FireOutlined,
  HistoryOutlined,
  HomeOutlined,
  InboxOutlined,
  MessageOutlined,
  SafetyOutlined,
  ScheduleOutlined,
  SettingOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { ROUTES } from './routes.js'

export const MENU_ITEMS = [
  {
    key: ROUTES.HOME,
    label: 'Tổng quan',
    path: ROUTES.HOME,
    icon: HomeOutlined,
  },
  {
    key: ROUTES.SHOPS,
    label: 'Cửa hàng',
    path: ROUTES.SHOPS,
    icon: ShopOutlined,
    permission: 'list-shop',
  },
  {
    key: ROUTES.CUSTOMERS,
    label: 'Khách hàng',
    path: ROUTES.CUSTOMERS,
    icon: TeamOutlined,
    permission: 'list-customer',
  },
  {
    key: 'opportunities',
    label: 'Cơ hội',
    icon: UserOutlined,
    children: [
      {
        key: ROUTES.OPPORTUNITIES,
        label: 'Theo đơn hàng',
        title: 'Cơ hội / Theo đơn hàng',
        path: ROUTES.OPPORTUNITIES,
        permission: 'view-chance',
      },
      {
        key: ROUTES.OPPORTUNITIES_IMPORT,
        label: 'Theo import',
        title: 'Cơ hội / Theo import',
        path: ROUTES.OPPORTUNITIES_IMPORT,
        permission: 'view-chance',
      },
    ],
  },
  {
    key: 'assignments',
    label: 'Quản lý phân công',
    icon: ScheduleOutlined,
    children: [
      {
        key: ROUTES.CUSTOMER_ASSIGNMENTS,
        label: 'Theo dõi phân bổ khách hàng',
        path: ROUTES.CUSTOMER_ASSIGNMENTS,
        permission: 'chance-customer',
      },
      {
        key: ROUTES.STAFF_ASSIGNMENTS,
        label: 'Theo dõi phân bổ nhân viên',
        path: ROUTES.STAFF_ASSIGNMENTS,
        permission: 'chance-staff',
      },
    ],
  },
  {
    key: ROUTES.ORDERS,
    label: 'Đơn hàng',
    path: ROUTES.ORDERS,
    icon: ShoppingCartOutlined,
  },
  {
    key: ROUTES.PRODUCTS,
    label: 'Sản phẩm',
    path: ROUTES.PRODUCTS,
    icon: InboxOutlined,
    permission: 'list-product',
  },
  {
    key: ROUTES.STAFF,
    label: 'Nhân viên',
    path: ROUTES.STAFF,
    icon: SafetyOutlined,
    permission: 'list-staff',
  },
  {
    key: ROUTES.LOYALTY,
    label: 'Loyalty',
    path: ROUTES.LOYALTY,
    icon: FireOutlined,
    permission: 'create-update-destroy-loyalty-tier',
  },
  {
    key: 'appointments',
    label: 'Lịch hẹn',
    icon: CalendarOutlined,
    children: [
      { key: ROUTES.CARE_TODAY, label: 'KH chăm sóc hôm nay', path: ROUTES.CARE_TODAY },
      { key: ROUTES.CARE_UPCOMING, label: 'Lịch chăm sóc sắp diễn ra', path: ROUTES.CARE_UPCOMING },
      { key: ROUTES.CARE_OVERDUE, label: 'Khách chăm sóc quá hạn', path: ROUTES.CARE_OVERDUE },
      { key: ROUTES.CARE_FIX_REQUESTS, label: 'Yêu cầu sửa CSKH', path: ROUTES.CARE_FIX_REQUESTS },
    ],
  },
  {
    key: 'reports',
    label: 'Báo cáo',
    icon: BarChartOutlined,
    reportAccess: true,
    children: [
      {
        key: ROUTES.REVENUE,
        label: 'Doanh thu/nhân viên',
        path: ROUTES.REVENUE,
        permission: 'revenue',
        reportAccess: true,
      },
      {
        key: ROUTES.ORDER_REPORT,
        label: 'Báo cáo đơn hàng',
        path: ROUTES.ORDER_REPORT,
        reportAccess: true,
      },
      {
        key: ROUTES.CUSTOMER_REPORT,
        label: 'Báo cáo khách hàng',
        path: ROUTES.CUSTOMER_REPORT,
        reportAccess: true,
      },
      {
        key: ROUTES.PURCHASE_REGIONS,
        label: 'Khu vực mua hàng',
        path: ROUTES.PURCHASE_REGIONS,
        permission: 'region-report',
        reportAccess: true,
      },
      {
        key: ROUTES.PRODUCT_REPORT,
        label: 'Báo cáo sản phẩm',
        path: ROUTES.PRODUCT_REPORT,
        reportAccess: true,
      },
      {
        key: ROUTES.CUSTOMERS_UPGRADING,
        label: 'Khách hàng sắp thăng hạng',
        title: 'Báo cáo / Khách hàng sắp thăng hạng',
        path: ROUTES.CUSTOMERS_UPGRADING,
        permission: 'customer-pendding-upgrade',
        reportAccess: true,
      },
      {
        key: ROUTES.LOYALTY_REPORT,
        label: 'Báo cáo Loyalty',
        path: ROUTES.LOYALTY_REPORT,
        permission: 'loyalty-report',
        reportAccess: true,
      },
    ],
  },
  {
    key: ROUTES.WEBHOOK_MONITOR,
    label: 'Giám sát Webhook',
    path: ROUTES.WEBHOOK_MONITOR,
    icon: HistoryOutlined,
    adminOnly: true,
  },
  {
    key: ROUTES.ACTIVITY_LOG,
    label: 'Quản lý Log',
    path: ROUTES.ACTIVITY_LOG,
    icon: HistoryOutlined,
    permission: 'view-action',
    adminOnly: true,
  },
  {
    key: 'zalo-chat', label: 'Zalo Chat', icon: MessageOutlined,
    children: [
      { key: ROUTES.ZALO_CONNECTION, label: 'Kết nối Zalo', path: ROUTES.ZALO_CONNECTION, adminOnly: true },
      { key: ROUTES.ZALO_INBOX, label: 'Hộp thoại', path: ROUTES.ZALO_INBOX },
    ],
  },
  {
    key: 'settings',
    label: 'Cài đặt / Cấu hình',
    icon: SettingOutlined,
    permission: 'config',
    children: [
      {
        key: ROUTES.GENERAL_SETTINGS,
        label: 'Cài đặt chung',
        path: ROUTES.GENERAL_SETTINGS,
        permission: 'setting-general',
      },
      {
        key: ROUTES.ROLES,
        label: 'Cài đặt phân quyền',
        path: ROUTES.ROLES,
        permission: 'role',
      },
      { key: ROUTES.WEBHOOK, label: 'Cài đặt Webhook URL', path: ROUTES.WEBHOOK },
    ],
  },
]

export const flattenMenuItems = (items = MENU_ITEMS) =>
  items.flatMap((item) => [item, ...(item.children ? flattenMenuItems(item.children) : [])])

export const PLACEHOLDER_ROUTES = [
  ...flattenMenuItems().filter((item) => item.path && item.path !== ROUTES.HOME),
  { key: ROUTES.PROFILE, label: 'Thông tin cá nhân', path: ROUTES.PROFILE },
]

export const getRouteMetadata = (pathname) => {
  return [
    { key: ROUTES.HOME, label: 'Tổng quan', title: 'Tổng quan', path: ROUTES.HOME },
    ...PLACEHOLDER_ROUTES,
  ].find((item) => item.path === pathname)
}

export const findParentMenuKey = (pathname, items = MENU_ITEMS) => {
  for (const item of items) {
    if (item.children?.some((child) => child.path === pathname)) return item.key
    if (item.children) {
      const nestedKey = findParentMenuKey(pathname, item.children)
      if (nestedKey) return item.key
    }
  }

  return null
}

