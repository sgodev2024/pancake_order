import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const removeEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getOrders = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.ORDERS, {
    params: removeEmptyParams({
      ...params,
      include_totals: params.include_totals === undefined ? undefined : Number(Boolean(params.include_totals)),
      view: 'summary',
    }),
    ...(signal ? { signal } : {}),
  })

  return response.data.data
}

export const getOrderTotals = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.ORDERS, {
    params: removeEmptyParams({
      ...params,
      page: 1,
      page_size: 1,
      totals_only: 1,
      view: 'summary',
    }),
    ...(signal ? { signal } : {}),
  })

  return response.data.data
}

export const getOrderSalesChartData = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.ORDER_SALES_CHART, {
    params: removeEmptyParams(params),
    ...(signal ? { signal } : {}),
  })

  return response.data.data
}

export const getOrderPages = async ({ shop_id: shopId, context, type } = {}, { signal } = {}) => {
  if (shopId === undefined || shopId === null || shopId === '') return []

  const response = await apiClient.get(API_ROUTES.ORDER_PAGES, {
    params: removeEmptyParams({ shop_id: shopId, context, type }),
    ...(signal ? { signal } : {}),
  })

  return Array.isArray(response.data.data)
    ? response.data.data.map((page) => ({ ...page, id: String(page.id) }))
    : []
}

export const getOrderSources = async (params = {}) => {
  const shopId = params.shop_id
  if (shopId === undefined || shopId === null || shopId === '') return []

  const response = await apiClient.get(API_ROUTES.ORDER_SOURCES, {
    params: removeEmptyParams({ shop_id: shopId }),
  })

  return Array.isArray(response.data.data) ? response.data.data : []
}

export const getOrderHistory = async (orderId, params = {}) => {
  const response = await apiClient.get(`${API_ROUTES.ORDERS}/${orderId}/history`, {
    params: removeEmptyParams({
      page: 1,
      per_page: 30,
      ...params,
    }),
  })

  return response.data
}
