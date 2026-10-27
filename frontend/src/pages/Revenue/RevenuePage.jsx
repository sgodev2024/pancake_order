import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Avatar, Button, Card, Empty, Input, Progress, Select, Space } from 'antd'
import dayjs from 'dayjs'
import ReportDateRange from '../../components/ReportDateRange.jsx'
import { useMemo, useState } from 'react'
import { getRoleOptions, getStaff, getWeeklyRevenue } from '../../api/users.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import './revenue.css'

const EMPTY_SCOPE = { shop_id: undefined, role_id: undefined, search: '' }
const currency = new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 })
const initial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const formatWeek = (start, end) => `${dayjs(start).format('DD/MM/YYYY')} – ${dayjs(end).format('DD/MM/YYYY')}`

const RevenuePage = () => {
  const [draftScope, setDraftScope] = useState(EMPTY_SCOPE)
  const [draftRange, setDraftRange] = useState(null)
  const [appliedScope, setAppliedScope] = useState(EMPTY_SCOPE)
  const [appliedRange, setAppliedRange] = useState(null)

  const selectedFrom = appliedRange?.[0]?.format('YYYY-MM-DD')
  const selectedTo = appliedRange?.[1]?.format('YYYY-MM-DD')
  const rankingParams = useMemo(() => ({
    shop_id: appliedScope.shop_id,
    role_id: appliedScope.role_id,
    ...(selectedFrom && selectedTo
      ? { date_from: selectedFrom, date_to: selectedTo }
      : { week_mode: 'latest' }),
  }), [appliedScope.role_id, appliedScope.shop_id, selectedFrom, selectedTo])
  const rankingQuery = useQuery({
    queryKey: ['revenue-weekly-ranking', rankingParams],
    queryFn: () => getWeeklyRevenue(rankingParams),
    staleTime: 5 * 60_000,
  })
  const rankingData = rankingQuery.data ?? {}
  const periodFrom = rankingData.from || selectedFrom
  const periodTo = rankingData.to || selectedTo
  const periodStart = periodFrom ? dayjs(periodFrom) : null
  const periodEnd = periodTo ? dayjs(periodTo) : null
  const params = useMemo(() => ({
    page: 1,
    page_name: 'report_page',
    shop_id: appliedScope.shop_id,
    role_id: appliedScope.role_id,
    search: appliedScope.search,
    date_from: periodFrom,
    date_to: periodTo,
  }), [appliedScope, periodFrom, periodTo])
  const revenueQuery = useQuery({
    queryKey: ['revenue-by-staff', params],
    queryFn: () => getStaff(params),
    enabled: Boolean(params.date_from && params.date_to),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
  })
  const rolesQuery = useQuery({ queryKey: ['roles', 'options'], queryFn: getRoleOptions, staleTime: 5 * 60_000 })
  const data = revenueQuery.data ?? {}
  const ranking = useMemo(() => {
    const items = rankingData.items ?? []
    const leader = Number(items[0]?.revenue) || 0
    return items.map((item, index) => ({
      ...item,
      rank: index + 1,
      percent: leader > 0 ? Math.round((Number(item.revenue) / leader) * 100) : 0,
    }))
  }, [rankingData.items])
  const roleOptions = (rolesQuery.data ?? []).map((role) => ({ value: role.id, label: role.name }))
  const updateScope = (key, value) => setDraftScope((current) => ({ ...current, [key]: value }))
  const apply = () => {
    setAppliedScope({ ...draftScope, search: draftScope.search.trim() })
    setAppliedRange(draftRange?.[0] && draftRange?.[1] ? draftRange : null)
  }
  const reset = () => {
    setDraftScope(EMPTY_SCOPE)
    setDraftRange(null)
    setAppliedScope(EMPTY_SCOPE)
    setAppliedRange(null)
  }

  return (
    <main className="revenue-page">
      <Card title="Doanh thu/nhân viên" className="revenue-card">
        <div className="revenue-filters">
          <div className="revenue-field"><label>Cửa hàng</label><ShopSelect value={draftScope.shop_id} onChange={(shop_id) => updateScope('shop_id', shop_id)} useGlobalSelection={false} /></div>
          <div className="revenue-field"><label>Tìm kiếm</label><Input value={draftScope.search} onChange={(event) => updateScope('search', event.target.value)} onPressEnter={apply} placeholder="Tìm kiếm nhân viên" /></div>
          <div className="revenue-field revenue-field--date">
            <label>Thời gian tạo đơn</label>
            <ReportDateRange value={draftRange} onChange={setDraftRange} />
          </div>
          <div className="revenue-field"><label>Chức vụ</label><Select allowClear value={draftScope.role_id} onChange={(role_id) => updateScope('role_id', role_id)} options={roleOptions} loading={rolesQuery.isLoading} placeholder="Chọn chức vụ" /></div>
          <Space className="revenue-actions"><Button type="primary" icon={<FilterOutlined />} onClick={apply}>Lọc</Button><Button icon={<ReloadOutlined />} onClick={reset}>Làm mới</Button></Space>
        </div>

        <section className="revenue-ranking" aria-label="Top doanh thu nhân viên theo tuần">
          <div className="revenue-ranking__head">
            <div>
              <h3>Top doanh thu theo tuần</h3>
              <p>
                {periodStart && periodEnd
                  ? `Tổng COD của đơn do nhân viên tạo, từ ${formatWeek(periodStart, periodEnd)}.`
                  : 'Đang xác định tuần có đơn gần nhất.'}
              </p>
            </div>
          </div>
          {rankingQuery.isError ? (
            <Alert type="error" showIcon message="Không thể tải top doanh thu" description={getApiErrorMessage(rankingQuery.error)} />
          ) : rankingQuery.isLoading ? (
            <div className="revenue-ranking__loading">Đang tính top doanh thu...</div>
          ) : ranking.length === 0 ? (
            <Empty
              image={Empty.PRESENTED_IMAGE_SIMPLE}
              description={rankingData.latest_order_at
                ? `Không có doanh thu trong tuần này. Đơn gần nhất là ${dayjs(rankingData.latest_order_at).format('DD/MM/YYYY')}.`
                : 'Không có doanh thu trong tuần này'}
            />
          ) : (
            <ol className="revenue-ranking__list">
              {ranking.map((item) => (
                <li key={item.id}>
                  <span className="revenue-ranking__rank">{item.rank}</span>
                  <Avatar className="revenue-avatar" size={28}>{initial(item.name)}</Avatar>
                  <strong className="revenue-ranking__name">{item.name || '—'}</strong>
                  <Progress percent={item.percent} showInfo={false} strokeColor="#1677ff" />
                  <div className="revenue-ranking__metrics">
                    <b>{currency.format(Number(item.revenue || 0))}</b>
                    <span>{Number(item.orders_count || 0).toLocaleString('vi-VN')} đơn</span>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </section>

        <div className="revenue-summary">
          Tổng số nhân viên: <strong>{Number(data.total_items || 0).toLocaleString('vi-VN')}</strong>
          {' · '}
          Tổng doanh thu tuần: <strong>{currency.format(Number(data.total_cod || 0))}</strong>
          {periodStart && periodEnd && <span> · {formatWeek(periodStart, periodEnd)}</span>}
        </div>
        {revenueQuery.isError && <Alert type="error" showIcon message="Không thể tải doanh thu nhân viên" description={getApiErrorMessage(revenueQuery.error)} />}
      </Card>
    </main>
  )
}

export default RevenuePage
