import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Alert, Button, Card, Empty, Result, Select, Space } from 'antd'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getCustomers, getLoyaltyTiers } from '../../api/customers.api.js'
import { hasPermission } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { ROUTES } from '../../constants/routes.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { useMemo, useState } from 'react'
import './customers-upgrading.css'

const getNextTier = (customer, tiers) => {
  const amount = Number(customer.purchased_amount) || 0
  return tiers.find((tier) => Number(tier.min_order_value) > amount) || null
}

const CustomersUpgradingPage = () => {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const user = useAuthStore((state) => state.user)
  const canViewCustomers = hasPermission(user, 'list-customer')
  const [shopId, setShopId] = useState()
  const [currentTierId, setCurrentTierId] = useState()
  const [nextTierId, setNextTierId] = useState(() => searchParams.get('next_tier_id') || undefined)
  const [filters, setFilters] = useState({})

  const customersQuery = useQuery({
    queryKey: ['customers-upgrading', filters],
    queryFn: () => getCustomers({ ...filters, page: 1, page_size: 100 }),
    enabled: canViewCustomers,
    staleTime: 5 * 60_000,
  })

  const tiersQuery = useQuery({
    queryKey: ['loyalty-tiers'],
    queryFn: getLoyaltyTiers,
    enabled: canViewCustomers,
    staleTime: 5 * 60_000,
  })

  const tiers = useMemo(
    () => [...(tiersQuery.data ?? [])].sort((a, b) => Number(a.min_order_value) - Number(b.min_order_value)),
    [tiersQuery.data],
  )

  const tierOptions = tiers.map((tier) => ({ value: String(tier.id), label: tier.name }))

  const customers = useMemo(
    () =>
      (customersQuery.data?.customers ?? [])
        .map((customer) => ({ ...customer, nextTier: getNextTier(customer, tiers) }))
        .filter((customer) => {
          const currentMatches = !currentTierId || String(customer.loyalty_tier?.id) === String(currentTierId)
          const nextMatches = !nextTierId || String(customer.nextTier?.id) === String(nextTierId)
          return currentMatches && nextMatches && customer.nextTier
        }),
    [customersQuery.data, tiers, currentTierId, nextTierId],
  )

  const applyFilters = (event) => {
    event.preventDefault()
    setFilters(shopId ? { shop_id: shopId } : {})
  }

  const resetFilters = () => {
    setShopId(undefined)
    setCurrentTierId(undefined)
    setNextTierId(undefined)
    setFilters({})
  }

  if (!canViewCustomers) {
    return (
      <Card className="customers-upgrading-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem khách hàng"
          subTitle="Tài khoản hiện tại cần quyền “list-customer” để truy cập báo cáo."
          extra={<Button type="primary" onClick={() => navigate(ROUTES.HOME)}>Về Tổng quan</Button>}
        />
      </Card>
    )
  }

  return (
    <main className="customers-upgrading-page">
      <Card title="Khách hàng sắp thăng hạng" className="customers-upgrading-card">
        {(customersQuery.isError || tiersQuery.isError) && (
          <Alert
            type="error"
            showIcon
            message="Không thể tải dữ liệu báo cáo"
            description="Vui lòng thử lại sau hoặc liên hệ quản trị viên."
            className="customers-upgrading-alert"
          />
        )}

        <form className="customers-upgrading-filters" onSubmit={applyFilters}>
          <div className="customers-upgrading-filters__grid">
            <label>
              Cửa hàng
              <ShopSelect value={shopId} onChange={setShopId} useGlobalSelection={false} />
            </label>
            <label>
              Hạng hiện tại
              <Select
                allowClear
                value={currentTierId}
                options={tierOptions}
                placeholder="Chọn hạng hiện tại"
                onChange={setCurrentTierId}
              />
            </label>
            <label>
              Hạng sắp thăng
              <Select
                allowClear
                value={nextTierId}
                options={tierOptions}
                placeholder="Chọn hạng sắp thăng"
                onChange={setNextTierId}
              />
            </label>
            <Space>
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />}>
                Lọc
              </Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters}>
                Làm mới
              </Button>
            </Space>
          </div>
        </form>

        <div className="customers-upgrading-summary">
          Tổng số khách hàng: <strong>{customersQuery.isLoading ? '...' : customers.length}</strong>
        </div>

        {customers.length === 0 && !customersQuery.isLoading && <Empty description="Chưa có khách hàng sắp thăng hạng" />}
      </Card>
    </main>
  )
}

export default CustomersUpgradingPage
