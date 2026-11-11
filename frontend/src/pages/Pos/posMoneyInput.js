export const MAX_VND = 999999999999
export const VND_INPUT_ERROR = 'Nhập số tiền VND nguyên, không âm (tối đa 999.999.999.999 đ).'

const inputText = (value) => String(value ?? '').trim().replace(/\s*[đ₫]$/u, '').trim()
const MONEY_INPUT = /^(?:\d{1,3}(?:\.\d{3})+|\d+)$/

export const isValidMoneyInput = (value) => {
  const text = inputText(value)
  return text === '' || (MONEY_INPUT.test(text) && text.replace(/\./g, '').length <= 12)
}

export const parseMoneyInput = (value) => {
  const text = inputText(value)
  // A dot is only a thousands separator. Never coerce malformed input or fractions.
  return isValidMoneyInput(value) ? text.replace(/\./g, '').replace(/^0+(?=\d)/, '') : String(value ?? '')
}

export const formatMoneyInput = (value) => {
  const text = String(value ?? '')
  return /^\d+$/.test(text) ? text.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : text
}

// Existing separators can temporarily be ungrouped while inserting/deleting digits.
// Only normalize those edits; pasted amounts must have valid Vietnamese grouping.
export const editMoneyInput = (previous, text, caret, inputType = '') => {
  let start = 0
  while (start < previous.length && start < text.length && previous[start] === text[start]) start++
  let end = 0
  while (end < previous.length - start && end < text.length - start
    && previous[previous.length - 1 - end] === text[text.length - 1 - end]) end++
  const inserted = text.slice(start, text.length - end)
  const digitEdit = !inputType.includes('Paste') && !inputType.includes('Drop')
    && isValidMoneyInput(previous) && /^\d*$/.test(inserted) && /^[\d.]*$/.test(text)
  const removed = previous.slice(start, previous.length - end)
  if (digitEdit && inserted === '' && removed === '.' && inputType.startsWith('deleteContent')) {
    // Delete the adjacent digit too, so Delete/Backspace never gets stuck on a separator.
    const index = inputType === 'deleteContentBackward' ? caret - 1 : caret
    text = text.slice(0, index) + text.slice(index + 1)
    if (inputType === 'deleteContentBackward') caret = Math.max(0, caret - 1)
  }
  const digitsText = text.replace(/\./g, '')
  const value = digitEdit ? digitsText.replace(/^0+(?=\d)/, '') : parseMoneyInput(text)
  const display = formatMoneyInput(value)
  if (!/^\d*$/.test(value)) return { value, display, caret }
  const leadingZeros = /^\d+$/.test(digitsText) ? digitsText.length - value.length : 0
  const digitsBefore = Math.max(0, text.slice(0, caret).replace(/\D/g, '').length - leadingZeros)
  let position = 0
  let digits = 0
  while (position < display.length && digits < digitsBefore) {
    if (/\d/.test(display[position])) digits++
    position++
  }
  return { value, display, caret: position }
}
