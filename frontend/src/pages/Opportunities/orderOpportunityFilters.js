export const createEmptyOpportunityFilters = () => ({
  shopId: undefined,
  orderPageId: undefined,
  phone: '',
  dateRange: null,
})

export const normalizeOpportunityFilters = (filters) => ({
  shop_id: filters.shopId,
  phone: filters.phone.trim() || undefined,
  order_page_id:
    filters.orderPageId == null || filters.orderPageId === ''
      ? undefined
      : String(filters.orderPageId),
  date_from: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
  date_to: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
})

export const changeOpportunityFilterShop = (filters, shopId) => ({
  ...filters,
  shopId,
  orderPageId: undefined,
})

export const getOpportunityOrderPageOptions = (pages = []) =>
  (Array.isArray(pages) ? pages : [])
    .filter((page) => page?.id !== undefined && page?.id !== null && String(page.id).trim() !== '')
    .map((page) => ({
      value: String(page.id),
      label: page.name,
    }))
