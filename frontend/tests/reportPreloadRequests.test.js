import assert from 'node:assert/strict'
import test from 'node:test'
import { registerHooks } from 'node:module'
import apiClient from '../src/api/client.js'

test('warming all report routes imports code once and issues no data HTTP requests', async () => {
  const pages = new Set()
  const hooks = registerHooks({
    load(url, context, nextLoad) {
      if (url.endsWith('.jsx')) {
        pages.add(url)
        return { format: 'module', source: 'export default function Page() {}', shortCircuit: true }
      }
      return nextLoad(url, context)
    },
  })
  const adapter = apiClient.defaults.adapter
  const requests = []
  apiClient.defaults.adapter = async (config) => {
    requests.push(config.url)
    return { data: { success: true, data: [] }, status: 200, headers: {}, config }
  }
  try {
    const { preloadDefaultReport, preloadRemainingReports } = await import('../src/app/reportPreload.js')
    const first = preloadRemainingReports()
    assert.equal(preloadRemainingReports(), first)
    await Promise.all([first, preloadDefaultReport()])
    assert.equal(pages.size, 8)
    assert.deepEqual(requests, [])
  } finally {
    hooks.deregister()
    apiClient.defaults.adapter = adapter
  }
})
