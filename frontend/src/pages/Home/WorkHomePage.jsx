import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Card, Empty, Progress, Skeleton, Statistic, Tag, Typography } from 'antd'
import { Link } from 'react-router-dom'
import { getOverview } from '../../api/overview.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { filterMenuByPermissions, isManager } from '../../auth/permissions.js'
import { MENU_ITEMS, flattenMenuItems } from '../../constants/menu.js'
import { ROUTES } from '../../constants/routes.js'
import './work-home.css'

export default function WorkHomePage() {
  const user = useAuthStore((state) => state.user)
  const manager = isManager(user)
  const query = useQuery({ queryKey: ['overview', user?.id], queryFn: getOverview, staleTime: 60000 })
  const data = query.data ?? {}
  const links = flattenMenuItems(filterMenuByPermissions(MENU_ITEMS, user)).filter((item) => item.path && item.path !== ROUTES.HOME)
  const metrics = [
    ['Chăm sóc hôm nay', 'customer_care_today', ROUTES.CARE_TODAY],
    ['Đã chăm sóc hôm nay', 'customer_care_today_done', ROUTES.CARE_TODAY],
    ['Chăm sóc quá hạn', 'customer_care_expire', ROUTES.CARE_OVERDUE],
    ['Đơn hàng hôm nay', 'total_order_today', ROUTES.ORDERS],
  ]
  const done = Number(data.customer_care_today_done) || 0
  const total = Number(data.customer_care_today) || 0
  return <section className="work-home">
    <header className="work-home__header"><div><Tag color="blue">WORKSPACE V2</Tag><Typography.Title level={2}>{manager ? 'Tổng quan quản lý' : 'Công việc của tôi'}</Typography.Title><Typography.Text type="secondary">Xin chào {user?.name || user?.email} · {user?.role?.name}</Typography.Text></div><Button onClick={() => query.refetch()} loading={query.isFetching}>Làm mới</Button></header>
    <Alert type="info" showIcon message={manager ? 'Số liệu theo cửa hàng và phạm vi được phân quyền' : 'Số liệu theo phạm vi công việc được phân quyền'} />
    {query.isError ? <Alert type="error" showIcon message="Không tải được số liệu" description="Vui lòng thử lại. Chưa có số liệu để hiển thị." /> : query.isPending ? <Skeleton active /> : <div className="work-home__metrics">{metrics.map(([title, key, path]) => <Card key={key}><Statistic title={title} value={Number(data[key]) || 0} />{links.some((item) => item.path === path) && <Link to={path}>Xem chi tiết</Link>}</Card>)}</div>}
    <div className="work-home__body"><Card title="Tiến độ chăm sóc hôm nay">{query.isError || query.isPending ? <Empty description="Chưa có số liệu" /> : <><Progress percent={total ? Math.min(100, Math.round(done / total * 100)) : 0} /><p>{done} / {total} công việc đã chăm sóc</p><Link to={ROUTES.CARE_TODAY}><Button type="primary">Mở công việc hôm nay</Button></Link></>}</Card><Card title={manager ? 'Điều hành và nghiệp vụ' : 'Truy cập nhanh'}><div className="work-home__links">{links.map((item) => <Link key={item.path} to={item.path}><Button>{item.title || item.label}</Button></Link>)}</div></Card></div>
  </section>
}

