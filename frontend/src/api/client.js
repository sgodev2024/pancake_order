import axios from 'axios'
import { developmentApi } from './developmentApi.js'
import { API_ROUTES, ROUTES } from '../constants/routes.js'
import { ApiBusinessError, assertSuccessfulResponse, getResponseMessage } from '../utils/response.js'

const authHandlers = {
  getToken: () => null,
  onUnauthorized: () => {},
}

export const configureApiAuth = ({ getToken, onUnauthorized }) => {
  authHandlers.getToken = getToken
  authHandlers.onUnauthorized = onUnauthorized
}

const isJsonPayload = (data) => {
  if (!data || typeof data !== 'object') return false
  if (typeof FormData !== 'undefined' && data instanceof FormData) return false
  if (typeof URLSearchParams !== 'undefined' && data instanceof URLSearchParams) return false
  return true
}

const isLoginRequest = (config) => config?.url?.endsWith(API_ROUTES.LOGIN)
const usesCurrentSession = (config) => {
  const authorization = config?.headers?.get?.('Authorization') ?? config?.headers?.Authorization ?? config?.headers?.authorization
  const token = authHandlers.getToken()
  return Boolean(token && typeof authorization === 'string' && authorization.replace(/^Bearer\s+/i, '') === token)
}

const apiClient = axios.create({
  baseURL: (import.meta.env?.DEV && developmentApi(import.meta.env?.VITE_API_URL)?.baseURL) || import.meta.env?.VITE_API_URL,
  headers: {
    Accept: 'application/json',
  },
})

apiClient.interceptors.request.use((config) => {
  const token = authHandlers.getToken()

  if (token && !config.headers.has('Authorization')) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }

  if (isJsonPayload(config.data)) {
    config.headers.set('Content-Type', 'application/json')
  }

  return config
})

apiClient.interceptors.response.use(
  (response) => {
    assertSuccessfulResponse(response.data, response.status)
    return response
  },
  (error) => {
    if (error.response?.status === 401 && !isLoginRequest(error.config) && usesCurrentSession(error.config)) {
      authHandlers.onUnauthorized()

      if (typeof window !== 'undefined' && window.location.pathname !== ROUTES.LOGIN) {
        window.location.replace(ROUTES.LOGIN)
      }
    }

    const data = error.response?.data
    if (data?.success === false) {
      return Promise.reject(
        new ApiBusinessError(getResponseMessage(data), {
          status: error.response.status,
          errors: data.errors,
          data,
        }),
      )
    }

    return Promise.reject(error)
  },
)

export default apiClient
