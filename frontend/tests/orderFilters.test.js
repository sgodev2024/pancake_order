import assert from 'node:assert/strict'
import test from 'node:test'
import {
  changeOrderFilterShop,
  createEmptyOrderFilters,
  getOrderSourceDisplayName,
  getOrderPageOptions,
  normalizeOrderFilters,
} from '../src/pages/Orders/orderFilters.js'

const fakeDate = (value) => ({ format: () => value })

test('maps the selected page to the Order API without coercing its string ID', () => {
  const normalized = normalizeOrderFilters({
    ...createEmptyOrderFilters(),
    shopId: '26',
    orderPageId: '-1',
    search: '  customer  ',
    dateRange: [fakeDate('2026-09-01'), fakeDate('2026-09-12')],
    status: '3',
  })

  assert.deepEqual(normalized, {
    shop_id: '26',
    order_page_id: '-1',
    search: 'customer',
    date_from: '2026-09-01',
    date_to: '2026-09-12',
    status: '3',
  })
  assert.equal(typeof normalized.order_page_id, 'string')
})

test('clearing a page omits it and changing shop always clears the old page', () => {
  const empty = normalizeOrderFilters(createEmptyOrderFilters())
  assert.equal(empty.order_page_id, undefined)

  const changed = changeOrderFilterShop({
    ...createEmptyOrderFilters(),
    shopId: '26',
    orderPageId: '-1',
  }, '31')

  assert.equal(changed.shopId, '31')
  assert.equal(changed.orderPageId, undefined)
})

test('page options keep IDs as strings and snapshot display handles blank names', () => {
  assert.deepEqual(getOrderPageOptions([
    { id: '115624128265497', name: 'Hương Chất TV' },
    { id: 9, name: 'Page nine' },
    { id: ' ', name: 'Unusable' },
  ]), [
    { value: '115624128265497', label: 'Hương Chất TV' },
    { value: '9', label: 'Page nine' },
  ])
  assert.equal(getOrderSourceDisplayName(null), '—')
  assert.equal(getOrderSourceDisplayName('   '), '—')
  assert.equal(getOrderSourceDisplayName('Hương Chất TV', 'Facebook'), 'Hương Chất TV')
  assert.equal(getOrderSourceDisplayName(null, 'Facebook'), 'Facebook')
  assert.equal(getOrderSourceDisplayName(' ', 'Zalo'), 'Zalo')
  assert.equal(getOrderSourceDisplayName(null, ' '), '—')
  assert.equal(normalizeOrderFilters({ ...createEmptyOrderFilters(), orderPageId: 'pzl_695112902870160686' }).order_page_id, 'pzl_695112902870160686')
  assert.equal('order_source_id' in normalizeOrderFilters(createEmptyOrderFilters()), false)
})
