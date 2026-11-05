export const PRODUCT_PLACEHOLDER =
  'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%226%22 fill=%22%23f1f5f9%22/%3E%3Cpath d=%22M13 31l7-8 5 5 4-4 6 7H13z%22 fill=%22%2394a3b8%22/%3E%3Ccircle cx=%2220%22 cy=%2218%22 r=%223%22 fill=%22%2394a3b8%22/%3E%3C/svg%3E'
const getNestedValue = (source, path) => {
  if (!source || !path) return undefined
  return path.split('.').reduce((acc, key) => acc?.[key], source)
}

export const getProductName = (product) => {
  const candidates = [
    product?.name,
    getNestedValue(product, 'pancake_full_data.product.name'),
    getNestedValue(product, 'pancake_full_data.variation_info.name'),
    getNestedValue(product, 'pancake_full_data.name'),
    'Sản phẩm',
  ]

  return candidates.find((value) => typeof value === 'string' && value.trim().length > 0) || 'Sản phẩm'
}

const findFirstImageInObject = (source) => {
  if (!source || typeof source !== 'object') return undefined

  if (Array.isArray(source)) {
    for (const item of source) {
      const found = findFirstImageInObject(item)
      if (found) return found
    }
    return undefined
  }

  for (const [key, value] of Object.entries(source)) {
    const normalizedKey = String(key).toLowerCase()
    if (['image', 'images', 'thumbnail', 'thumbnail_url', 'avatar', 'media', 'url'].includes(normalizedKey)) {
      if (Array.isArray(value)) {
        const first = value.find((item) => typeof item === 'string' && item.trim().length > 0)
        if (first) return first
      }

      if (typeof value === 'string' && value.trim().length > 0) {
        return value
      }
    }

    if (typeof value === 'object') {
      const nested = findFirstImageInObject(value)
      if (nested) return nested
    }
  }

  return undefined
}

export const getProductImage = (product) => {
  const payload = product?.pancake_full_data ?? {}
  if (payload.image_removed === true || payload.image_removed === 1) return PRODUCT_PLACEHOLDER
  const candidates = [
    getNestedValue(product, 'pancake_full_data.images'),
    getNestedValue(product, 'pancake_full_data.variation_info.images'),
    getNestedValue(product, 'pancake_full_data.product.images'),
    getNestedValue(product, 'pancake_full_data.product.image'),
    getNestedValue(product, 'pancake_full_data.image'),
    getNestedValue(product, 'pancake_full_data.product.variation_info.images'),
    getNestedValue(product, 'pancake_full_data.product.variation_info.image'),
    findFirstImageInObject(payload),
    findFirstImageInObject(product),
  ]

  const firstImage = candidates.find((value) => {
    if (Array.isArray(value)) {
      return value.some((image) => typeof image === 'string' && image.trim().length > 0)
    }

    return typeof value === 'string' && value.trim().length > 0
  })

  if (Array.isArray(firstImage)) {
    return firstImage.find((image) => typeof image === 'string' && image.trim().length > 0) || PRODUCT_PLACEHOLDER
  }

  return typeof firstImage === 'string' && firstImage.trim().length > 0 ? firstImage : PRODUCT_PLACEHOLDER
}

