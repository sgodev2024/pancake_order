export const formatMoneyInput = (value, { userTyping = false, input } = {}) => {
  // Keep the raw edit buffer and caret stable. InputNumber can supply a value
  // already padded to precision=2 while the operator is still typing.
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
