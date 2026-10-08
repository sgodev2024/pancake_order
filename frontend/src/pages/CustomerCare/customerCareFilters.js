export const createEmptyFilters = () => ({
  shopId: undefined,
  orderPageId: undefined,
  search: '',
  dateRange: null,
  status: undefined,
  userId: undefined,
  isAccept: undefined,
})

export const normalizeFilters = (filters) => ({
  shop_id: filters.shopId,
  order_page_id: filters.orderPageId == null || filters.orderPageId === '' ? undefined : String(filters.orderPageId),
  search: filters.search.trim(),
  date_from: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
  date_to: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
  status: filters.status,
  user_id: filters.userId,
  is_accept: filters.isAccept,
})

export const changeCareFilterShop = (filters, shopId) => ({
  ...filters,     
  shopId,
  orderPageId: undefined,
  userId: undefined,
})
