import assert from 'node:assert/strict'
import test from 'node:test'
import { rolldown } from 'rolldown'
import { fileURLToPath } from 'node:url'

test('care segment calendar locale loads and renders Vietnamese month labels', async () => {
  const bundle = await rolldown({
    input: fileURLToPath(new URL('../src/pages/CareSegments/calendarLocale.js', import.meta.url)),
    platform: 'browser',
  })
  try {
    const { output } = await bundle.generate({ format: 'esm' })
    const locale = await import(`data:text/javascript;base64,${Buffer.from(output[0].code).toString('base64')}`)
    assert.equal(locale.monthLocale.lang.locale, 'vi_VN')
    assert.deepEqual(locale.monthLocale.lang.shortMonths, Array.from({length: 12}, (_, i) => `Tháng ${i+1}`))
    assert.equal(locale.dateLocale.lang.placeholder, 'Chọn thời điểm')
  } finally {
    await bundle.close()
  }
})
