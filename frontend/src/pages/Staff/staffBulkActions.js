export const canSelectStaff = (record, user) => String(record.id) !== String(user.id) && record.role?.slug !== 'admin'

export async function runStaffBulkAction(records, action) {
  const succeeded = []
  const failed = []
  for (const record of records) {
    try {
      const result = await action(record)
      if (result?.success === false) throw new Error(result.message || 'Thao tác thất bại.')
      succeeded.push(record.id)
    } catch (error) {
      failed.push({ id: record.id, name: record.name || record.email || String(record.id), error })
    }
  }
  return { succeeded, failed }
}
