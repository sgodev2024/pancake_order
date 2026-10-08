import { Select, Typography } from 'antd'

const CustomerCareOrderPageFilter = ({ shopId, value, options, query, onChange }) => (
  <div className="customer-care-field">
    <label htmlFor="customer-care-order-page">Nguồn đơn</label>
    <Select
      id="customer-care-order-page"
      aria-label="Nguồn đơn"
      allowClear
      showSearch
      optionFilterProp="label"
      disabled={!shopId}
      loading={Boolean(shopId) && query.isFetching}
      status={query.isError ? 'error' : undefined}
      value={value}
      options={shopId ? options : []}
      placeholder={shopId ? 'Tất cả nguồn đơn' : 'Chọn cửa hàng trước'}
      notFoundContent={query.isFetching ? 'Đang tải…' : 'Không có nguồn đơn'}
      onChange={(nextValue) => onChange(nextValue == null ? undefined : String(nextValue))}
    />
    {query.isError && (
      <Typography.Text type="danger" role="alert">
        Không thể tải nguồn đơn. Vui lòng thử chọn lại cửa hàng.
      </Typography.Text>
    )}
  </div>
)

export default CustomerCareOrderPageFilter
