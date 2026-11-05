import assert from 'node:assert/strict'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { rolldown } from 'rolldown'
import React from 'react'
import { create, act } from 'react-test-renderer'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import dayjs from 'dayjs'
import apiClient from '../src/api/client.js'

globalThis.IS_REACT_ACT_ENVIRONMENT = true
test('selected sources survive month confirmation, applying and repeating the filter', async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/CareSegments/CareSegmentsPage.jsx', import.meta.url)),
    platform: 'node',
    transform: { jsx: { runtime: 'automatic' } },
    plugins: [{
      name: 'segment-ui',
      resolveId(id) {
        if (id === 'dayjs/locale/vi') return { id: import.meta.resolve('dayjs/locale/vi.js'), external: true }
        if (id.endsWith('/calendarLocale.js') || id === './calendarLocale.js') return '\0calendar-test'
        if (id === 'antd') return fileURLToPath(new URL('./fixtures/segmentUi.js', import.meta.url))
        if (id.endsWith('/auth/auth.store.js')) return '\0segment-user'
        if (id.endsWith('/api/client.js')) return { id: new URL('../src/api/client.js', import.meta.url).href, external: true }
        if (!id.startsWith('.') && !id.startsWith('/') && !id.startsWith('\0')) return { id: import.meta.resolve(id), external: true }
      },
      load(id) {
        if (id === '\0calendar-test') return 'export const monthLocale = {}; export const dateLocale = {}'
        if (id === '\0segment-user') return "export const useAuthStore = (select) => select({user: {id: 1, role: {slug: 'admin', permissions: []}}})"
      },
    }],
  })
  let renderer
  const original = apiClient.get
  const requests = []
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    const Page = (await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)).default
    apiClient.get = async (url, config) => {
      requests.push({ url, params: config?.params })
      return { data: { data: url === '/shops' ? [{ id: 39, name: 'Shop' }]
        : { customers: [], total: 0, products: [], sources: [{ id: 'source-a', name: 'Source A' }], unmatched_orders: 0 } } }
    }
    const settle = async () => act(async () => { await new Promise((resolve) => setTimeout(resolve, 20)) })
    await act(async () => {
      renderer = create(React.createElement(QueryClientProvider, { client },
        React.createElement(MemoryRouter, {}, React.createElement(Page))))
    })
    await settle()
    const source = () => renderer.root.findAllByType('segment-select').find((node) => node.props['aria-label'] === 'Nguồn đơn Lọc CSKH')
    await act(async () => source().props.onChange(['source-a']))
    await act(async () => renderer.root.findByType('segment-months').props.onChange([dayjs('2026-08-01'), dayjs('2026-09-01')]))
    await settle()
    assert.deepEqual(source().props.value, ['source-a'])
    const filterButton = () => renderer.root.findAllByType('segment-button').find((node) => node.children.includes('Lọc danh sách'))
    await act(async () => filterButton().props.onClick())
    await settle()
    assert.deepEqual(source().props.value, ['source-a'])
    const reports = () => requests.filter((request) => request.url === '/care-segments' && !request.params.sources_only)
    assert.deepEqual(reports().at(-1).params.order_page_ids, ['source-a'])
    assert.equal(reports().at(-1).params.month_from, '2026-08')
    const count = reports().length
    await act(async () => filterButton().props.onClick())
    await settle()
    assert.ok(reports().length > count)
    assert.deepEqual(reports().at(-1).params.order_page_ids, ['source-a'])
  } finally {
    if (renderer) await act(async () => renderer.unmount())
    client.clear()
    apiClient.get = original
    await bundle.close()
  }
})
