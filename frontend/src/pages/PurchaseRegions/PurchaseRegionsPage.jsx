import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Button, Card, Space } from 'antd'
import ReportDateRange from '../../components/ReportDateRange.jsx'
import { useMemo, useState } from 'react'
import { getProvinceCharts } from '../../api/provinces.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import ProvinceSalesBoard from './ProvinceSalesBoard.jsx'
import './purchase-regions.css'

const EMPTY_SCOPE = { shop_id: undefined }

const PurchaseRegionsPage = () => {
  const [draftScope, setDraftScope] = useState(EMPTY_SCOPE)
  const [draftRange, setDraftRange] = useState(null)
  const [appliedScope, setAppliedScope] = useState(EMPTY_SCOPE)
  const [appliedRange, setAppliedRange] = useState(null)

  const selectedFrom = appliedRange?.[0]?.format('YYYY-MM-DD')
  const selectedTo = appliedRange?.[1]?.format('YYYY-MM-DD')
  const chartParams = useMemo(() => ({
    shop_id: appliedScope.shop_id,
    ...(selectedFrom && selectedTo
      ? { date_from: selectedFrom, date_to: selectedTo }
      : { week_mode: 'latest' }),
  }), [appliedScope.shop_id, selectedFrom, selectedTo])
  const chartsQuery = useQuery({
    queryKey: ['provinces-charts', chartParams],
    queryFn: () => getProvinceCharts(chartParams),
    staleTime: 5 * 60_000,
  })
  const chartData = chartsQuery.data ?? {}
  const updateScope = (key, value) => setDraftScope((current) => ({ ...current, [key]: value }))
  const apply = () => {
    setAppliedScope(draftScope)
    setAppliedRange(draftRange?.[0] && draftRange?.[1] ? draftRange : null)
  }
  const reset = () => {
    setDraftScope(EMPTY_SCOPE)
    setDraftRange(null)
    setAppliedScope(EMPTY_SCOPE)
    setAppliedRange(null)
  }
  return (
    <main className="purchase-regions-page">
      <Card title="Khu vực mua hàng" className="purchase-regions-card">
        <div className="purchase-regions-filters">
          <div className="purchase-regions-field purchase-regions-field--shop">
            <label>Cửa hàng</label>
            <ShopSelect
              value={draftScope.shop_id}
              onChange={(shopId) => updateScope('shop_id', shopId)}
              useGlobalSelection={false}
              placeholder="Chọn cửa hàng"
            />
          </div>
          <div className="purchase-regions-field purchase-regions-field--date">
            <label>Thời gian tạo đơn</label>
            <ReportDateRange value={draftRange} onChange={setDraftRange} />
          </div>
          <Space className="purchase-regions-actions">
            <Button type="primary" icon={<FilterOutlined />} onClick={apply}>Lọc</Button>
            <Button icon={<ReloadOutlined />} onClick={reset}>Làm mới</Button>
          </Space>
        </div>

        <ProvinceSalesBoard
          data={chartData}
          loading={chartsQuery.isLoading}
          error={chartsQuery.isError}
          errorMessage={getApiErrorMessage(chartsQuery.error)}
          onRetry={() => chartsQuery.refetch()}
        />
      </Card>
    </main>
  )
}

export default PurchaseRegionsPage
