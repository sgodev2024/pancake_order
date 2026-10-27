import { DownloadOutlined, FilterOutlined, ReloadOutlined, UploadOutlined, UserSwitchOutlined } from '@ant-design/icons'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Card, DatePicker, Empty, Pagination, Space, Table, Typography } from 'antd'
import { useMemo, useRef, useState } from 'react'
import {
  assignImportedOpportunities,
  downloadImportedOpportunityTemplate,
  getImportedOpportunities,
  importOpportunities,
} from '../../api/opportunities.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { hasPermission } from '../../auth/permissions.js'
import CustomerCareAssignmentModal from '../../components/CustomerCareAssignmentModal.jsx'
import ShopSelect from '../../components/ShopSelect.jsx'
import { ApiBusinessError, getApiErrorMessage } from '../../utils/response.js'
import './imported-opportunities.css'

const PAGE_SIZE = 30

const formatPhones = (value) => {
  if (Array.isArray(value)) return value.join(', ') || '—'
  return value || '—'
}

const getInitial = (name) => name?.trim()?.charAt(0)?.toUpperCase() || '—'

const ImportedOpportunitiesPage = () => {
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const fileInputRef = useRef(null)
  const currentUser = useAuthStore((state) => state.user)
  const canView = hasPermission(currentUser, 'view-chance')
  const canAssign = hasPermission(currentUser, 'asign-cskh')
  const [shopId, setShopId] = useState()
  const [dateRange, setDateRange] = useState(null)
  const [appliedFilters, setAppliedFilters] = useState({})
  const [page, setPage] = useState(1)
  const [selectedRowKeys, setSelectedRowKeys] = useState([])
  const [selectedRows, setSelectedRows] = useState([])
  const [assignmentUserId, setAssignmentUserId] = useState()
  const [assignmentError, setAssignmentError] = useState(null)
  const [assignmentOpen, setAssignmentOpen] = useState(false)
  const [importError, setImportError] = useState('')

  const params = useMemo(() => ({ ...appliedFilters, page }), [appliedFilters, page])
  const opportunitiesQuery = useQuery({
    queryKey: ['imported-opportunities', params],
    queryFn: () => getImportedOpportunities(params),
    enabled: canView,
    placeholderData: keepPreviousData,
  })

  const importMutation = useMutation({
    mutationFn: ({ file, selectedShopId }) => importOpportunities({ shopId: selectedShopId, file }),
    onSuccess: (result) => {
      setImportError('')
      message.success(result.message || 'Import cơ hội thành công.')
      queryClient.invalidateQueries({ queryKey: ['imported-opportunities'] })
      if (fileInputRef.current) fileInputRef.current.value = ''
    },
    onError: (error) => {
      setImportError(getApiErrorMessage(error, 'Không thể nhập file Excel.'))
      if (fileInputRef.current) fileInputRef.current.value = ''
    },
  })

  const assignMutation = useMutation({
    mutationFn: ({ ids, pancakeUserId }) => assignImportedOpportunities({ ids, pancakeUserId }),
    onSuccess: (result) => {
      message.success(result.message || 'Phân công thành công.')
      setSelectedRowKeys([])
      setSelectedRows([])
      setAssignmentUserId(undefined)
      setAssignmentError(null)
      setAssignmentOpen(false)
      queryClient.invalidateQueries({ queryKey: ['imported-opportunities'] })
    },
    onError: (error) => setAssignmentError(getApiErrorMessage(error, 'Không thể phân công khách hàng.')),
  })

  const handleFilter = () => {
    setAppliedFilters({
      shop_id: shopId,
      date_from: dateRange?.[0]?.format('YYYY-MM-DD'),
      date_to: dateRange?.[1]?.format('YYYY-MM-DD'),
    })
    setPage(1)
  }

  const resetFilters = () => {
    setShopId(undefined)
    setDateRange(null)
    setAppliedFilters({})
    setPage(1)
    setSelectedRowKeys([])
    setSelectedRows([])
    setAssignmentOpen(false)
  }

  const handleImport = (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    if (!shopId) {
      message.warning('Vui lòng chọn cửa hàng trước khi nhập Excel.')
      event.target.value = ''
      return
    }
    setImportError('')
    importMutation.mutate({ file, selectedShopId: shopId })
  }

  const downloadTemplate = async () => {
    try {
      const blob = await downloadImportedOpportunityTemplate()
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = 'mau-import-co-hoi.xlsx'
      link.click()
      URL.revokeObjectURL(url)
    } catch (error) {
      message.error(getApiErrorMessage(error, 'Không thể tải mẫu import.'))
    }
  }

  const data = opportunitiesQuery.data?.orders ?? []
  const totalItems = opportunitiesQuery.data?.total_items ?? 0
  const selectedShopIds = [...new Set(selectedRows.map((row) => row.shop_id).filter(Boolean))]
  const assignmentShopId = selectedShopIds.length === 1 ? selectedShopIds[0] : undefined
  const assignmentShopName = selectedShopIds.length === 1 ? selectedRows[0]?.shop?.name : undefined
  const assignmentCustomerName = selectedRows.length === 1 ? selectedRows[0]?.name : undefined
  const assignmentIds = selectedRows.map((row) => row.id).filter(Boolean)

  const submitAssignment = () => {
    if (!assignmentUserId) {
      setAssignmentError('Vui lòng chọn nhân viên chăm sóc khách hàng.')
      return
    }
    if (assignmentIds.length === 0) {
      setAssignmentError('Vui lòng chọn ít nhất một khách hàng.')
      return
    }
    setAssignmentError(null)
    assignMutation.mutate({ ids: assignmentIds, pancakeUserId: assignmentUserId })
  }

  const columns = [
    { title: 'STT', key: 'index', width: 64, align: 'center', render: (_, __, index) => (page - 1) * PAGE_SIZE + index + 1 },
    {
      title: 'Cửa hàng',
      dataIndex: 'shop',
      key: 'shop',
      width: 220,
      render: (shop) => shop?.name ? <Space><Avatar className="imported-opportunities-shop-avatar">{getInitial(shop.name)}</Avatar><span>{shop.name}</span></Space> : '—',
    },
    { title: 'Khách hàng', dataIndex: 'name', key: 'name', width: 190, render: (value) => value || '—' },
    { title: 'Số điện thoại', dataIndex: 'phone', key: 'phone', width: 180, render: formatPhones },
    { title: 'Địa chỉ', dataIndex: 'address', key: 'address', width: 320, render: (value) => value || '—' },
  ]

  if (!canView) {
    return <Card><Empty description="Bạn không có quyền xem cơ hội import." /></Card>
  }

  return (
    <main className="imported-opportunities-page">
      <Card className="imported-opportunities-card">
        <div className="imported-opportunities-toolbar">
          <Space wrap>
            <ShopSelect value={shopId} onChange={setShopId} useGlobalSelection={false} />
            <Button type="primary" icon={<FilterOutlined />} onClick={handleFilter}>Lọc</Button>
            <Button icon={<ReloadOutlined />} onClick={resetFilters}>Làm mới</Button>
            <Button icon={<DownloadOutlined />} onClick={downloadTemplate}>Tải file mẫu</Button>
            <Button icon={<UploadOutlined />} loading={importMutation.isPending} onClick={() => fileInputRef.current?.click()}>Nhập file Excel</Button>
            {canAssign && selectedRows.length > 0 && (
              <Button
                type="primary"
                icon={<UserSwitchOutlined />}
                onClick={() => {
                  setAssignmentUserId(undefined)
                  setAssignmentError(null)
                  setAssignmentOpen(true)
                }}
              >
                Phân công ({selectedRows.length})
              </Button>
            )}
            <input ref={fileInputRef} type="file" accept=".xlsx,.xls,.csv" hidden onChange={handleImport} />
          </Space>
        </div>
        {importError && (
          <Alert
            className="imported-opportunities-alert"
            type="error"
            showIcon
            closable
            onClose={() => setImportError('')}
            message="Không thể nhập file Excel"
            description={<Typography.Paragraph className="imported-opportunities-import-error">{importError}</Typography.Paragraph>}
          />
        )}
        <div className="imported-opportunities-summary">Số lượng: <strong>{totalItems}</strong></div>
        {opportunitiesQuery.isError && (
          <Alert className="imported-opportunities-alert" type={opportunitiesQuery.error instanceof ApiBusinessError ? 'warning' : 'error'} message={getApiErrorMessage(opportunitiesQuery.error)} action={<Button size="small" onClick={() => opportunitiesQuery.refetch()}>Thử lại</Button>} />
        )}
        <Table
          className="app-table imported-opportunities-table"
          rowKey="id"
          columns={columns}
          dataSource={data}
          rowSelection={{
            selectedRowKeys,
            preserveSelectedRowKeys: true,
            onChange: (keys, rows) => {
              setSelectedRowKeys(keys)
              setSelectedRows(rows)
            },
          }}
          loading={opportunitiesQuery.isLoading || opportunitiesQuery.isFetching}
          pagination={false}
          scroll={{ x: 1000 }}
          locale={{ emptyText: <Empty description="Chưa có cơ hội import." /> }}
        />
        <Pagination current={page} pageSize={PAGE_SIZE} total={totalItems} showSizeChanger={false} onChange={setPage} />
      </Card>
      <CustomerCareAssignmentModal
        open={assignmentOpen}
        shopId={assignmentShopId}
        shopName={assignmentShopName}
        customerName={assignmentCustomerName}
        selectedCount={selectedRows.length}
        value={assignmentUserId}
        error={assignmentError}
        isSubmitting={assignMutation.isPending}
        onCancel={() => {
          if (assignMutation.isPending) return
          setAssignmentUserId(undefined)
          setAssignmentError(null)
          setAssignmentOpen(false)
        }}
        onSubmit={submitAssignment}
        onChange={(value) => {
          setAssignmentUserId(value)
          setAssignmentError(null)
        }}
      />
    </main>
  )
}

export default ImportedOpportunitiesPage
