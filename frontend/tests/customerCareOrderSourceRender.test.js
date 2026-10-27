import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { App } from 'antd'
import { CUSTOMER_CARE_TYPES } from '../src/constants/customer-care.js'
import { createEmptyFilters, normalizeFilters } from '../src/pages/CustomerCare/customerCareFilters.js'

// Use Vite's installed bundler to load real JSX; no browser or application API.
const loadComponent = async (file) => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL(file, import.meta.url)),
    platform: 'node',
    transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'node-render-test',
      resolveId(id) {
        if (id.endsWith('.css')) return '\0empty-style'
        if (!id.startsWith('.') && !id.startsWith('/') && !/^[A-Za-z]:/.test(id)) {
          return { id: import.meta.resolve(id), external: true }
        }
      },
      load(id) { if (id === '\0empty-style') return 'export default {}' },
    }],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    return (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
  } finally { await bundle.close() }
}

let Page
let Filter
const clients = []
before(async () => {
  Page = await loadComponent('../src/pages/CustomerCare/CustomerCarePage.jsx')
  Filter = await loadComponent('../src/pages/CustomerCare/CustomerCareOrderPageFilter.jsx')
})
after(() => clients.forEach((client) => client.clear()))

for (const [pageType, { apiType }] of Object.entries(CUSTOMER_CARE_TYPES)) {
  test(`${pageType} renders real source cells after Order, with snapshot name and null dash`, () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    clients.push(client)
    client.setQueryData(['customer-cares', { type: apiType, context: 'v2', ...normalizeFilters(createEmptyFilters()), page: 1 }], {
      customers: [
        { id: 1, order_page_name: 'Exact <snapshot>', customer_name: 'First', order_source_name: 'Facebook' },
        { id: 2, order_page_name: null, customer_name: 'Second', order_source_name: 'Zalo' },
      ], total_items: 2, per_page: 30,
    })
    const html = renderToStaticMarkup(React.createElement(QueryClientProvider, { client },
      React.createElement(MemoryRouter, {}, React.createElement(App, {}, React.createElement(Page, { pageType }))),
    ))
    const filterLabels = ['Tìm kiếm', 'Cửa hàng', 'Nguồn đơn', 'Nhân viên', 'Thời gian', 'Trạng thái', 'Trạng thái duyệt']
    let previousIndex = -1
    for (const label of filterLabels) {
      const labelIndex = html.indexOf(`>${label}</label>`)
      assert.ok(labelIndex > previousIndex, `${pageType}: ${label} must follow the V1 filter order`)
      previousIndex = labelIndex
    }
    assert.ok(html.indexOf('>Lọc</span>') > previousIndex)
    assert.ok(html.indexOf('>Làm mới</span>') > previousIndex)
    assert.ok(!html.includes('Bộ lọc nâng cao'))
    assert.ok(!html.includes('Người tạo lịch'))
    assert.ok(!html.includes('Xác nhận CSKH'))
    const tableIndex = html.indexOf('<table')
    assert.ok(html.indexOf('Đơn hàng', tableIndex) < html.indexOf('Nguồn đơn', tableIndex))
    assert.ok(html.indexOf('Nguồn đơn', tableIndex) < html.indexOf('Địa chỉ', tableIndex))
    const cells = [...html.matchAll(/<span[^>]*class="[^"]*customer-care-order-page[^"]*"[^>]*>(.*?)<\/span>/g)].map((match) => match[1])
    assert.deepEqual(cells, ['Exact &lt;snapshot&gt;', '—'])
    assert.ok(!html.includes('Facebook') && !html.includes('Zalo'))
  })
}

test('source selector renders disabled without shop, enabled with shop, and accessible error', () => {
  const props = { options: [], query: {}, onChange: () => {} }
  const disabled = renderToStaticMarkup(React.createElement(Filter, props))
  assert.match(disabled, /id="customer-care-order-page"[^>]*disabled=""|disabled=""[^>]*id="customer-care-order-page"/)
  assert.match(disabled, /Chọn cửa hàng trước/)
  const enabled = renderToStaticMarkup(React.createElement(Filter, { ...props, shopId: '34' }))
  assert.doesNotMatch(enabled, /disabled=""/)
  assert.match(enabled, /aria-label="Nguồn đơn"/)
  const error = renderToStaticMarkup(React.createElement(Filter, { ...props, shopId: '34', query: { isError: true } }))
  assert.match(error, /role="alert"/)
})
