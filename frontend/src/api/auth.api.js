import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const login = async (credentials) => {
  const response = await apiClient.post(API_ROUTES.LOGIN, credentials)
  return response.data
}

export const getMe = async () => {
  const response = await apiClient.get(API_ROUTES.ME)
  return response.data.data
}

export const logout = async () => {
  const response = await apiClient.post(API_ROUTES.LOGOUT)
  return response.data
}

export const changeFirstPassword = async (payload) => {
  const response = await apiClient.post(API_ROUTES.CHANGE_FIRST_PASSWORD, payload)
  return response.data
}
