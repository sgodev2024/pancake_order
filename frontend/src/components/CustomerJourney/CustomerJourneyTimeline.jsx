import { Button, Empty, Skeleton, Timeline } from 'antd'
import CustomerJourneyEvent from './CustomerJourneyEvent.jsx'
import { getJourneyActionConfig } from './journeyActionConfig.js'

const CustomerJourneyTimeline = ({ events, isLoading, hasNextPage, isFetchingNextPage, onLoadMore }) => {
  if (isLoading) {
    return <Skeleton active paragraph={{ rows: 7 }} className="customer-journey-loading" />
  }

  if (events.length === 0) {
    return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có dữ liệu hành trình khách hàng." />
  }

  return (
    <>
      <Timeline
        className="customer-journey-timeline"
        items={events.map((event) => {
          const config = getJourneyActionConfig(event.action)
          const Icon = config.icon
          return {
            key: event.id,
            color: config.color,
            dot: <Icon />,
            children: <CustomerJourneyEvent event={event} />,
          }
        })}
      />
      {hasNextPage && (
        <div className="customer-journey-load-more">
          <Button onClick={onLoadMore} loading={isFetchingNextPage}>
            Xem thêm
          </Button>
        </div>
      )}
    </>
  )
}

export default CustomerJourneyTimeline
