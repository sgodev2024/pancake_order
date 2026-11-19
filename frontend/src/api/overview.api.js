import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const getOverview = async ({ signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.OVERVIEW, { ...(signal ? { signal } : {}) })
  return response.data.data ?? {}
}

export const getLoyaltyOverview = async ({ signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_OVERVIEW, { ...(signal ? { signal } : {}) })
  return response.data.data ?? []
}

export const getLoyaltyTotalDiscount = async ({ signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_TOTAL_DISCOUNT, { ...(signal ? { signal } : {}) })
  return response.data.data ?? {}
}

// Let the database calculate these aggregates instead of downloading customers
// and counting them again in the browser.
export const getLoyaltyUpgradeSummary = async ({ signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_UPGRADE_SUMMARY, { ...(signal ? { signal } : {}) })
  return response.data.data ?? { total_customer_pendding_upgrade: 0, loyalty_tier_detail: [] }
}
