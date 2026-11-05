import assert from 'node:assert/strict'
import test, { afterEach, before } from 'node:test'
import { fileURLToPath } from 'node:url'
import React, { act } from 'react'
import { create } from 'react-test-renderer'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { rolldown } from 'rolldown'
import apiClient from '../src/api/client.js'
import { shopQueryKeys } from '../src/api/shops.api.js'
import { dialogs, messages } from './fixtures/posUi.js'

globalThis.IS_REACT_ACT_ENVIRONMENT = true
const storage = new Map()
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, value),
  removeItem: (key) => storage.delete(key),
}
const { useAuthStore } = await import('../src/auth/auth.store.js')
const { useShopStore } = await import('../src/stores/shop.store.js')
const actor = (id, slug = 'staff-sale') => ({ id, role: { slug, permissions: ['list-product', 'list-customer', 'pos-create'].map((slug) => ({ slug })) } })
const shopA = { id: 7, name: 'Shop A được phân công' }
const shopB = { id: 22, name: 'Shop B được phân công' }
const allRequiredValues = { customer_name: 'TEST', customer_phone: '0900000000', shipping_address: 'TEST', discount_amount: 0, shipping_fee: 0, surcharge: 0, paid_amount: 0, payment_method: 'cash', cod_mode: 'remaining' }
const originals = { get: apiClient.get, post: apiClient.post }
let Page
let renderer
let client
let shops
let getShops
let getProducts
let calls
let posts

before(async () => {
  const uiUrl = new URL('./fixtures/posUi.js', import.meta.url).href
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/Pos/PosPage.jsx', import.meta.url)),
    platform: 'node', transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'pos-real-lifecycle',
      resolveId(id) {
        if (id.endsWith('.css')) return '\0style'
        if (id === 'antd' || id === '@ant-design/icons') return { id: uiUrl, external: true }
        if (id === './client.js' || id.endsWith('/api/client.js')) return { id: new URL('../src/api/client.js', import.meta.url).href, external: true }
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

afterEach(async () => {
  if (renderer) await act(async () => renderer.unmount())
  renderer = undefined
  client?.clear()
  client = undefined
  apiClient.get = originals.get
  apiClient.post = originals.post
  useAuthStore.setState({ user: null, token: null })
  dialogs.length = 0
  messages.length = 0
})

const settle = async () => {
  for (let i = 0; i < 6; i++) await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)) })
}
const mount = async (user = actor(101), options = {}) => {
  shops = options.shops ?? [shopA]
  getShops = options.getShops ?? (async () => shops)
  getProducts = options.getProducts
  calls = []
  posts = []
  useAuthStore.setState({ user, token: `test-session-${user.id}` })
  client = new QueryClient({ defaultOptions: { queries: { retry: false, retryDelay: 0, gcTime: 0 } } })
  if (options.cachedShops) client.setQueryData(shopQueryKeys.posOptions(user.id), options.cachedShops)
  apiClient.get = async (url, config = {}) => {
    const userId = useAuthStore.getState().user?.id
    calls.push({ url, userId, ...config })
    if (url === '/shops') return { data: { data: await getShops() } }
    const id = Number(config.params?.shop_id)
    if (url === '/products') return { data: { data: getProducts ? await getProducts(userId, id, config.signal) : { products: [{ id: id * 10, name: `product-${userId}-${id}`, price: 1000 }], total_items: 1 } } }
    if (url === '/customers') return { data: { data: { customers: [{ id: userId * 100, name: `customer-${userId}`, phone: '0900000000', address: 'TEST' }] } } }
    if (url.startsWith('/orders/')) return { data: { data: { id: 99, origin: 'local_pos', reference: 'POS-99', totals: {}, items: [] } } }
    throw new Error(`Unexpected GET ${url}`)
  }
  apiClient.post = async (...args) => { posts.push(args); throw new Error('No order should be submitted by shop selection') }
  await act(async () => {
    renderer = create(React.createElement(options.strict ? React.StrictMode : React.Fragment, {},
      React.createElement(QueryClientProvider, { client },
        React.createElement(MemoryRouter, { initialEntries: [options.url ?? '/ban-hang'] }, React.createElement(Page)))))
  })
  await settle()
}
const select = () => renderer.root.findAll((node) => node.type === 'select-control' && node.props['aria-label'] === 'Chọn cửa hàng')[0]
const form = () => renderer.root.findByType('form-control')
const submitButton = () => renderer.root.findAll((node) => node.type === 'button-control' && node.props.htmlType === 'submit')[0]
const cartRows = () => renderer.root.findAll((node) => node.type === 'div' && node.props.className === 'pos-cart-row')
const dataCalls = () => calls.filter((call) => ['/products', '/customers'].includes(call.url))
const pickShop = async (id) => { await act(async () => select().props.onChange(id)); await settle() }
const addProduct = async () => {
  const product = renderer.root.findAll((node) => node.type === 'button' && node.props.className === 'pos-product')[0]
  await act(async () => product.props.onClick())
}
const changeList = async (next) => {
  shops = next
  await act(async () => { await client.invalidateQueries({ queryKey: shopQueryKeys.posOptions(useAuthStore.getState().user.id) }) })
  await settle()
}

