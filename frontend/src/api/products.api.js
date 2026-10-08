import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

const removeEmptyParams = (params = {}) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getProducts = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCTS, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    products: [],
    current_page: 1,
    per_page: 30,
    total_items: 0,
    total_pages: 0,
  }
}

export const getProductSales = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_SALES, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    from: null,
    to: null,
    latest_order_at: null,
    total_quantity: 0,
    total_orders: 0,
    items: [],
  }
}

export const getProductPeriodReport = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_PERIOD_REPORT, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    summary: { total_revenue: 0, total_quantity: 0, total_orders: 0, previous_revenue: null, change_amount: null, change_percent: null },
    chart: [],
    products: [],
    from: null,
    to: null,
    view_mode: 'month',
    compare: 'none',
  }
}

export const getProductMonthlyQuantities = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_MONTHLY_QUANTITIES, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    year: new Date().getFullYear(),
    total_quantity: 0,
    summary: { up_count: 0, up_qty_total: 0, down_count: 0, down_qty_total: 0 },
    months: [],
  }
}
