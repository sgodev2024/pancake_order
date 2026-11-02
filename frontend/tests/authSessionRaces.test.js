import assert from 'node:assert/strict'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { getMe } from '../src/api/auth.api.js'
import { isSessionSuperseded, useAuthStore } from '../src/auth/auth.store.js'

const admin = { id: 1, role: { slug: 'admin' } }
const staff = { id: 2, role: { slug: 'staff-sale' } }
const tick = () => new Promise((resolve) => setImmediate(resolve))

const deferredProfiles = () => {
  const requests = new Map()
  apiClient.defaults.adapter = (config) => new Promise((resolve, reject) => {
    const token = config.headers.get('Authorization')
    requests.set(token, {
      resolve: (user) => resolve({ data: { success: true, data: user }, status: 200, headers: {}, config }),
      reject: (status = 401) => reject({ config, response: { status, data: { success: false, message: 'Profile failed' } } }),
    })
  })
  return requests
}

test('logout during profile completion cannot restore an authenticated session', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().clearSession()
  const requests = deferredProfiles()
  try {
    const pending = useAuthStore.getState().completeSession({ access_token: 'old-profile' })
    const canceled = assert.rejects(pending, isSessionSuperseded)
    await tick()
    useAuthStore.getState().clearSession()
    requests.get('Bearer old-profile').resolve(admin)
    await canceled
    assert.equal(useAuthStore.getState().isAuthenticated, false)
    assert.equal(useAuthStore.getState().token, null)
    assert.equal(useAuthStore.getState().user, null)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('the newest login attempt wins regardless of profile response order', async () => {
  const adapter = apiClient.defaults.adapter
  try {
    for (const oldFirst of [true, false]) {
      useAuthStore.getState().clearSession()
      const requests = deferredProfiles()
      const old = useAuthStore.getState().completeSession({ access_token: 'old-profile' })
      const canceled = assert.rejects(old, isSessionSuperseded)
      await tick()
      const current = useAuthStore.getState().completeSession({ access_token: 'new-profile' })
      await tick()
      if (oldFirst) {
        requests.get('Bearer old-profile').resolve(admin)
        await canceled
        assert.equal(useAuthStore.getState().isAuthenticated, false)
      }
      requests.get('Bearer new-profile').resolve(staff)
      await current
      if (!oldFirst) {
        requests.get('Bearer old-profile').resolve(admin)
        await canceled
      }
      assert.equal(useAuthStore.getState().token, 'new-profile')
      assert.equal(useAuthStore.getState().user.id, staff.id)
    }
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('an older credential response is rejected before requesting its profile', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().clearSession()
  let calls = 0
  apiClient.defaults.adapter = async (config) => {
    calls++
    return { data: { success: true, data: staff }, status: 200, headers: {}, config }
  }
  try {
    const oldAttempt = useAuthStore.getState().beginSessionAttempt()
    const newAttempt = useAuthStore.getState().beginSessionAttempt()
    await useAuthStore.getState().completeSession({ access_token: 'new-profile' }, { attempt: newAttempt })
    await assert.rejects(useAuthStore.getState().completeSession({ access_token: 'old-profile' }, { attempt: oldAttempt }), isSessionSuperseded)
    assert.equal(calls, 1)
    assert.equal(useAuthStore.getState().token, 'new-profile')
    assert.equal(useAuthStore.getState().user.id, staff.id)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('a delayed initialization cannot overwrite or clear a replacement session', async () => {
  const adapter = apiClient.defaults.adapter
  try {
    for (const fail of [false, true]) {
      useAuthStore.getState().clearSession()
      useAuthStore.setState({ token: 'old-initialization', isInitializing: true })
      const requests = deferredProfiles()
      const pending = useAuthStore.getState().initializeAuth()
      await tick()
      useAuthStore.getState().setSession({ token: 'new-session', user: staff })
      if (fail) requests.get('Bearer old-initialization').reject()
      else requests.get('Bearer old-initialization').resolve(admin)
      await pending
      assert.equal(useAuthStore.getState().token, 'new-session')
      assert.equal(useAuthStore.getState().user.id, staff.id)
      assert.equal(useAuthStore.getState().isInitializing, false)
    }
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('an old initialization promise does not block a fresh initialization after logout', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().clearSession()
  useAuthStore.setState({ token: 'old-initialization', isInitializing: true })
  const requests = deferredProfiles()
  try {
    const old = useAuthStore.getState().initializeAuth()
    await tick()
    useAuthStore.getState().clearSession()
    useAuthStore.setState({ token: 'new-initialization', isInitializing: true })
    const current = useAuthStore.getState().initializeAuth()
    await tick()
    assert.equal(requests.size, 2)
    requests.get('Bearer old-initialization').resolve(admin)
    await old
    assert.equal(useAuthStore.getState().isInitializing, true)
    requests.get('Bearer new-initialization').resolve(staff)
    await current
    assert.equal(useAuthStore.getState().token, 'new-initialization')
    assert.equal(useAuthStore.getState().user.id, staff.id)
    assert.equal(useAuthStore.getState().isInitializing, false)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('a late old-token 401 cannot log out or redirect the current account', async () => {
  const adapter = apiClient.defaults.adapter
  const previousWindow = globalThis.window
  let redirects = 0
  globalThis.window = { location: { pathname: '/', replace: () => redirects++ } }
  useAuthStore.getState().setSession({ token: 'old-session', user: admin })
  const requests = deferredProfiles()
  try {
    const old = apiClient.get('/orders')
    const rejected = assert.rejects(old)
    await tick()
    useAuthStore.getState().setSession({ token: 'new-session', user: staff })
    requests.get('Bearer old-session').reject()
    await rejected
    assert.equal(useAuthStore.getState().token, 'new-session')
    assert.equal(useAuthStore.getState().user.id, staff.id)
    assert.equal(redirects, 0)

    const current = apiClient.get('/orders')
    const currentRejected = assert.rejects(current)
    await tick()
    requests.get('Bearer new-session').reject()
    await currentRejected
    assert.equal(useAuthStore.getState().isAuthenticated, false)
    assert.equal(redirects, 1)
  } finally {
    apiClient.defaults.adapter = adapter
    if (previousWindow === undefined) delete globalThis.window
    else globalThis.window = previousWindow
    useAuthStore.getState().clearSession()
  }
})

test('an explicit profile token failure rejects the request without revoking a different current token', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().setSession({ token: 'current-session', user: staff })
  const requests = deferredProfiles()
  try {
    const pending = getMe({ token: 'different-profile-token' })
    const rejected = assert.rejects(pending)
    await tick()
    requests.get('Bearer different-profile-token').reject()
    await rejected
    assert.equal(useAuthStore.getState().token, 'current-session')
    assert.equal(useAuthStore.getState().user.id, staff.id)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

test('a superseded profile failure cannot be handled as a current login failure', async () => {
  const adapter = apiClient.defaults.adapter
  useAuthStore.getState().clearSession()
  const requests = deferredProfiles()
  try {
    const old = useAuthStore.getState().completeSession({ access_token: 'old-profile' })
    const canceled = assert.rejects(old, isSessionSuperseded)
    await tick()
    useAuthStore.getState().setSession({ token: 'new-session', user: staff })
    requests.get('Bearer old-profile').reject(500)
    await canceled
    assert.equal(useAuthStore.getState().token, 'new-session')
    assert.equal(useAuthStore.getState().user.id, staff.id)
  } finally {
    apiClient.defaults.adapter = adapter
    useAuthStore.getState().clearSession()
  }
})

