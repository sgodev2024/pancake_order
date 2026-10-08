import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

export const getSetting = async (code) => {
  const response = await apiClient.get(`${API_ROUTES.SETTINGS}/${code}`)
  return response.data.data ?? { setting: null, image: null }
}

export const saveSetting = async ({ code, data, image, removeImage = false }) => {
  const payload = new FormData()
  payload.append('code', code)
  payload.append('data', JSON.stringify(data))
  if (image) payload.append('image', image)
  if (removeImage) payload.append('remove_image', '1')

  const response = await apiClient.post(API_ROUTES.SETTINGS, payload)
  return response.data
}
