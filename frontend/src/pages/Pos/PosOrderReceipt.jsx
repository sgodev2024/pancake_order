import { Alert, Descriptions, Divider, Space, Table, Tag, Typography } from 'antd'
import { money } from './posTotals.js'

const PosOrderReceipt = ({ order }) => {
  const totals = order.totals ?? {}
  return <section className="pos-receipt" aria-label="Chi tiết đơn POS">
    <Space wrap><Typography.Title level={4} style={{ margin: 0 }}>{order.reference}</Typography.Title>
      <Tag color="blue">Đơn Local</Tag><Tag color={order.warehouse_status === 'packed' ? 'green' : 'gold'}>{order.warehouse_status === 'waiting_packing' ? 'Chờ đóng hàng' : order.warehouse_status === 'packed' ? 'Đã đóng hàng' : 'Chưa vào Kho'}</Tag></Space>
    <Descriptions column={1} size="small" style={{ marginTop: 16 }} items={[
      { key: 'shop', label: 'Cửa hàng', children: order.shop?.name },
      { key: 'customer', label: 'Khách hàng', children: `${order.customer_name || ''} · ${order.customer_phone || ''}` },
      { key: 'address', label: 'Địa chỉ', children: order.customer_address },
      { key: 'source', label: 'Nguồn đơn', children: order.order_page_name || 'POS Local' },
      { key: 'creator', label: 'Người tạo', children: order.user_creator?.name || '—' },
      { key: 'tracking', label: 'Mã vận đơn', children: order.order_number_vtp || 'Chưa có' },
    ]} />
    <Table size="small" pagination={false} rowKey="product_id" dataSource={order.items ?? []} columns={[
      { title: 'Sản phẩm', dataIndex: 'name' }, { title: 'SL', dataIndex: 'quantity', align: 'right' },
      { title: 'Đơn giá', dataIndex: 'unit_price', align: 'right', render: money },
      { title: 'Thành tiền', dataIndex: 'line_total', align: 'right', render: money },
    ]} scroll={{ x: 450 }} />
    <Divider />
    <Descriptions column={1} size="small" items={[
      { key: 'subtotal', label: 'Tiền hàng', children: money(totals.subtotal) },
      { key: 'discount', label: 'Giảm giá', children: money(totals.discount_amount) },
      { key: 'shipping', label: 'Phí vận chuyển', children: money(totals.shipping_fee) },
      { key: 'surcharge', label: 'Phụ thu', children: money(totals.surcharge) },
      { key: 'total', label: 'Tổng thanh toán', children: <strong>{money(totals.total_amount)}</strong> },
      { key: 'paid', label: 'Đã thanh toán', children: money(totals.paid_amount) },
      { key: 'remaining', label: 'Còn phải thu', children: <strong>{money(totals.remaining_amount)}</strong> },
      { key: 'cod', label: 'COD dự kiến', children: money(totals.expected_cod) },
      { key: 'note', label: 'Ghi chú', children: order.note || '—' },
    ]} />
    <Alert type="info" showIcon title="COD là khoản dự kiến thu hộ, chưa phải tiền đã thu." />
  </section>
}
export default PosOrderReceipt
