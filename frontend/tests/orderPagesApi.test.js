import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { getOrders, getOrderPages, getOrderTotals } from '../src/api/orders.api.js'
import apiClient from '../src/api/client.js'
import { API_ROUTES } from '../src/constants/routes.js'
import { changeOrderFilterShop, createEmptyOrderFilters, normalizeOrderFilters } from '../src/pages/Orders/orderFilters.js'

test('page options require shop, use local endpoint, and preserve string IDs', async () => {
  const originalGet = apiClient.get
  const requests = []
  apiClient.get = async (...args) => {
    requests.push(args)
    return { data: { data: [
      { id: '115624128265497', name: 'Hương Chất TV' },
      { id: 'pzl_695112902870160686', name: 'Hương Chất Group' },
    ] } }
  }
  try {
    assert.deepEqual(await getOrderPages(), [])
    assert.deepEqual(await getOrderPages({ shop_id: '' }), [])
    assert.equal(requests.length, 0)
    const pages = await getOrderPages({ shop_id: '13' })
    assert.deepEqual(requests, [[API_ROUTES.ORDER_PAGES, { params: { shop_id: '13' } }]])
    assert.equal(pages[0].name, 'Hương Chất TV')
    assert.equal(pages[0].id, '115624128265497')
    assert.equal(pages[1].id, 'pzl_695112902870160686')
  } finally {
    apiClient.get = originalGet
  }
})

test('selected page survives pagination, clears safely on shop change, and always uses summary', async () => {
  const originalGet = apiClient.get
  const requests = []
  apiClient.get = async (...args) => {
    requests.push(args)
    return { data: { data: { orders: [] } } }
  }
  try {
    const selected = { ...createEmptyOrderFilters(), shopId: '13', orderPageId: '115624128265497' }
    await getOrders({ ...normalizeOrderFilters(selected), page: 2, page_size: 30 })
    await getOrders({ ...normalizeOrderFilters({ ...selected, orderPageId: undefined }), page: 1 })
    await getOrders({ ...normalizeOrderFilters(changeOrderFilterShop(selected, '26')), page: 1 })
    assert.deepEqual(requests[0], [API_ROUTES.ORDERS, { params: {
      shop_id: '13', order_page_id: '115624128265497', page: 2, page_size: 30, view: 'summary',
    } }])
    assert.equal(requests[1][1].params.order_page_id, undefined)
    assert.equal(requests[2][1].params.shop_id, '26')
    for (const [, { params }] of requests) {
      assert.equal('order_source_id' in params, false)
      assert.equal(params.view, 'summary')
    }
    assert.equal('order_page_id' in requests[2][1].params, false)
  } finally {
    apiClient.get = originalGet
  }
})

test('summary total flags use Laravel-compatible boolean query values', async () => {
  const originalGet = apiClient.get
  const requests = []
  apiClient.get = async (...args) => {
    requests.push(args)
    return { data: { data: {} } }
  }
  try {
    await getOrders({ include_totals: false })
    await getOrderTotals({})
    assert.equal(requests[0][1].params.include_totals, 0)
    assert.equal(requests[1][1].params.totals_only, 1)
  } finally {
    apiClient.get = originalGet
  }
})

// Wiring guards complement pure API/filter tests; these are not browser/E2E tests.
test('OrdersPage wires shop-specific options without previous-shop placeholder and resets pagination', () => {
  const source = readFileSync(new URL('../src/pages/Orders/OrdersPage.jsx', import.meta.url), 'utf8')
  const optionsQuery = source.slice(source.indexOf('const orderPagesQuery'), source.indexOf('const orderPageOptions'))
  assert.match(optionsQuery, /queryKey: \['order-pages', draftFilters.shopId \?\? null\]/)
  assert.match(optionsQuery, /enabled: Boolean\(draftFilters.shopId\)/)
  assert.doesNotMatch(optionsQuery, /placeholderData|keepPreviousData/)
  const shopChange = source.slice(source.indexOf('const handleShopChange'), source.indexOf('const handleOrderPageChange'))
  assert.match(shopChange, /order_page_id: undefined/)
  assert.match(shopChange, /setPage\(1\)/)
  assert.match(source, /getOrderSourceDisplayName\(pageName, record.order_source_name\)/)
  assert.doesNotMatch(source, /getOrderSources|order_source_id/)
  assert.match(source, /optionFilterProp="label"/)
  assert.match(source, /enabled: Boolean\(ordersQuery\.data\) && !ordersQuery\.isPlaceholderData/)
  assert.match(source, /loading={ordersQuery\.isLoading}/)
})
