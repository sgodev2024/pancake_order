import assert from 'node:assert/strict'
import test from 'node:test'
import {
  CARE_COUNT_OPTIONS,
  HAS_ORDER_OPTIONS,
  JOURNEY_ACTION_OPTIONS,
  createEmptyCustomerFilters,
  normalizeCustomerFilters,
} from '../src/pages/Customers/customerJourneyFilters.js'

const fakeDate = (value) => ({ format: () => value })

test('defines the approved Journey filter values and safe Vietnamese order wording', () => {
  assert.deepEqual(
    JOURNEY_ACTION_OPTIONS.map((option) => option.value),
    [
      'customer.entered_system',
      'customer_care.assigned',
      'customer_care.reassigned',
      'customer_care.reclaimed',
      'customer_care.completed',
      'order.created',
    ],
  )
  assert.deepEqual(HAS_ORDER_OPTIONS, [
    { value: 'yes', label: 'Có phát sinh đơn hàng' },
    { value: 'no', label: 'Chưa ghi nhận đơn hàng' },
  ])
  assert.equal(HAS_ORDER_OPTIONS.some((option) => option.label.includes('Đã mua')), false)
})

test('maps Journey controls to the customer-list API contract', () => {
  assert.deepEqual(normalizeCustomerFilters({
    ...createEmptyCustomerFilters(),
    shopId: '12',
    journeyAction: 'customer_care.completed',
    careCount: '5+',
    hasOrder: 'yes',
    journeyDateRange: [fakeDate('2026-08-01'), fakeDate('2026-08-31')],
    careUserId: '55',
  }), {
    shop_id: '12',
    search: '',
    date_from: undefined,
    date_to: undefined,
    loyalty_tier_id: undefined,
    journey_action: 'customer_care.completed',
    care_count_min: 5,
    has_order: 'yes',
    journey_date_from: '2026-08-01',
    journey_date_to: '2026-08-31',
    care_user_id: '55',
  })
})

test('maps exact care counts and clearing filters removes all Journey params', () => {
  for (const option of CARE_COUNT_OPTIONS.slice(0, 5)) {
    const normalized = normalizeCustomerFilters({
      ...createEmptyCustomerFilters(),
      careCount: option.value,
    })
    const count = Number(option.value)
    assert.equal(normalized.care_count_min, count)
    assert.equal(normalized.care_count_max, count)
  }

  const empty = normalizeCustomerFilters(createEmptyCustomerFilters())
  assert.equal(empty.journey_action, undefined)
  assert.equal(empty.care_count_min, undefined)
  assert.equal(empty.care_count_max, undefined)
  assert.equal(empty.has_order, undefined)
  assert.equal(empty.journey_date_from, undefined)
  assert.equal(empty.journey_date_to, undefined)
  assert.equal(empty.care_user_id, undefined)
})
