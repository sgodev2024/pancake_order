import assert from 'node:assert/strict'
import test, { before } from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import apiClient from '../src/api/client.js'
import { createViettelPostShipment } from '../src/api/warehouse.api.js'
import { shipmentConfirmation } from '../src/pages/Warehouse/shipmentConfirmation.js'
import { aicrmOrderStatuses } from '../src/pages/Warehouse/aicrmOrderStatuses.js'

let ui
before(async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/Warehouse/ViettelPostShipment.jsx', import.meta.url)),
    platform: 'node', transform: { jsx: { runtime: 'automatic' } },
    external: ['react', 'react/jsx-runtime', 'antd'],
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    // Resolve package URLs because the module is loaded from a data URL.
    const code = output[0].code.replace(/from "([^"]+)"/g, (_, id) => `from ${JSON.stringify(import.meta.resolve(id))}`)
    ui = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`)
  } finally { await bundle.close() }
})
const render = (component, props) => renderToStaticMarkup(React.createElement(component, props))
const packed = { id: 12, reference: 'POS-12', warehouse_status: 'packed', can_create_shipment: true }

test('shipment API sends only the order identity and preserves backend outcomes', async () => {
  const original = apiClient.post
  apiClient.post = async (...args) => {
    assert.deepEqual(args, ['/warehouse/orders/12/viettel-post'])
    return { data: { success: true, replayed: false, data: { mode: 'mock', tracking_number: 'MOCK-VTP-12' } } }
  }
  try { assert.equal((await createViettelPostShipment(12)).data.tracking_number, 'MOCK-VTP-12') }
  finally { apiClient.post = original }
})

test('shipment API surfaces errors without retrying the POST', async () => {
  const original = apiClient.post
  let calls = 0
  apiClient.post = async () => { calls++; throw new Error('unknown result') }
  try { await assert.rejects(createViettelPostShipment(12), /unknown result/); assert.equal(calls, 1) }
  finally { apiClient.post = original }
})

test('create button requires processing permission and a packed order', () => {
  assert.equal(render(ui.ShipmentButton, { order: packed, allowed: false }), '')
  assert.equal(render(ui.ShipmentButton, { order: { ...packed, warehouse_status: 'waiting_packing' }, allowed: true }), '')
  const html = render(ui.ShipmentButton, { order: packed, allowed: true, mode: 'mock' })
  assert.match(html, /Tạo vận đơn Viettel Post/)
  assert.match(html, /Mock/)
  assert.doesNotMatch(html, /disabled=""/)
})

test('backend ineligibility and pending requests disable creation', () => {
  for (const props of [{ order: { ...packed, can_create_shipment: false } }, { order: packed, pending: true }]) {
    assert.match(render(ui.ShipmentButton, { ...props, allowed: true, mode: 'mock' }), /disabled=""/)
  }
})

test('rendering and preparing confirmation never send an order; explicit confirmation invokes the action', async () => {
  let calls = 0
  const action = async () => { calls++; return 'created' }
  render(ui.ShipmentButton, { order: packed, allowed: true, mode: 'mock', onCreate: action })
  const dialog = shipmentConfirmation(packed, 'mock_http', action)
  assert.equal(calls, 0)
  assert.match(dialog.title, /POS-12/)
  assert.match(dialog.content, /Mock/)
  assert.equal(dialog.cancelText, 'Kiểm tra lại')
  assert.equal(await dialog.onOk(), 'created')
  assert.equal(calls, 1)
  assert.match(shipmentConfirmation(packed, 'sandbox', action).content, /Sandbox/)
})

test('success status shows the persisted mock tracking number', () => {
  const html = render(ui.ShipmentStatus, { order: { shipment: { status: 'succeeded', mode: 'mock', tracking_number: 'MOCK-VTP-12' } } })
  for (const text of ['Đã tạo vận đơn', 'MOCK-VTP-12', 'Mock']) assert.ok(html.includes(text), text)
})

test('ambiguous and processing outcomes instruct reconciliation; rejection history remains visible', () => {
  for (const status of ['unknown', 'processing']) {
    assert.match(render(ui.ShipmentStatus, { order: { shipment: { status, mode: 'mock' } } }), /đối soát/)
  }
  const html = render(ui.ShipmentStatus, { history: true, order: { shipment: {
    mode: 'mock', status: 'failed', last_error: 'Kiểm tra dữ liệu',
    attempts: [{ id: 1, status: 'failed', http_status: 400, error_message: 'Sai địa chỉ', created_at: '2026-11-04' }],
  } } })
  for (const text of ['Kiểm tra dữ liệu', 'Sai địa chỉ', 'HTTP 400', '2026-11-04']) assert.ok(html.includes(text), text)
})

test('live confirmation clearly identifies a real shipment and never sends without confirmation', () => {
  let calls = 0
  const dialog = shipmentConfirmation(packed, 'live', () => { calls++ })
  assert.match(dialog.content, /VẬN ĐƠN THẬT/)
  assert.match(dialog.content, /người nhận và địa chỉ thật/)
  assert.equal(dialog.okText, 'Xác nhận tạo vận đơn thật')
  assert.equal(calls, 0)
  const html = render(ui.ShipmentButton, { order: packed, allowed: true, mode: 'live' })
  assert.match(html, /Live/)
  assert.doesNotMatch(html, /Mock/)
})

test('persisted live result displays real environment, AICRM identity and response status', () => {
  const html = render(ui.ShipmentStatus, { history: true, order: { shipment: {
    mode: 'live', status: 'succeeded', tracking_number: 'FAKE-TEST-LIVE', remote_order_id: 'remote-test-id', remote_status: 'PENDING',
  } } })
  for (const value of ['Live', 'Vận đơn thật', 'remote-test-id', 'PENDING', 'FAKE-TEST-LIVE']) assert.ok(html.includes(value), value)
  assert.ok(!html.includes('Mock'))
})

test('all eight audited GET statuses display their exact code and Vietnamese label', () => {
  const expected = {
    PENDING: 'Chờ xử lý', PICKING: 'Đang lấy hàng', PROCESSING: 'Đang xử lý', CANCELED: 'Đã hủy',
    SHIPPING: 'Đang vận chuyển', DELIVERED: 'Đã giao hàng', RETURNED: 'Đã hoàn hàng', ERROR: 'Lỗi',
  }
  assert.deepEqual(aicrmOrderStatuses, expected)
  for (const [code, label] of Object.entries(expected)) {
    const html = render(ui.ShipmentStatus, { history: true, order: { shipment: {
      mode: 'live', status: 'succeeded', tracking_number: 'VTP-12', remote_status: code,
    } } })
    for (const value of ['Trạng thái đơn AICRM (GET)', code, label]) assert.ok(html.includes(value), value)
  }
})

test('POST result does not imply a shipping status or fabricate a missing UUID', () => {
  for (const remote_status of [null, 200, '200', 'CANCELLED', 'constructor']) {
    const html = render(ui.ShipmentStatus, { history: true, order: { shipment: {
      mode: 'live', status: 'succeeded', tracking_number: 'VTP-12', remote_status,
    } } })
    assert.match(html, /Đã tạo vận đơn/)
    assert.match(html, /Chưa liên kết UUID đơn AICRM/)
    assert.match(html, /Chưa xác minh trạng thái đơn AICRM qua GET/)
    assert.doesNotMatch(html, /Đang vận chuyển|ID đơn AICRM:|Trạng thái phản hồi AICRM: 200/)
  }
})

test('unknown and processing shipments keep creation disabled', () => {
  for (const status of ['unknown', 'processing']) {
    const html = render(ui.ShipmentButton, { allowed: true, mode: 'live', order: {
      ...packed, can_create_shipment: false, shipment: { status },
    } })
    assert.match(html, /disabled=""/)
  }
})
