import { Input } from 'antd'
import { useLayoutEffect, useRef } from 'react'
import { editMoneyInput, formatMoneyInput } from './posMoneyInput.js'

// Text input avoids InputNumber's decimal parsing, rounding and stale valid values.
export default function VndMoneyInput({ value, onChange, ...props }) {
  const input = useRef(null)
  const selection = useRef(null)
  const pasted = useRef(false)
  const display = formatMoneyInput(value)
  useLayoutEffect(() => {
    if (selection.current !== null) {
      input.current?.input?.setSelectionRange(selection.current, selection.current)
      selection.current = null
    }
  })
  return <Input {...props} ref={input} className="pos-money-input" inputMode="numeric" suffix="đ" value={display}
    onPaste={() => { pasted.current = true }}
    onChange={(event) => {
      const result = editMoneyInput(display, event.target.value, event.target.selectionStart ?? event.target.value.length,
        pasted.current ? 'insertFromPaste' : event.nativeEvent?.inputType ?? '')
      pasted.current = false
      selection.current = result.caret
      // Apply immediately too: deleting a separator can leave the form value unchanged.
      event.target.value = result.display
      event.target.setSelectionRange?.(result.caret, result.caret)
      onChange?.(result.value)
    }} />
}
