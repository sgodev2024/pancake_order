import assert from 'node:assert/strict'
import test from 'node:test'
import { QueryClient } from '@tanstack/react-query'
import apiClient from '../src/api/client.js'
import {
  getShopOptions,
  SHOP_OPTIONS_STALE_TIME,
  shopOptionsQueryOptions,
  shopQueryKeys,
} from '../src/api/shops.api.js'
import { API_ROUTES } from '../src/constants/routes.js'

test('shop options always use the minimal summary contract', async () => {
  const originalGet = apiClient.get
  let request
  apiClient.get = async (...args) => {
    request = args
    return { data: { data: [{ id: 11, name: 'Allowed shop' }] } }
  }

  try {
    assert.deepEqual(await getShopOptions(), [{ id: 11, name: 'Allowed shop' }])
    assert.deepEqual(request, [API_ROUTES.SHOPS, { params: { view: 'summary' } }])
  } finally {
    apiClient.get = originalGet
  }
})

test('all shop selectors share one finite, session-cleared cache entry', async () => {
  const originalGet = apiClient.get
  const client = new QueryClient()
  let requestCount = 0
  let resolveRequest
  apiClient.get = async () => {
    requestCount += 1
    return new Promise((resolve) => {
      resolveRequest = () => resolve({ data: { data: [{ id: 11, name: 'Allowed shop' }] } })
    })
  }

  try {
    const firstOptions = shopOptionsQueryOptions()
    const secondOptions = shopOptionsQueryOptions()
    assert.deepEqual(firstOptions.queryKey, shopQueryKeys.options())
    assert.deepEqual(firstOptions.queryKey, secondOptions.queryKey)
    assert.equal(firstOptions.staleTime, SHOP_OPTIONS_STALE_TIME)
    assert.equal(firstOptions.staleTime, 300_000)
    assert.equal(firstOptions.retry, 1)
    assert.equal(firstOptions.refetchOnWindowFocus, false)
    assert.equal(firstOptions.refetchOnMount, true)

    const firstRequest = client.fetchQuery(firstOptions)
    const secondRequest = client.fetchQuery(secondOptions)
    await Promise.resolve()
    assert.equal(requestCount, 1)
    resolveRequest()
    const [firstResult, secondResult] = await Promise.all([firstRequest, secondRequest])
    assert.deepEqual(firstResult, secondResult)
  } finally {
    client.clear()
    apiClient.get = originalGet
  }
})
