import { API_ROUTES } from '../constants/routes.js'
import apiClient from './client.js'

export const getRoleOptions = async () => {
  const response = await apiClient.get(API_ROUTES.ROLE_OPTIONS)
  return response.data.data ?? []
}

export const getRoles = async () => {
  const response = await apiClient.get(API_ROUTES.ROLE_MANAGEMENT)
  return response.data.data ?? { roles: [], permissionGroups: [], permissionRoles: [] }
}

export const getPermissionGroups = async () => {
  const response = await apiClient.get(API_ROUTES.PERMISSION_GROUPS)
  return response.data.data ?? []
}

export const createPermissionGroup = async ({ name }) => {
  const response = await apiClient.post(API_ROUTES.PERMISSION_GROUPS, { name })
  return response.data
}

export const deletePermissionGroup = async (groupId) => {
  const response = await apiClient.delete(`${API_ROUTES.PERMISSION_GROUPS}/${groupId}`)
  return response.data
}

export const createPermission = async (values) => {
  const response = await apiClient.post(API_ROUTES.PERMISSIONS, values)
  return response.data
}

export const updatePermission = async ({ id, ...values }) => {
  const response = await apiClient.put(`${API_ROUTES.PERMISSIONS}/${id}`, values)
  return response.data
}

export const deletePermission = async (permissionId) => {
  const response = await apiClient.delete(`${API_ROUTES.PERMISSIONS}/${permissionId}`)
  return response.data
}

export const getRolePermissions = async (roleId) => {
  const response = await apiClient.get(`${API_ROUTES.ROLE_PERMISSIONS}/${roleId}`)
  return response.data.data ?? []
}

export const saveRolePermissions = async ({ role_id, permission_ids }) => {
  const response = await apiClient.post(API_ROUTES.ROLE_PERMISSIONS, { role_id, permission_ids })
  return response.data
}

export const createRole = async (values) => {
  const response = await apiClient.post(API_ROUTES.ROLE_MANAGEMENT, values)
  return response.data
}

export const updateRole = async ({ id, ...values }) => {
  const response = await apiClient.put(`${API_ROUTES.ROLE_MANAGEMENT}/${id}`, values)
  return response.data
}

export const deleteRole = async (roleId) => {
  const response = await apiClient.delete(`${API_ROUTES.ROLE_MANAGEMENT}/${roleId}`)
  return response.data
}
