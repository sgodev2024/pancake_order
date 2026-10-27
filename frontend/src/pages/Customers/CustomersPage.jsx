import { DownOutlined, EyeOutlined, FilterOutlined, MoreOutlined, ReloadOutlined, UpOutlined, UserOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Avatar, Button, Card, DatePicker, Descriptions, Dropdown, Empty, Input, Modal, Result, Select, Space, Table, Tooltip } from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getCustomers, getLoyaltyTiers } from '../../api/customers.api.js'
import { getAllUsers } from '../../api/users.api.js'
import { hasPermission } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import CustomerJourneyDrawer from '../../components/CustomerJourney/CustomerJourneyDrawer.jsx'
import { CUSTOMER_PAGE_SIZES, DEFAULT_CUSTOMER_PAGE_SIZE } from '../../constants/customer.js'
import { ROUTES } from '../../constants/routes.js'
import { isPermissionError, isUnauthorizedError, getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import {
  CARE_COUNT_OPTIONS,
  HAS_ORDER_OPTIONS,
  JOURNEY_ACTION_OPTIONS,
  createEmptyCustomerFilters,
  normalizeCustomerFilters,
} from './customerJourneyFilters.js'
import './customers.css'

const { RangePicker } = DatePicker




const formatDateTime = (value) => {
  if (!value) return '—'
  const parsed = dayjs(value)
  return parsed.isValid() ? parsed.format('DD/MM/YYYY - HH:mm') : '—'
}

const formatPercent = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const percent = Number(value)
  return Number.isFinite(percent)
    ? `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(percent)}%`
    : '—'
}

const formatNumber = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? new Intl.NumberFormat('vi-VN').format(amount) : '—'
}

const formatVnd = (value) => {
  const amount = formatNumber(value)
  return amount === '—' ? amount : `${amount} đ`
}

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const getPhoneNumbers = (value) => {
  if (!value) return []
  return String(value)
    .split(',')
    .map((phone) => phone.trim())
    .filter(Boolean)
}

const getCustomerAddress = (customer) => {
  const addresses = customer?.pancake_full_data?.shop_customer_addresses
  if (!Array.isArray(addresses)) return '—'

  const values = addresses.map((address) => address?.full_address?.trim()).filter(Boolean)
  return values.length ? values.join(' · ') : '—'
}

