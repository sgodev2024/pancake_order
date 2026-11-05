// UI estimates only; the order API validates prices and calculates authoritative totals.
export const toCents = (value) => {
  const text = String(value ?? 0)
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}

export const calculatePosTotals = (items, adjustments = {}) => {
  const amounts = ['discount_amount', 'shipping_fee', 'surcharge'].map((key) => optionalMoneyCents(adjustments[key]))
  amounts.push(toCents(adjustments.paid_amount))
  if (amounts.some((amount) => amount === null)) return { error: 'Nhập số tiền hợp lệ, tối đa 2 chữ số thập phân.' }
  const [discount, shipping, surcharge, paid] = amounts
  let subtotal = 0
  for (const item of items) {
    const price = toCents(item.unit_price)
    if (price === null || !Number.isInteger(item.quantity) || item.quantity < 1) return { error: 'Kiểm tra lại sản phẩm và số lượng.' }
    subtotal += price * item.quantity
  }
  if (!Number.isSafeInteger(subtotal) || subtotal > 99999999999999) return { error: 'Giá trị đơn vượt giới hạn.' }
  if (discount > subtotal) return { error: 'Giảm giá không được vượt tiền hàng.' }
  const total = subtotal - discount + shipping + surcharge
  if (total > 99999999999999) return { error: 'Giá trị đơn vượt giới hạn.' }
  if (paid > total) return { error: 'Tiền đã trả không được vượt tổng thanh toán.' }
  return { subtotal, discount, shipping, surcharge, total, paid, remaining: total - paid,
    cod: adjustments.cod_mode === 'none' ? 0 : total - paid }
}

export const optionalMoneyCents = (value) => toCents(value == null || (typeof value === 'string' && value.trim() === '') ? 0 : value)

export const centsToAmount = (cents) => `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`

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
  const deposit = optionalMoneyCents(fields.deposit_amount)
  if (mode === 'bank_transfer_cod' && (deposit === null || deposit <= 0 || deposit >= totals.total)) {
    return { error: 'Tiền chuyển khoản một phần phải lớn hơn 0 và nhỏ hơn tổng thanh toán.' }
  }
  const paid = mode === 'bank_transfer_cod' ? deposit : (mode !== 'cod' && confirmed ? totals.total : 0)
  return { ...totals, paid, remaining: totals.total - paid, cod: ['cod', 'bank_transfer_cod'].includes(mode) ? totals.total - paid : 0 }
}

export const money = (value) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(Number(value ?? 0)) + ' đ'
