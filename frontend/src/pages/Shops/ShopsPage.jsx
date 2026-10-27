import {
  AppstoreOutlined,
  DeleteOutlined,
  EditOutlined,
  FilterOutlined,
  MoreOutlined,
  PlusOutlined,
  ReloadOutlined,
  ShoppingOutlined,
  SyncOutlined,
  TeamOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, Avatar, Button, Card, DatePicker, Dropdown, Form, Input, InputNumber, Modal, Space, Table, Tag } from 'antd'
import dayjs from 'dayjs'
import { useMemo, useState } from 'react'
import { createShop, deleteShop, getShops, syncShopData, updateShop } from '../../api/shops.api.js'
import { getApiErrorMessage } from '../../utils/response.js'
import { hasPermission, isAdmin } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import './shops.css'

const EMPTY_FILTERS = { search: '', dates: null }
const formatMoney = (value) => new Intl.NumberFormat('vi-VN').format(Number(value || 0))
const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '?'
const SYNC_MENU_ITEMS = [
  { key: 'order', icon: <ShoppingOutlined />, label: 'Đồng bộ đơn hàng' },
  { key: 'product', icon: <AppstoreOutlined />, label: 'Đồng bộ sản phẩm' },
  { key: 'employee', icon: <TeamOutlined />, label: 'Đồng bộ nhân viên' },
  { key: 'customer', icon: <UserOutlined />, label: 'Đồng bộ khách hàng' },
]

