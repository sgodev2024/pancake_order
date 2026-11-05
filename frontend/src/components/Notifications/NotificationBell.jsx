import { BellOutlined } from '@ant-design/icons'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Badge, Button, Empty, Popover, Spin } from 'antd'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../../api/client.js'
export default function NotificationBell({ user }) {
 const [open, setOpen] = useState(false)
 const navigate = useNavigate(); const cache = useQueryClient(); const { message } = App.useApp()
 const query = useQuery({ queryKey: ['care-notifications', user?.id], queryFn: async () => (await api.get('/care-notifications')).data.data, enabled: Boolean(user?.id), refetchInterval: 30000, retry: false })
 const read = async (item) => { try { await api.post(`/care-notifications/${item.id}/read`); cache.invalidateQueries({ queryKey: ['care-notifications', user?.id] }); setOpen(false); navigate(item.path) } catch { message.error('Không thể mở thông báo. Vui lòng thử lại.') } }
 const readAll = async () => { try { await api.post('/care-notifications/read-all'); cache.invalidateQueries({ queryKey: ['care-notifications', user?.id] }) } catch { message.error('Không thể đánh dấu đã đọc.') } }
 return <Popover trigger="click" placement="bottomRight" open={open} onOpenChange={setOpen} title={<div style={{ display:'flex', justifyContent:'space-between', gap:16 }}><span>Thông báo của tôi</span><Button size="small" type="link" onClick={readAll} disabled={!query.data?.unread}>Đọc tất cả</Button></div>} content={<div style={{ width:'min(360px, calc(100vw - 48px))', maxHeight:420, overflowY:'auto' }}>{query.isPending ? <Spin /> : query.isError ? <Alert type="error" title="Chưa tải được thông báo" action={<Button onClick={() => query.refetch()}>Thử lại</Button>} /> : !query.data?.items?.length ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có thông báo" /> : query.data.items.map(item => <button key={item.id} onClick={() => read(item)} style={{ display:'block', textAlign:'left', width:'100%', border:'none', borderBottom:'1px solid #eee', padding:12, cursor:'pointer', background:item.read_at?'white':'#eef7ff', color:'#123' }}><strong>{item.title}</strong><p style={{ margin:'6px 0' }}>{item.task_count} công việc · Nhấn để mở lịch</p><small>{new Date(item.created_at.replace(' ','T')+'+07:00').toLocaleString('vi-VN')}</small></button>)}</div>}><Badge count={query.data?.unread || 0} overflowCount={99}><Button type="text" shape="circle" icon={<BellOutlined />} aria-label="Thông báo" className="app-header__notification" /></Badge></Popover>
}