test('0 shops displays the assignment message and never loads data or creates an order', async () => {
  await mount(actor(101), { shops: [] })
  assert.equal(select().props.value, undefined)
  assert.ok(renderer.root.findAllByType('empty-control').some((node) => node.props.description === 'Bạn chưa được phân công cửa hàng để bán hàng'))
  assert.equal(submitButton().props.disabled, true)
  await act(async () => form().props.onFinish(allRequiredValues))
  assert.deepEqual(dataCalls(), [])
  assert.deepEqual(posts, [])
})

for (const role of ['staff-sale', 'staff-cskh', 'admin']) {
  test(`${role} with one shop automatically selects it and immediately loads both datasets`, async () => {
    await mount(actor(102, role))
    assert.equal(select().props.value, '7')
    assert.equal(select().props.options[0].label, shopA.name)
    assert.equal(select().props.allowClear, false)
    assert.deepEqual(dataCalls().map((call) => [call.url, call.params.shop_id]), [['/products', '7'], ['/customers', '7']])
    assert.equal(calls.filter((call) => call.url === '/shops').length, 1)
    assert.equal(posts.length, 0)
  })
}

test('Admin with multiple shops keeps the placeholder until a manual selection', async () => {
  useShopStore.setState({ selectedShopId: '7' })
  await mount(actor(103, 'admin'), { shops: [shopA, shopB] })
  assert.equal(select().props.placeholder, 'Chọn cửa hàng')
  assert.equal(select().props.value, undefined)
  assert.deepEqual(dataCalls(), [])
  await pickShop('22')
  assert.equal(select().props.value, '22')
  assert.deepEqual(dataCalls().map((call) => call.params.shop_id), ['22', '22'])
  assert.equal(posts.length, 0)
})

test('switching shops confirms first and resets cart, customer, address and adjustments', async () => {
  await mount(actor(104), { shops: [shopA, shopB] })
  await pickShop('7')
  await addProduct()
  await act(async () => form().props.form.setFieldsValue({ customer_id: 10400, shipping_address: 'old address', note: 'old note', discount_amount: 500 }))
  await pickShop('22')
  assert.equal(select().props.value, '7')
  assert.equal(cartRows().length, 1)
  assert.equal(dialogs.length, 1)
  const previousSubmit = form().props.onFinish
  await act(async () => dialogs[0].onOk())
  await settle()
  assert.equal(select().props.value, '22')
  assert.equal(cartRows().length, 0)
  assert.equal(form().props.form.getFieldsValue().customer_id, undefined)
  assert.equal(form().props.form.getFieldsValue().shipping_address, undefined)
  assert.equal(form().props.form.getFieldsValue().note, undefined)
  assert.equal(form().props.form.getFieldsValue().discount_amount, 0)
  await act(async () => previousSubmit(allRequiredValues))
  assert.equal(posts.length, 0)
})

test('removing access to a selected shop clears its draft and auto-selects the remaining single shop', async () => {
  await mount(actor(105), { shops: [shopA, shopB] })
  await pickShop('7')
  await addProduct()
  const previousSubmit = form().props.onFinish
  await changeList([shopB])
  assert.equal(select().props.value, '22')
  assert.equal(cartRows().length, 0)
  assert.equal(submitButton().props.disabled, true)
  assert.equal(client.getQueriesData({ queryKey: ['products', 'pos', 105, '7'] }).length, 0)
  assert.equal(client.getQueriesData({ queryKey: ['customers', 'pos', 105, '7'] }).length, 0)
  await act(async () => previousSubmit(allRequiredValues))
  assert.equal(posts.length, 0)
})

test('one-to-multiple and then zero shops clears selection and never reuses the former cart', async () => {
  await mount(actor(106))
  await addProduct()
  await changeList([shopA, shopB])
  assert.equal(select().props.value, undefined)
  assert.equal(cartRows().length, 0)
  await pickShop('22')
  await addProduct()
  const before = dataCalls().length
  await changeList([])
  assert.equal(select().props.value, undefined)
  assert.equal(cartRows().length, 0)
  assert.equal(dataCalls().length, before)
  assert.equal(posts.length, 0)
})

test('slow shop responses never choose or load products/customers before successful completion', async () => {
  let resolve
  await mount(actor(107), { getShops: () => new Promise((done) => { resolve = done }) })
  assert.equal(select().props.value, undefined)
  assert.equal(select().props.disabled, true)
  assert.deepEqual(dataCalls(), [])
  await act(async () => { resolve([shopB]) })
  await settle()
  assert.equal(select().props.value, '22')
  assert.equal(dataCalls().length, 2)
  assert.equal(posts.length, 0)
})

