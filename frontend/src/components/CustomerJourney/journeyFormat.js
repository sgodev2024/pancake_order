import dayjs from 'dayjs'

export const formatJourneyDateTime = (value) => {
  if (!value) return '—'

  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY HH:mm') : '—'
}

export const formatJourneyVnd = (value) => {
  if (value === null || value === undefined || value === '') return '—'

  const amount = Number(value)
  return Number.isFinite(amount) ? `${new Intl.NumberFormat('vi-VN').format(amount)} đ` : '—'
}
