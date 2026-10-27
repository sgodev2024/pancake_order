import { DeleteOutlined, EditOutlined, FilterOutlined, MoreOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  App,
  Avatar,
  Button,
  Card,
  Dropdown,
  Form,
  Input,
  Modal,
  Select,
  Space,
  Table,
  Tag,
} from 'antd'
import { useCallback, useMemo, useState } from 'react'
import { createStaff, deleteStaff, getRoleOptions, getStaff, getStaffById, updateStaff } from '../../api/users.api.js'
import { getShopOptions } from '../../api/shops.api.js'
import { hasPermission } from '../../auth/permissions.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import './staff.css'

const EMPTY_FILTERS = { shop_id: undefined, role_id: undefined, search: '' }

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '?'

const StaffPage = () => {
  const user = useAuthStore((state) => state.user)
  const canView = hasPermission(user, 'list-staff')
  const canCreateStaff = hasPermission(user, 'create-staff')
  const canUpdateStaff = hasPermission(user, 'update-staff')
  const canDeleteStaff = hasPermission(user, 'delete-staff')
  const queryClient = useQueryClient()
  const [draftFilters, setDraftFilters] = useState(EMPTY_FILTERS)
  const [appliedFilters, setAppliedFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingStaff, setEditingStaff] = useState(null)
  const [loadingStaffId, setLoadingStaffId] = useState(null)
  const [form] = Form.useForm()
  const { message } = App.useApp()

  const staffQuery = useQuery({
    queryKey: ['staff', appliedFilters, page],
    queryFn: () => getStaff({ ...appliedFilters, page }),
    enabled: canView,
    retry: 1,
  })
  const shopsQuery = useQuery({ queryKey: ['shops', 'options'], queryFn: getShopOptions, enabled: canView })
  const rolesQuery = useQuery({ queryKey: ['roles', 'options'], queryFn: getRoleOptions, enabled: canView })

  const saveMutation = useMutation({
    mutationFn: (values) =>
      editingStaff ? updateStaff({ id: editingStaff.id, ...values }) : createStaff(values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['staff'] })
      setModalOpen(false)
      form.resetFields()
    },
    onError: (error) => {
      message.error(getApiErrorMessage(error, 'Không thể lưu nhân viên. Vui lòng thử lại.'))
    },
  })
  const deleteMutation = useMutation({
    mutationFn: deleteStaff,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['staff'] }),
    onError: (error) => {
      message.error(getApiErrorMessage(error, 'Không thể xóa nhân viên. Vui lòng thử lại.'))
    },
  })

  const data = staffQuery.data ?? {}
  const staff = data.items ?? []
  const shopOptions = (shopsQuery.data ?? []).map((shop) => ({ value: shop.id, label: shop.name }))
  const roleOptions = (rolesQuery.data ?? []).map((role) => ({ value: role.id, label: role.name }))
  const queryError = staffQuery.error || shopsQuery.error || rolesQuery.error

  const openCreate = () => {
    setEditingStaff(null)
    form.resetFields()
    setModalOpen(true)
  }

  const openEdit = useCallback(async (record) => {
    setLoadingStaffId(record.id)
    try {
      const staffDetails = await getStaffById(record.id)
      setEditingStaff(staffDetails)
      form.resetFields()
      form.setFieldsValue({
        email: staffDetails.email ?? '',
        name: staffDetails.name ?? '',
        phone_number: staffDetails.phone_number ?? '',
        role_id: staffDetails.role_id,
        shop_ids: (staffDetails.shops ?? []).map((shop) => shop.id),
      })
      setModalOpen(true)
    } catch (error) {
      message.error(getApiErrorMessage(error, 'Không thể tải thông tin nhân viên. Vui lòng thử lại.'))
    } finally {
      setLoadingStaffId(null)
    }
  }, [form, message])

  const submit = async (values) => {
    const payload = {
      ...values,
      shop_ids: Array.isArray(values.shop_ids) ? values.shop_ids : [],
    }
    if (!editingStaff) payload.password = values.password
    else if (!payload.password) delete payload.password
    await saveMutation.mutateAsync(payload)
    message.success(editingStaff ? 'Cập nhật nhân viên thành công.' : 'Thêm nhân viên thành công.')
  }

  const updateDraftFilter = (key, value) => {
    setDraftFilters((current) => ({ ...current, [key]: value }))
  }

  const applyFilters = () => {
    setAppliedFilters({
      ...draftFilters,
      search: draftFilters.search.trim(),
    })
    setPage(1)
  }

  const resetFilters = () => {
    setDraftFilters(EMPTY_FILTERS)
    setAppliedFilters(EMPTY_FILTERS)
    setPage(1)
  }

  const columns = useMemo(() => {
    const cols = [
      { title: 'STT', width: 64, render: (_, __, index) => (page - 1) * 30 + index + 1 },
      {
        title: 'Cửa hàng',
        dataIndex: 'shops',
        render: (shops = []) => (
          <Space wrap>
            {shops.length ? shops.map((shop) => <Tag key={shop.id}>{shop.name}</Tag>) : null}
          </Space>
        ),
      },
      {
        title: 'Nhân viên',
        dataIndex: 'name',
        render: (name) => (
          <Space>
            <Avatar className="staff-avatar">{getInitial(name)}</Avatar>
            <strong>{name || ''}</strong>
          </Space>
        ),
      },
      { title: 'Email', dataIndex: 'email', ellipsis: true },
      { title: 'Số điện thoại', dataIndex: 'phone_number', width: 150 },
      { title: 'Chức vụ', dataIndex: ['role', 'name'], width: 160, render: (role) => role || '' },
    ]

    if (canUpdateStaff || canDeleteStaff) {
      cols.push({
        title: 'Thao tác',
        width: 68,
        align: 'center',
        fixed: 'right',
        className: 'staff-action-column',
        render: (_, record) => {
          const items = []
          if (canUpdateStaff) {
            items.push({
              key: 'edit',
              icon: <EditOutlined />,
              label: 'Sửa nhân viên',
              disabled: loadingStaffId !== null,
              onClick: () => openEdit(record),
            })
          }
          if (canDeleteStaff) {
            items.push({
              key: 'delete',
              icon: <DeleteOutlined />,
              label: 'Xóa nhân viên',
              danger: true,
              disabled: deleteMutation.isPending,
              onClick: () => Modal.confirm({
                title: 'Xóa nhân viên này?',
                content: 'Thao tác này không thể hoàn tác.',
                okText: 'Xóa',
                cancelText: 'Hủy',
                okButtonProps: { danger: true },
                onOk: () => deleteMutation.mutateAsync(record.id),
              }),
            })
          }
          if (items.length === 0) return null

          return (
            <Dropdown
              menu={{ items }}
              trigger={['click']}
              placement="bottomRight"
            >
              <Button
                type="text"
                size="small"
                icon={<MoreOutlined />}
                loading={loadingStaffId === record.id || (deleteMutation.isPending && deleteMutation.variables === record.id)}
                aria-label={`Mở thao tác nhân viên ${record.name}`}
                aria-haspopup="menu"
              />
            </Dropdown>
          )
        },
      })
    }

    return cols
  }, [canDeleteStaff, canUpdateStaff, deleteMutation, loadingStaffId, openEdit, page])

  if (!canView) {
    return <Alert type="warning" showIcon message="Bạn không có quyền xem danh sách nhân viên." />
  }

  return (
    <section className="staff-page">
      <Card
        className="staff-card"
        title="Quản lý nhân viên"
        extra={canCreateStaff ? <Button type="primary" icon={<PlusOutlined />} onClick={openCreate} disabled={loadingStaffId !== null}>Thêm nhân viên</Button> : null}
      >
        {queryError && (
          <QueryErrorAlert
            error={queryError}
            fallbackTitle="Không thể tải dữ liệu nhân viên"
            onRetry={() => staffQuery.refetch()}
            className="staff-alert"
          />
        )}

        <div className="staff-filters">
          <div className="staff-field">
            <label htmlFor="staff-shop">Cửa hàng</label>
            <Select id="staff-shop" allowClear placeholder="Chọn cửa hàng" options={shopOptions} value={draftFilters.shop_id} onChange={(shop_id) => updateDraftFilter('shop_id', shop_id)} />
          </div>
          <div className="staff-field">
            <label htmlFor="staff-search">Tìm kiếm</label>
            <Input id="staff-search" placeholder="Tìm kiếm nhân viên" value={draftFilters.search} onChange={(event) => updateDraftFilter('search', event.target.value)} />
          </div>
          <div className="staff-field">
            <label htmlFor="staff-role">Chức vụ</label>
            <Select id="staff-role" allowClear placeholder="Chọn chức vụ" options={roleOptions} value={draftFilters.role_id} onChange={(role_id) => updateDraftFilter('role_id', role_id)} />
          </div>
          <Space className="staff-filter-actions">
            <Button type="primary" icon={<FilterOutlined />} onClick={applyFilters}>Lọc</Button>
            <Button icon={<ReloadOutlined />} onClick={resetFilters}>Làm mới</Button>
          </Space>
        </div>

        <p className="staff-summary">Tổng số nhân viên: <strong>{data.total_items ?? 0}</strong></p>
        <Table
          className="app-table"
          rowKey="id"
          loading={staffQuery.isLoading || staffQuery.isFetching}
          columns={columns}
          dataSource={staff}
          scroll={{ x: 980, y: 'calc(100vh - 430px)' }}
          pagination={{
            current: data.current_page ?? page,
            pageSize: data.per_page ?? 30,
            total: data.total_items ?? 0,
            showSizeChanger: false,
            onChange: setPage,
          }}
          locale={{ emptyText: 'Chưa có nhân viên' }}
        />
      </Card>

      <Modal
        className="staff-modal"
        title={editingStaff ? 'Cập nhật nhân viên' : 'Thêm nhân viên'}
        open={modalOpen}
        okText={editingStaff ? 'Cập nhật' : 'Thêm mới'}
        cancelText="Hủy"
        confirmLoading={saveMutation.isPending}
        onCancel={() => setModalOpen(false)}
        onOk={() => form.submit()}
      >
        <Form form={form} layout="vertical" onFinish={submit}>
          <Form.Item name="email" label="Email" normalize={(value) => value?.trim()} rules={[{ required: true, message: 'Vui lòng nhập email.' }, { type: 'email', message: 'Email chưa đúng định dạng.' }]}>
            <Input placeholder="Nhập email..." />
          </Form.Item>
          <Form.Item name="name" label="Tên nhân viên" rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập tên.' }, { max: 255, message: 'Tên nhân viên tối đa 255 ký tự.' }]}>
            <Input placeholder="Nhập tên nhân viên..." />
          </Form.Item>
          <Form.Item
            name="phone_number"
            label="Số điện thoại"
            normalize={(value) => value?.replace(/\s+/g, '')}
            rules={[
              { required: true, message: 'Vui lòng nhập số điện thoại.' },
              { pattern: /^(0\d{9,10}|\+84\d{9,10})$/, message: 'Số điện thoại không đúng định dạng!' },
            ]}
          >
            <Input placeholder="Nhập số điện thoại..." />
          </Form.Item>
          <Form.Item name="shop_ids" label="Cửa hàng">
            <Select mode="multiple" allowClear placeholder="Chọn cửa hàng" options={shopOptions} />
          </Form.Item>
          <Form.Item name="role_id" label="Chức vụ" rules={[{ required: true, message: 'Vui lòng chọn chức vụ.' }]}>
            <Select placeholder="Chọn chức vụ" options={roleOptions} />
          </Form.Item>
          <Form.Item name="password" label={editingStaff ? 'Mật khẩu mới (không bắt buộc)' : 'Mật khẩu'} rules={editingStaff ? [{ min: 6, message: 'Mật khẩu tối thiểu 6 ký tự.' }] : [{ required: true, min: 6, message: 'Mật khẩu tối thiểu 6 ký tự.' }]}>
            <Input.Password placeholder="Nhập mật khẩu..." />
          </Form.Item>
        </Form>
      </Modal>
    </section>
  )
}

export default StaffPage
