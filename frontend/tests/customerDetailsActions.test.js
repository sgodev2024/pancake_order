import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const customersPage = readFileSync(
  new URL('../src/pages/Customers/CustomersPage.jsx', import.meta.url),
  'utf8',
)
const customerJourneyDrawer = readFileSync(
  new URL('../src/components/CustomerJourney/CustomerJourneyDrawer.jsx', import.meta.url),
  'utf8',
)

test('customer table keeps selected details out of columns and exposes them in the action drawer', () => {
  const tableColumns = customersPage.slice(
    customersPage.indexOf('const columns = useMemo'),
    customersPage.indexOf('if (permissionDenied)'),
  )
  for (const heading of ['Ngày tạo', 'SL đơn hàng', 'Hạng thành viên', 'Giảm giá']) {
    assert.equal(tableColumns.includes(`title: '${heading}'`), false, `${heading} should not be a table column`)
  }

  assert.match(tableColumns, /icon={<MoreOutlined \/>}/)
  assert.match(tableColumns, /label: 'Thông tin khách hàng'/)
  assert.match(tableColumns, /label: 'Hành trình khách hàng'/)
  assert.match(customersPage, /title=\{`Thông tin khách hàng/)
  assert.match(customersPage, /formatDateTime\(customerInfo\.created_at\)/)
  assert.match(customersPage, /customerInfo\.order_count/)
  assert.match(customersPage, /customerInfo\.loyalty_tier\?\.name/)
  assert.match(customersPage, /customerInfo\.loyalty_tier\?\.discount_percent/)
  assert.doesNotMatch(customerJourneyDrawer, /Thông tin khách hàng/)
})
