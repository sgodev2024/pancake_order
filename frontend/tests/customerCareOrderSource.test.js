import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { QueryClient } from '@tanstack/react-query'
import apiClient from '../src/api/client.js'
import { getCustomerCares } from '../src/api/customer-care.api.js'
import { changeCareFilterShop, createEmptyFilters, normalizeFilters } from '../src/pages/CustomerCare/customerCareFilters.js'
import { careOrderPagesQueryOptions } from '../src/pages/CustomerCare/careOrderPages.js'
import { CUSTOMER_CARE_TYPES } from '../src/constants/customer-care.js'
import { useAuthStore } from '../src/auth/auth.store.js'
import { queryClient } from '../src/app/queryClient.js'

test('care options are disabled without shop and isolated by context, type and shop', async () => {
  const original = apiClient.get
  const calls = []
  apiClient.get = async (...args) => { calls.push(args); return { data: { data: [{ id: 'pzl_695112902870160686', name: 'Exact' }] } } }
  try {
    for (const [pageType, { apiType }] of Object.entries(CUSTOMER_CARE_TYPES)) {
      const missing = careOrderPagesQueryOptions(pageType, undefined)
      assert.equal(missing.enabled, false)
      assert.deepEqual(await missing.queryFn(), [])
      const options = careOrderPagesQueryOptions(pageType, '34')
      assert.deepEqual(options.queryKey, ['order-pages', 'customer_care', pageType, '34'])
      assert.equal(options.placeholderData, undefined)
      assert.equal(options.enabled, true)
      assert.equal((await options.queryFn())[0].id, 'pzl_695112902870160686')
      assert.deepEqual(calls.at(-1), ['/order-pages', { params: { shop_id: '34', context: 'customer_care', type: apiType } }])
    }
    assert.equal(calls.length, 4)
    const client = new QueryClient()
    client.setQueryData(careOrderPagesQueryOptions('today', '34').queryKey, ['old-shop-option'])
    assert.equal(client.getQueryData(careOrderPagesQueryOptions('today', '15').queryKey), undefined)
    assert.equal(client.getQueryData(careOrderPagesQueryOptions('upcoming', '34').queryKey), undefined)
    client.clear()
  } finally { apiClient.get = original }
})

test('apply and pagination preserve exact string ID; changing or clearing shop and reset remove source', async () => {
  const original = apiClient.get
  const calls = []
  apiClient.get = async (...args) => { calls.push(args); return { data: { data: { customers: [] } } } }
  try {
    const draft = {
      ...createEmptyFilters(),
      shopId: '34',
      orderPageId: 'pzl_695112902870160686',
      userId: 'staff',
      search: '  Customer  ',
      dateRange: [
        { format: () => '2026-09-01' },
        { format: () => '2026-09-15' },
      ],
    }
    const applied = normalizeFilters(draft)
    await getCustomerCares({ type: 'customer_care_today', context: 'v2', ...applied, page: 1 })
    await getCustomerCares({ type: 'customer_care_today', context: 'v2', ...applied, page: 2 })
    for (const [, { params }] of calls) {
      assert.equal(params.order_page_id, 'pzl_695112902870160686')
      assert.equal(params.search, 'Customer')
      assert.equal(params.context, 'v2')
      assert.equal(params.date_from, '2026-09-01')
      assert.equal(params.date_to, '2026-09-15')
    }
    assert.equal(calls[1][1].params.page, 2)
    for (const shopId of ['15', undefined]) {
      const changed = changeCareFilterShop(draft, shopId)
      assert.equal(changed.shopId, shopId)
      assert.equal(changed.orderPageId, undefined)
      assert.equal(changed.userId, undefined)
      await getCustomerCares({ ...normalizeFilters(changed), page: 1 })
      assert.equal('order_page_id' in calls.at(-1)[1].params, false)
    }
    assert.equal(normalizeFilters(createEmptyFilters()).order_page_id, undefined)
    assert.equal(normalizeFilters(createEmptyFilters()).date_from, undefined)
    assert.equal(normalizeFilters(createEmptyFilters()).date_to, undefined)
  } finally { apiClient.get = original }
})

test('page renders one V1-aligned filter area and preserves source/apply/reset pagination behavior', () => {
  const source = readFileSync(new URL('../src/pages/CustomerCare/CustomerCarePage.jsx', import.meta.url), 'utf8')
  assert.match(source, /\(\) => \(\{ type: pageMeta\.apiType, context: 'v2', \.\.\.appliedFilters, page \}\)/)
  assert.match(source, /staleTime: 45_000/)
  assert.match(source, /retry: shouldRetryCustomerCareQuery/)
  assert.match(source, /enabled: Boolean\(selectedCare\?\.id\) && selectedCareTab === 'history'/)
  assert.match(source, /enabled: Boolean\(selectedCare\?\.id\) && selectedCareTab === 'orders'/)
  assert.match(source, /<ShopSelect[\s\S]*?useGlobalSelection=\{false\}/)
  const shopHandler = source.slice(source.indexOf('const handleShopChange'), source.indexOf('const applyFilters'))
  assert.match(shopHandler, /changeCareFilterShop/)
  assert.match(shopHandler, /setAppliedFilters[\s\S]*user_id: undefined, order_page_id: undefined/)
  assert.match(shopHandler, /setPage\(1\)/)
  const applyReset = source.slice(source.indexOf('const applyFilters'), source.indexOf('const openCareDrawer'))
  assert.match(applyReset, /normalizeFilters\(draftFilters\)/)
  assert.match(applyReset, /createEmptyFilters\(\)/)
  assert.equal((applyReset.match(/setPage\(1\)/g) ?? []).length, 2)
  const filters = source.slice(source.indexOf('<form className="customer-care-filters"'), source.indexOf('{usersQuery.isError &&'))
  assert.match(filters, /<CustomerCareOrderPageFilter/)
  assert.match(filters, /<RangePicker/)
  assert.doesNotMatch(source, /Bộ lọc nâng cao|Người tạo lịch|advancedOpen|customer-care-advanced-filters/)
  assert.match(source, /scroll=\{\{ x: 'max-content' \}\}/)
  assert.match(source, /fixed: 'right'/)
})

test('CSKH options cache is discarded between admin and staff sessions', () => {
  const key = careOrderPagesQueryOptions('today', '34').queryKey
  useAuthStore.getState().setSession({ token: 'admin-test', user: { id: 1 } })
  queryClient.setQueryData(key, [{ id: 'private', name: 'Admin source' }])
  useAuthStore.getState().clearSession()
  useAuthStore.getState().setSession({ token: 'staff-test', user: { id: 2 } })
  assert.equal(queryClient.getQueryData(key), undefined)
  useAuthStore.getState().clearSession()
})
