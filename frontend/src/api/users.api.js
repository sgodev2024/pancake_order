import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

export const getAllUsers = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.USERS_ALL, { params })
  return response.data.data ?? []
}

export const getStaffAssignmentMonitoring = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.USER_MONITORING, { params })
  return response.data.data ?? { items: [], total_appointments: 0 }
}

export const getWeeklyRevenue = async (params = {}) => {
  const response = await apiClient.get(`${API_ROUTES.USERS}/weekly-revenue`, { params })
  return response.data.data ?? { from: null, to: null, latest_order_at: null, items: [] }
}

export const getStaff = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.USERS, { params })
  return response.data.data ?? {
    items: [],
    current_page: 1,
    per_page: 30,
    total_items: 0,
    total_pages: 0,
  }
}

export const getStaffById = async (id) => {
  const response = await apiClient.get(`${API_ROUTES.USERS}/${id}`)
  return response.data.data
}

export const getRoleOptions = async () => {
  const response = await apiClient.get(API_ROUTES.ROLES)
  return response.data.data ?? []
}

export const createStaff = async (payload) => {
  const response = await apiClient.post(API_ROUTES.USERS, payload)
  return response.data
}

export const updateStaff = async ({ id, ...payload }) => {
  const response = await apiClient.put(`${API_ROUTES.USERS}/${id}`, payload)
  return response.data
}

export const deleteStaff = async (id) => {
  const response = await apiClient.delete(`${API_ROUTES.USERS}/${id}`)
  return response.data
}
