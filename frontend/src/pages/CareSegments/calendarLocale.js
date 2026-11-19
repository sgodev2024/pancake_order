import viPicker from 'antd/es/date-picker/locale/vi_VN'

export const monthLocale = {
  ...viPicker,
  lang: {
    ...viPicker.lang,
    shortMonths: Array.from({ length: 12 }, (_, index) => `Tháng ${index + 1}`),
  },
}

export const dateLocale = viPicker
