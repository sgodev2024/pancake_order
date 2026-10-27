import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Button, Card, Input, Select, Typography } from 'antd'
import { useMemo, useState } from 'react'
import ReportDateRange from '../../components/ReportDateRange.jsx'
import { getOrderPages, getOrderSalesChartData, getOrderTotals } from '../../api/orders.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { ORDER_STATUSES } from '../../constants/order.js'
import OrderSummaryChart from '../Orders/OrderSummaryChart.jsx'
import ShopProductSalesChart from './ShopProductSalesChart.jsx'
import {
  changeOrderFilterShop,
  createEmptyOrderFilters,
  getOrderPageOptions,
  normalizeOrderFilters,
} from '../Orders/orderFilters.js'
import '../Orders/orders.css'
import './reports.css'

const normalizeReportFilters = (filters) => normalizeOrderFilters(filters)

const OrderReportPage = () => {
  const [draftFilters, setDraftFilters] = useState(createEmptyOrderFilters)
  const [appliedFilters, setAppliedFilters] = useState(() => normalizeReportFilters(createEmptyOrderFilters()))

  const totalsParams = useMemo(() => ({ ...appliedFilters }), [appliedFilters])
  const reportQuery = useQuery({
    queryKey: ['order-report', totalsParams],
    queryFn: ({ signal }) => getOrderTotals(totalsParams, { signal }),
    staleTime: 5 * 60_000,
  })
  const chartQuery = useQuery({
    queryKey: ['order-sales-chart-data', totalsParams],
    queryFn: ({ signal }) => getOrderSalesChartData(totalsParams, { signal }),
    staleTime: 5 * 60_000,
  })
  const orderPagesQuery = useQuery({
    queryKey: ['order-pages', draftFilters.shopId ?? null],
    queryFn: ({ signal }) => getOrderPages({ shop_id: draftFilters.shopId }, { signal }),
    enabled: Boolean(draftFilters.shopId),
    staleTime: 5 * 60_000,
  })
  const orderPageOptions = useMemo(() => getOrderPageOptions(orderPagesQuery.data), [orderPagesQuery.data])

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setAppliedFilters(normalizeReportFilters(draftFilters))
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyOrderFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeReportFilters(emptyFilters))
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => changeOrderFilterShop(current, shopId))
  }

  const report = reportQuery.data

  return (
    <main className="report-page">
      <Card title="Báo cáo đơn hàng" className="report-card">
        <form className="orders-filters report-filters" onSubmit={applyFilters}>
          <div className="orders-filters__grid">
            <div className="orders-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>
            <div className="orders-field">
              <label htmlFor="report-order-source">Nguồn đơn</label>
              <Select
                id="report-order-source"
                allowClear
                showSearch
                optionFilterProp="label"
                disabled={!draftFilters.shopId}
                loading={orderPagesQuery.isLoading || orderPagesQuery.isFetching}
                status={orderPagesQuery.isError ? 'error' : undefined}
                value={draftFilters.orderPageId}
                options={orderPageOptions}
                placeholder="Chọn nguồn đơn"
                onChange={(value) => updateDraft('orderPageId', value)}
              />
              {orderPagesQuery.isError && (
                <Typography.Text type="danger" className="orders-field__error">
                  Không thể tải danh sách nguồn đơn.
                </Typography.Text>
              )}
            </div>
            <div className="orders-field">
              <label htmlFor="report-order-search">Tìm kiếm</label>
              <Input
                id="report-order-search"
                allowClear
                value={draftFilters.search}
                onChange={(event) => updateDraft('search', event.target.value)}
                placeholder="Mã đơn, khách hàng, số điện thoại..."
              />
            </div>
            <div className="orders-field orders-field--date">
              <label>Thời gian tạo đơn</label>
              <ReportDateRange value={draftFilters.dateRange} onChange={(dateRange) => updateDraft('dateRange', dateRange)} />
            </div>
            <div className="orders-field">
              <label>Trạng thái</label>
              <Select
                allowClear
                showSearch
                optionFilterProp="label"
                value={draftFilters.status}
                options={ORDER_STATUSES}
                placeholder="Chọn trạng thái"
                onChange={(value) => updateDraft('status', value)}
              />
            </div>
            <div className="orders-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />}>Lọc</Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters}>Làm mới</Button>
            </div>
          </div>
        </form>

        <OrderSummaryChart
          createdAmount={report?.total_revenue}
          successAmount={report?.success_amount}
          successCount={report?.success_order_count}
          prepaidAmount={report?.total_prepaid_amount}
          breakdown={report?.status_breakdown}
          trend={report?.trend}
          compare={report?.compare}
          loading={reportQuery.isLoading || reportQuery.isFetching}
          error={reportQuery.isError}
          onRetry={() => reportQuery.refetch()}
        />

        <ShopProductSalesChart
          data={chartQuery.data}
          loading={chartQuery.isLoading || chartQuery.isFetching}
          error={chartQuery.isError}
          onRetry={() => chartQuery.refetch()}
        />
      </Card>
    </main>
  )
}

export default OrderReportPage
