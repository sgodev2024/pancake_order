export const formatMoneyInput = (value) => {
  if (value === null || value === undefined || value === '') return ''

  const [integer, fraction] = String(value).split('.')
  const groupedInteger = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')

  return fraction === undefined ? groupedInteger : `${groupedInteger},${fraction}`
}

export const parseMoneyInput = (value) => value
  ?.replace(/\./g, '')
  .replace(',', '.')
  .replace(/[^\d.-]/g, '') ?? ''
