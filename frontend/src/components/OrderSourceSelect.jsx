import { Select } from 'antd'
import { useQuery } from '@tanstack/react-query'
import { useAuthStore } from '../auth/auth.store.js'
import { getOrderPages } from '../api/orders.api.js'
import { getOrderPageOptions } from '../pages/Orders/orderFilters.js'
import { filterOrderSource, renderOrderSourceOption } from './OrderSourceOption.jsx'

export default function OrderSourceSelect({ shopId, ...props }) {
  const userId = useAuthStore((state) => state.user?.id)
  const catalog = useQuery({
    queryKey: ['order-pages', userId, String(shopId ?? ''), 'catalog'],
    queryFn: ({ signal }) => getOrderPages({ shop_id: shopId, context: 'catalog' }, { signal }),
    enabled: !!shopId,
    staleTime: 0,
    retry: false,
  })
  const options = shopId ? getOrderPageOptions(catalog.data) : props.options
  return <Select {...props} allowClear showSearch
    options={options}
    loading={shopId ? catalog.isFetching : props.loading}
    status={catalog.isError ? 'error' : props.status}
    notFoundContent={catalog.isError ? 'Không tải được nguồn đơn. Vui lòng thử lại.' : props.notFoundContent}
    placeholder={props.placeholder ?? 'Tất cả nguồn đơn'}
    optionRender={renderOrderSourceOption} filterOption={filterOrderSource}
    popupMatchSelectWidth={Math.max(320, Number(props.popupMatchSelectWidth) || 0)}
  />
}
