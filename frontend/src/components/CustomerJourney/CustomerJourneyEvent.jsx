import { Tag, Tooltip, Typography } from 'antd'
import { formatJourneyDateTime, formatJourneyVnd } from './journeyFormat.js'
import { getJourneyActionConfig } from './journeyActionConfig.js'

const MAX_VISIBLE_ITEMS = 3

const OrderDetails = ({ event }) => {
  const metadata = event?.metadata ?? {}
  const items = Array.isArray(metadata.items) ? metadata.items.filter((item) => item?.name) : []
  const orderIdentifier = event?.pancake_order_id || metadata.order_code || metadata.order_id

  return (
    <div className="customer-journey-event__order-details">
      {orderIdentifier && <span>Mã đơn: {orderIdentifier}</span>}
      {metadata.amount !== null && metadata.amount !== undefined && metadata.amount !== '' && (
        <span>Giá trị đơn: {formatJourneyVnd(metadata.amount)}</span>
      )}
      {metadata.quantity !== null && metadata.quantity !== undefined && metadata.quantity !== '' && (
        <span>Số lượng: {metadata.quantity}</span>
      )}
      {items.length > 0 && (
        <div className="customer-journey-event__items">
          {items.slice(0, MAX_VISIBLE_ITEMS).map((item, index) => {
            const itemName = String(item.name)
            return (
              <Tooltip title={itemName} key={`${itemName}-${index}`}>
                <span className="customer-journey-event__item">
                  {itemName} ×{item.quantity ?? 0}
                </span>
              </Tooltip>
            )
          })}
          {items.length > MAX_VISIBLE_ITEMS && <span>+{items.length - MAX_VISIBLE_ITEMS} sản phẩm khác</span>}
        </div>
      )}
    </div>
  )
}

const CustomerJourneyEvent = ({ event }) => {
  const config = getJourneyActionConfig(event?.action)
  const title = config.buildTitle?.(event) ?? config.label
  const description = config.buildDescription?.(event)

  return (
    <div className="customer-journey-event">
      <div className="customer-journey-event__header">
        <Typography.Text strong className="customer-journey-event__title">
          {title}
        </Typography.Text>
        <Tag color={config.color}>{config.label}</Tag>
      </div>
      {description && <Typography.Paragraph type="secondary">{description}</Typography.Paragraph>}
      {config.kind === 'order' && <OrderDetails event={event} />}
      <Typography.Text type="secondary" className="customer-journey-event__time">
        {formatJourneyDateTime(event?.occurred_at)}
      </Typography.Text>
    </div>
  )
}

export default CustomerJourneyEvent
