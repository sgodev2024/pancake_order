import assert from 'node:assert/strict'
import test, { afterEach, before } from 'node:test'
import React, { act } from 'react'
import { create } from 'react-test-renderer'
import { rolldown } from 'rolldown'
import { fileURLToPath } from 'node:url'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import apiClient from '../src/api/client.js'
import { dialogs, messages } from './fixtures/posUi.js'

globalThis.IS_REACT_ACT_ENVIRONMENT = true
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} }
const { useAuthStore } = await import('../src/auth/auth.store.js')
let Page, renderer, client
const originals = { get: apiClient.get, post: apiClient.post }
const packed = { id: 349323, reference: 'POS-349323', shop_id: 38, shop: { name: 'TEST Shop 38' },
  warehouse_status: 'packed', items: [], can_create_shipment: true, can_simulate_shipment: true }
before(async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/Warehouse/WarehousePage.jsx', import.meta.url)),
    platform: 'node', transform: { jsx: { runtime: 'automatic' } },
    plugins: [{ name: 'warehouse-click-lifecycle', resolveId(id) {
      if (id.endsWith('.css')) return '\0empty'
      if (id === 'antd' || id === '@ant-design/icons') return { id: new URL('./fixtures/warehouseUi.js', import.meta.url).href, external: true }
      if (id.endsWith('/ShopSelect.jsx')) return '\0shop'
      if (id.endsWith('/OrderHistoryModal.jsx')) return '\0products'
      if (id.endsWith('/QueryErrorAlert.jsx')) return '\0error'
      if (id === './client.js' || id.endsWith('/api/client.js')) return { id: new URL('../src/api/client.js', import.meta.url).href, external: true }
      if (id.endsWith('/auth/auth.store.js')) return { id: new URL('../src/auth/auth.store.js', import.meta.url).href, external: true }
      if (!id.startsWith('.') && !id.startsWith('/')) return { id: import.meta.resolve(id), external: true }
    }, load(id) {
      if (id === '\0empty') return 'export default {}'
      if (id === '\0products') return 'export const ProductCell = () => null'
      if (['\0shop', '\0error'].includes(id)) return 'export default () => null'
    } }],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    Page = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
  } finally { await bundle.close() }
})
afterEach(async () => {
  if (renderer) await act(async () => renderer.unmount())
  renderer = null
  client?.clear()
  apiClient.get = originals.get
  apiClient.post = originals.post
  useAuthStore.setState({ user: null, token: null })
  dialogs.length = 0
  messages.length = 0
})
const settle = async () => {
  for (let i = 0; i < 6; i++) await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
}
const mount = async (order) => {
  useAuthStore.setState({ user: { id: 637, role: { slug: 'warehouse-test', permissions: ['warehouse-view', 'warehouse-pack'].map((slug) => ({ slug })) } }, token: 'fake-session' })
  apiClient.get = async () => ({ data: { data: { orders: [order], total_items: 1, module: { enabled: true }, shipping: { mode: 'live' } } } })
  client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } } })
  await act(async () => { renderer = create(React.createElement(QueryClientProvider, { client }, React.createElement(Page))) })
  await settle()
  await act(async () => renderer.root.findByType('tabs-control').props.onChange('packed'))
  await settle()
}
const button = (text) => renderer.root.findAllByType('button-control').find((node) => node.children.join('').includes(text))
const visibleText = () => {
  const read = (node) => typeof node === 'string' ? node : Array.isArray(node) ? node.map(read).join(' ') : (node?.children ?? []).map(read).join(' ')
  return read(renderer.toJSON())
}

test('actual warehouse click opens one dialog, POSTs only ID once, shows loading and saved ORDER_NUMBER', async () => {
  const order = { ...packed }
  const posts = []
  let resolvePost
  apiClient.post = (...args) => { posts.push(args); return new Promise((resolve) => { resolvePost = resolve }) }
  await mount(order)
  await act(async () => { button('Tạo vận đơn Viettel Post').props.onClick(); button('Tạo vận đơn Viettel Post').props.onClick() })
  assert.equal(dialogs.length, 1)
  assert.equal(posts.length, 0)
  let first, second
  await act(async () => { first = dialogs[0].onOk(); second = dialogs[0].onOk(); await Promise.resolve() })
  await settle()
  assert.equal(first, second)
  assert.deepEqual(posts, [['/warehouse/orders/349323/viettel-post']])
  assert.equal(button('Tạo vận đơn Viettel Post').props.loading, true)
  assert.equal(button('Tạo vận đơn Viettel Post').props.disabled, true)
  order.can_create_shipment = false
  order.can_simulate_shipment = false
  order.shipment = { mode: 'live', status: 'succeeded', tracking_number: 'FAKE-ORDER-NUMBER' }
  await act(async () => { resolvePost({ data: { success: true, message: 'Đã tạo vận đơn', data: order.shipment } }); await first })
  await settle()
  assert.ok(messages.some(([type, text]) => type === 'success' && text.includes('FAKE-ORDER-NUMBER')))
  assert.ok(visibleText().includes('FAKE-ORDER-NUMBER'))
})

test('disabled Live explains remedies while separate TEST/Mock action succeeds without a fake tracking number', async () => {
  const order = { ...packed, can_create_shipment: false, shipment_blockers: [{ code: 'provider_not_ready',
    message: 'Provider Viettel Post đang tắt.', remedy: 'Kiểm tra token tại AICRM.' }] }
  const posts = []
  apiClient.post = async (...args) => {
    posts.push(args)
    return { data: { success: true, message: 'TEST/Mock: không tạo shipment hoặc mã vận đơn.', data: { order_id: 349323, mode: 'simulation', tracking_number: null } } }
  }
  await mount(order)
  assert.equal(button('Tạo vận đơn Viettel Post').props.disabled, true)
  assert.ok(visibleText().includes('Kiểm tra token tại AICRM.'))
  await act(async () => button('Mô phỏng TEST/Mock').props.onClick())
  assert.match(dialogs[0].content, /Không gọi AICRM/)
  await act(async () => dialogs[0].onOk())
  await settle()
  assert.deepEqual(posts, [['/warehouse/orders/349323/viettel-post?simulate=1']])
  assert.ok(renderer.root.findAllByType('alert-control').some((node) => node.props.title === 'TEST/Mock · POS-349323'))
  assert.equal(order.shipment, undefined)
})

test('unknown result refreshes eligibility, shows reconciliation error and never retries POST', async () => {
  const order = { ...packed }
  let posts = 0
  apiClient.post = async () => {
    posts++
    order.can_create_shipment = false
    order.can_simulate_shipment = false
    order.shipment = { mode: 'live', status: 'unknown' }
    throw { response: { status: 502, data: { message: 'Timeout AICRM. Cần đối soát, không gửi lại.' } } }
  }
  await mount(order)
  await act(async () => button('Tạo vận đơn Viettel Post').props.onClick())
  await act(async () => Promise.all([dialogs[0].onOk(), dialogs[0].onOk()]))
  await settle()
  assert.equal(posts, 1)
  assert.equal(button('Tạo vận đơn Viettel Post').props.disabled, true)
  assert.ok(messages.some(([type, text]) => type === 'error' && text.includes('đối soát')))
})
