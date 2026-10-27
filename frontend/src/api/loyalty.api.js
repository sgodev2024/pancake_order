import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const getLoyaltyTiers = async () => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_TIERS)
  return response.data.data ?? []
}

export const getLoyaltyOverview = async () => {
  const response = await apiClient.get(API_ROUTES.LOYALTY_OVERVIEW)
  return response.data.data ?? []
}

export const createLoyaltyTier = async (payload) => {
  const response = await apiClient.post(API_ROUTES.LOYALTY_TIERS, payload)
  return response.data.data
}

export const updateLoyaltyTier = async ({ id, ...payload }) => {
  const response = await apiClient.put(`${API_ROUTES.LOYALTY_TIERS}/${id}`, payload)
  return response.data.data
}

export const deleteLoyaltyTier = async (id) => {
  await apiClient.delete(`${API_ROUTES.LOYALTY_TIERS}/${id}`)
}
