import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const withoutEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getCustomerCares = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.CUSTOMER_CARES, {
    params: withoutEmptyParams(params),
  })

  return response.data.data ?? {}
}

export const getCustomerCareHistories = async (customerCareId) => {
  const response = await apiClient.get(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}/histories`)
  return response.data.data ?? []
}

export const getCustomerCareOrders = async (customerCareId) => {
  const response = await apiClient.get(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}/orders`)
  return response.data.data ?? []
}

export const markCustomerCareAsCared = async (customerCareId, payload) => {
  const response = await apiClient.put(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}`, payload)
  return response.data
}

export const assignCustomerCare = async (customerCareId, payload) => {
  const response = await apiClient.post(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}/assign`, payload)
  return response.data
}

export const acceptCustomerCare = async (customerCareId, payload) => {
  const response = await apiClient.post(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}/accept`, payload)
  return response.data
}

export const reclaimCustomerCare = async (customerCareId, payload = {}) => {
  const response = await apiClient.post(`${API_ROUTES.CUSTOMER_CARES}/${customerCareId}/reclaim`, payload)
  return response.data
}
