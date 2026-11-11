import React from 'react'
export * from './posUi.js'
const control = (name) => ({ children, ...props }) => React.createElement(name, props, children)
export const Typography = { Title: control('title-control'), Text: control('text-control'), Paragraph: control('paragraph-control') }
export const Modal = control('modal-control')
export const Tabs = control('tabs-control')
export const Timeline = control('timeline-control')
export const Tooltip = control('tooltip-control')
export const EyeOutlined = () => null
export const FilterOutlined = () => null
export const ReloadOutlined = () => null
export const Table = ({ dataSource, columns }) => React.createElement('table-control', {}, dataSource.map((row) =>
  React.createElement('row-control', { key: row.id }, columns.map((column, index) =>
    React.createElement('cell-control', { key: index }, column.render ? column.render(row[column.dataIndex], row) : row[column.dataIndex])))))
