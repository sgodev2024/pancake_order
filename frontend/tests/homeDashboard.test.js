import assert from 'node:assert/strict'
import { before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { App } from 'antd'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '../src/auth/auth.store.js'

let HomePage

before(async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/Home/HomePage.jsx', import.meta.url)),
    platform: 'node',
    transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'home-render-test',
      resolveId(id) {
        if (id.endsWith('.css')) return '\0empty-style'
        if (id.endsWith('/auth.store.js')) {
          return { id: new URL('../src/auth/auth.store.js', import.meta.url).href, external: true }
        }
        if (!id.startsWith('.') && !id.startsWith('/')) {
          return { id: import.meta.resolve(id), external: true }
        }
      },
      load(id) { if (id === '\0empty-style') return 'export default {}' },
    }],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    HomePage = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
  } finally { await bundle.close() }
})

const renderHome = (roleSlug) => {
  const initial = useAuthStore.getInitialState()
  const previous = initial.user
  initial.user = { id: 1, name: 'Home test', role: { slug: roleSlug, permissions: [] } }
  const client = new QueryClient({ defaultOptions: { queries: { enabled: false, retry: false, gcTime: 0 } } })
  try {
    const page = React.createElement(App, {}, React.createElement(HomePage))
    const router = React.createElement(MemoryRouter, {}, page)
    return renderToStaticMarkup(React.createElement(QueryClientProvider, { client }, router))
  } finally { initial.user = previous; client.clear() }
}

for (const role of ['admin', 'director']) {
  test(`${role} sees the updated reports dashboard at the home route`, () => {
    const html = renderHome(role)
    assert.ok(html.includes('class="ov-page"'))
    assert.ok(html.includes('Mở báo cáo'))
    assert.ok(!html.includes('home-metrics'))
    assert.ok(!html.includes('class="work-home"'))
  })
}

test('sales staff retain the work homepage instead of the management report dashboard', () => {
  const html = renderHome('staff-sale')
  assert.ok(html.includes('class="work-home"'))
  assert.ok(html.includes('Công việc của tôi'))
  assert.ok(!html.includes('class="ov-page"'))
})
