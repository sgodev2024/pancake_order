import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

const removeEmptyParams = (params) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getActivityLogs = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.ACTIVITY_LOGS, {
    params: removeEmptyParams(params),
  })

  return response.data.data
}
