import { DeleteOutlined, EditOutlined, MoreOutlined, PlusOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Card, Dropdown, Empty, Form, Input, InputNumber, Modal, Table } from 'antd'
import { useState } from 'react'
import {
  createLoyaltyTier,
  deleteLoyaltyTier,
  getLoyaltyTiers,
  updateLoyaltyTier,
} from '../../api/loyalty.api.js'
import { ApiBusinessError, getApiErrorMessage } from '../../utils/response.js'
import './loyalty.css'

const formatVnd = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  return `${new Intl.NumberFormat('vi-VN').format(Number(value))} đ`
}

const formatPercent = (value) => `${new Intl.NumberFormat('vi-VN').format(Number(value) || 0)}%`

const formatRange = (tier) => {
  const min = formatVnd(tier.min_order_value)
  const max = tier.max_order_value === null || tier.max_order_value === undefined
    ? 'Không giới hạn'
    : formatVnd(tier.max_order_value)

  return `${min} -> ${max}`
}

const getErrorMessage = (error) => {
  if (error instanceof ApiBusinessError) return getApiErrorMessage(error)
  return 'Không thể kết nối đến máy chủ.'
}

const emptyFormValues = {
  name: '',
  min_order_value: undefined,
  max_order_value: null,
  discount_percent: undefined,
}

const formatNumberInput = (value) => {
  if (value === undefined || value === null || value === '') return ''

  return new Intl.NumberFormat('vi-VN', {
    maximumFractionDigits: 2,
  }).format(Number(value))
}

const parseNumberInput = (value) => value?.replace(/[^\d,.-]/g, '').replace(',', '.')

