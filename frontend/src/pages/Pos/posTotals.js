// UI estimates only; the order API validates prices and calculates authoritative totals.
export const toCents = (value) => {
  const text = String(value ?? 0)
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}

export const calculatePosTotals = (items, adjustments = {}) => {
  const amounts = ['discount_amount', 'shipping_fee', 'surcharge', 'paid_amount']
    .map((key) => toCents(adjustments[key]))
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

export const money = (value) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(Number(value ?? 0)) + ' đ'
