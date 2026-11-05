
export const filterOrderSource = (input, option) =>
  `${option?.label ?? ''} ${option?.value ?? ''}`.toLocaleLowerCase('vi').includes(input.toLocaleLowerCase('vi'))

export const renderOrderSourceOption = (option) => {
  const id = String(option.value ?? '')
  const channel = id.startsWith('pzl_') ? 'Zalo · ' : ''
  return <div title={id}>
    <div>{option.label}</div>
    <div style={{ fontSize: 12, color: '#64748b', overflowWrap: 'anywhere' }}>{channel}ID: {id}</div>
  </div>
}