test('cached one-shop data is not used until entry revalidation finishes with the real list', async () => {
  let resolve
  await mount(actor(108), { cachedShops: [shopA], getShops: () => new Promise((done) => { resolve = done }) })
  assert.equal(select().props.value, undefined)
  assert.deepEqual(dataCalls(), [])
  await act(async () => { resolve([shopA, shopB]) })
  await settle()
  assert.equal(select().props.value, undefined)
  assert.deepEqual(dataCalls(), [])
})

test('failed shop API blocks data/orders and a successful retry can auto-select', async () => {
  let fail = true
  await mount(actor(109), { getShops: async () => { if (fail) throw new Error('Shop API unavailable'); return [shopB] } })
  assert.equal(select().props.status, 'error')
  assert.equal(select().props.value, undefined)
  assert.deepEqual(dataCalls(), [])
  assert.equal(submitButton().props.disabled, true)
  await act(async () => form().props.onFinish(allRequiredValues))
  assert.equal(posts.length, 0)
  fail = false
  await act(async () => { await client.refetchQueries({ queryKey: shopQueryKeys.posOptions(109) }) })
  await settle()
  assert.equal(select().props.value, '22')
  assert.equal(dataCalls().length, 2)
})

test('background scope revalidation blocks checkout, then restores the same valid draft', async () => {
  await mount(actor(110))
  await addProduct()
  let resolve
  getShops = () => new Promise((done) => { resolve = done })
  let pending
  await act(async () => { pending = client.invalidateQueries({ queryKey: shopQueryKeys.posOptions(110) }) })
  await settle()
  assert.equal(submitButton().props.disabled, true)
  await act(async () => form().props.onFinish(allRequiredValues))
  assert.equal(posts.length, 0)
  await act(async () => { resolve([shopA]); await pending })
  await settle()
  assert.equal(select().props.value, '7')
  assert.equal(cartRows().length, 1)
  assert.equal(submitButton().props.disabled, false)
})

test('switching actors sharing a shop clears cart, private customers and late responses', async () => {
  let resolveOldProducts
  await mount(actor(111), { getProducts: (id) => id === 111
    ? new Promise((done) => { resolveOldProducts = done })
    : Promise.resolve({ products: [{ id: 999, name: 'new actor product', price: 1000 }], total_items: 1 }) })
  const oldSubmit = form().props.onFinish
  const oldSignal = dataCalls().find((call) => call.url === '/products').signal
  await act(async () => useAuthStore.setState({ user: actor(112, 'staff-cskh'), token: 'new-test-session' }))
  await settle()
  await act(async () => resolveOldProducts({ products: [{ id: 888, name: 'old actor product', price: 1 }], total_items: 1 }))
  await settle()
  assert.equal(oldSignal.aborted, true)
  assert.equal(select().props.value, '7')
  assert.equal(cartRows().length, 0)
  const names = renderer.root.findAll((node) => node.type === 'span' && node.props.className === 'pos-product-name').map((node) => node.children.join(''))
  assert.deepEqual(names, ['new actor product'])
  const customers = renderer.root.findAll((node) => node.type === 'select-control' && node.props.placeholder === 'Tìm theo tên hoặc số điện thoại')[0]
  assert.ok(customers.props.options.every((option) => option.label.startsWith('customer-112')))
  await act(async () => oldSubmit(allRequiredValues))
  assert.equal(posts.length, 0)
})

test('logout/login never inherits a former employee selection, including a shared global filter', async () => {
  await mount(actor(113))
  await addProduct()
  useShopStore.setState({ selectedShopId: '7' })
  await act(async () => useAuthStore.setState({ user: null, token: null }))
  shops = [shopA, shopB]
  await act(async () => useAuthStore.setState({ user: actor(114, 'admin'), token: 'admin-test-session' }))
  await settle()
  assert.equal(select().props.value, undefined)
  assert.equal(cartRows().length, 0)
  assert.equal(submitButton().props.disabled, true)
  assert.equal(posts.length, 0)
})

test('permission loss clears the draft; delayed confirmation cannot resurrect it', async () => {
  await mount(actor(115), { shops: [shopA, shopB] })
  await pickShop('7')
  await addProduct()
  await pickShop('22')
  const dialog = dialogs[0]
  await act(async () => useAuthStore.setState({ user: { id: 115, role: { slug: 'staff-sale', permissions: [] } } }))
  assert.equal(dialog.destroyed, true)
  await act(async () => dialog.onOk())
  await act(async () => useAuthStore.setState({ user: actor(115) }))
  await settle()
  assert.equal(select().props.value, undefined)
  assert.equal(cartRows().length, 0)
  assert.equal(posts.length, 0)
})

test('StrictMode still selects a single shop without submitting anything', async () => {
  await mount(actor(116), { strict: true })
  assert.equal(select().props.value, '7')
  await addProduct()
  assert.equal(cartRows().length, 1)
  assert.equal(posts.length, 0)
})

test('reading an existing POS order does not fetch shops or start a new sales draft', async () => {
  await mount(actor(117), { url: '/ban-hang?order_id=99' })
  assert.deepEqual(calls.map((call) => call.url), ['/orders/99'])
  assert.equal(posts.length, 0)
})
