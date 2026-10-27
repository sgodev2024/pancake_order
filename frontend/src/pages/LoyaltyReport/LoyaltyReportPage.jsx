import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Card } from 'antd'
import { getLoyaltyOverview } from '../../api/loyalty.api.js'
import { getApiErrorMessage } from '../../utils/response.js'
import './loyalty-report.css'

const formatVnd = (value) => `${new Intl.NumberFormat('vi-VN').format(Number(value) || 0)} đ`

const LoyaltyReportPage = () => {
  const overviewQuery = useQuery({
    queryKey: ['loyalty-overview'],
    queryFn: getLoyaltyOverview,
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
  })
  const rows = overviewQuery.data ?? []
  const totalRevenue = rows.reduce((sum, row) => sum + Number(row.total_revenue || 0), 0)
  const totalCustomers = rows.reduce((sum, row) => sum + Number(row.customers_count || 0), 0)

  return (
    <main className="loyalty-report-page">
      <Card title="Báo cáo Loyalty" className="loyalty-report-card">
        {overviewQuery.isError && <Alert type="error" showIcon message="Không thể tải báo cáo Loyalty" description={getApiErrorMessage(overviewQuery.error)} />}
        <p className="loyalty-report-summary">
          {overviewQuery.isLoading ? 'Đang tính…' : `${rows.length.toLocaleString('vi-VN')} hạng · ${totalCustomers.toLocaleString('vi-VN')} khách hàng · ${formatVnd(totalRevenue)}`}
        </p>
      </Card>
    </main>
  )
}

export default LoyaltyReportPage
