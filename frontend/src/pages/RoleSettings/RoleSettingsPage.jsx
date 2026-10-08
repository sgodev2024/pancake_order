import { CheckOutlined, DeleteOutlined, DownOutlined, EditOutlined, MoreOutlined, PlusOutlined, SaveOutlined, SafetyOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Checkbox, Dropdown, Empty, Form, Input, Modal, Select, Space, Spin, Table, Tag, Typography } from 'antd'
import { useMemo, useState } from 'react'
import {
  createRole,
  createPermissionGroup,
  createPermission,
  deletePermissionGroup,
  deletePermission,
  deleteRole,
  getPermissionGroups,
  getRolePermissions,
  getRoles,
  saveRolePermissions,
  updatePermission,
  updateRole,
} from '../../api/roles.api.js'
import { getApiErrorMessage } from '../../utils/response.js'
import './role-settings.css'

const EMPTY_ROLES = []
const EMPTY_GROUPS = []

const slugify = (value = '') =>
  value
    .trim()
    .toLocaleLowerCase('vi')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')

const RoleSettingsPage = () => {
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const [roleForm] = Form.useForm()
  const [groupForm] = Form.useForm()
  const [permissionForm] = Form.useForm()
  const [selectedRoleIdState, setSelectedRoleId] = useState()
  const [permissionDraft, setPermissionDraft] = useState({ roleId: null, ids: [] })
  const [expandedPermissionGroups, setExpandedPermissionGroups] = useState(() => new Set())
  const [search, setSearch] = useState('')
  const [roleModalOpen, setRoleModalOpen] = useState(false)
  const [groupModalOpen, setGroupModalOpen] = useState(false)
  const [permissionModalOpen, setPermissionModalOpen] = useState(false)
  const [editingRole, setEditingRole] = useState(null)
  const [editingPermission, setEditingPermission] = useState(null)
  const [roleCodeManuallyEdited, setRoleCodeManuallyEdited] = useState(false)
  const [permissionCodeManuallyEdited, setPermissionCodeManuallyEdited] = useState(false)

  const rolesQuery = useQuery({
    queryKey: ['roles', 'management'],
    queryFn: getRoles,
    retry: 1,
  })
  const groupsQuery = useQuery({
    queryKey: ['permission-groups', 'management'],
    queryFn: getPermissionGroups,
    retry: 1,
  })

  const roles = rolesQuery.data?.roles ?? EMPTY_ROLES
  const selectedRoleId = roles.some((role) => role.id === selectedRoleIdState)
    ? selectedRoleIdState
    : roles[0]?.id
  const selectedRole = roles.find((role) => role.id === selectedRoleId)
  const rolePermissionsQuery = useQuery({
    queryKey: ['role-permissions', selectedRoleId],
    queryFn: () => getRolePermissions(selectedRoleId),
    enabled: Boolean(selectedRoleId),
    retry: 1,
  })

  const selectedPermissionIds = permissionDraft.roleId === selectedRoleId
    ? permissionDraft.ids
    : (rolePermissionsQuery.data ?? []).map((permission) => permission.id)

  const filteredRoles = useMemo(() => {
    const normalizedSearch = search.trim().toLocaleLowerCase('vi')
    if (!normalizedSearch) return roles
    return roles.filter((role) =>
      `${role.name ?? ''} ${role.slug ?? ''}`.toLocaleLowerCase('vi').includes(normalizedSearch),
    )
  }, [roles, search])

  const invalidateRoleData = () => {
    queryClient.invalidateQueries({ queryKey: ['roles'] })
    queryClient.invalidateQueries({ queryKey: ['roles', 'options'] })
    queryClient.invalidateQueries({ queryKey: ['role-permissions'] })
  }

  const saveRolePermissionsMutation = useMutation({
    mutationFn: () => saveRolePermissions({
      role_id: selectedRoleId,
      permission_ids: selectedPermissionIds,
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['role-permissions', selectedRoleId] })
      queryClient.invalidateQueries({ queryKey: ['roles', 'management'] })
      message.success('Đã lưu phân quyền.')
    },
  })
  const saveRoleMutation = useMutation({
    mutationFn: (values) =>
      editingRole
        ? updateRole({ id: editingRole.id, name: values.name })
        : createRole({ name: values.name, code: values.code }),
    onSuccess: () => {
      invalidateRoleData()
      setRoleModalOpen(false)
      roleForm.resetFields()
      message.success(editingRole ? 'Đã cập nhật vai trò.' : 'Đã thêm vai trò.')
    },
  })
  const createGroupMutation = useMutation({
    mutationFn: createPermissionGroup,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['permission-groups', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['roles', 'management'] })
      setGroupModalOpen(false)
      groupForm.resetFields()
      message.success('Đã thêm nhóm quyền.')
    },
  })
  const deleteGroupMutation = useMutation({
    mutationFn: deletePermissionGroup,
    onSuccess: async (_, groupId) => {
      await queryClient.invalidateQueries({ queryKey: ['permission-groups', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['roles', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['role-permissions'] })
      const deletedPermissionIds = new Set(
        (groups.find((group) => group.id === groupId)?.permissions ?? []).map((permission) => permission.id),
      )
      setPermissionDraft((current) => ({
        roleId: current.roleId,
        ids: current.ids.filter((id) => !deletedPermissionIds.has(id)),
      }))
      message.success('Đã xóa nhóm quyền.')
    },
  })
  const savePermissionMutation = useMutation({
    mutationFn: ({ isEdit, id, ...values }) =>
      isEdit ? updatePermission({ id, ...values }) : createPermission(values),
    onSuccess: async (_, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['permission-groups', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['roles', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['role-permissions'] })
      setPermissionModalOpen(false)
      setEditingPermission(null)
      permissionForm.resetFields()
      message.success(variables.isEdit ? 'Đã cập nhật quyền.' : 'Đã thêm quyền.')
    },
  })
  const deletePermissionMutation = useMutation({
    mutationFn: deletePermission,
    onSuccess: async (_, permissionId) => {
      await queryClient.invalidateQueries({ queryKey: ['permission-groups', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['roles', 'management'] })
      await queryClient.invalidateQueries({ queryKey: ['role-permissions'] })
      setPermissionDraft((current) => ({
        roleId: current.roleId,
        ids: current.ids.filter((id) => id !== permissionId),
      }))
      message.success('Đã xóa quyền.')
    },
  })
  const deleteRoleMutation = useMutation({
    mutationFn: deleteRole,
    onSuccess: () => {
      invalidateRoleData()
      message.success('Đã xóa vai trò.')
    },
  })

  const openCreateRole = () => {
    setEditingRole(null)
    setRoleCodeManuallyEdited(false)
    roleForm.resetFields()
    setRoleModalOpen(true)
  }
  const openEditRole = (role) => {
    setEditingRole(role)
    roleForm.setFieldsValue({ name: role.name })
    setRoleModalOpen(true)
  }
  const closeRoleModal = () => {
    setRoleModalOpen(false)
    roleForm.resetFields()
  }
  const openCreateGroup = () => {
    groupForm.resetFields()
    setGroupModalOpen(true)
  }
  const closeGroupModal = () => {
    setGroupModalOpen(false)
    groupForm.resetFields()
  }
  const openCreatePermission = (groupId) => {
    setEditingPermission(null)
    setPermissionCodeManuallyEdited(false)
    permissionForm.resetFields()
    permissionForm.setFieldsValue({ permission_group_id: groupId })
    setPermissionModalOpen(true)
  }
  const openEditPermission = (permission) => {
    setEditingPermission(permission)
    setPermissionCodeManuallyEdited(true)
    permissionForm.setFieldsValue({
      permission_group_id: permission.permission_group_id,
      name: permission.name,
      slug: permission.slug,
    })
    setPermissionModalOpen(true)
  }
  const closePermissionModal = () => {
    setPermissionModalOpen(false)
    setEditingPermission(null)
    permissionForm.resetFields()
  }

  const permissionsByRole = useMemo(() => {
    const counts = new Map()
    for (const assignment of rolesQuery.data?.permissionRoles ?? []) {
      counts.set(assignment.role_id, (counts.get(assignment.role_id) ?? 0) + 1)
    }
    return counts
  }, [rolesQuery.data])

  const columns = [
    {
      title: 'Vai trò',
      dataIndex: 'name',
      sorter: (left, right) => (left.name ?? '').localeCompare(right.name ?? '', 'vi'),
      render: (name, role) => (
        <div className="role-settings-table__name">
          <span className="role-settings-table__icon"><SafetyOutlined /></span>
          <span><strong>{name}</strong><small>{role.slug || 'Chưa có mã vai trò'}</small></span>
        </div>
      ),
    },
    {
      title: 'Số quyền',
      align: 'center',
      width: 140,
      sorter: (left, right) => (permissionsByRole.get(left.id) ?? 0) - (permissionsByRole.get(right.id) ?? 0),
      render: (_, role) => {
        const permissionCount = permissionsByRole.get(role.id) ?? 0
        return <Tag color={permissionCount ? 'blue' : 'default'}>{permissionCount} quyền</Tag>
      },
    },
    {
      title: 'Thao tác',
      align: 'center',
      width: 68,
      fixed: 'right',
      className: 'role-settings-action-column',
      render: (_, role) => {
        const actionItems = [{
          key: 'select',
          icon: selectedRoleId === role.id ? <CheckOutlined /> : <SafetyOutlined />,
          label: selectedRoleId === role.id ? 'Đang chọn' : 'Phân quyền',
          onClick: () => setSelectedRoleId(role.id),
        }]

        if (role.slug !== 'admin') {
          actionItems.push(
            {
              key: 'edit',
              icon: <EditOutlined />,
              label: 'Sửa vai trò',
              onClick: () => openEditRole(role),
            },
            {
              key: 'delete',
              icon: <DeleteOutlined />,
              label: 'Xóa vai trò',
              danger: true,
              disabled: deleteRoleMutation.isPending,
              onClick: () => Modal.confirm({
                title: `Xóa vai trò ${role.name}?`,
                content: 'Nhân viên đang dùng vai trò này có thể bị ảnh hưởng.',
                okText: 'Xóa',
                cancelText: 'Hủy',
                okButtonProps: { danger: true },
                onOk: () => deleteRoleMutation.mutateAsync(role.id),
              }),
            },
          )
        }

        return (
          <div onClick={(event) => event.stopPropagation()}>
            <Dropdown menu={{ items: actionItems }} trigger={['click']} placement="bottomRight">
              <Button
                type="text"
                size="small"
                icon={<MoreOutlined />}
                loading={deleteRoleMutation.isPending && deleteRoleMutation.variables === role.id}
                aria-label={`Mở thao tác vai trò ${role.name}`}
                aria-haspopup="menu"
              />
            </Dropdown>
          </div>
        )
      },
    },
  ]

  const toggleGroupPermissions = (permissionIds, checked) => {
    const next = new Set(selectedPermissionIds)
    permissionIds.forEach((id) => checked ? next.add(id) : next.delete(id))
    setPermissionDraft({
      roleId: selectedRoleId,
      ids: [...next],
    })
  }

  const togglePermission = (permissionId, checked) => {
    const next = new Set(selectedPermissionIds)
    if (checked) next.add(permissionId)
    else next.delete(permissionId)
    setPermissionDraft({ roleId: selectedRoleId, ids: [...next] })
  }

  const togglePermissionGroup = (groupId) => {
    setExpandedPermissionGroups((current) => {
      const next = new Set(current)
      if (next.has(groupId)) next.delete(groupId)
      else next.add(groupId)
      return next
    })
  }

  const mutationError = saveRolePermissionsMutation.error
    || saveRoleMutation.error
    || deleteRoleMutation.error
    || deleteGroupMutation.error
    || savePermissionMutation.error
    || deletePermissionMutation.error
  const groups = groupsQuery.data ?? EMPTY_GROUPS

  return (
    <main className="role-settings-page">
      <header className="role-settings-heading">
        <div>
          <Typography.Title level={3}>Cài đặt phân quyền</Typography.Title>
          <Typography.Paragraph>
            Quản lý vai trò và chọn những chức năng mỗi vai trò được phép sử dụng.
          </Typography.Paragraph>
        </div>
        <Space wrap>
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreateRole}>Thêm chức vụ</Button>
          <Button icon={<PlusOutlined />} onClick={openCreateGroup}>Thêm nhóm quyền</Button>
        </Space>
      </header>

      {(rolesQuery.error || groupsQuery.error || mutationError || createGroupMutation.error) && (
        <Alert
          className="role-settings-alert"
          type="error"
          showIcon
          message="Không thể hoàn tất thao tác"
          description={getApiErrorMessage(rolesQuery.error || groupsQuery.error || mutationError || createGroupMutation.error)}
        />
      )}

      <Card className="role-settings-card" title="Danh sách vai trò">
        <div className="role-settings-toolbar">
          <div className="role-settings-toolbar__summary">
            <Typography.Text strong>{filteredRoles.length} / {roles.length} vai trò</Typography.Text>
            <Typography.Text type="secondary">
              {selectedRole ? `Đang cấu hình: ${selectedRole.name}` : 'Chọn một vai trò để cài đặt quyền truy cập'}
            </Typography.Text>
          </div>
          <Input.Search
            allowClear
            className="role-settings-search"
            placeholder="Tìm tên hoặc mã vai trò"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        <Table
          className="app-table role-settings-table"
          rowKey="id"
          columns={columns}
          dataSource={filteredRoles}
          loading={rolesQuery.isLoading}
          pagination={false}
          showSorterTooltip={false}
          locale={{
            emptyText: rolesQuery.error
              ? 'Không tải được danh sách vai trò'
              : search.trim()
                ? 'Không tìm thấy vai trò phù hợp'
                : 'Chưa có vai trò nào',
          }}
          rowClassName={(role) => role.id === selectedRoleId ? 'role-settings-table__row--selected' : ''}
          onRow={(role) => ({ onClick: () => setSelectedRoleId(role.id) })}
        />
      </Card>

      {selectedRole && (
        <Card
          className="role-settings-card role-settings-permissions"
          title={<span>Quyền của vai trò: <strong>{selectedRole.name}</strong></span>}
          extra={(
            <Button
              type="primary"
              icon={<SaveOutlined />}
              loading={saveRolePermissionsMutation.isPending}
              disabled={rolePermissionsQuery.isLoading || groupsQuery.isLoading}
              onClick={() => saveRolePermissionsMutation.mutate()}
            >
              Lưu phân quyền
            </Button>
          )}
        >
          <Typography.Paragraph className="role-settings-permissions__hint">
            Bật hoặc tắt quyền truy cập; mỗi quyền có thể được thêm, sửa hoặc xóa riêng.
          </Typography.Paragraph>
          {rolePermissionsQuery.error && (
            <Alert
              className="role-settings-alert"
              type="error"
              showIcon
              message="Không thể tải quyền của vai trò"
              description={getApiErrorMessage(rolePermissionsQuery.error)}
            />
          )}
          {groupsQuery.isLoading || rolePermissionsQuery.isLoading ? (
            <div className="role-settings-loading"><Spin /></div>
          ) : groups.length ? (
            <div className="role-settings-group-list">
              {groups.map((group) => {
                const permissions = group.permissions ?? []
                const permissionIds = permissions.map((permission) => permission.id)
                const checkedCount = permissionIds.filter((id) => selectedPermissionIds.includes(id)).length
                const isExpanded = expandedPermissionGroups.has(group.id)
                const permissionListId = `role-permissions-${group.id}`
                return (
                  <section className="role-settings-group" key={group.id}>
                    <div className="role-settings-group__heading">
                      <div>
                        <Typography.Title level={5}>{group.name}</Typography.Title>
                        <Typography.Text type="secondary">
                          {checkedCount}/{permissions.length} quyền đang bật
                        </Typography.Text>
                      </div>
                      <Space wrap className="role-settings-group__actions">
                        <Button
                          size="small"
                          type="default"
                          className="role-settings-group__toggle"
                          aria-expanded={isExpanded}
                          aria-controls={permissionListId}
                          icon={<DownOutlined className={isExpanded ? 'role-settings-group__chevron--expanded' : ''} />}
                          onClick={() => togglePermissionGroup(group.id)}
                        >
                          {isExpanded ? 'Thu gọn' : 'Xem quyền'}
                        </Button>
                        <Checkbox
                          checked={permissions.length > 0 && checkedCount === permissions.length}
                          indeterminate={checkedCount > 0 && checkedCount < permissions.length}
                          disabled={!permissions.length}
                          onChange={(event) => toggleGroupPermissions(permissionIds, event.target.checked)}
                        >
                          Chọn cả nhóm
                        </Checkbox>
                        <Dropdown
                          menu={{
                            items: [
                              {
                                key: 'add',
                                icon: <PlusOutlined />,
                                label: 'Thêm quyền',
                                onClick: () => openCreatePermission(group.id),
                              },
                              {
                                key: 'delete',
                                icon: <DeleteOutlined />,
                                label: 'Xóa nhóm quyền',
                                danger: true,
                                disabled: deleteGroupMutation.isPending,
                                onClick: () => Modal.confirm({
                                  title: `Xóa nhóm quyền ${group.name}?`,
                                  content: 'Tất cả quyền trong nhóm sẽ bị xóa và gỡ khỏi mọi chức vụ.',
                                  okText: 'Xóa',
                                  cancelText: 'Hủy',
                                  okButtonProps: { danger: true },
                                  onOk: () => deleteGroupMutation.mutateAsync(group.id),
                                }),
                              },
                            ],
                          }}
                          trigger={['click']}
                          placement="bottomRight"
                        >
                          <Button
                            type="text"
                            size="small"
                            icon={<MoreOutlined />}
                            loading={deleteGroupMutation.isPending && deleteGroupMutation.variables === group.id}
                            aria-label={`Mở thao tác nhóm quyền ${group.name}`}
                            aria-haspopup="menu"
                          />
                        </Dropdown>
                      </Space>
                    </div>
                    {isExpanded && (
                      <div id={permissionListId} className="role-settings-group__content">
                        {permissions.length ? (
                          <Table
                            className="app-table role-settings-permission-table"
                            rowKey="id"
                            size="small"
                            pagination={false}
                            scroll={{ x: 520 }}
                            dataSource={permissions}
                            columns={[
                              {
                                title: 'Quyền truy cập',
                                dataIndex: 'name',
                                render: (name, permission) => (
                                  <Checkbox
                                    className="role-settings-permission__checkbox"
                                    checked={selectedPermissionIds.includes(permission.id)}
                                    aria-label={`Gán quyền ${name} cho ${selectedRole.name}`}
                                    onChange={(event) => togglePermission(permission.id, event.target.checked)}
                                  >
                                    <span className="role-settings-permission__label">
                                      <strong>{name}</strong>
                                      <small>{permission.slug}</small>
                                    </span>
                                  </Checkbox>
                                ),
                              },
                              {
                                title: 'Trạng thái',
                                align: 'center',
                                width: 140,
                                render: (_, permission) => (
                                  <Tag color={selectedPermissionIds.includes(permission.id) ? 'success' : 'default'}>
                                    {selectedPermissionIds.includes(permission.id) ? 'Đang bật' : 'Đang tắt'}
                                  </Tag>
                                ),
                              },
                              {
                                title: 'Thao tác',
                                align: 'center',
                                width: 68,
                                className: 'role-settings-action-column',
                                render: (_, permission) => (
                                  <Dropdown
                                    menu={{
                                      items: [
                                        {
                                          key: 'edit',
                                          icon: <EditOutlined />,
                                          label: 'Sửa quyền',
                                          onClick: () => openEditPermission(permission),
                                        },
                                        {
                                          key: 'delete',
                                          icon: <DeleteOutlined />,
                                          label: 'Xóa quyền',
                                          danger: true,
                                          disabled: deletePermissionMutation.isPending,
                                          onClick: () => Modal.confirm({
                                            title: `Xóa quyền ${permission.name}?`,
                                            content: 'Quyền này sẽ bị gỡ khỏi tất cả chức vụ.',
                                            okText: 'Xóa',
                                            cancelText: 'Hủy',
                                            okButtonProps: { danger: true },
                                            onOk: () => deletePermissionMutation.mutateAsync(permission.id),
                                          }),
                                        },
                                      ],
                                    }}
                                    trigger={['click']}
                                    placement="bottomRight"
                                  >
                                    <Button
                                      type="text"
                                      size="small"
                                      icon={<MoreOutlined />}
                                      loading={deletePermissionMutation.isPending && deletePermissionMutation.variables === permission.id}
                                      aria-label={`Mở thao tác quyền ${permission.name}`}
                                      aria-haspopup="menu"
                                    />
                                  </Dropdown>
                                ),
                              },
                            ]}
                          />
                        ) : (
                          <div className="role-settings-group__empty">
                            <Typography.Text type="secondary">Nhóm này chưa có quyền.</Typography.Text>
                            <Button
                              size="small"
                              icon={<PlusOutlined />}
                              onClick={() => openCreatePermission(group.id)}
                            >
                              Thêm quyền đầu tiên
                            </Button>
                          </div>
                        )}
                      </div>
                    )}
                  </section>
                )
              })}
            </div>
          ) : (
            <Empty description="Chưa có nhóm quyền nào để cài đặt." />
          )}
        </Card>
      )}

      <Modal
        title={editingRole ? 'Sửa chức vụ' : 'Thêm chức vụ'}
        open={roleModalOpen}
        okText={editingRole ? 'Lưu thay đổi' : 'Đồng ý'}
        cancelText="Hủy"
        confirmLoading={saveRoleMutation.isPending}
        onCancel={closeRoleModal}
        onOk={() => roleForm.submit()}
        destroyOnHidden
      >
        <Form
          form={roleForm}
          layout="vertical"
          onValuesChange={(changedValues, values) => {
            if ('name' in changedValues && !roleCodeManuallyEdited) {
              roleForm.setFieldValue('code', slugify(values.name || ''))
            }
          }}
          onFinish={(values) => saveRoleMutation.mutate(values)}
        >
          <Form.Item
            name="name"
            label="Chức vụ"
            rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập chức vụ.' }]}
          >
            <Input maxLength={255} placeholder="Nhập chức vụ" />
          </Form.Item>
          {!editingRole && (
            <Form.Item
              name="code"
              label="Mã chức vụ"
              rules={[
                { required: true, whitespace: true, message: 'Vui lòng nhập mã chức vụ.' },
                { pattern: /^[a-z0-9-]+$/, message: 'Chỉ dùng chữ thường không dấu, số và dấu gạch ngang.' },
              ]}
            >
              <Input
                maxLength={255}
                placeholder="Nhập mã chức vụ"
                onChange={() => setRoleCodeManuallyEdited(true)}
              />
            </Form.Item>
          )}
        </Form>
      </Modal>

      <Modal
        title="Thêm nhóm quyền"
        open={groupModalOpen}
        okText="Đồng ý"
        cancelText="Hủy"
        confirmLoading={createGroupMutation.isPending}
        onCancel={closeGroupModal}
        onOk={() => groupForm.submit()}
        destroyOnHidden
      >
        <Form form={groupForm} layout="vertical" onFinish={(values) => createGroupMutation.mutate(values)}>
          <Form.Item
            name="name"
            label="Tên nhóm quyền"
            rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập tên nhóm quyền.' }]}
          >
            <Input maxLength={255} placeholder="Nhập tên nhóm quyền" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={editingPermission ? 'Cập nhật quyền' : 'Thêm quyền'}
        open={permissionModalOpen}
        okText="Đồng ý"
        cancelText="Hủy"
        confirmLoading={savePermissionMutation.isPending}
        onCancel={closePermissionModal}
        onOk={() => permissionForm.submit()}
        destroyOnHidden
      >
        <Form
          form={permissionForm}
          layout="vertical"
          onValuesChange={(changedValues, values) => {
            if ('name' in changedValues && !permissionCodeManuallyEdited) {
              permissionForm.setFieldValue('slug', slugify(values.name || ''))
            }
          }}
          onFinish={(values) => savePermissionMutation.mutate({
            ...values,
            ...(editingPermission ? { id: editingPermission.id, isEdit: true } : { isEdit: false }),
          })}
        >
          <Form.Item
            name="permission_group_id"
            label="Nhóm quyền"
            rules={[{ required: true, message: 'Vui lòng chọn nhóm quyền.' }]}
          >
            <Select
              placeholder="Chọn nhóm quyền"
              options={groups.map((group) => ({ value: group.id, label: group.name }))}
            />
          </Form.Item>
          <Form.Item
            name="name"
            label="Tên quyền"
            rules={[{ required: true, whitespace: true, message: 'Vui lòng nhập tên quyền.' }]}
          >
            <Input maxLength={255} placeholder="Nhập tên quyền" />
          </Form.Item>
          <Form.Item
            name="slug"
            label="Slug"
            extra={editingPermission ? undefined : 'Mã quyền được dùng để kiểm tra quyền truy cập trong hệ thống.'}
            rules={[
              { required: true, whitespace: true, message: 'Vui lòng nhập mã quyền.' },
              { pattern: /^[a-z0-9-]+$/, message: 'Chỉ dùng chữ thường không dấu, số và dấu gạch ngang.' },
            ]}
          >
            <Input
              maxLength={255}
              placeholder="Nhập slug"
              disabled={Boolean(editingPermission)}
              onChange={() => setPermissionCodeManuallyEdited(true)}
            />
          </Form.Item>
        </Form>
      </Modal>
    </main>
  )
}

export default RoleSettingsPage
