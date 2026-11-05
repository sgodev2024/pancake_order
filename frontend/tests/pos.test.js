import assert from 'node:assert/strict'
import test from 'node:test'
import { calculatePosPaymentTotals, calculatePosTotals, centsToAmount, optionalMoneyCents, POS_PAYMENT_OPTIONS, toCents } from '../src/pages/Pos/posTotals.js'
import { formatMoneyInput, isValidMoneyInput, parseMoneyInput } from '../src/pages/Pos/posMoneyInput.js'
import { canManageProducts, canUsePos, filterMenuByPermissions } from '../src/auth/permissions.js'
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
test('optional adjustments accept blank/null/undefined as zero and reject malformed money', () => {
  for (const empty of ['', '  ', null, undefined]) {
    assert.equal(optionalMoneyCents(empty), 0)
    const result = calculatePosPaymentTotals(cart, { discount_amount: empty, shipping_fee: empty, surcharge: empty })
    assert.equal(result.total, 50000000)
    assert.equal(result.paid, 0)
    assert.equal(result.cod, result.total)
  }
  for (const invalid of [-1, 'abc', '1,50', '0.001', false, [], 1000000000000]) {
    assert.ok(calculatePosPaymentTotals(cart, { surcharge: invalid }).error, String(invalid))
  }
})
test('cash and transfer stay unpaid until receipt confirmation; COD always remains uncollected', () => {
  assert.equal(POS_PAYMENT_OPTIONS.length, 4)
  const adjustments = { discount_amount: 10000, shipping_fee: 30000, surcharge: 5000 }
  for (const mode of ['cash', 'bank_transfer']) {
    const before = calculatePosPaymentTotals(cart, { ...adjustments, payment_mode: mode })
    assert.equal(before.total, 52500000)
    assert.equal(before.paid, 0)
    assert.equal(before.remaining, 52500000)
    assert.equal(before.cod, 0)
    const confirmed = calculatePosPaymentTotals(cart, { ...adjustments, payment_mode: mode }, true)
    assert.equal(confirmed.paid, confirmed.total)
    assert.equal(confirmed.remaining, 0)
    assert.equal(confirmed.cod, 0)
  }
  const cod = calculatePosPaymentTotals(cart, { ...adjustments, payment_mode: 'cod' }, true)
  assert.equal(cod.paid, 0)
  assert.equal(cod.remaining, cod.total)
  assert.equal(cod.cod, cod.total)
  assert.equal(centsToAmount(70), '0.70')
  assert.ok(calculatePosPaymentTotals(cart, { payment_mode: 'invalid' }).error)
})
test('raw localized money does not silently discard invalid characters, signs or precision', () => {
  for (const invalid of ['abc', '1abc', '-1', '1.2', '1,234', '1,,2']) assert.equal(isValidMoneyInput(invalid), false, invalid)
  for (const valid of ['', '1.234.567,89', '100.000 đ', '0,50', '1234']) assert.equal(isValidMoneyInput(valid), true, valid)
  assert.equal(parseMoneyInput('1abc'), '1abc')
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
test('staff and manager CSKH use explicit POS grants; admin still needs actual read grants', () => {
  const user = (slug, permissions) => ({ role: { slug, permissions: permissions.map((slug) => ({ slug })) } })
  for (const slug of ['staff-cskh', 'manager-cskh']) {
    assert.equal(canUsePos(user(slug, ['pos-create', 'list-product', 'list-customer'])), true)
    assert.equal(canUsePos(user(slug, ['list-product', 'list-customer'])), false)
  }
  for (const permissions of [[], ['list-product'], ['list-customer']]) {
    assert.equal(canUsePos(user('admin', permissions)), false)
  }
  for (const slug of ['staff-sale', 'staff-cskh']) {
    assert.equal(canManageProducts(user(slug, ['list-product'])), false)
  }
  assert.equal(canManageProducts(user('manager-cskh', ['list-product'])), true)
  assert.equal(canManageProducts(user('manager-cskh', [])), false)
  assert.equal(canManageProducts(user('admin', ['list-product'])), true)
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

test('partial bank transfer collects only the balance as COD after receipt confirmation', () => {
  const fields = { payment_mode: 'bank_transfer_cod', shipping_fee: 30000, deposit_amount: 200000 }
  const totals = calculatePosPaymentTotals(cart, fields, true)
  assert.equal(totals.total, 53000000)
  assert.equal(totals.paid, 20000000)
  assert.equal(totals.cod, 33000000)
  assert.equal(totals.remaining, 33000000)
  assert.equal(calculatePosPaymentTotals(cart, fields).cod, 33000000)
  for (const deposit_amount of [undefined, 0, -1, 530000, 600000, 'abc', '1.234']) {
    assert.ok(calculatePosPaymentTotals(cart, { ...fields, deposit_amount }, true).error)
  }
})
