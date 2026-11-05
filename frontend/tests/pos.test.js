import assert from 'node:assert/strict'
import test from 'node:test'
import { calculatePosTotals, toCents } from '../src/pages/Pos/posTotals.js'
import { formatMoneyInput, parseMoneyInput } from '../src/pages/Pos/posMoneyInput.js'
import { canUsePos, filterMenuByPermissions } from '../src/auth/permissions.js'
import { MENU_ITEMS } from '../src/constants/menu.js'
import { ROUTES } from '../src/constants/routes.js'
import apiClient from '../src/api/client.js'
import { createOrder, getOrder } from '../src/api/orders.api.js'

const cart = [{ product_id: 1, unit_price: 250000, quantity: 2 }]

test('POS money inputs use Vietnamese thousands and decimal separators', () => {
  assert.equal(formatMoneyInput(100000), '100.000')
  assert.equal(formatMoneyInput('1234567.89'), '1.234.567,89')
  assert.equal(formatMoneyInput(''), '')
  assert.equal(parseMoneyInput('1.234.567,89'), '1234567.89')
  assert.equal(parseMoneyInput('100.000 đ'), '100000')
})

test('500000 goods + 30000 shipping - 200000 paid yields 330000 remaining and expected COD', () => {
  const result = calculatePosTotals(cart, { shipping_fee: 30000, paid_amount: 200000 })
  assert.equal(result.total, 53000000)
  assert.equal(result.remaining, 33000000)
  assert.equal(result.cod, 33000000)
})
test('discount, surcharge and full payment use exact decimal cents', () => {
  const result = calculatePosTotals([{ unit_price: '0.10', quantity: 3 }], {
    discount_amount: '0.10', shipping_fee: '0.20', surcharge: '0.30', paid_amount: '0.70',
  })
  assert.equal(result.total, 70)
  assert.equal(result.remaining, 0)
  assert.equal(result.cod, 0)
  assert.equal(toCents('0.10'), 10)
})
test('no-COD does not mark remaining money as received', () => {
  const result = calculatePosTotals(cart, { paid_amount: 200000, cod_mode: 'none' })
  assert.equal(result.remaining, 30000000)
  assert.equal(result.cod, 0)
  assert.equal(result.paid, 20000000)
})
test('negative money, overpayment, excessive discount and unsafe total are invalid', () => {
  for (const adjustment of [{ paid_amount: -1 }, { paid_amount: 500001 }, { discount_amount: 500001 }, { shipping_fee: '0.001' }]) {
    assert.ok(calculatePosTotals(cart, adjustment).error)
  }
  assert.ok(calculatePosTotals([{ unit_price: 999999999999, quantity: 1000000 }]).error)
})
test('quantity 100 at displayed virtual stock 5 is valid without reducing source stock', () => {
  const line = { product_id: 1, unit_price: 10, quantity: 100, virtual_inventory_quantity: 5, inventory_quantity: 0 }
  const before = structuredClone(line)
  assert.equal(calculatePosTotals([line]).total, 100000)
  assert.deepEqual(line, before)
})
test('POS access uses existing sales roles and both existing permissions', () => {
  const role = (slug, permissions = ['list-product', 'list-customer']) => ({ role: { slug, permissions: permissions.map((slug) => ({ slug })) } })
  for (const slug of ['admin', 'staff-sale', 'manager-sale']) {
    assert.equal(canUsePos(role(slug)), true)
    assert.ok(!filterMenuByPermissions(MENU_ITEMS, role(slug)).some((item) => item.key === ROUTES.POS))
  }
  assert.equal(canUsePos(role('staff-cskh')), false)
  assert.equal(canUsePos(role('staff-sale', ['list-product'])), false)
  assert.equal(canUsePos(null), false)
})
test('custom POS role needs explicit create permission and both read permissions', () => {
  const role = (slugs) => ({ role: { slug: 'sales-custom', permissions: slugs.map((slug) => ({ slug })) } })
  const required = ['pos-create', 'list-product', 'list-customer']
  assert.equal(canUsePos(role(required)), true)
  assert.ok(!filterMenuByPermissions(MENU_ITEMS, role(required)).some((item) => item.key === ROUTES.POS))
  for (const missing of required) assert.equal(canUsePos(role(required.filter((slug) => slug !== missing))), false)
})
test('createOrder forwards the same idempotency key on retry; detail uses the existing resource API', async () => {
  const originals = { post: apiClient.post, get: apiClient.get }
  const calls = []
  const payload = { idempotency_key: '12d7cb56-89d1-42d5-abd9-d96f1155fc87', shop_id: 11, items: cart }
  apiClient.post = async (url, body) => { calls.push([url, body]); return { data: { success: true, data: { id: 9 } } } }
  apiClient.get = async (url) => { assert.equal(url, '/orders/9'); return { data: { data: { id: 9, origin: 'local_pos' } } } }
  try {
    await createOrder(payload); await createOrder(payload)
    assert.deepEqual(calls, [['/orders', payload], ['/orders', payload]])
    assert.deepEqual(await getOrder(9), { id: 9, origin: 'local_pos' })
  } finally { apiClient.post = originals.post; apiClient.get = originals.get }
})
