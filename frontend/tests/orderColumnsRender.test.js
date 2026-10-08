import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { after, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { App } from 'antd'
import { MemoryRouter } from 'react-router-dom'

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

let OrdersPage
let client

before(async () => {
  OrdersPage = await loadComponent('../src/pages/Orders/OrdersPage.jsx')
  client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  client.setQueryData(['orders', {
    shop_id: undefined,
    order_page_id: undefined,
    search: '',
    date_from: undefined,
    date_to: undefined,
    status: undefined,
    page: 1,
    page_size: 30,
  }], {
    orders: [
      {
        id: 1,
        pancake_order_id: 'ORDER-1',
        created_at: '2026-09-14 08:00:00',
        status: 3,
        cod: 0,
        cash: 0,
        prepaid_amount: 445000,
        customer_name: 'Customer',
        customer_phone: '+84901234567',
        customer_address: 'A very long address for the compact order cell',
        province_name: 'Ho Chi Minh',
        note: 'A very long delivery note for the compact order cell',
      },
      {
        id: 2,
        pancake_order_id: 'ORDER-2',
        created_at: '2026-09-14 08:00:00',
        status: 3,
        cod: 0,
        cash: 0,
        prepaid_amount: 100000,
        customer_name: 'Empty fields',
        customer_phone: null,
        customer_address: null,
        province_name: null,
        note: null,
      },
    ],
    total_items: 2,
  })
})

after(() => client?.clear())

test('keeps private order fields out of the table and provides a detail action', () => {
  const html = renderToStaticMarkup(React.createElement(QueryClientProvider, { client },
    React.createElement(MemoryRouter, {}, React.createElement(App, {}, React.createElement(OrdersPage))),
  ))

  assert.match(html, /M\u1edf thao t\u00e1c \u0111\u01a1n h\u00e0ng ORDER-1/)
  assert.match(html, /T\u1ed5ng gi\u00e1 tr\u1ecb \(VND\)/)
  assert.match(html, /Kh\u00e1ch \u0111\u00e3 tr\u1ea3 \(VND\)/)
  assert.match(html, /445\.000/)
  assert.match(html, /100\.000/)
  assert.ok(!html.includes('445.000 đ'))
  assert.ok(!html.includes('100.000 đ'))
  assert.ok(!html.includes('Tạo lúc'))
  assert.ok(!html.includes('Nhân viên tạo'))
  assert.ok(!html.includes('Số điện thoại'))
  assert.ok(!html.includes('Địa chỉ'))
  assert.ok(!html.includes('Ghi chú'))
  assert.ok(!html.includes('+84901234567'))
  assert.ok(!html.includes('A very long address for the compact order cell'))
  assert.ok(!html.includes('A very long delivery note for the compact order cell'))
  assert.ok(!html.includes('Th\u00e0nh ph\u1ed1'))
  assert.ok(!html.includes('Tráº¡ng thÃ¡i VTP'))

  const source = readFileSync(fileURLToPath(new URL('../src/pages/Orders/OrdersPage.jsx', import.meta.url)), 'utf8')
  assert.match(source, /label: 'Xem chi ti\u1ebft'/)
  assert.match(source, /icon={<MoreOutlined \/>}/)
  for (const field of ['created_at', 'user_creator?.name', 'customer_phone', 'customer_address', 'note', 'prepaid_amount']) {
    assert.ok(source.includes(`selectedOrder.${field}`), `detail modal should render ${field}`)
  }
})
