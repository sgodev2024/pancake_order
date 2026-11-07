import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Card, Spin, Switch } from 'antd'
import api from '../api/client.js'
import { useAuthStore } from '../auth/auth.store.js'

const statusQuery = { queryKey: ['website-maintenance'], queryFn: async () => (await api.get('/website-maintenance')).data, refetchInterval: 15000, refetchIntervalInBackground: true, staleTime: 0 }
export function MaintenanceSettings() {
  const user = useAuthStore(s => s.user)
  const query = useQuery(statusQuery)
  const client = useQueryClient()
  const { message } = App.useApp()
  const mutation = useMutation({ mutationFn: async enabled => (await api.put('/website-maintenance', { enabled })).data, onSuccess: data => { client.setQueryData(statusQuery.queryKey, data); message.success(data.enabled ? 'Đã bật bảo trì website' : 'Đã tắt bảo trì website') }, onError: () => message.error('Không lưu được chế độ bảo trì. Vui lòng thử lại.') })
  if (user?.role?.slug !== 'admin') return null
  return <Card title="Bảo trì website"><Switch checked={Boolean(query.data?.enabled)} disabled={!query.data || query.isError} loading={mutation.isPending} checkedChildren="Bật" unCheckedChildren="Tắt" onChange={enabled => mutation.mutate(enabled)} /><p>Khi bật, người dùng sẽ thấy thông báo “Website đang bảo trì”. Admin vẫn sử dụng được hệ thống. Webhook và worker tiếp tục hoạt động.</p></Card>
}
export function MaintenanceGate({ children }) {
  const user = useAuthStore(s => s.user)
  const query = useQuery(statusQuery)
  const enabled = query.data?.enabled
  if (query.isPending) return <main style={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}><Spin /></main>
  // Keep the login route accessible so administrators can end maintenance.
  if (enabled && user?.role?.slug !== 'admin' && window.location.pathname !== '/dang-nhap') {
    return <main style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: '#f5f6f8', textAlign: 'center' }}><section><h1>Website đang bảo trì</h1><a href="/dang-nhap">Đăng nhập quản trị</a></section></main>
  }
  return <>{enabled && user?.role?.slug === 'admin' && <Alert type="warning" showIcon message="Website đang bật chế độ bảo trì. Bạn có thể tắt tại Cài đặt chung." />}{children}</>
}
