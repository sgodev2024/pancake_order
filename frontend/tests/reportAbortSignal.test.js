import assert from 'node:assert/strict'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { getProductSales, getProductPeriodReport, getProductMonthlyQuantities } from '../src/api/products.api.js'
import { getProvinceCharts } from '../src/api/provinces.api.js'
import { getOverview } from '../src/api/overview.api.js'

test('dashboard and report clients retain filters and propagate query cancellation to HTTP', async () => {
  const adapter = apiClient.defaults.adapter
  const controller = new AbortController()
  const requests = []
  apiClient.defaults.adapter = async (config) => {
    requests.push(config)
    return { data: { success: true, data: {} }, status: 200, headers: {}, config }
  }
  try {
    const params = { shop_id: '13', date_from: '2026-10-01', date_to: '2026-10-31' }
    for (const client of [getProductSales, getProductPeriodReport, getProductMonthlyQuantities, getProvinceCharts]) await client(params, { signal: controller.signal })
    await getOverview({ signal: controller.signal })
    assert.equal(requests.length, 5)
    for (const request of requests) assert.equal(request.signal, controller.signal)
    for (const request of requests.slice(0, 4)) assert.deepEqual(request.params, params)
    controller.abort()
    await assert.rejects(getProductSales(params, { signal: controller.signal }), (error) => error.code === 'ERR_CANCELED')
    assert.equal(requests.length, 5)
  } finally {
    apiClient.defaults.adapter = adapter
  }
})
