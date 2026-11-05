import { Alert, Button, Space, Tag, Timeline, Typography } from 'antd'
import { aicrmOrderStatuses } from './aicrmOrderStatuses.js'

const states = {
  processing: ['Đang gửi AICRM · cần đối soát nếu chờ lâu', 'processing'],
  succeeded: ['Đã tạo vận đơn', 'success'],
  failed: ['AICRM từ chối · có thể kiểm tra và thử lại', 'error'],
  unknown: ['Chưa rõ kết quả · cần đối soát, không gửi lại', 'warning'],
}

export const ShipmentButton = ({ order, allowed, pending, mode, onCreate }) => {
  if (!allowed || order?.warehouse_status !== 'packed') return null
  return <Button disabled={!order.can_create_shipment || pending} loading={pending}
    onClick={() => onCreate(order)}>
    Tạo vận đơn Viettel Post{mode === 'live' ? ' (Live)' : mode === 'sandbox' ? '' : ' (Mock)'}
  </Button>
}

export const ShipmentStatus = ({ order, history = false }) => {
  const shipment = order?.shipment
  if (!shipment) return order?.existing_tracking_number ? <Tag>{order.existing_tracking_number}</Tag> : null
  const [label, color] = states[shipment.status] ?? ['Cần kiểm tra', 'default']
  const remoteLabel = Object.hasOwn(aicrmOrderStatuses, shipment.remote_status) ? aicrmOrderStatuses[shipment.remote_status] : null
  return <Space orientation="vertical" size={6}>
    <Space wrap><Tag color={color}>{label}</Tag>{shipment.mode === 'live' ? <Tag color="red">Live · Vận đơn thật</Tag>
      : shipment.mode === 'sandbox' ? <Tag>Sandbox</Tag> : <Tag color="purple">Mock · Local</Tag>}</Space>
    {shipment.tracking_number && <Typography.Text copyable>{shipment.tracking_number}</Typography.Text>}
    {history && shipment.remote_order_id && <Typography.Text>ID đơn AICRM: {shipment.remote_order_id}</Typography.Text>}
    {history && shipment.status === 'succeeded' && !shipment.remote_order_id && <Typography.Text type="secondary">Chưa liên kết UUID đơn AICRM</Typography.Text>}
    {history && (remoteLabel ? <Typography.Text>Trạng thái đơn AICRM (GET): {remoteLabel} ({shipment.remote_status})</Typography.Text>
      : <Typography.Text type="secondary">Chưa xác minh trạng thái đơn AICRM qua GET</Typography.Text>)}
    {history && shipment.last_error && <Alert type="warning" showIcon title={shipment.last_error} />}
    {history && !!shipment.attempts?.length && <Timeline items={shipment.attempts.map((attempt) => ({
      key: attempt.id,
      content: `${attempt.created_at} · ${states[attempt.status]?.[0] ?? attempt.status}${attempt.actor_user_id ? ` · Người thao tác #${attempt.actor_user_id}` : ''}${attempt.http_status ? ` · HTTP ${attempt.http_status}` : ''}${attempt.error_message ? ` · ${attempt.error_message}` : ''}`,
    }))} />}
  </Space>
}
