import apiClient from './client.js'
import { API_ROUTES } from '../constants/routes.js'

const removeEmptyParams = (params = {}) =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  )

export const getProducts = async (params = {}, { signal } = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCTS, {
    params: removeEmptyParams(params),
    ...(signal ? { signal } : {}),
  })

  return response.data.data ?? {
    products: [],
    current_page: 1,
    per_page: 30,
    total_items: 0,
    total_pages: 0,
  }
}

const productPayload = ({ image, remove_image: removeImage, ...values }) => {
  if (!image) return removeImage ? { ...values, remove_image: true } : values
  const payload = new FormData()
  Object.entries(values).forEach(([key, value]) => {
    if (value !== undefined && value !== null) payload.append(key, value)
  })
  if (removeImage) payload.append('remove_image', '1')
  payload.append('image', image)
  return payload
}

export const createProduct = async (values) => {
  const response = await apiClient.post(API_ROUTES.PRODUCTS, productPayload(values))
  return response.data
}

export const updateProduct = async ({ id, ...values }) => {
  const payload = productPayload(values)
  const url = `${API_ROUTES.PRODUCTS}/${id}`
  // PHP parses uploaded files on POST; Laravel routes the method override to update.
  if (payload instanceof FormData) {
    payload.append('_method', 'PUT')
    return (await apiClient.post(url, payload)).data
  }
  return (await apiClient.put(url, payload)).data
}

export const getProductSales = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_SALES, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    from: null,
    to: null,
    latest_order_at: null,
    total_quantity: 0,
    total_orders: 0,
    items: [],
  }
}

export const getProductPeriodReport = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_PERIOD_REPORT, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    summary: { total_revenue: 0, total_quantity: 0, total_orders: 0, previous_revenue: null, change_amount: null, change_percent: null },
    chart: [],
    products: [],
    from: null,
    to: null,
    view_mode: 'month',
    compare: 'none',
  }
}

export const getProductMonthlyQuantities = async (params = {}) => {
  const response = await apiClient.get(API_ROUTES.PRODUCT_MONTHLY_QUANTITIES, {
    params: removeEmptyParams(params),
  })

  return response.data.data ?? {
    year: new Date().getFullYear(),
    total_quantity: 0,
    summary: { up_count: 0, up_qty_total: 0, down_count: 0, down_qty_total: 0 },
    months: [],
  }
}
