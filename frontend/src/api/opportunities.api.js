import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const withoutEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getOrderOpportunities = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.ORDER_OPPORTUNITIES, {
    params: withoutEmptyParams(params),
  })

  return response.data.data ?? {}
}

export const getImportedOpportunities = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.IMPORTED_OPPORTUNITIES, {
    params: withoutEmptyParams(params),
  })

  return response.data.data ?? {}
}

export const importOpportunities = async ({ shopId, file }) => {
  const formData = new FormData()
  formData.append('shop_id', String(shopId))
  formData.append('file', file)

  const response = await apiClient.post(API_ROUTES.IMPORTED_OPPORTUNITIES_IMPORT, formData)
  return response.data
}

export const assignImportedOpportunities = async ({ ids, pancakeUserId }) => {
  const response = await apiClient.post(API_ROUTES.IMPORTED_OPPORTUNITIES_ASSIGN, {
    ids,
    pancake_user_ids: [pancakeUserId],
  })
  return response.data
}

export const downloadImportedOpportunityTemplate = async () => {
  const response = await apiClient.get(API_ROUTES.IMPORTED_OPPORTUNITIES_TEMPLATE, {
    responseType: 'blob',
  })
  return response.data
}
