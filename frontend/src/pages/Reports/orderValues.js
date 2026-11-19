// Full POS value already includes the amount paid; keep that separate from COD.
export const orderValues = (orders = {}) => {
  const cod = Number(orders.total_cod ?? orders.total_revenue) || 0
  const prepaid = Number(orders.total_prepaid_amount) || 0
  const total = Number(orders.total_order_value ?? (cod + prepaid)) || 0
  return { cod, prepaid, total }
}

export const withOrderValues = (params = {}) => ({ ...params, include_order_values: 1 })
