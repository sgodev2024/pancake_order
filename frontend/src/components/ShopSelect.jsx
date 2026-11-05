import { useQuery } from '@tanstack/react-query'
import { Select, Typography } from 'antd'
import { useEffect, useMemo } from 'react'
import { shopOptionsQueryOptions } from '../api/shops.api.js'
import { useShopStore } from '../stores/shop.store.js'

// Presentation only: POS passes its own verified query instead of starting a second one.
export const ShopSelectInput = ({
  shopsQuery,
  className,
  style,
  placeholder = 'Chọn cửa hàng',
  value,
  onChange,
  disabled = false,
  allowClear = true,
}) => (
  <div className={className} style={style}>
    <Select
      disabled={disabled}
      allowClear={allowClear}
      showSearch
      loading={shopsQuery.isFetching}
      value={value}
      options={(shopsQuery.data ?? []).map((shop) => ({ value: String(shop.id), label: shop.name }))}
      placeholder={placeholder}
      optionFilterProp="label"
      status={shopsQuery.isError ? 'error' : undefined}
      onChange={onChange}
      aria-label="Chọn cửa hàng"
      notFoundContent={shopsQuery.isFetching ? 'Đang tải...' : 'Không có cửa hàng'}
    />
    {shopsQuery.isError && (
      <Typography.Text type="danger" className="shop-select__error">
        Không thể tải danh sách cửa hàng.
      </Typography.Text>
    )}
  </div>
)

const ShopSelect = ({
  className,
  style,
  placeholder = 'Chọn cửa hàng',
  value,
  onChange,
  useGlobalSelection = true,
  disabled = false,
}) => {
  const selectedShopId = useShopStore((state) => state.selectedShopId)
  const setSelectedShopId = useShopStore((state) => state.setSelectedShopId)
  const clearSelectedShop = useShopStore((state) => state.clearSelectedShop)
  const shopsQuery = useQuery(shopOptionsQueryOptions())

  const options = useMemo(
    () =>
      (shopsQuery.data ?? []).map((shop) => ({
        value: String(shop.id),
        label: shop.name,
      })),
    [shopsQuery.data],
  )
  const activeValue = useGlobalSelection ? selectedShopId : value

  useEffect(() => {
    if (!shopsQuery.isSuccess || !activeValue) return

    const shopStillVisible = options.some((option) => option.value === String(activeValue))
    if (shopStillVisible) return

    if (useGlobalSelection) {
      clearSelectedShop()
    } else {
      onChange?.(undefined)
    }
  }, [activeValue, clearSelectedShop, onChange, options, shopsQuery.isSuccess, useGlobalSelection])

  const handleChange = (nextValue) => {
    if (useGlobalSelection) {
      if (nextValue) setSelectedShopId(nextValue)
      else clearSelectedShop()
      return
    }

    onChange?.(nextValue)
  }

  return <ShopSelectInput shopsQuery={shopsQuery} className={className} style={style}
    placeholder={placeholder} value={activeValue} onChange={handleChange} disabled={disabled} />
}

export default ShopSelect