const LoyaltyPage = () => {
  const [form] = Form.useForm()
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingTier, setEditingTier] = useState(null)

  const tiersQuery = useQuery({
    queryKey: ['loyalty-tiers'],
    queryFn: getLoyaltyTiers,
  })

  const invalidateTiers = () => queryClient.invalidateQueries({ queryKey: ['loyalty-tiers'] })

  const saveMutation = useMutation({
    mutationFn: (values) =>
      editingTier ? updateLoyaltyTier({ id: editingTier.id, ...values }) : createLoyaltyTier(values),
    onSuccess: () => {
      message.success(editingTier ? 'Cập nhật hạng thành công.' : 'Thêm hạng thành công.')
      setModalOpen(false)
      setEditingTier(null)
      form.resetFields()
      invalidateTiers()
    },
    onError: (error) => message.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteLoyaltyTier,
    onSuccess: () => {
      message.success('Xóa hạng thành công.')
      invalidateTiers()
    },
    onError: (error) => message.error(getErrorMessage(error)),
    onError: (error) => message.error(getErrorMessage(error)),
  })

  const openCreateModal = () => {
    setEditingTier(null)
    form.setFieldsValue(emptyFormValues)
    setModalOpen(true)
  }

  const openEditModal = (tier) => {
    setEditingTier(tier)
    form.setFieldsValue({
      name: tier.name,
      min_order_value: Number(tier.min_order_value),
      max_order_value: tier.max_order_value === null ? null : Number(tier.max_order_value),
      discount_percent: Number(tier.discount_percent),
    })
    setModalOpen(true)
  }

  const closeModal = () => {
    if (saveMutation.isPending) return
    setModalOpen(false)
    setEditingTier(null)
    form.resetFields()
  }

  const handleSubmit = (values) => {
    saveMutation.mutate({
      ...values,
      max_order_value: values.max_order_value ?? null,
    })
  }

  const columns = [
    {
      title: 'STT',
      key: 'index',
      width: 76,
      align: 'center',
      render: (_, __, index) => index + 1,
    },
    {
      title: 'Tên Hạng',
      dataIndex: 'name',
      key: 'name',
    },
    {
      title: 'Giá trị đơn hàng tích lũy',
      key: 'range',
      width: 330,
      render: (_, tier) => formatRange(tier),
    },
    {
      title: 'Giảm giá / Tổng đơn hàng',
      key: 'discount',
      width: 250,
      align: 'center',
      render: (_, tier) => formatPercent(tier.discount_percent),
    },
    {
      title: 'Thao tác',
      key: 'actions',
      width: 68,
      align: 'center',
      fixed: 'right',
      className: 'loyalty-action-column',
      render: (_, tier) => (
        <Dropdown
          menu={{
            items: [
              {
                key: 'edit',
                icon: <EditOutlined />,
                label: 'Sửa hạng',
                onClick: () => openEditModal(tier),
              },
              {
                key: 'delete',
                icon: <DeleteOutlined />,
                label: 'Xóa hạng',
                danger: true,
                disabled: deleteMutation.isPending,
                onClick: () => Modal.confirm({
                  title: 'Xóa hạng khách hàng?',
                  content: 'Dữ liệu hạng này sẽ bị xóa vĩnh viễn.',
                  okText: 'Xóa',
                  cancelText: 'Hủy',
                  okButtonProps: { danger: true, loading: deleteMutation.isPending },
                  onOk: () => deleteMutation.mutateAsync(tier.id),
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
            loading={deleteMutation.isPending && deleteMutation.variables === tier.id}
            aria-label={`Mở thao tác hạng ${tier.name}`}
            aria-haspopup="menu"
          />
        </Dropdown>
      ),
    },
  ]

  return (
    <main className="loyalty-page">
      <Card
        className="loyalty-card"
        title="Loyalty"
        extra={
          <Button type="primary" icon={<PlusOutlined />} onClick={openCreateModal}>
            Thêm hạng mới
          </Button>
        }
      >
        {tiersQuery.isError ? (
          <Empty description={getErrorMessage(tiersQuery.error)} />
        ) : (
          <Table
            className="app-table loyalty-table"
            rowKey="id"
            columns={columns}
            dataSource={tiersQuery.data ?? []}
            loading={tiersQuery.isLoading}
            pagination={false}
            scroll={{ x: 760, y: 'calc(100vh - 250px)' }}
            locale={{ emptyText: <Empty description="Chưa có hạng khách hàng." /> }}
          />
        )}
      </Card>

      <Modal
        className="loyalty-tier-modal"
        title={editingTier ? 'Cập nhật hạng khách hàng' : 'Thêm hạng khách hàng'}
        open={modalOpen}
        onCancel={closeModal}
        okText={editingTier ? 'Cập nhật' : 'Thêm mới'}
        cancelText="Hủy"
        confirmLoading={saveMutation.isPending}
        onOk={() => form.submit()}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" initialValues={emptyFormValues} onFinish={handleSubmit}>
          <Form.Item
            label="Tên hạng"
            name="name"
            rules={[{ required: true, message: 'Vui lòng nhập tên hạng.' }]}
          >
            <Input placeholder="Ví dụ: Hạng Vàng" maxLength={100} />
          </Form.Item>
          <Form.Item
            label="Giá trị đơn hàng tối thiểu"
            name="min_order_value"
            rules={[{ required: true, message: 'Vui lòng nhập giá trị tối thiểu.' }]}
          >
            <InputNumber
              className="loyalty-tier-number-input"
              min={0}
              precision={2}
              controls={false}
              formatter={formatNumberInput}
              parser={parseNumberInput}
              placeholder="Nhập giá trị tối thiểu..."
            />
          </Form.Item>
          <Form.Item
            label="Giá trị đơn hàng tối đa"
            name="max_order_value"
            dependencies={['min_order_value']}
            rules={[
              ({ getFieldValue }) => ({
                validator(_, value) {
                  if (value === null || value === undefined || value > getFieldValue('min_order_value')) {
                    return Promise.resolve()
                  }
                  return Promise.reject(new Error('Giá trị tối đa phải lớn hơn giá trị tối thiểu.'))
                },
              }),
            ]}
          >
            <InputNumber
              className="loyalty-tier-number-input"
              min={0}
              precision={2}
              controls={false}
              formatter={formatNumberInput}
              parser={parseNumberInput}
              placeholder="Nhập giá trị tối đa..."
            />
          </Form.Item>
          <Form.Item
            label="Phần trăm giảm giá"
            name="discount_percent"
            rules={[{ required: true, message: 'Vui lòng nhập phần trăm giảm giá.' }]}
          >
            <InputNumber
              className="loyalty-tier-number-input"nn
              min={0}
              max={100}
              precision={2}
              controls={false}
              formatter={(value) => (value === undefined || value === null || value === '' ? '' : `${value}%`)}
              parser={(value) => value?.replace('%', '')}
              placeholder="XX%"
            />
          </Form.Item>
        </Form>
      </Modal>
    </main>
  )
}

export default LoyaltyPage
