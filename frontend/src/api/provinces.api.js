import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

const removeEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getProvinces = async () => {
  const response = await apiClient.get(API_ROUTES.PROVINCES)
  return response.data.data ?? []
}

export const getProvinceReport = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PROVINCES_REPORT, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? { provinces: [], shop: null }
}

export const getProvinceCharts = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PROVINCES_CHARTS, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    from: null,
    to: null,
    latest_order_at: null,
    total_revenue: 0,
    total_orders: 0,
    province_count: 0,
    items: [],
    trend: { labels: [], current: [] },
  }
}
