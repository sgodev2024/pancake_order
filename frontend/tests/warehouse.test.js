import assert from 'node:assert/strict'
import test, { after, before } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { App } from 'antd'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import apiClient from '../src/api/client.js'
import { completePacking, getWarehouseOrder, getWarehouseOrders } from '../src/api/warehouse.api.js'
import { canPackOrders, canViewWarehouse, filterMenuByPermissions } from '../src/auth/permissions.js'
import { useAuthStore } from '../src/auth/auth.store.js'
import { MENU_ITEMS } from '../src/constants/menu.js'
import { ROUTES } from '../src/constants/routes.js'

const actor = (permissions, slug = 'warehouse-test') => ({ role: { slug, permissions: permissions.map((slug) => ({ slug })) } })
test('warehouse requires explicit view/pack permissions even for admin', () => {
  for (const role of ['admin', 'staff-sale', 'staff-cskh', 'warehouse-test']) {
    assert.equal(canViewWarehouse(actor([], role)), false)
    assert.equal(canPackOrders(actor(['warehouse-pack'], role)), false)
    assert.equal(canViewWarehouse(actor(['warehouse-view'], role)), true)
    assert.equal(canPackOrders(actor(['warehouse-view'], role)), false)
    assert.equal(canPackOrders(actor(['warehouse-view', 'warehouse-pack'], role)), true)
  }
  assert.equal(canViewWarehouse(null), false)
  assert.ok(!filterMenuByPermissions(MENU_ITEMS, actor([], 'admin')).some((item) => item.key === ROUTES.WAREHOUSE))
})
test('warehouse API sends exact shop/status/page filters without empty values', async () => {
  const original = apiClient.get
  apiClient.get = async (url, options) => {
    assert.equal(url, '/warehouse/orders')
    assert.deepEqual(options.params, { shop_id: 11, status: 'packed', page: 2, page_size: 20 })
    return { data: { data: { orders: [], module: { enabled: false } } } }
  }
  try { assert.equal((await getWarehouseOrders({ shop_id: 11, status: 'packed', search: '', page: 2, page_size: 20 })).module.enabled, false) }
  finally { apiClient.get = original }
})
test('detail and complete only identify the order and never send money/stock modifications', async () => {
  const original = { get: apiClient.get, post: apiClient.post }
  const order = { id: 12, warehouse_status: 'packed', packed_by_user_id: 3 }
  apiClient.get = async (url) => { assert.equal(url, '/warehouse/orders/12'); return { data: { data: order } } }
  apiClient.post = async (...args) => { assert.deepEqual(args, ['/warehouse/orders/12/complete']); return { data: { success: true, replayed: true, data: order } } }
  try { assert.deepEqual(await getWarehouseOrder(12), order); assert.equal((await completePacking(12)).replayed, true) }
  finally { apiClient.get = original.get; apiClient.post = original.post }
})

let Page
const clients = []
before(async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/Warehouse/WarehousePage.jsx', import.meta.url)),
    platform: 'node', transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'warehouse-real-render',
      resolveId(id) {
        if (id.endsWith('.css')) return '\0style'
        if (id.endsWith('/auth/auth.store.js')) return { id: new URL('../src/auth/auth.store.js', import.meta.url).href, external: true }
        if (!id.startsWith('.') && !id.startsWith('/')) return { id: import.meta.resolve(id), external: true }
      },
      load(id) { if (id === '\0style') return 'export default {}' },
    }],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    Page = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
  } finally { await bundle.close() }
})
after(() => clients.forEach((client) => client.clear()))
const render = (permissions, data) => {
  const previous = useAuthStore.getState().user
  const initialSnapshot = useAuthStore.getInitialState()
  const previousServerUser = initialSnapshot.user
  initialSnapshot.user = actor(permissions)
  useAuthStore.setState({ user: actor(permissions) })
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  clients.push(client)
  client.setQueryData(['warehouse-orders', 'waiting_packing', {}, 1], data)
  client.setQueryData(['shops', 'options'], [{ id: 11, name: 'Shop A TEST' }])
  try {
    return renderToStaticMarkup(React.createElement(QueryClientProvider, { client }, React.createElement(App, {}, React.createElement(Page))))
  } finally { useAuthStore.setState({ user: previous }); initialSnapshot.user = previousServerUser }
}
const data = { orders: [
  { id: 1, reference: 'POS-1', origin: 'local_pos', shop: { name: 'Shop A TEST' }, customer_name: 'Local TEST', total_quantity: 100, items: [{ name: 'Sản phẩm TEST', quantity: 100 }], warehouse_status: 'waiting_packing' },
  { id: 2, reference: 'source-2', origin: 'pancake', shop: { name: 'Shop A TEST' }, customer_name: 'Pancake TEST', total_quantity: 1, items: [] },
], module: { enabled: true, activated_at: '2026-10-21T00:00:00+07:00', configuration_valid: true }, total_items: 2 }
test('real warehouse page renders both sources, two tabs, products and explicit completion actions', () => {
  const html = render(['warehouse-view', 'warehouse-pack'], data)
  for (const text of ['Chờ đóng hàng', 'Đã đóng hàng', 'POS-1', 'Local', 'Pancake', 'Sản phẩm TEST']) assert.ok(html.includes(text), text)
  // The list action is "Hoàn tất"; "Hoàn tất đóng hàng" appears in its confirmation dialog.
  assert.match(html, /<span>Hoàn tất<\/span>/)
  assert.ok(!html.includes('WAREHOUSE_ACTIVATED_AT'))
})
test('view-only user can inspect but has no packing action', () => {
  const html = render(['warehouse-view'], data)
  assert.ok(html.includes('POS-1'))
  assert.doesNotMatch(html, /<span>Hoàn tất<\/span>/)
})
test('disabled warehouse displays clear status and does not show existing sample orders', () => {
  const html = render(['warehouse-view', 'warehouse-pack'], { orders: [], total_items: 0, module: { enabled: false, activated_at: null, configuration_valid: true } })
  assert.ok(html.includes('Module Kho đang tắt'))
  assert.ok(!html.includes('POS-1'))
  assert.doesNotMatch(html, /<span>Hoàn tất<\/span>/)
})
test('user without view permission receives the real 403 page', () => {
  const html = render([], data)
  assert.ok(html.includes('Bạn chưa có quyền Kho'))
  assert.ok(!html.includes('POS-1'))
})
