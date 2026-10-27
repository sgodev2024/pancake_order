export const createEmptyOrderFilters = () => ({
  shopId: undefined,
  orderPageId: undefined,
  search: '',
  dateRange: null,
  status: undefined,
  cod: undefined,
})

export const normalizeOrderFilters = (filters) => ({
  shop_id: filters.shopId,
  order_page_id: filters.orderPageId == null || filters.orderPageId === '' ? undefined : String(filters.orderPageId),
  search: filters.search.trim(),
  date_from: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
  date_to: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
  status: filters.status,
  ...(filters.cod === undefined || filters.cod === null || filters.cod === '' ? {} : { cod: filters.cod }),
})

export const changeOrderFilterShop = (filters, shopId) => ({
  ...filters,
  shopId,
  orderPageId: undefined,
})

export const getOrderPageOptions = (pages = []) =>
  (Array.isArray(pages) ? pages : [])
    .filter((page) => page?.id !== undefined && page?.id !== null && String(page.id).trim() !== '')
    .map((page) => ({
      value: String(page.id),
      label: page.name,
    }))

export const getOrderSourceDisplayName = (pageName, sourceName) =>
  [pageName, sourceName].find((name) => typeof name === 'string' && name.trim()) ?? '—'