const CustomersPage = () => {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const user = useAuthStore((state) => state.user)
  const canViewCustomers = hasPermission(user, 'list-customer')
  const [draftFilters, setDraftFilters] = useState(() => ({
    ...createEmptyCustomerFilters(),
    loyaltyTierId: searchParams.get('loyalty_tier_id') || undefined,
  }))
  const [appliedFilters, setAppliedFilters] = useState(() =>
    normalizeCustomerFilters({
      ...createEmptyCustomerFilters(),
      loyaltyTierId: searchParams.get('loyalty_tier_id') || undefined,
    }),
  )
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_CUSTOMER_PAGE_SIZE)
  const [queryVersion, setQueryVersion] = useState(0)
  const [selectedCustomer, setSelectedCustomer] = useState(null)
  const [customerInfo, setCustomerInfo] = useState(null)
  const [advancedOpen, setAdvancedOpen] = useState(false)

  const customerParams = useMemo(
    () => ({ ...appliedFilters, page, page_size: pageSize }),
    [appliedFilters, page, pageSize],
  )

  const customersQuery = useQuery({
    queryKey: ['customers', customerParams, queryVersion],
    queryFn: () => getCustomers(customerParams),
    enabled: canViewCustomers,
    placeholderData: keepPreviousData,
  })

  const loyaltyTiersQuery = useQuery({
    queryKey: ['loyalty-tiers'],
    queryFn: getLoyaltyTiers,
    enabled: canViewCustomers,
    staleTime: 60_000,
  })

  const usersQuery = useQuery({
    queryKey: ['customer-journey-care-users', draftFilters.shopId ?? 'all'],
    queryFn: () => getAllUsers({
      ...(draftFilters.shopId ? { shop_id: draftFilters.shopId } : {}),
      assignment_eligible: true,
    }),
    enabled: canViewCustomers && advancedOpen,
    staleTime: 60_000,
  })

  const loyaltyTierOptions = useMemo(
    () =>
      (loyaltyTiersQuery.data ?? []).map((tier) => ({
        value: String(tier.id),
        label: tier.name || `Hạng #${tier.id}`,
      })),
    [loyaltyTiersQuery.data],
  )

  const careUserOptions = useMemo(
    () =>
      (usersQuery.data ?? []).map((staff) => ({
        value: String(staff.id),
        label: staff.name || `Nhân viên #${staff.id}`,
      })),
    [usersQuery.data],
  )

  const updateDraft = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const handleShopChange = (shopId) => {
    setDraftFilters((current) => ({ ...current, shopId, careUserId: undefined }))
  }

  const applyFilters = (event) => {
    event.preventDefault()
    setAppliedFilters(normalizeCustomerFilters(draftFilters))
    setPage(1)
    setQueryVersion((current) => current + 1)
  }

  const resetFilters = () => {
    const emptyFilters = createEmptyCustomerFilters()
    setDraftFilters(emptyFilters)
    setAppliedFilters(normalizeCustomerFilters(emptyFilters))
    setPage(1)
    setQueryVersion((current) => current + 1)
  }

  const handleTableChange = (pagination) => {
    const nextPageSize = pagination.pageSize ?? pageSize
    if (nextPageSize !== pageSize) {
      setPageSize(nextPageSize)
      setPage(1)
      return
    }

    setPage(pagination.current ?? 1)
  }

  const customers = customersQuery.data?.customers ?? []
  const totalItems = customersQuery.data?.total_items ?? 0
  const hasAppliedFilters = Object.values(appliedFilters).some((value) => value !== undefined && value !== '')
  const permissionDenied = !canViewCustomers || isPermissionError(customersQuery.error)

  const columns = useMemo(
    () => [
      {
        title: 'STT',
        key: 'index',
        width: 68,
        align: 'center',
        render: (_, __, index) => (page - 1) * pageSize + index + 1,
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 210,
        render: (shop) =>
          shop?.name ? (
            <Space size={8} className="customers-shop">
              <Avatar size={32} className="customers-shop__avatar" aria-hidden="true">
                {getInitial(shop.name)}
              </Avatar>
              <span>{shop.name}</span>
            </Space>
          ) : (
            '—'
          ),
      },
      {
        title: 'Tên khách hàng',
        dataIndex: 'name',
        key: 'name',
        width: 190,
        render: (name) => name || '—',
      },
      {
        title: 'Số điện thoại',
        dataIndex: 'phone_numbers',
        key: 'phone_numbers',
        width: 160,
        render: (phoneNumbers) => {
          const values = getPhoneNumbers(phoneNumbers)
          return values.length ? (
            <div className="customers-phones">
              {values.map((phone, index) => (
                <span key={`${phone}-${index}`}>{phone}</span>
              ))}
            </div>
          ) : (
            '—'
          )
        },
      },
      {
        title: 'Tổng giá trị mua(VND)',
        dataIndex: 'purchased_amount',
        key: 'purchased_amount',
        width: 170,
        align: 'right',
        render: (purchasedAmount) => <span className="customers-money">{formatNumber(purchasedAmount)}</span>,
      },
      {
        title: 'Địa chỉ',
        key: 'address',
        width: 360,
        render: (_, customer) => {
          const address = getCustomerAddress(customer)
          return address === '—' ? (
            address
          ) : (
            <Tooltip title={address}>
              <span className="customers-address">{address}</span>
            </Tooltip>
          )
        },
      },
      {
        title: 'Thao tác',
        key: 'actions',
        width: 68,
        align: 'center',
        fixed: 'right',
        className: 'customers-action-column',
        render: (_, customer) => (
          <Dropdown
            menu={{
              items: [{
                key: 'customer-info',
                icon: <UserOutlined />,
                label: 'Thông tin khách hàng',
                disabled: customer.id === null || customer.id === undefined || customer.id === '',
                onClick: () => setCustomerInfo(customer),
              }, {
                key: 'journey',
                icon: <EyeOutlined />,
                label: 'Hành trình khách hàng',
                disabled: customer.id === null || customer.id === undefined || customer.id === '',
                onClick: () => setSelectedCustomer(customer),
              }],
            }}
            trigger={['click']}
            placement="bottomRight"
          >
            <Button
              type="text"
              size="small"
              icon={<MoreOutlined />}
              disabled={customer.id === null || customer.id === undefined || customer.id === ''}
              aria-label={`Mở thao tác khách hàng ${customer.name || customer.id}`}
              aria-haspopup="menu"
            />
          </Dropdown>
        ),
      },
    ],
    [page, pageSize],
  )

  if (permissionDenied) {
    return (
      <Card className="customers-state-card">
        <Result
          status="403"
          title="Bạn không có quyền xem khách hàng"
          subTitle="Tài khoản hiện tại cần quyền “list-customer” để truy cập danh sách khách hàng."
          extra={
            <Button type="primary" onClick={() => navigate(ROUTES.HOME)}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  if (customersQuery.isError && isUnauthorizedError(customersQuery.error)) {
    return (
      <Card className="customers-state-card">
        <Result
          status="403"
          title="Phiên đăng nhập không còn hiệu lực"
          subTitle="Vui lòng đăng nhập lại để tiếp tục xem danh sách khách hàng."
        />
      </Card>
    )
  }

  return (
    <main className="customers-page">
      <Card title="Danh sách khách hàng" className="customers-card">
        <form className="customers-filters" onSubmit={applyFilters}>
          <div className="customers-filters__grid">
            <div className="customers-field">
              <label>Cửa hàng</label>
              <ShopSelect
                className="shop-select"
                value={draftFilters.shopId}
                onChange={handleShopChange}
                useGlobalSelection={false}
              />
            </div>

            <div className="customers-field">
              <label htmlFor="customers-search">Tìm kiếm</label>
              <Input
                id="customers-search"
                allowClear
                value={draftFilters.search}
                onChange={(event) => updateDraft('search', event.target.value)}
                placeholder="Tìm kiếm khách hàng"
              />
            </div>

            <div className="customers-field customers-field--date">
              <label>Thời gian tạo khách hàng</label>
              <RangePicker
                aria-label="Khoảng thời gian tạo khách hàng"
                allowClear
                value={draftFilters.dateRange}
                onChange={(value) => updateDraft('dateRange', value)}
                format="DD/MM/YYYY"
                placeholder={['Từ ngày', 'Đến ngày']}
              />
            </div>

            <div className="customers-field">
              <label>Hạng thành viên</label>
              <Select
                aria-label="Hạng thành viên"
                allowClear
                showSearch
                optionFilterProp="label"
                value={draftFilters.loyaltyTierId}
                options={loyaltyTierOptions}
                loading={loyaltyTiersQuery.isLoading}
                status={loyaltyTiersQuery.isError ? 'error' : undefined}
                placeholder="Chọn hạng thành viên"
                notFoundContent={loyaltyTiersQuery.isLoading ? 'Đang tải...' : 'Không có hạng thành viên'}
                onChange={(value) => updateDraft('loyaltyTierId', value)}
              />
            </div>

            <div className="customers-filters__actions">
              <Button type="primary" htmlType="submit" icon={<FilterOutlined />} className="filter-action-button">
                Lọc
              </Button>
              <Button htmlType="button" icon={<ReloadOutlined />} onClick={resetFilters} className="filter-action-button">
                Đặt lại bộ lọc
              </Button>
            </div>
          </div>
{/* 
          <button
            type="button"
            className="customers-advanced-toggle"
            onClick={() => setAdvancedOpen((current) => !current)}
            aria-expanded={advancedOpen}
            aria-controls="customers-journey-filters"
          >
            Bộ lọc nâng cao · Hành trình khách hàng {advancedOpen ? <UpOutlined /> : <DownOutlined />}
          </button> */}

          {advancedOpen && (
            <div id="customers-journey-filters" className="customers-filters__advanced">
              <div className="customers-field">
                <label>Loại hoạt động</label>
                <Select
                  aria-label="Loại hoạt động hành trình"
                  allowClear
                  value={draftFilters.journeyAction}
                  options={JOURNEY_ACTION_OPTIONS}
                  placeholder="Tất cả hoạt động"
                  onChange={(value) => updateDraft('journeyAction', value)}
                />
              </div>

              <div className="customers-field">
                <label>Số lần chăm sóc đã ghi nhận</label>
                <Select
                  aria-label="Số lần chăm sóc đã ghi nhận"
                  allowClear
                  value={draftFilters.careCount}
                  options={CARE_COUNT_OPTIONS}
                  placeholder="Tất cả"
                  onChange={(value) => updateDraft('careCount', value)}
                />
              </div>

              <div className="customers-field">
                <label>Đơn hàng</label>
                <Select
                  aria-label="Trạng thái đơn hàng đã ghi nhận"
                  allowClear
                  value={draftFilters.hasOrder}
                  options={HAS_ORDER_OPTIONS}
                  placeholder="Tất cả"
                  onChange={(value) => updateDraft('hasOrder', value)}
                />
              </div>

              <div className="customers-field customers-field--date">
                <label>Thời gian hoạt động</label>
                <RangePicker
                  aria-label="Khoảng thời gian hoạt động hành trình"
                  allowClear
                  value={draftFilters.journeyDateRange}
                  onChange={(value) => updateDraft('journeyDateRange', value)}
                  format="DD/MM/YYYY"
                  placeholder={['Từ ngày', 'Đến ngày']}
                />
              </div>

              <div className="customers-field">
                <label>Nhân viên chăm sóc</label>
                <Select
                  aria-label="Nhân viên chăm sóc"
                  allowClear
                  showSearch
                  optionFilterProp="label"
                  value={draftFilters.careUserId}
                  options={careUserOptions}
                  loading={usersQuery.isLoading}
                  status={usersQuery.isError ? 'error' : undefined}
                  placeholder="Chọn nhân viên"
                  notFoundContent={usersQuery.isLoading ? 'Đang tải...' : 'Không có nhân viên'}
                  onChange={(value) => updateDraft('careUserId', value)}
                />
              </div>
            </div>
          )}
        </form>

        {loyaltyTiersQuery.isError && (
          <Alert
            type="warning"
            showIcon
            message="Không thể tải danh sách hạng thành viên"
            description="Bạn vẫn có thể xem khách hàng và dùng các bộ lọc khác."
            action={
              <Button size="small" onClick={() => loyaltyTiersQuery.refetch()}>
                Thử lại
              </Button>
            }
            className="customers-tier-alert"
          />
        )}

        {usersQuery.isError && advancedOpen && (
          <Alert
            type="warning"
            showIcon
            message="Không thể tải danh sách nhân viên chăm sóc"
            description="Bạn vẫn có thể dùng các bộ lọc Hành trình khác."
            action={
              <Button size="small" onClick={() => usersQuery.refetch()}>
                Thử lại
              </Button>
            }
            className="customers-user-alert"
          />
        )}

        <div className="customers-summary" aria-live="polite">
          Tổng số khách hàng: <strong>{totalItems.toLocaleString('vi-VN')}</strong>
        </div>

        {customersQuery.isError && (
          <QueryErrorAlert
            error={customersQuery.error}
            fallbackTitle="Không thể tải danh sách khách hàng"
            onRetry={() => customersQuery.refetch()}
            className="customers-query-alert"
          />
        )}

        <Table
          className="app-table customers-table"
          rowKey={(record) => record.id ?? record.pancake_customer_id}
          columns={columns}
          dataSource={customers}
          loading={customersQuery.isLoading || customersQuery.isFetching}
          scroll={{ x: 'max-content', y: 'calc(100vh - 390px)' }}
          onChange={handleTableChange}
          locale={{
            emptyText: (
              <Empty
                image={Empty.PRESENTED_IMAGE_SIMPLE}
                description={
                  <span>
                    Không có khách hàng phù hợp.
                    {hasAppliedFilters && <small>Hãy thử thay đổi hoặc xóa bớt bộ lọc.</small>}
                  </span>
                }
              />
            ),
          }}
          pagination={{
            current: page,
            pageSize,
            total: totalItems,
            showSizeChanger: true,
            pageSizeOptions: CUSTOMER_PAGE_SIZES.map(String),
            position: ['bottomRight'],
            showTotal: (total) => `Tổng ${total.toLocaleString('vi-VN')} khách hàng`,
          }}
        />
      </Card>
      <Modal
        title={`Thông tin khách hàng${customerInfo?.name ? ` — ${customerInfo.name}` : ''}`}
        open={Boolean(customerInfo)}
        onCancel={() => setCustomerInfo(null)}
        footer={null}
        width={640}
        destroyOnHidden
      >
        {customerInfo && (
          <Descriptions bordered column={1} size="small" className="customers-detail">
            <Descriptions.Item label="Ngày tạo">
              {formatDateTime(customerInfo.created_at)}
            </Descriptions.Item>
            <Descriptions.Item label="Số lượng đơn hàng">
              {customerInfo.order_count ?? '—'}
            </Descriptions.Item>
            <Descriptions.Item label="Tổng giá trị mua">
              {formatVnd(customerInfo.purchased_amount)}
            </Descriptions.Item>
            <Descriptions.Item label="Hạng thành viên">
              {customerInfo.loyalty_tier?.name || '—'}
            </Descriptions.Item>
            <Descriptions.Item label="Giảm giá">
              {formatPercent(customerInfo.loyalty_tier?.discount_percent)}
            </Descriptions.Item>
            <Descriptions.Item label="Số điện thoại">
              {getPhoneNumbers(customerInfo.phone_numbers).join(', ') || '—'}
            </Descriptions.Item>
            <Descriptions.Item label="Địa chỉ">
              {getCustomerAddress(customerInfo)}
            </Descriptions.Item>
          </Descriptions>
        )}
      </Modal>
      <CustomerJourneyDrawer
        open={Boolean(selectedCustomer)}
        customer={selectedCustomer}
        onClose={() => setSelectedCustomer(null)}
      />
    </main>
  )
}

export default CustomersPage
