import React from 'react'
const element = (tag) => ({ children, ...props }) => React.createElement(tag, props, children)
export const Select = element('segment-select')
export const Button = element('segment-button')
export const Input = element('segment-input')
export const InputNumber = element('segment-number')
export const Table = element('segment-table')
export const Modal = element('segment-modal')
export const Alert = element('segment-alert')
export const Card = element('segment-card')
export const DatePicker = Object.assign(element('segment-date'), { RangePicker: element('segment-months') })
export const Typography = { Paragraph: element('p'), Text: element('span') }
export const Space = Object.assign(element('div'), { Compact: element('div') })
const message = { error() {}, success() {} }
export const App = { useApp: () => ({ message }) }
