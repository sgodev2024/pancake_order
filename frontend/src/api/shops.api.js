import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const SHOP_OPTIONS_STALE_TIME = 5 * 60_000

export const shopQueryKeys = {
  all: ['shops'],
  options: () => ['shops', 'options'],
  posOptions: (userId) => ['shops', 'options', 'pos', userId],
}

export const getShops = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.SHOPS, { params, ...(signal ? { signal } : {}) })
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

// A POS draft must verify the current actor's scope on entry and on returning to the tab.
export const posShopOptionsQueryOptions = (userId) => ({
  queryKey: shopQueryKeys.posOptions(userId),
  queryFn: async ({ signal }) => {
    const shops = await getShops({ view: 'summary' }, { signal })
    if (!Array.isArray(shops)) throw new Error('Danh sách cửa hàng không hợp lệ.')
    return shops
  },
  staleTime: 0,
  retry: 1,
  refetchOnMount: 'always',
  refetchOnWindowFocus: true,
})
