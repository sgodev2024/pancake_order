import { JOURNEY_ACTION_CONFIG } from '../../components/CustomerJourney/journeyActionConfig.js'

export const JOURNEY_ACTION_OPTIONS = Object.freeze(
  Object.entries(JOURNEY_ACTION_CONFIG).map(([value, config]) => ({
    value,
    label: config.label,
  })),
)

export const CARE_COUNT_OPTIONS = Object.freeze([
  { value: '0', label: '0 lần' },
  { value: '1', label: '1 lần' },
  { value: '2', label: '2 lần' },
  { value: '3', label: '3 lần' },
  { value: '4', label: '4 lần' },
  { value: '5+', label: 'Từ 5 lần trở lên' },
])

export const HAS_ORDER_OPTIONS = Object.freeze([
  { value: 'yes', label: 'Có phát sinh đơn hàng' },
  { value: 'no', label: 'Chưa ghi nhận đơn hàng' },
])

const CARE_COUNT_PARAMS = Object.freeze({
  0: { care_count_min: 0, care_count_max: 0 },
  1: { care_count_min: 1, care_count_max: 1 },
  2: { care_count_min: 2, care_count_max: 2 },
  3: { care_count_min: 3, care_count_max: 3 },
  4: { care_count_min: 4, care_count_max: 4 },
  '5+': { care_count_min: 5 },
})

export const createEmptyCustomerFilters = () => ({
  shopId: undefined,
  search: '',
  dateRange: null,
  loyaltyTierId: undefined,
  journeyAction: undefined,
  careCount: undefined,
  hasOrder: undefined,
  journeyDateRange: null,
  careUserId: undefined,
})

export const normalizeCustomerFilters = (filters) => ({
  shop_id: filters.shopId,
  search: filters.search.trim(),
  date_from: filters.dateRange?.[0]?.format('YYYY-MM-DD'),
  date_to: filters.dateRange?.[1]?.format('YYYY-MM-DD'),
  loyalty_tier_id: filters.loyaltyTierId,
  journey_action: filters.journeyAction,
  ...CARE_COUNT_PARAMS[filters.careCount],
  has_order: filters.hasOrder,
  journey_date_from: filters.journeyDateRange?.[0]?.format('YYYY-MM-DD'),
  journey_date_to: filters.journeyDateRange?.[1]?.format('YYYY-MM-DD'),
  care_user_id: filters.careUserId,
})
