import assert from 'node:assert/strict'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { useAuthStore } from '../src/auth/auth.store.js'
import { queryClient } from '../src/app/queryClient.js'
import { useShopStore } from '../src/stores/shop.store.js'

const admin = { id: 1, role: { id: 1, slug: 'admin', permissions: [{ slug: 'view-orders' }] } }

test('login authenticates /me with the new token and publishes only the complete profile', async () => {
  useAuthStore.getState().clearSession()
  const adapter = apiClient.defaults.adapter
  const changes = []
  const unsubscribe = useAuthStore.subscribe((state) => changes.push(state))
  let resolveProfile
  let request
  apiClient.defaults.adapter = (config) => {
    request = config
    return new Promise((resolve) => { resolveProfile = () => resolve({ data: { success: true, data: admin }, status: 200, headers: {}, config }) })
  }
  try {
    const pending = useAuthStore.getState().completeSession({ access_token: 'new-token', user: { id: 1 } })
    await new Promise((resolve) => setImmediate(resolve))
    assert.equal(request.headers.get('Authorization'), 'Bearer new-token')
    assert.equal(useAuthStore.getState().isAuthenticated, false)
    assert.equal(useAuthStore.getState().token, null)
    assert.equal(changes.length, 0)
    resolveProfile()
    assert.deepEqual(await pending, admin)
    assert.equal(changes.length, 1)
    assert.equal(changes[0].user.role.slug, 'admin')
    assert.equal(changes[0].isAuthenticated, true)
  } finally {
    unsubscribe()
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('explicit profile token is preserved when replacing a restricted token', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().setSession({ token: 'restricted-old-token', user: { id: 1 }, requirePasswordChange: true })
  let authorization
  apiClient.defaults.adapter = async (config) => {
    authorization = config.headers.get('Authorization')
    return { data: { success: true, data: admin }, status: 200, headers: {}, config }
  }
  try {
    await useAuthStore.getState().completeSession({ access_token: 'full-new-token' })
    assert.equal(authorization, 'Bearer full-new-token')
    assert.equal(useAuthStore.getState().requirePasswordChange, false)
    assert.equal(useAuthStore.getState().token, 'full-new-token')
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('failed profile does not publish a token; first login preserves password-change restriction without /me', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().clearSession()
  let requests = 0
  apiClient.defaults.adapter = async () => { requests++; throw new Error('Profile unavailable') }
  try {
    await assert.rejects(useAuthStore.getState().completeSession({ access_token: 'new-token' }), /Profile unavailable/)
    assert.equal(useAuthStore.getState().isAuthenticated, false)
    await useAuthStore.getState().completeSession({ access_token: 'restricted-token', user: { id: 1 }, require_password_change: true })
    assert.equal(requests, 1)
    assert.equal(useAuthStore.getState().requirePasswordChange, true)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('same-user permission changes evict old report data, while permission ordering does not', () => {
  const user = { ...admin, role: { ...admin.role, permissions: [{ slug: 'view-orders' }, { slug: 'view-customers' }] } }
  useAuthStore.getState().setSession({ token: 'same-token', user })
  queryClient.setQueryData(['overview'], { restricted: true })
  useAuthStore.getState().setSession({ token: 'same-token', user: { ...user, role: { ...user.role, permissions: [...user.role.permissions].reverse() } } })
  assert.deepEqual(queryClient.getQueryData(['overview']), { restricted: true })
  useAuthStore.getState().setSession({ token: 'same-token', user: admin })
  assert.equal(queryClient.getQueryData(['overview']), undefined)
  useAuthStore.getState().clearSession()
})

test('logout and account replacement clear shop selection while same-user token rotation preserves it', () => {
  useAuthStore.getState().clearSession()
  try {
    useAuthStore.getState().setSession({ token: 'original-token', user: admin })
    useShopStore.getState().setSelectedShopId('13')
    useAuthStore.getState().setSession({ token: 'original-token', user: { ...admin } })
    assert.equal(useShopStore.getState().selectedShopId, '13')
    useAuthStore.getState().setSession({ token: 'rotated-token', user: admin })
    assert.equal(useShopStore.getState().selectedShopId, '13')
    useAuthStore.getState().setSession({ token: 'replacement-token', user: { ...admin, id: 2 } })
    assert.equal(useShopStore.getState().selectedShopId, null)
    useShopStore.getState().setSelectedShopId('22')
    useAuthStore.getState().clearSession()
    assert.equal(useShopStore.getState().selectedShopId, null)
  } finally {
    useAuthStore.getState().clearSession()
  }
})
