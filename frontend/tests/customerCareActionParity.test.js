import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { acceptCustomerCare, markCustomerCareAsCared } from '../src/api/customer-care.api.js'
import { ROUTES } from '../src/constants/routes.js'

const pageSource = readFileSync(
  new URL('../src/pages/CustomerCare/CustomerCarePage.jsx', import.meta.url),
  'utf8',
)
const routerSource = readFileSync(new URL('../src/app/router.jsx', import.meta.url), 'utf8')

test('completion modal has V1 fields, validation, exact payload and reset/refresh behavior', () => {
  assert.match(pageSource, /label: 'Xác nhận đã chăm sóc'/)
  const modal = pageSource.slice(
    pageSource.indexOf('title="Xác nhận đã chăm sóc"'),
    pageSource.indexOf('title="Thu hồi khách hàng?"'),
  )
  assert.match(modal, /label="Thời gian xác nhận"[\s\S]*readOnly/)
  assert.match(modal, /name="note"[\s\S]*label="Nội dung chăm sóc"/)
  assert.match(modal, /required: true, whitespace: true/)
  assert.match(modal, /name="nextDateCare"[\s\S]*label="Ngày chăm sóc tiếp theo"/)
  assert.match(modal, /okText="Cập nhật"/)
  assert.match(modal, /cancelText="Hủy"/)

  const submit = pageSource.slice(
    pageSource.indexOf('const submitCareCompletion'),
    pageSource.indexOf('const openAcceptConfirmation'),
  )
  assert.match(submit, /status: 1/)
  assert.match(submit, /format\('YYYY-MM-DD HH:mm:00'\)/)
  assert.match(submit, /note: values\.note\.trim\(\)/)
  assert.match(submit, /next_date_care: values\.nextDateCare\?\.format\('YYYY-MM-DD'\) \?\? null/)
  assert.match(pageSource, /careCompletionForm\.resetFields\(\)/)
  assert.match(pageSource, /\['customer-care-history', variables\.customerCareId\]/)
})

test('completion and review APIs reuse the existing backend contracts', async () => {
  const originalPut = apiClient.put
  const originalPost = apiClient.post
  const calls = []
  apiClient.put = async (...args) => { calls.push(['put', ...args]); return { data: { success: true } } }
  apiClient.post = async (...args) => { calls.push(['post', ...args]); return { data: { success: true } } }

  try {
    const completion = {
      status: 1,
      date: '2026-09-16 10:30:00',
      note: 'Đã gọi khách hàng',
      next_date_care: null,
    }
    await markCustomerCareAsCared(17, completion)
    await acceptCustomerCare(17, { is_accept: 0, reason: 'Thông tin chưa hợp lệ' })
    assert.deepEqual(calls, [
      ['put', '/customer-cares/17', completion],
      ['post', '/customer-cares/17/accept', { is_accept: 0, reason: 'Thông tin chưa hợp lệ' }],
    ])
  } finally {
    apiClient.put = originalPut
    apiClient.post = originalPost
  }
})

test('reject action requires backend-supported role, permission, accepted state and a trimmed reason', () => {
  const actions = pageSource.slice(
    pageSource.indexOf('const canAccept ='),
    pageSource.indexOf('const canReclaim ='),
  )
  assert.match(actions, /canReviewCustomerCare\(currentUser\)/)
  assert.match(actions, /hasPermission\(currentUser, 'reject-cskh'\)/)
  assert.match(actions, /String\(record\.is_accept\) === '1'/)
  assert.match(pageSource, /label: 'Từ chối sửa CSKH'/)
  assert.match(pageSource, /if \(!reason\.trim\(\)\)/)
  assert.match(pageSource, /payload: \{ is_accept: isAccept, reason: reason\.trim\(\) \}/)
})

test('history uses real care time and order history exposes useful existing fields', () => {
  assert.match(pageSource, /item\.time_care[\s\S]*formatDateTime\(item\.time_care\)/)
  assert.match(pageSource, /dataIndex: 'total_quantity'/)
  assert.match(pageSource, /dataIndex: 'cod'/)
  assert.match(pageSource, /dataIndex: \['user_creator', 'name'\]/)
  assert.match(pageSource, /dataIndex: 'note'/)
})

test('all four customer care routes still map to their intended page type', () => {
  assert.deepEqual(
    [ROUTES.CARE_TODAY, ROUTES.CARE_UPCOMING, ROUTES.CARE_OVERDUE, ROUTES.CARE_FIX_REQUESTS],
    ['/kh-cham-soc-hom-nay', '/lich-cham-soc-sap-dien-ra', '/khach-cham-soc-qua-han', '/yeu-cau-sua-cskh'],
  )
  for (const pageType of ['today', 'upcoming', 'overdue', 'editRequests']) {
    assert.match(routerSource, new RegExp(`pageProps=\\{\\{ pageType: '${pageType}' \\}\\}`))
  }
})
