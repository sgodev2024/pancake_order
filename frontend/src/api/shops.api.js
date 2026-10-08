import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const SHOP_OPTIONS_STALE_TIME = 5 * 60_000

export const shopQueryKeys = {
  all: ['shops'],
  options: () => ['shops', 'options'],
}

export const getShops = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.SHOPS, { params })
  return response.data.data ?? []
}

export const getShopOptions = () => getShops({ view: 'summary' })

export const createShop = async (payload) => {
  const response = await apiClient.post(API_ROUTES.SHOPS, payload)
  return response.data
}

export const updateShop = async ({ id, ...payload }) => {
  const response = await apiClient.put(`${API_ROUTES.SHOPS}/${id}`, payload)
  return response.data
}

export const deleteShop = async (id) => {
  const response = await apiClient.delete(`${API_ROUTES.SHOPS}/${id}`)
  return response.data
}

export const syncShopEmployees = async (id) => {
  const response = await apiClient.post(`${API_ROUTES.SHOPS}/${id}/update-employee-from-pancake`)
  return response.data
}

export const syncShopData = async ({ shopId, type }) => {
  const response = await apiClient.post(`${API_ROUTES.SHOPS}/${shopId}/get-data-pancake`, { type })
  return response.data
}

export const shopOptionsQueryOptions = () => ({
  queryKey: shopQueryKeys.options(),
  queryFn: getShopOptions,
  staleTime: SHOP_OPTIONS_STALE_TIME,
  retry: 1,
  refetchOnWindowFocus: false,
  refetchOnMount: true,
})