const ShopsPage = () => {
  const user = useAuthStore((state) => state.user)
  const canCreateShop = hasPermission(user, 'create-shop')
  const canUpdateShop = hasPermission(user, 'update-shop')
  const canDeleteShop = hasPermission(user, 'delete-shop')
  const isUserAdmin = isAdmin(user)

  const queryClient = useQueryClient()
  const [draftFilters, setDraftFilters] = useState(EMPTY_FILTERS)
  const [appliedFilters, setAppliedFilters] = useState(EMPTY_FILTERS)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingShop, setEditingShop] = useState(null)
  const [form] = Form.useForm()

  const shopsQuery = useQuery({
    queryKey: ['shops', 'management', appliedFilters.dates?.map((date) => date?.format('YYYY-MM-DD'))],
    queryFn: () => getShops({
      date_from: appliedFilters.dates?.[0]?.format('YYYY-MM-DD'),
      date_to: appliedFilters.dates?.[1]?.format('YYYY-MM-DD'),
    }),
    retry: 1,
  })

  const saveMutation = useMutation({
    mutationFn: (values) => editingShop ? updateShop({ id: editingShop.id, ...values }) : createShop(values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shops'] })
      setModalOpen(false)
      form.resetFields()
    },
  })
  const deleteMutation = useMutation({
    mutationFn: deleteShop,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['shops'] }),
  })
  const syncMutation = useMutation({
    mutationFn: syncShopData,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['shops'] }),
  })

  const shops = useMemo(() => {
    const search = appliedFilters.search.trim().toLocaleLowerCase('vi')
    return (shopsQuery.data ?? []).filter((shop) => !search || shop.name?.toLocaleLowerCase('vi').includes(search))
  }, [appliedFilters.search, shopsQuery.data])

  const totalRevenue = shops.reduce((total, shop) => total + Number(shop.total_cod || 0), 0)
  const openCreate = () => {
    setEditingShop(null)
    form.resetFields()
    setModalOpen(true)
  }
  const openEdit = (shop) => {
    setEditingShop(shop)
    form.setFieldsValue({ care_cycle_days: shop.care_cycle_days })
    setModalOpen(true)
  }
  const applyFilters = () => setAppliedFilters({ ...draftFilters })
  const resetFilters = () => {
    setDraftFilters(EMPTY_FILTERS)
    setAppliedFilters(EMPTY_FILTERS)
  }

  const columns = [
    { title: 'STT', width: 64, align: 'center', render: (_, __, index) => index + 1 },
    { title: 'Shop_id', dataIndex: 'id', width: 64, align: 'center', render: (value) => value ?? '' },
    {
      title: 'Cửa hàng',
      dataIndex: 'name',
      width: 160,
      render: (name) => <Space><Avatar className="shops-avatar">{getInitial(name)}</Avatar><strong>{name}</strong></Space>,
    },
    { title: 'Doanh thu (vnđ)', dataIndex: 'total_cod', width: 160, align: 'right', render: formatMoney },
    { title: 'SL đơn hàng', dataIndex: 'orders_count', width: 64, align: 'center', render: (value) => formatMoney(value) },
    { title: 'Cài đặt CSKH', dataIndex: 'care_cycle_days', width: 64, align: 'center', render: (value) => value ?? '' },
    { title: 'Thời gian thêm cửa hàng', dataIndex: 'created_at', width: 160, render: (value) => value ? dayjs(value).format('DD/MM/YYYY - HH:mm') : '' },
    { title: 'Số lượng nhân viên', dataIndex: 'users', width: 180, render: (users = []) => <Tag color="blue">{users.length} NHÂN VIÊN</Tag> },
    {
      title: 'Thao tác',
      width: 68,
      align: 'center',
      fixed: 'right',
      className: 'shops-action-column',
      render: (_, shop) => {
        const isSyncingShop = syncMutation.isPending && syncMutation.variables?.shopId === shop.id
        const isDeletingShop = deleteMutation.isPending && deleteMutation.variables === shop.id
        const actionItems = []
        if (isUserAdmin) {
          actionItems.push({
            key: 'sync',
            icon: <SyncOutlined />,
            label: 'Đồng bộ',
            children: SYNC_MENU_ITEMS.map((item) => ({ ...item, key: `sync-${item.key}` })),
          })
        }
        if (canUpdateShop) {
          if (actionItems.length > 0) actionItems.push({ type: 'divider' })
          actionItems.push({
            key: 'edit',
            icon: <EditOutlined />,
            label: 'Sửa cửa hàng',
            onClick: () => openEdit(shop),
          })
        }
        if (canDeleteShop) {
          if (actionItems.length > 0 && !canUpdateShop) actionItems.push({ type: 'divider' })
          actionItems.push({
            key: 'delete',
            icon: <DeleteOutlined />,
            label: 'Xóa cửa hàng',
            danger: true,
            onClick: () => Modal.confirm({
              title: 'Xóa cửa hàng này?',
              content: `Bạn có chắc chắn muốn xóa cửa hàng "${shop.name}"?`,
              okText: 'Xóa',
              cancelText: 'Hủy',
              okButtonProps: { danger: true },
              onOk: () => deleteMutation.mutateAsync(shop.id),
            }),
          })
        }

        if (actionItems.length === 0) return null

        return (
          <Dropdown
            menu={{
              items: actionItems,
              onClick: ({ key }) => {
                if (key.startsWith('sync-')) {
                  syncMutation.mutate({ shopId: shop.id, type: key.slice('sync-'.length) })
                }
              },
            }}
            trigger={['click']}
            placement="bottomRight"
          >
            <Button
              type="text"
              size="small"
              icon={<MoreOutlined />}
              loading={isSyncingShop || isDeletingShop}
              aria-label={`Mở thao tác cửa hàng ${shop.name}`}
              aria-haspopup="menu"
            />
          </Dropdown>
        )
      },
    },
  ]

  return (
    <main className="shops-page">
      <Card className="shops-card" title="Quản lý cửa hàng" extra={canCreateShop ? <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>Thêm cửa hàng</Button> : null}>
        {shopsQuery.error && <Alert className="shops-alert" type="error" showIcon message="Không thể tải danh sách cửa hàng" description={getApiErrorMessage(shopsQuery.error)} />}
        {(saveMutation.error || deleteMutation.error || syncMutation.error) && <Alert className="shops-alert" type="error" showIcon message="Thao tác không thành công" description={getApiErrorMessage(saveMutation.error || deleteMutation.error || syncMutation.error)} closable />}
        <div className="shops-filters">
          <div className="shops-field"><label>Tìm kiếm</label><Input placeholder="Tìm kiếm cửa hàng" value={draftFilters.search} onChange={(event) => setDraftFilters((current) => ({ ...current, search: event.target.value }))} /></div>
          <div className="shops-field shops-field--date"><label>Thời gian</label><DatePicker.RangePicker value={draftFilters.dates} format="DD/MM/YYYY" onChange={(dates) => setDraftFilters((current) => ({ ...current, dates }))} /></div>
          <Space><Button type="primary" icon={<FilterOutlined />} onClick={applyFilters}>Lọc</Button><Button icon={<ReloadOutlined />} onClick={resetFilters}>Làm mới</Button></Space>
        </div>
        <p className="shops-summary">Tổng số cửa hàng: <strong>{shops.length}</strong> · Tổng doanh thu: <strong>{formatMoney(totalRevenue)} VNĐ</strong></p>
        <Table className="app-table shops-table" rowKey="id" columns={columns} dataSource={shops} loading={shopsQuery.isLoading || shopsQuery.isFetching} scroll={{ x: 'max-content', y: 'calc(100vh - 390px)' }} pagination={{ pageSize: 30, showSizeChanger: false, position: ['bottomRight'] }} locale={{ emptyText: 'Chưa có cửa hàng' }} />
      </Card>
      <Modal className="shops-modal" title={editingShop ? 'Cập nhật cửa hàng' : 'Thêm cửa hàng'} open={modalOpen} okText={editingShop ? 'Cập nhật' : 'Đồng ý'} cancelText="Hủy" confirmLoading={saveMutation.isPending} onCancel={() => setModalOpen(false)} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(values) => saveMutation.mutate(values)}>
          {!editingShop && <Form.Item name="api_key" label="API key" rules={[{ required: true, message: 'Vui lòng nhập API key.' }]}><Input.Password placeholder="Nhập API key..." /></Form.Item>}
          <Form.Item name="care_cycle_days" label="Chu kỳ chăm sóc (ngày)" initialValue={5} rules={[{ required: true, message: 'Vui lòng nhập chu kỳ.' }]}><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item>
        </Form>
      </Modal>
    </main>
  )
}

export default ShopsPage
