import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { test } from 'node:test'

const readSrc = (relativePath) =>
  readFileSync(resolve(process.cwd(), relativePath), 'utf8')

test('index.css declares --filter-control-height matching ant-control-height (32px)', () => {
  const indexCss = readSrc('src/index.css')
  assert.match(
    indexCss,
    /--filter-control-height:\s*var\(--ant-control-height,\s*32px\);/,
    'index.css must define --filter-control-height with 32px fallback',
  )
})

test('layout.css defines shared filter-action-button rules', () => {
  const layoutCss = readSrc('src/styles/layout.css')
  assert.ok(
    layoutCss.includes('.filter-action-button'),
    'layout.css must declare .filter-action-button',
  )
  assert.ok(
    layoutCss.includes('height: var(--filter-control-height, 32px);'),
    'layout.css must enforce height: var(--filter-control-height, 32px)',
  )
  assert.ok(
    layoutCss.includes('min-height: var(--filter-control-height, 32px);'),
    'layout.css must enforce min-height: var(--filter-control-height, 32px)',
  )
})

const filterCssFiles = [
  { name: 'orders.css', path: 'src/pages/Orders/orders.css', actionClass: '.orders-filters__actions' },
  { name: 'order-opportunities.css', path: 'src/pages/Opportunities/order-opportunities.css', actionClass: '.order-opportunities-filters__actions' },
  { name: 'customer-care.css', path: 'src/pages/CustomerCare/customer-care.css', actionClass: '.customer-care-filters__actions' },
  { name: 'customers.css', path: 'src/pages/Customers/customers.css', actionClass: '.customers-filters__actions' },
  { name: 'activity-logs.css', path: 'src/pages/ActivityLogs/activity-logs.css', actionClass: '.activity-filters__actions' },
]

for (const { name, path, actionClass } of filterCssFiles) {
  test(`${name} uses --filter-control-height (32px) and no longer has 40px or 44px min-height`, () => {
    const css = readSrc(path)
    // Must NOT have 40px on filter actions
    assert.doesNotMatch(
      css,
      new RegExp(`${actionClass.replace('.', '\\.')}[^{]*\\{[^}]*min-height:\\s*40px`),
      `${name} must not contain min-height: 40px for ${actionClass}`,
    )
    // Must NOT have 44px on filter actions
    assert.doesNotMatch(
      css,
      new RegExp(`${actionClass.replace('.', '\\.')}[^{]*\\{[^}]*min-height:\\s*44px`),
      `${name} must not contain min-height: 44px for ${actionClass}`,
    )
    // Must contain --filter-control-height
    assert.ok(
      css.includes('var(--filter-control-height, 32px)'),
      `${name} must use var(--filter-control-height, 32px)`,
    )
  })
}

test('order-opportunities-toolbar button (Phân công) is NOT altered to filter button style', () => {
  const oppCss = readSrc('src/pages/Opportunities/order-opportunities.css')
  assert.ok(
    oppCss.includes('.order-opportunities-toolbar .ant-btn'),
    'order-opportunities-toolbar button must remain separate from filter actions',
  )
})

const filterJsxFiles = [
  { name: 'OrdersPage.jsx', path: 'src/pages/Orders/OrdersPage.jsx' },
  { name: 'OrderOpportunitiesPage.jsx', path: 'src/pages/Opportunities/OrderOpportunitiesPage.jsx' },
  { name: 'CustomerCarePage.jsx', path: 'src/pages/CustomerCare/CustomerCarePage.jsx' },
  { name: 'CustomersPage.jsx', path: 'src/pages/Customers/CustomersPage.jsx' },
  { name: 'ActivityLogsPage.jsx', path: 'src/pages/ActivityLogs/ActivityLogsPage.jsx' },
]

for (const { name, path } of filterJsxFiles) {
  test(`${name} attaches filter-action-button class to action buttons`, () => {
    const jsx = readSrc(path)
    const matches = [...jsx.matchAll(/className="filter-action-button"/g)]
    assert.equal(
      matches.length,
      2,
      `${name} must have exactly 2 buttons with className="filter-action-button" (Lọc & Làm mới/Đặt lại)`,
    )
  })
}

