import { Select } from 'antd'
import { filterOrderSource, renderOrderSourceOption } from './OrderSourceOption.jsx'

export default function OrderSourceSelect(props) {
  return <Select {...props} allowClear showSearch
    placeholder={props.placeholder ?? 'Tất cả nguồn đơn'}
    optionRender={renderOrderSourceOption} filterOption={filterOrderSource}
    popupMatchSelectWidth={Math.max(320, Number(props.popupMatchSelectWidth) || 0)}
  />
}
