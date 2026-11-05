// Lightweight controls for lifecycle tests; POS, React Query, auth and API modules stay real.
import React, { useEffect, useState, useSyncExternalStore } from 'react'

const control = (name) => ({ children, ...props }) => React.createElement(name, props, children)
export const Alert = control('alert-control')
export const Button = control('button-control')
export const Card = control('card-control')
export const Empty = control('empty-control')
Empty.PRESENTED_IMAGE_SIMPLE = 'simple'
export const Image = control('image-control')
export const Input = control('input-control')
Input.TextArea = control('textarea-control')
export const InputNumber = control('number-control')
export const Pagination = control('pagination-control')
export const Result = control('result-control')
export const Select = control('select-control')
export const Space = control('space-control')
Space.Compact = control('compact-control')
export const Spin = control('spin-control')
export const Typography = { Title: control('title-control'), Text: control('text-control') }
export const Descriptions = control('descriptions-control')
export const Divider = control('divider-control')
export const Table = control('table-control')
export const Tag = control('tag-control')
export const MinusOutlined = () => null
export const PlusOutlined = () => null
export const SearchOutlined = () => null
export const ShoppingCartOutlined = () => null
export const DeleteOutlined = () => null

export const dialogs = []
export const messages = []
export const App = { useApp: () => ({
  message: {
    success: (...args) => messages.push(['success', ...args]),
    error: (...args) => messages.push(['error', ...args]),
  },
  modal: { confirm: (config) => {
    const dialog = { ...config, destroyed: false, destroy() { this.destroyed = true } }
    dialogs.push(dialog)
    return dialog
  } },
}) }

const createForm = () => {
  let values = {}
  let initial = {}
  const listeners = new Set()
  const set = (next) => { values = next; listeners.forEach((listener) => listener()) }
  return {
    initialize: (next) => { initial = next; set({ ...next }) },
    getFieldsValue: () => values,
    resetFields: () => set({ ...initial }),
    setFieldsValue: (next) => set({ ...values, ...next }),
    setFields: () => {},
    subscribe: (listener) => { listeners.add(listener); return () => listeners.delete(listener) },
  }
}
export const Form = ({ form, initialValues, children, ...props }) => {
  useEffect(() => form.initialize(initialValues), [form, initialValues])
  return React.createElement('form-control', { ...props, form }, children)
}
Form.Item = control('form-item-control')
Form.useForm = () => [useState(createForm)[0]]
Form.useWatch = (_, form) => useSyncExternalStore(form.subscribe, form.getFieldsValue, form.getFieldsValue)
