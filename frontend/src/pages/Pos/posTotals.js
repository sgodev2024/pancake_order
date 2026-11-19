import { MAX_VND, VND_INPUT_ERROR } from './posMoneyInput.js'

// UI estimates in whole VND; the order API validates prices and calculates authoritative totals.
export const toVnd = (value) => {
  if (typeof value !== 'number' && typeof value !== 'string') return null
  if (!/^\d{1,12}$/.test(String(value))) return null
  const amount = Number(value)
  return Number.isSafeInteger(amount) && amount <= MAX_VND ? amount : null
}

// Catalog/model values may retain a decimal storage suffix; never round a fractional price.
export const productPriceVnd = (value) => typeof value === 'string' ? toVnd(value.replace(/\.0{1,2}$/, '')) : toVnd(value)

export const calculatePosTotals = (items, adjustments = {}) => {
  const amounts = ['discount_amount', 'shipping_fee', 'surcharge'].map((key) => optionalMoneyVnd(adjustments[key]))
  amounts.push(toVnd(adjustments.paid_amount ?? 0))
  if (amounts.some((amount) => amount === null)) return { error: VND_INPUT_ERROR }
  const [discount, shipping, surcharge, paid] = amounts
  let subtotal = 0
  for (const item of items) {
    const price = productPriceVnd(item.unit_price)
    if (price === null || !Number.isInteger(item.quantity) || item.quantity < 1) return { error: 'Kiểm tra lại sản phẩm và số lượng.' }
    subtotal += price * item.quantity
  }
  if (!Number.isSafeInteger(subtotal) || subtotal > MAX_VND) return { error: 'Giá trị đơn vượt giới hạn.' }
  if (discount > subtotal) return { error: 'Giảm giá không được vượt tiền hàng.' }
  const total = subtotal - discount + shipping + surcharge
  if (total > MAX_VND) return { error: 'Giá trị đơn vượt giới hạn.' }
  if (paid > total) return { error: 'Tiền đã trả không được vượt tổng thanh toán.' }
  return { subtotal, discount, shipping, surcharge, total, paid, remaining: total - paid,
    cod: adjustments.cod_mode === 'none' ? 0 : total - paid }
}

export const optionalMoneyVnd = (value) => toVnd(value == null || (typeof value === 'string' && value.trim() === '') ? 0 : value)

export const POS_PAYMENT_OPTIONS = [
  { value: 'cash', label: 'Tiền mặt — đã thu đủ' },
  { value: 'bank_transfer', label: 'Chuyển khoản — đã nhận đủ' },
  { value: 'bank_transfer_cod', label: 'Chuyển khoản một phần + COD' },
  { value: 'cod', label: 'Thu hộ COD — thu khi giao hàng' },
]

// Cash/transfer stay unpaid in the preview until the operator explicitly confirms receipt.
export const calculatePosPaymentTotals = (items, fields = {}, confirmed = false) => {
  const mode = fields.payment_mode ?? 'cod'
  if (!POS_PAYMENT_OPTIONS.some((option) => option.value === mode)) return { error: 'Chọn hình thức thanh toán hợp lệ.' }
  const totals = calculatePosTotals(items, { ...fields, paid_amount: 0, cod_mode: 'remaining' })
  if (totals.error) return totals
  const deposit = optionalMoneyVnd(fields.deposit_amount)
  if (mode === 'bank_transfer_cod' && (deposit === null || deposit <= 0 || deposit >= totals.total)) {
    return { error: 'Tiền chuyển khoản một phần phải lớn hơn 0 và nhỏ hơn tổng thanh toán.' }
  }
  const paid = mode === 'bank_transfer_cod' ? deposit : (mode !== 'cod' && confirmed ? totals.total : 0)
  return { ...totals, paid, remaining: totals.total - paid, cod: ['cod', 'bank_transfer_cod'].includes(mode) ? totals.total - paid : 0 }
}

export const money = (value) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(Number(value ?? 0)) + ' đ'
