export class ApiBusinessError extends Error {
  constructor(message, options = {}) {
    super(message)
    this.name = 'ApiBusinessError'
    this.status = options.status
    this.errors = options.errors
    this.data = options.data
  }
}

const firstValidationMessage = (errors) => {
  if (!errors || typeof errors !== 'object') return null

  for (const value of Object.values(errors)) {
    if (Array.isArray(value) && value[0]) return String(value[0])
    if (typeof value === 'string' && value) return value
  }

  return null
}

export const getResponseMessage = (data, fallback = 'Yêu cầu không thể hoàn tất.') =>
  data?.message || firstValidationMessage(data?.errors) || fallback

export const assertSuccessfulResponse = (data, status) => {
  if (data?.success === false) {
    throw new ApiBusinessError(getResponseMessage(data), {
      status,
      errors: data.errors,
      data,
    })
  }

  return data
}

export const getApiErrorMessage = (error, fallback = 'Đã có lỗi xảy ra. Vui lòng thử lại.') => {
  if (error instanceof ApiBusinessError) return error.message

  const data = error?.response?.data
  return getResponseMessage(data, error?.message || fallback)
}

// --- Error classification helpers (centralized from page-level duplicates) ---

export const isPermissionError = (error) => {
  const message = getApiErrorMessage(error, '').toLocaleLowerCase('vi')
  return error?.status === 403 || error?.response?.status === 403 || message.includes('không có quyền')
}

export const isUnauthorizedError = (error) =>
  error?.status === 401 || error?.response?.status === 401

export const getErrorTitle = (error, fallbackTitle = 'Đã có lỗi xảy ra') => {
  const status = error?.status ?? error?.response?.status
  if (status === 422) return 'Bộ lọc không hợp lệ'
  if (status >= 500) return 'Máy chủ chưa thể xử lý yêu cầu'
  if (error instanceof ApiBusinessError) return 'Yêu cầu chưa thể xử lý'
  if (!error?.response) return 'Không thể kết nối đến máy chủ'
  return fallbackTitle
}
