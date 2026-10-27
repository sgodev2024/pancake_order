import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { getOrderOpportunities } from '../src/api/opportunities.api.js'
import { getOrderPages } from '../src/api/orders.api.js'
import apiClient from '../src/api/client.js'
import {
  changeOpportunityFilterShop,
  createEmptyOpportunityFilters,
  getOpportunityOrderPageOptions,
  normalizeOpportunityFilters,
} from '../src/pages/Opportunities/orderOpportunityFilters.js'

const fakeDate = (value) => ({ format: () => value })

test('opportunity filters preserve page IDs as strings and clear them with the shop', () => {
  const filters = {
    ...createEmptyOpportunityFilters(),
    shopId: '33',
    orderPageId: 'pzl_695112902870160686',
    phone: ' 0912345678 ',
    dateRange: [fakeDate('2026-09-01'), fakeDate('2026-09-15')],
  }

  assert.deepEqual(normalizeOpportunityFilters(filters), {
    shop_id: '33',
    phone: '0912345678',
    order_page_id: 'pzl_695112902870160686',
    date_from: '2026-09-01',
    date_to: '2026-09-15',
  })
  assert.equal(typeof normalizeOpportunityFilters(filters).order_page_id, 'string')
  assert.deepEqual(changeOpportunityFilterShop(filters, '44'), {
    ...filters,
    shopId: '44',
    orderPageId: undefined,
  })
  assert.equal(normalizeOpportunityFilters(createEmptyOpportunityFilters()).order_page_id, undefined)
  assert.deepEqual(getOpportunityOrderPageOptions([{ id: 12, name: 'Page' }]), [
    { value: '12', label: 'Page' },
  ])
})

test('opportunity page options use their scoped context and pagination keeps the applied filter', async () => {
  const originalGet = apiClient.get
  const requests = []
  apiClient.get = async (...args) => {
    requests.push(args)
    return args[0].endsWith('order-pages')
      ? { data: { data: [{ id: 'pzl_695112902870160686', name: 'PZL Page' }] } }
      : { data: { data: { orders: [], total_items: 0 } } }
  }

  try {
    const pages = await getOrderPages({ shop_id: '33', context: 'opportunity' })
    await getOrderOpportunities({
      ...normalizeOpportunityFilters({
        ...createEmptyOpportunityFilters(),
        shopId: '33',
        orderPageId: pages[0].id,
        phone: '1234',
      }),
      page: 2,
    })

    assert.deepEqual(requests[0][1].params, { shop_id: '33', context: 'opportunity' })
    assert.deepEqual(requests[1][1].params, {
      shop_id: '33',
      phone: '1234',
      order_page_id: 'pzl_695112902870160686',
      page: 2,
    })
  } finally {
    apiClient.get = originalGet
  }
})

test('OrderOpportunitiesPage wires the source column and dependent filter safely', () => {
  const source = readFileSync(
    new URL('../src/pages/Opportunities/OrderOpportunitiesPage.jsx', import.meta.url),
    'utf8',
  )
  const codeColumn = source.indexOf("title: 'Mã đơn'")
  const sourceColumn = source.indexOf("dataIndex: 'order_page_name'")
  const dateColumn = source.indexOf("title: 'Ngày tạo'")

  assert.ok(codeColumn >= 0 && codeColumn < sourceColumn && sourceColumn < dateColumn)
  assert.match(source, /record\.order_page_name \?\? '—'/)
  assert.doesNotMatch(source, /order_source_name/)
  assert.match(source, /context: 'opportunity'/)
  assert.match(source, /enabled: canView && Boolean\(draftFilters\.shopId\)/)
  assert.match(source, /disabled=\{!draftFilters\.shopId\}/)

  const optionsQuery = source.slice(source.indexOf('const orderPagesQuery'), source.indexOf('const orderPageOptions'))
  assert.doesNotMatch(optionsQuery, /placeholderData|keepPreviousData/)

  const shopChange = source.slice(source.indexOf('const handleShopChange'), source.indexOf('const applyFilters'))
  assert.match(shopChange, /order_page_id: undefined/)
  assert.match(shopChange, /setPage\(1\)/)
  assert.match(shopChange, /clearSelection\(\)/)
})
