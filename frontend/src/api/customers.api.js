import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const removeEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getCustomers = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.CUSTOMERS, {
    params: removeEmptyParams(params),
  })

  return response.data.data
}

export const getOrderCustomers = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.CUSTOMER_ORDER_INSIGHTS, {
    params: removeEmptyParams(params),
    signal,
  })

  return response.data.data
}

export const getCustomerOrders = async (customerId, params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.CUSTOMER_ORDERS(customerId), {
    params: removeEmptyParams(params),
    signal,
  })

  return response.data.data
}

export const getCustomerJourney = async (customerId, params = {}) => {
  const response = await apiClient.get(API_ROUTES.CUSTOMER_JOURNEY(customerId), {
    params: removeEmptyParams(params),
  })

  return response.data.data
}

export const getLoyaltyTiers = async () => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_TIERS)
  return response.data.data
}
