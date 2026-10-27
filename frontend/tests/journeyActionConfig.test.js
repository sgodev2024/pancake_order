import assert from 'node:assert/strict'
import test from 'node:test'
import { JOURNEY_ACTION_CONFIG, getJourneyActionConfig } from '../src/components/CustomerJourney/journeyActionConfig.js'
import { API_ROUTES } from '../src/constants/routes.js'

test('builds the journey route from the local customer ID', () => {
  assert.equal(API_ROUTES.CUSTOMER_JOURNEY(42), '/customers/42/journey')
})

test('defines the six supported journey actions with Vietnamese labels', () => {
  assert.deepEqual(Object.keys(JOURNEY_ACTION_CONFIG), [
    'customer.entered_system',
    'customer_care.assigned',
    'customer_care.reassigned',
    'customer_care.reclaimed',
    'customer_care.completed',
    'order.created',
  ])
  assert.equal(JOURNEY_ACTION_CONFIG['order.created'].label, 'Phát sinh đơn hàng')
})

test('uses backend care sequence and never an array index', () => {
  const config = getJourneyActionConfig('customer_care.completed')
  assert.equal(config.buildTitle({ metadata: { care_sequence_number: 2 } }), 'Hoàn thành chăm sóc lần 2')
  assert.equal(config.buildTitle({ metadata: {} }), 'Hoàn thành chăm sóc')
})

test('renders reassignment names only when safely available', () => {
  const config = getJourneyActionConfig('customer_care.reassigned')
  assert.equal(
    config.buildDescription({
      target_user: { name: 'Nguyễn Văn B' },
      metadata: { previous_assignee_name: 'Nguyễn Văn A' },
    }),
    'Nguyễn Văn A → Nguyễn Văn B',
  )
  assert.equal(config.buildDescription({ metadata: {} }), 'Đã phân công lại chăm sóc')
})

test('renders a manual reclaim business reason without exposing machine state', () => {
  const config = getJourneyActionConfig('customer_care.reclaimed')
  const description = config.buildDescription({
    metadata: {
      manual_reason: 'Phân công nhầm nhân viên',
      reclaim_reason: 'manual_reclaim',
      reclaim_source: 'manual',
    },
  })

  assert.equal(description, 'Lý do: Phân công nhầm nhân viên')
  assert.doesNotMatch(description, /manual_reclaim|reclaim_source/)
  assert.equal(config.buildDescription({ metadata: { reclaim_reason: 'manual_reclaim' } }), null)
})

test('order-created mapping does not claim a successful purchase', () => {
  const output = `${JOURNEY_ACTION_CONFIG['order.created'].label} ${JOURNEY_ACTION_CONFIG['order.created'].buildDescription({}) || ''}`
  assert.doesNotMatch(output, /Mua hàng thành công|Doanh thu thành công/)
})
