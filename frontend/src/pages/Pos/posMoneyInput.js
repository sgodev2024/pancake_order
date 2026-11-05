export const formatMoneyInput = (value, { userTyping = false, input } = {}) => {
  if (userTyping && typeof input === 'string') return input

  if (value === null || value === undefined || value === '') return ''

  const [integer, fraction] = String(value).split('.')
  const groupedInteger = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')

  return fraction === undefined ? groupedInteger : `${groupedInteger},${fraction}`
}

const inputText = (value) => String(value ?? '').trim().replace(/\s*[đ₫]$/u, '').trim()
const MONEY_INPUT = /^(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?$/

export const isValidMoneyInput = (value) => inputText(value) === '' || MONEY_INPUT.test(inputText(value))

export const parseMoneyInput = (value) => {
  const text = inputText(value)
  if (text === '') return ''
  // Do not silently turn malformed input such as "1abc" or "1.2" into a different amount.
  if (!/^-?(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?$/.test(text)) return text
  return text.replace(/\./g, '').replace(',', '.')
}

// The partial-payment field groups digits while editing, including incomplete
// groups created by inserting or deleting digits in an already formatted value.
export const formatLiveMoneyInput = (value, { userTyping = false, input } = {}) => {
  // Format the edit buffer, not InputNumber's precision-padded numeric value.
  // Preserve an unfinished decimal separator and let InputNumber track the caret.
  if (userTyping && typeof input === 'string') {
    const raw = input.trim().replace(/\s*[đ₫]$/u, '')
    if (raw === '') return ''
    if (!/^\d[\d.]*(?:,\d{0,2})?$/.test(raw)) return input
    const [whole, decimal] = raw.split(',')
    const grouped = whole.replace(/\./g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')
    return decimal === undefined ? grouped : `${grouped},${decimal}`
  }

  return formatMoneyInput(value)
}

export const parseLiveMoneyInput = (value) => {
  const text = inputText(value)
  if (text === '') return ''
  if (!/^\d[\d.]*(?:,\d{0,2})?$/.test(text)) return text
  return text.replace(/\./g, '').replace(',', '.')
}
