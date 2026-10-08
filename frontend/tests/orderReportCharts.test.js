import assert from 'node:assert/strict'
import { before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { getOrderSalesChartData } from '../src/api/orders.api.js'

const loadComponent = async (file) => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL(file, import.meta.url)),
    platform: 'node',
    transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'node-render-test',
      resolveId(id) {
        if (id.endsWith('.css')) return '\0empty-style'
        if (!id.startsWith('.') && !id.startsWith('/') && !/^[A-Za-z]:/.test(id)) {
          return { id: import.meta.resolve(id), external: true }
        }
      },
      load(id) { if (id === '\0empty-style') return 'export default {}' },
    }],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    return (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
  } finally { await bundle.close() }
}

let OrderSummaryChart
let ShopProductSalesChart

before(async () => {
  OrderSummaryChart = await loadComponent('../src/pages/Orders/OrderSummaryChart.jsx')
  ShopProductSalesChart = await loadComponent('../src/pages/Reports/ShopProductSalesChart.jsx')
})

test('CASE 1 [Regression Fix]: OrderSummaryChart does NOT throw when periodMetrics is undefined', () => {
  // Before the fix, accessing periodMetrics.prepaid?.difference threw: Cannot read properties of undefined (reading 'prepaid')
  assert.doesNotThrow(() => {
    const html = renderToStaticMarkup(
      React.createElement(OrderSummaryChart, {
        createdAmount: 150000000,
        successAmount: 120000000,
        successCount: 150,
        prepaidAmount: 30000000,
        breakdown: [
          { key: 'success', label: 'Đã nhận', count: 120, amount: 100000000 },
          { key: 'shipping', label: 'Đang giao', count: 30, amount: 20000000 },
        ],
        periodMetrics: undefined, // specifically undefined as passed from OrderReportPage
        loading: false,
        error: false,
      })
    )
    assert.ok(html.includes('Thanh toán trả trước'))
    assert.ok(html.includes('30,00 tr'))
  })
})

test('CASE 2: OrderSummaryChart renders with periodMetrics provided', () => {
  assert.doesNotThrow(() => {
    const html = renderToStaticMarkup(
      React.createElement(OrderSummaryChart, {
        createdAmount: 200000000,
        successAmount: 180000000,
        successCount: 200,
        prepaidAmount: 50000000,
        periodMetrics: {
          revenue: { current: 200000000, previous: 150000000, difference: 50000000, percent: 33.3 },
          orders: { current: 200, previous: 150, difference: 50, percent: 33.3 },
          quantity: { current: 300, previous: 250, difference: 50, percent: 20 },
          success_amount: { current: 180000000, previous: 140000000, difference: 40000000, percent: 28.5 },
          success_orders: { current: 180, previous: 140, difference: 40, percent: 28.5 },
          prepaid: { current: 50000000, previous: 40000000, difference: 10000000, percent: 25 },
        },
        loading: false,
        error: false,
      })
    )
    assert.ok(html.includes('Thanh toán trả trước'))
    assert.ok(html.includes('50,00 tr'))
  })
})

test('CASE 3: OrderSummaryChart handles loading and error states gracefully', () => {
  const loadingHtml = renderToStaticMarkup(
    React.createElement(OrderSummaryChart, { loading: true })
  )
  assert.ok(loadingHtml.includes('…'))

  const errorHtml = renderToStaticMarkup(
    React.createElement(OrderSummaryChart, { error: true })
  )
  assert.ok(errorHtml.includes('Chưa tính được số liệu đơn hàng'))
})

test('CASE 4: ShopProductSalesChart renders champion banner, product comparisons and rankings', () => {
  const mockData = {
    from: '2026-08-01',
    to: '2026-08-17',
    total_revenue: 1182614000,
    total_quantity: 432,
    total_products: 2,
    top_pairings: [
      {
        shop_id: 11,
        shop_name: 'Hương Chất',
        product_name: 'Canxi váng sữa',
        revenue: 357120000,
        quantity: 147,
        orders_count: 102,
      },
      {
        shop_id: 34,
        shop_name: 'Hương Chất Home',
        product_name: 'Nghệ nano',
        revenue: 201240000,
        quantity: 158,
        orders_count: 100,
      },
    ],
    products: [
      {
        product_name: 'Canxi váng sữa',
        total_revenue: 961894000,
        total_quantity: 380,
        total_orders: 260,
        top_shop: {
          shop_id: 11,
          shop_name: 'Hương Chất',
          revenue: 357120000,
          quantity: 147,
          orders_count: 102,
          share_percent: 37.1,
        },
        shops: [
          {
            shop_id: 11,
            shop_name: 'Hương Chất',
            revenue: 357120000,
            quantity: 147,
            orders_count: 102,
            share_percent: 37.1,
          },
          {
            shop_id: 13,
            shop_name: 'CANXI MY',
            revenue: 171120000,
            quantity: 69,
            orders_count: 55,
            share_percent: 17.8,
          },
        ],
      },
    ],
  }

  const html = renderToStaticMarkup(
    React.createElement(ShopProductSalesChart, {
      data: mockData,
      loading: false,
      error: false,
    })
  )

  // Verify Champion Banner
  assert.ok(html.includes('QUÁN QUÂN DOANH SỐ TOÀN HỆ THỐNG'))
  assert.ok(html.includes('Hương Chất'))
  assert.ok(html.includes('Canxi váng sữa'))

  // Verify Top 1 Shop badge and comparative stats
  assert.ok(html.includes('Top 1 Doanh số'))
  assert.ok(html.includes('37.1% thị phần'))
  assert.ok(html.includes('CANXI MY'))
})

test('CASE 5: ShopProductSalesChart handles empty data and loading states properly', () => {
  const loadingHtml = renderToStaticMarkup(
    React.createElement(ShopProductSalesChart, { loading: true })
  )
  assert.ok(loadingHtml.includes('shop-sales-loading'))

  const emptyHtml = renderToStaticMarkup(
    React.createElement(ShopProductSalesChart, { data: { products: [] } })
  )
  assert.ok(emptyHtml.includes('Chưa có dữ liệu bán hàng'))

  const errorHtml = renderToStaticMarkup(
    React.createElement(ShopProductSalesChart, { error: true })
  )
  assert.ok(errorHtml.includes('Không thể tải dữ liệu biểu đồ'))
})

test('CASE 6: getOrderSalesChartData exists and is a function', () => {
  assert.equal(typeof getOrderSalesChartData, 'function')
})

