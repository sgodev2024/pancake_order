import { Alert, Descriptions, Modal, Select } from 'antd'
import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { getAllUsers } from '../api/users.api.js'
import './customer-care-assignment-modal.css'

const CustomerCareAssignmentModal = ({
  open,
  title = 'Phân công nhân viên chăm sóc khách hàng',
  shopId,
  customerName,
  shopName,
  orderCode,
  selectedCount = 1,
  value,
  error,
  isSubmitting = false,
  onChange,
  onCancel,
  onSubmit,
}) => {
  const usersQuery = useQuery({
    queryKey: ['customer-care-assignment-users', shopId ?? 'all'],
    queryFn: () =>
      getAllUsers({
        ...(shopId ? { shop_id: shopId } : {}),
        assignment_eligible: 1,
      }),
    enabled: open,
    staleTime: 60_000,
  })

  const userOptions = useMemo(
    () =>
      (usersQuery.data ?? [])
        .filter((item) => {
          const pancakeUserId = item.pancake_user_id
          return pancakeUserId && !String(pancakeUserId).includes('-')
        })
        .map((item) => ({
          value: String(item.pancake_user_id),
          label: item.name || `Người dùng #${item.pancake_user_id}`,
        })),
    [usersQuery.data],
  )

  const isBulk = selectedCount > 1

  return (
    <Modal
      title={title}
      open={open}
      onCancel={onCancel}
      onOk={onSubmit}
      okText="Phân công"
      cancelText="Hủy"
      confirmLoading={isSubmitting}
      okButtonProps={{ disabled: !value || usersQuery.isError }}
      cancelButtonProps={{ disabled: isSubmitting }}
      closable={!isSubmitting}
      maskClosable={!isSubmitting}
      destroyOnHidden
      className="customer-care-assign-modal"
    >
      <div className="customer-care-assignment">
        <p className="customer-care-assignment__notice">
          {isBulk
            ? `Bạn sắp phân công ${selectedCount.toLocaleString('vi-VN')} đơn hàng cho một nhân viên chăm sóc.`
            : 'Bạn sắp phân công đơn hàng này cho một nhân viên chăm sóc.'}{' '}
          Hệ thống sẽ tạo lịch CSKH mới cho mỗi đơn được phân công thành công.
        </p>

        <Descriptions column={1} size="small" bordered>
          {customerName && <Descriptions.Item label="Khách hàng">{customerName}</Descriptions.Item>}
          <Descriptions.Item label="Cửa hàng">{shopName || (isBulk ? 'Nhiều cửa hàng' : '—')}</Descriptions.Item>
          <Descriptions.Item label={isBulk ? 'Số đơn đã chọn' : 'Đơn hàng'}>
            {isBulk ? selectedCount.toLocaleString('vi-VN') : orderCode || '—'}
          </Descriptions.Item>
        </Descriptions>

        <div className="customer-care-assignment__field">
          <label htmlFor="customer-care-assignment-user">Nhân viên chăm sóc</label>
          <Select
            id="customer-care-assignment-user"
            aria-label="Nhân viên chăm sóc"
            allowClear
            showSearch
            optionFilterProp="label"
            value={value}
            options={userOptions}
            loading={usersQuery.isLoading}
            status={error || usersQuery.isError ? 'error' : undefined}
            placeholder={shopId ? 'Chọn nhân viên cùng cửa hàng' : 'Chọn nhân viên chăm sóc'}
            disabled={isSubmitting}
            notFoundContent={usersQuery.isLoading ? 'Đang tải...' : 'Không có nhân viên phù hợp'}
            onChange={onChange}
          />
          <small>
            {shopId
              ? 'Chỉ hiển thị nhân sự CSKH có định danh Pancake hợp lệ trong cửa hàng này.'
              : 'Chỉ hiển thị nhân sự CSKH có định danh Pancake hợp lệ trong phạm vi cửa hàng của bạn.'}
          </small>
        </div>

        {usersQuery.isError && (
          <Alert
            type="warning"
            showIcon
            message="Không thể tải danh sách nhân viên. Vui lòng thử lại sau."
          />
        )}

        {error && <Alert type="error" showIcon message={error} role="alert" />}
      </div>
    </Modal>
  )
}

export default CustomerCareAssignmentModal
