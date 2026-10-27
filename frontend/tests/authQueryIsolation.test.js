import assert from 'node:assert/strict'
import test from 'node:test'
import { queryClient } from '../src/app/queryClient.js'
import { shopQueryKeys } from '../src/api/shops.api.js'
import { useAuthStore } from '../src/auth/auth.store.js'

const pageKey = ['order-pages', '13']
const orderKey = ['orders', { shop_id: '13', view: 'summary' }]
const setSession = (token, id) => useAuthStore.getState().setSession({ token, user: { id } })

test('logout drops admin page options and Orders before staff can reuse their query keys', () => {
  setSession('test-admin', 1)
  queryClient.setQueryData(pageKey, [{ id: 'hidden-page', name: 'Admin-only page' }])
  queryClient.setQueryData(orderKey, { orders: [{ id: 99 }] })
  queryClient.setQueryData(shopQueryKeys.options(), [{ id: '13' }])

  useAuthStore.getState().clearSession()
  setSession('test-staff', 2)

  assert.equal(queryClient.getQueryData(pageKey), undefined)
  assert.equal(queryClient.getQueryData(orderKey), undefined)
  assert.equal(queryClient.getQueryCache().getAll().length, 0)
  useAuthStore.getState().clearSession()
})

test('replacing a session clears fresh options, but enriching the same session preserves its cache', () => {
  setSession('test-admin', 1)
  queryClient.setQueryData(pageKey, [{ id: 'admin-page' }])
  setSession('test-staff', 2)
  assert.equal(queryClient.getQueryData(pageKey), undefined)

  queryClient.setQueryData(pageKey, [{ id: 'staff-page' }])
  setSession('test-staff', 2)
  assert.deepEqual(queryClient.getQueryData(pageKey), [{ id: 'staff-page' }])
  setSession('test-staff', 3)
  assert.equal(queryClient.getQueryData(pageKey), undefined)
  useAuthStore.getState().clearSession()
})

test('a late response from the old session cannot repopulate the next session cache', async () => {
  setSession('test-admin', 1)
  let resolveOld
  const pending = queryClient.fetchQuery({
    queryKey: pageKey,
    queryFn: () => new Promise((resolve) => { resolveOld = resolve }),
  }).catch(() => undefined)

  useAuthStore.getState().clearSession()
  setSession('test-staff', 2)
  resolveOld([{ id: 'admin-only' }])
  await pending
  await Promise.resolve()

  assert.equal(queryClient.getQueryData(pageKey), undefined)
  queryClient.setQueryData(pageKey, [{ id: 'staff-only' }])
  assert.deepEqual(queryClient.getQueryData(pageKey), [{ id: 'staff-only' }])
  useAuthStore.getState().clearSession()
})
