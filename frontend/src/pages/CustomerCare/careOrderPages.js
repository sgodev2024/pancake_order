import { getOrderPages } from '../../api/orders.api.js'
import { CUSTOMER_CARE_TYPES } from '../../constants/customer-care.js'

export const careOrderPagesQueryOptions = (pageType, shopId) => ({
  queryKey: ['order-pages', 'customer_care', pageType, shopId ?? null],
  queryFn: () => getOrderPages({
    shop_id: shopId,
    context: 'customer_care',
    type: CUSTOMER_CARE_TYPES[pageType].apiType,
  }),
  enabled: Boolean(shopId),
  staleTime: 60_000,
})
