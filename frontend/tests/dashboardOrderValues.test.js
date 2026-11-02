import assert from 'node:assert/strict'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { getOrderTotals } from '../src/api/orders.api.js'
import { orderValues, withOrderValues } from '../src/pages/Reports/orderValues.js'

test('POS full order value does not add the payment a second time', () => {
  assert.deepEqual(orderValues({ total_revenue: 500, total_cod: 0, total_prepaid_amount: 100, total_order_value: 500 }), {
    cod: 0, prepaid: 100, total: 500,
  })
  assert.deepEqual(orderValues({ total_revenue: 300, total_cod: 250, total_prepaid_amount: 50, total_order_value: 300 }), {
    cod: 250, prepaid: 50, total: 300,
  })
  assert.deepEqual(orderValues({ total_revenue: 500, total_cod: 0, total_prepaid_amount: 0, total_order_value: 500 }), {
    cod: 0, prepaid: 0, total: 500,
  })
})

test('legacy responses keep their COD plus prepaid calculation', () => {
  assert.deepEqual(orderValues({ total_revenue: '250', total_prepaid_amount: '50' }), {
    cod: 250, prepaid: 50, total: 300,
  })
  assert.deepEqual(orderValues({ total_revenue: '250', total_cod: null, total_prepaid_amount: '50', total_order_value: null }), {
    cod: 250, prepaid: 50, total: 300,
  })
  assert.deepEqual(orderValues(), { cod: 0, prepaid: 0, total: 0 })
})

test('explicit zero and negative full values are retained instead of falling back to legacy revenue', () => {
  assert.deepEqual(orderValues({ total_revenue: 500, total_cod: 0, total_prepaid_amount: 0, total_order_value: 0 }), {
    cod: 0, prepaid: 0, total: 0,
  })
  assert.deepEqual(orderValues({ total_revenue: '-100', total_cod: '0', total_prepaid_amount: '-20', total_order_value: '-100' }), {
    cod: 0, prepaid: -20, total: -100,
  })
})

test('dashboard opt-in preserves filters and sends one totals request with the compatible value fields', async () => {
  const adapter = apiClient.defaults.adapter
  const filters = Object.freeze({ shop_id: '13', date_from: '2026-11-01', date_to: '2026-11-30' })
  const controller = new AbortController()
  const requests = []
  const payload = { total_items: 1, total_revenue: 500, total_cod: 0, total_prepaid_amount: 100, total_order_value: 500 }
  apiClient.defaults.adapter = async (config) => {
    requests.push(config)
    return { data: { success: true, data: payload }, status: 200, headers: {}, config }
  }
  try {
    const params = withOrderValues(filters)
    assert.deepEqual(filters, { shop_id: '13', date_from: '2026-11-01', date_to: '2026-11-30' })
    assert.deepEqual(params, { ...filters, include_order_values: 1 })
    const response = await getOrderTotals(params, { signal: controller.signal })
    assert.equal(requests.length, 1)
    assert.deepEqual(requests[0].params, { ...filters, include_order_values: 1, page: 1, page_size: 1, totals_only: 1, view: 'summary' })
    assert.equal(requests[0].signal, controller.signal)
    assert.deepEqual(response, payload)
    assert.equal(orderValues(response).total, 500)
  } finally {
    apiClient.defaults.adapter = adapter
  }
})

test('default totals API calls retain their existing request and response contract', async () => {
  const adapter = apiClient.defaults.adapter
  let request
  const payload = { total_items: 2, total_revenue: 250, total_prepaid_amount: 50, orders: [] }
  apiClient.defaults.adapter = async (config) => {
    request = config
    return { data: { success: true, data: payload }, status: 200, headers: {}, config }
  }
  try {
    const response = await getOrderTotals({ shop_id: '13' })
    assert.equal(Object.hasOwn(request.params, 'include_order_values'), false)
    assert.deepEqual(response, payload)
    assert.deepEqual(orderValues(response), { cod: 250, prepaid: 50, total: 300 })
    assert.deepEqual(withOrderValues(), { include_order_values: 1 })
  } finally {
    apiClient.defaults.adapter = adapter
  }
})

