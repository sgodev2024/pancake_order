import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const getWarehouseOrders = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.WAREHOUSE_ORDERS, {
    params: Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '')),
  })
  return response.data.data
}
export const getWarehouseOrder = async (id) => (await apiClient.get(`${API_ROUTES.WAREHOUSE_ORDERS}/${id}`)).data.data
export const completePacking = async (id) => (await apiClient.post(`${API_ROUTES.WAREHOUSE_ORDERS}/${id}/complete`)).data
