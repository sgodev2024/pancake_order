import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Form, Input, Space, Spin, Switch, Tag } from 'antd'
import { useEffect } from 'react'
import { useAuthStore } from '../../auth/auth.store.js'
import api from '../../api/client.js'
const names = { assigned:'Được phân công CSKH', today:'Lịch hôm nay', overdue:'Lịch quá hạn' }
function TemplateForm({ item, onSave }) {
 const [form] = Form.useForm(); const { message } = App.useApp()
 useEffect(() => { form.setFieldsValue({ ...item, email_enabled:Boolean(Number(item.email_enabled)) }) }, [form, item])
 return <Card size="small" title={names[item.type]}><Form form={form} layout="vertical" onFinish={async values => { try { await onSave(item.type, values); message.success('Đã lưu template thông báo.') } catch (error) { message.error(error.response?.data?.message || 'Không thể lưu template.') } }}><Form.Item name="email_enabled" label="Gửi qua email" valuePropName="checked"><Switch checkedChildren="Bật" unCheckedChildren="Tắt" /></Form.Item><Form.Item name="subject" label="Tiêu đề email" rules={[{ required:true },{ max:200 }]}><Input /></Form.Item><Form.Item name="body" label="Nội dung" rules={[{ required:true },{ max:5000 }]}><Input.TextArea rows={7} /></Form.Item><Button type="primary" htmlType="submit">Lưu template</Button></Form></Card>
}
export default function NotificationTemplates() {
 const user = useAuthStore(s => s.user); const cache = useQueryClient()
 const query = useQuery({ queryKey:['care-notification-templates'], queryFn: async () => (await api.get('/care-notification-templates')).data.data, enabled:user?.role?.slug==='admin', retry:false })
 if (user?.role?.slug!=='admin') return null
 return <Card title="Template thông báo" style={{ marginTop:24 }}><Alert type="info" showIcon title="Chuông luôn hiển thị thông báo riêng của user. Công tắc chỉ bật/tắt email." description="Phân công mới: kiểm tra mỗi phút. Hôm nay và quá hạn: tổng hợp một lần/ngày theo cửa hàng từ 08:00 giờ Việt Nam. Bật email không gửi lại thông báo đã tạo trước đó." /><p>Biến hỗ trợ: <Tag>{'{{employee_name}}'}</Tag><Tag>{'{{task_count}}'}</Tag><Tag>{'{{date}}'}</Tag><Tag>{'{{login_url}}'}</Tag><Tag>{'{{work_url}}'}</Tag></p>{query.isPending ? <Spin /> : query.isError ? <Alert type="error" title="Không tải được template" /> : <Space orientation="vertical" style={{ width:'100%' }}>{query.data.map(item => <TemplateForm key={item.type} item={item} onSave={async (type,values) => { await api.put(`/care-notification-templates/${type}`,values); await cache.invalidateQueries({ queryKey:['care-notification-templates'] }) }} />)}</Space>}</Card>
}
