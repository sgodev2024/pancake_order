import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const removeEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

/**
 * Fetch orders from Huong Chat Pancake integration API
 * @param {Object} params - Query filters (page, per_page, search, status, payment_status, start_date, end_date)
 * @param {Object} options - Request options (signal)
 */
export const getPancakeOrders = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.PANCAKE_ORDERS, {
    params: removeEmptyParams(params),
    ...(signal ? { signal } : {}),
  })

  return response.data
}

/**
 * Fetch detail of a single Pancake order
 * @param {string|number} id - Order ID
 * @param {Object} options - Request options (signal)
 */
export const getPancakeOrderDetail = async (id, { signal } = {}) => {
  const response = await apiClient.get(`${API_ROUTES.PANCAKE_ORDERS}/${id}`, {
    ...(signal ? { signal } : {}),
  })

  return response.data
}
