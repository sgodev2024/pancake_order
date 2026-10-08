import { Card, Statistic } from 'antd'

const CustomerJourneySummary = ({ summary }) => (
  <Card size="small" className="customer-journey-summary">
    <Statistic
      title="Số lần chăm sóc đã ghi nhận"
      value={Number(summary?.care_count) || 0}
    />
    <Statistic
      title="Đơn hàng đã ghi nhận"
      value={Number(summary?.order_count) || 0}
    />
  </Card>
)

export default CustomerJourneySummary
