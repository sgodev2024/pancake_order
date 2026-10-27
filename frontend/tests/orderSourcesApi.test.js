import assert from 'node:assert/strict'
import test from 'node:test'
import { getOrders, getOrderSources } from '../src/api/orders.api.js'
import apiClient from '../src/api/client.js'
import { API_ROUTES } from '../src/constants/routes.js'

test('does not request the source catalog without a selected shop', async () => {
  const originalGet = apiClient.get
  let requestCount = 0
  apiClient.get = async () => {
    requestCount += 1
  }

  try {
    assert.deepEqual(await getOrderSources(), [])
    assert.deepEqual(await getOrderSources({ shop_id: '' }), [])
    assert.equal(requestCount, 0)
  } finally {
    apiClient.get = originalGet
  }
})

test('requests the local catalog for the exact shop and preserves string source IDs', async () => {
  const originalGet = apiClient.get
  let request
  apiClient.get = async (...args) => {
    request = args
    return {
      data: {
        data: [{ id: '-1', name: 'Facebook', parent_id: null, is_active: true }],
      },
    }
  }

  try {
    const sources = await getOrderSources({ shop_id: '26' })

    assert.deepEqual(request, [API_ROUTES.ORDER_SOURCES, { params: { shop_id: '26' } }])
    assert.equal(sources[0].id, '-1')
    assert.equal(typeof sources[0].id, 'string')
  } finally {
    apiClient.get = originalGet
  }
})

test('sends the selected source with pagination and omits it after clearing', async () => {
  const originalGet = apiClient.get
  const requests = []
  apiClient.get = async (...args) => {
    requests.push(args)
    return { data: { data: { orders: [] } } }
  }

  try {
    await getOrders({ page: 2, page_size: 30, shop_id: '26', order_source_id: '-1' })
    await getOrders({
      page: 1,
      page_size: 30,
      shop_id: '26',
      order_source_id: undefined,
      view: 'legacy',
    })

    assert.deepEqual(requests[0], [API_ROUTES.ORDERS, {
      params: { page: 2, page_size: 30, shop_id: '26', order_source_id: '-1', view: 'summary' },
    }])
    assert.deepEqual(requests[1], [API_ROUTES.ORDERS, {
      params: { page: 1, page_size: 30, shop_id: '26', view: 'summary' },
    }])
  } finally {
    apiClient.get = originalGet
  }
})
