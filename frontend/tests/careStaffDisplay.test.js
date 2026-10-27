import assert from 'node:assert/strict'
import test from 'node:test'
import { getCareStaffDisplay } from '../src/pages/CustomerCare/careStaffDisplay.js'

const assignment = (overrides = {}) => ({
  customer_care_id: 10,
  status: 'active',
  cared_at: null,
  assignee: { name: 'Trang Cháy' },
  ...overrides,
})

test('uses the uncared current assignment for staff display', () => {
  const display = getCareStaffDisplay({
    id: 10,
    status: 0,
    current_assignment_ambiguous: false,
    current_assignment: assignment(),
  })

  assert.equal(display.kind, 'current')
  assert.equal(display.assignment.assignee.name, 'Trang Cháy')
})

test('uses a cared active assignment for completed-care display only', () => {
  const display = getCareStaffDisplay({
    id: 10,
    status: 1,
    current_assignment_ambiguous: false,
    current_assignment: null,
    active_assignment: assignment({ cared_at: '2026-08-26T10:19:00.000000Z' }),
  })

  assert.equal(display.kind, 'completed')
  assert.equal(display.assignment.assignee.name, 'Trang Cháy')
})

test('does not use a reclaimed assignment as care staff', () => {
  const display = getCareStaffDisplay({
    id: 10,
    status: 0,
    current_assignment_ambiguous: false,
    current_assignment: null,
    active_assignment: assignment({ status: 'reclaimed' }),
  })

  assert.equal(display.kind, 'none')
})

test('keeps legacy staff separate from assignment ownership', () => {
  const display = getCareStaffDisplay({
    id: 10,
    status: 0,
    current_assignment_ambiguous: false,
    current_assignment: null,
    user_care: { name: 'Trang Cháy' },
  })

  assert.deepEqual(display, { kind: 'legacy', name: 'Trang Cháy' })
})

test('fails closed when active assignments are ambiguous', () => {
  const display = getCareStaffDisplay({
    id: 10,
    status: 1,
    current_assignment_ambiguous: true,
    current_assignment: null,
    active_assignment: assignment({ cared_at: '2026-08-26T10:19:00.000000Z' }),
  })

  assert.deepEqual(display, { kind: 'ambiguous' })
})
