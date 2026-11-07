import test from 'node:test'
import assert from 'node:assert/strict'
import { canSelectStaff, runStaffBulkAction } from '../src/pages/Staff/staffBulkActions.js'
test('protects self and admin accounts', () => {
  assert.equal(canSelectStaff({ id: '1', role: { slug: 'staff-cskh' } }, { id: 1 }), false)
  assert.equal(canSelectStaff({ id: 2, role: { slug: 'admin' } }, { id: 1 }), false)
  assert.equal(canSelectStaff({ id: 3, role: { slug: 'staff-cskh' } }, { id: 1 }), true)
})
test('keeps per-account failures and continues without retrying successful actions', async () => {
  const calls = []
  const records = [1, 2, 3, 4].map(id => ({ id, name: `Staff ${id}` }))
  const result = await runStaffBulkAction(records, async ({ id }) => {
    calls.push(id)
    if (id === 2) throw new Error('Có lịch sử nghiệp vụ')
    return id === 3 ? { success: false, message: 'Không có quyền' } : { success: true }
  })
  assert.deepEqual(calls, [1, 2, 3, 4])
  assert.deepEqual(result.succeeded, [1, 4])
  assert.deepEqual(result.failed.map(r => r.id), [2, 3])
  assert.equal(result.failed[0].error.message, 'Có lịch sử nghiệp vụ')
  assert.equal(result.failed[1].error.message, 'Không có quyền')
})
