import { CloseOutlined, EditOutlined, FilterOutlined, PlusOutlined, ReloadOutlined, UploadOutlined } from '@ant-design/icons'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Button, Card, Empty, Form, Image, Input, InputNumber, Modal, Table, Tooltip, Upload } from 'antd'
import { useMemo, useState } from 'react'
import { createProduct, getProducts, updateProduct } from '../../api/products.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import { useAuthStore } from '../../auth/auth.store.js'
import { canManageProducts as canEditProducts } from '../../auth/permissions.js'
import { getProductImage, getProductName, PRODUCT_PLACEHOLDER } from '../../utils/products.js'
import './products.css'

const EMPTY_PRODUCTS = Object.freeze([])

const getInitial = (name) => name?.trim()?.charAt(0)?.toLocaleUpperCase('vi') || '—'

const formatPrice = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? new Intl.NumberFormat('vi-VN').format(amount) : '—'
}

const formatQuantity = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  return Number.isFinite(amount) ? new Intl.NumberFormat('vi-VN').format(amount) : '—'
}

const formatPriceInput = (value) => {
  if (value === null || value === undefined || value === '') return ''
  const [integer, fraction] = String(value).split('.')
  const groupedInteger = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return fraction === undefined ? groupedInteger : `${groupedInteger},${fraction}`
}

const parsePriceInput = (value) => value
  ?.replace(/\./g, '')
  .replace(',', '.')
  .replace(/[^\d.-]/g, '') ?? ''

const ProductsPage = () => {
  const queryClient = useQueryClient()
  const { message } = App.useApp()
  const user = useAuthStore((state) => state.user)
  const canManageProducts = canEditProducts(user)
  const [form] = Form.useForm()
  const [modalOpen, setModalOpen] = useState(false)
  const [editingProduct, setEditingProduct] = useState(null)
  const [imageFiles, setImageFiles] = useState([])
  const [imageRemoved, setImageRemoved] = useState(false)

  const [shopId, setShopId] = useState(undefined)
  const [search, setSearch] = useState('')
  const [appliedShopId, setAppliedShopId] = useState(undefined)
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const pageSize = 24

  const productsQuery = useQuery({
    queryKey: [
      'products',
      { shop_id: appliedShopId ?? undefined, search: appliedSearch, page, page_size: pageSize },
    ],
    queryFn: () =>
      getProducts({
        shop_id: appliedShopId,
        search: appliedSearch,
        page,
        page_size: pageSize,
      }),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
      retry: 1,
      refetchOnWindowFocus: false,
  })

  const products = productsQuery.data?.products ?? EMPTY_PRODUCTS
  const productRows = useMemo(
      () => products.map((product) => ({
        ...product,
        displayName: getProductName(product),
        displayImage: getProductImage(product),
        displayPrice: product.price,
      })),
      [products],
  )
  const totalItems = productsQuery.data?.total_items ?? 0
  const currentPage = productsQuery.data?.current_page ?? page

  const handlePageChange = (nextPage) => {
    setPage(nextPage)
  }

  const applyFilters = () => {
    setAppliedShopId(shopId)
    setAppliedSearch(search.trim())
    setPage(1)
  }

  const resetFilters = () => {
    setShopId(undefined)
    setSearch('')
    setAppliedShopId(undefined)
    setAppliedSearch('')
    setPage(1)
  }

  const saveMutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        ...values,
        image: imageFiles[0]?.originFileObj,
        remove_image: imageRemoved && !imageFiles[0]?.originFileObj,
      }
      return editingProduct
        ? updateProduct({ id: editingProduct.id, ...payload })
        : createProduct(payload)
    },
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      setModalOpen(false)
      message.success(result.message || 'Đã lưu sản phẩm.')
    },
    onError: (error) => {
      const errors = error?.errors ?? error?.response?.data?.errors
      if (errors) {
        form.setFields(Object.entries(errors).map(([name, messages]) => ({
          name, errors: Array.isArray(messages) ? messages : [messages],
        })))
      }
    },
  })

  const openProductForm = (product = null) => {
    saveMutation.reset()
    setEditingProduct(product)
    setImageFiles([])
    setImageRemoved(false)
    form.resetFields()
    form.setFieldsValue(product ? {
      name: product.name,
      shop_id: String(product.shop_id),
      selling_price: product.price,
      initial_quantity: product.inventory_quantity,
    } : { shop_id: shopId, initial_quantity: 0 })
    setModalOpen(true)
  }

  const columns = [
      {
        title: 'STT',
        key: 'index',
        width: 54,
        align: 'center',
        render: (_, __, index) => (page - 1) * pageSize + index + 1,
      },
      {
        title: 'Tên sản phẩm',
        dataIndex: 'name',
        key: 'name',
        width: 240,
        render: (_, record) => (
          <Tooltip title={record.displayName}>
            <span className="products-name">{record.displayName}</span>
          </Tooltip>
        ),
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 220,
        render: (shop, record) => {
          const shopName = record?.shop?.name || shop?.name || '—'
          return shopName === '—' ? '—' : (
            <Tooltip title={shopName}>
              <div className="products-shop">
                <Avatar size={32} className="products-shop__avatar" aria-hidden="true">
                  {getInitial(shopName)}
                </Avatar>
                <span className="products-shop__name">{shopName}</span>
              </div>
            </Tooltip>
          )
        },
      },
      {
        title: 'Hình ảnh',
        key: 'image',
        width: 420,
        align: 'center',
        render: (_, record) => {
          const image = record.displayImage

          return (
            <Image
              src={image}
              fallback={PRODUCT_PLACEHOLDER}
              loading="lazy"
              decoding="async"
              width={96}
              height={96}
              className="products-image"
              preview={image !== PRODUCT_PLACEHOLDER}
              alt={record.displayName}
            />
          )
        },
      },
      {
        title: 'Giá bán',
        key: 'price',
        width: 150,
        align: 'right',
        render: (_, record) => <span className="products-price">{formatPrice(record.displayPrice)}</span>,
      },
      {
        title: 'Tồn kho',
        dataIndex: 'inventory_quantity',
        key: 'inventory_quantity',
        width: 120,
        align: 'right',
        render: (quantity) => formatQuantity(quantity),
      },
      ...(canManageProducts ? [{
        title: 'Thao tác', key: 'actions', width: 110,
        render: (_, record) => (
          <Button icon={<EditOutlined />} onClick={() => openProductForm(record)}>Sửa</Button>
        ),
      }] : []),
    ]

  const currentProductImage = editingProduct ? getProductImage(editingProduct) : null

  return (
    <main className="products-page">
      <Card title="Danh sách sản phẩm" className="products-card" extra={canManageProducts && (
        <Button type="primary" icon={<PlusOutlined />} onClick={() => openProductForm()}>Thêm sản phẩm</Button>
      )}>
        <div className="products-toolbar">
          <div className="products-toolbar__field">
            <label>Cửa hàng</label>
            <ShopSelect
              className="shop-select"
              value={shopId}
              onChange={setShopId}
              useGlobalSelection={false}
            />
          </div>

          <div className="products-toolbar__field products-toolbar__field--search">
            <label htmlFor="products-search">Tìm kiếm</label>
            <Input
              id="products-search"
              allowClear
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Tìm kiếm sản phẩm..."
            />
          </div>

          <div className="products-toolbar__actions">
            <Button type="primary" icon={<FilterOutlined />} onClick={applyFilters}>
              Lọc
            </Button>
            <Button icon={<ReloadOutlined />} onClick={resetFilters}>
              Làm mới
            </Button>
          </div>
        </div>

        <div className="products-summary" aria-live="polite">
          Tổng số sản phẩm: <strong>{totalItems.toLocaleString('vi-VN')}</strong>
        </div>

        {productsQuery.isError && (
          <QueryErrorAlert
            error={productsQuery.error}
            fallbackTitle="Không thể tải danh sách sản phẩm"
            onRetry={() => productsQuery.refetch()}
            className="products-query-alert"
          />
        )}

        <Table
          className="app-table products-table"
          rowKey={(record) => record.id ?? record.pancake_product_id ?? record.name}
          columns={columns}
          dataSource={productRows}
          loading={productsQuery.isLoading || productsQuery.isFetching}
          scroll={{ x: 1200, y: 'calc(100vh - 430px)' }}
          pagination={{
            current: currentPage,
            pageSize,
            total: totalItems,
            showSizeChanger: false,
            position: ['bottomRight'],
            onChange: handlePageChange,
          }}
          locale={{
            emptyText: (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có sản phẩm phù hợp." />
            ),
          }}
        />
      </Card>
      <Modal
        title={editingProduct ? 'Sửa sản phẩm' : 'Thêm sản phẩm'}
        open={modalOpen}
        onCancel={() => { if (!saveMutation.isPending) setModalOpen(false) }}
        onOk={() => form.submit()}
        okText="Lưu sản phẩm"
        cancelText="Hủy"
        confirmLoading={saveMutation.isPending}
        cancelButtonProps={{ disabled: saveMutation.isPending }}
        closable={!saveMutation.isPending}
        maskClosable={!saveMutation.isPending}
        forceRender
      >
        {editingProduct?.pancake_product_id != null && (
          <Alert type="info" showIcon className="products-query-alert"
            title="Sản phẩm đồng bộ Pancake"
            description="Shop và mã Pancake được giữ nguyên. Lần đồng bộ tiếp theo sẽ cập nhật lại tên, giá, số lượng và ảnh từ Pancake." />
        )}
        {saveMutation.isError && (
          <Alert type="error" showIcon className="products-query-alert"
            title={getApiErrorMessage(saveMutation.error, 'Không thể lưu sản phẩm.')} />
        )}
        <Form form={form} layout="vertical" disabled={saveMutation.isPending} onFinish={(values) => saveMutation.mutate(values)}>
          <Form.Item name="name" label="Tên sản phẩm" rules={[{ required: true, whitespace: true, message: 'Nhập tên sản phẩm.' }, { max: 255 }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="shop_id" label="Shop" rules={[{ required: true, message: 'Chọn shop.' }]}>
            <ShopSelect useGlobalSelection={false} disabled={saveMutation.isPending || editingProduct?.pancake_product_id != null} />
          </Form.Item>
          <Form.Item name="selling_price" label="Giá bán (đ)" rules={[{ required: true, message: 'Nhập giá bán.' }, { type: 'number', min: 0, max: 999999999999.99 }]}>
            <InputNumber
              className="products-price-input"
              min={0}
              max={999999999999.99}
              precision={2}
              formatter={formatPriceInput}
              parser={parsePriceInput}
              style={{ width: '100%' }}
            />
          </Form.Item>
          <Form.Item name="initial_quantity" label={editingProduct ? 'Số lượng tồn gốc' : 'Số lượng tồn nhập ban đầu'}
            rules={[{ required: true, message: 'Nhập số lượng.' }, { type: 'integer', min: 0, max: 2147483647 }]}>
            <InputNumber min={0} max={2147483647} precision={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item label="Ảnh sản phẩm (không bắt buộc)" extra="JPG, PNG hoặc WebP, tối đa 5 MB. Không chọn ảnh mới sẽ giữ ảnh hiện tại.">
            {currentProductImage && currentProductImage !== PRODUCT_PLACEHOLDER && !imageRemoved && (
              <div className="products-image-editor">
                <span className="products-image-editor__label">Ảnh hiện tại</span>
                <div className="products-image-editor__thumbnail">
                  <Image
                    src={currentProductImage}
                    fallback={PRODUCT_PLACEHOLDER}
                    width={80}
                    height={80}
                    className="products-image-editor__preview"
                    alt={`Ảnh hiện tại của ${editingProduct.name}`}
                  />
                  <Button
                    className="products-image-editor__remove"
                    type="primary"
                    danger
                    shape="circle"
                    size="small"
                    icon={<CloseOutlined />}
                    aria-label="Xóa ảnh hiện tại"
                    title="Xóa ảnh hiện tại"
                    onClick={() => setImageRemoved(true)}
                  />
                </div>
              </div>
            )}
            <Upload accept="image/jpeg,image/png,image/webp" maxCount={1} fileList={imageFiles}
              beforeUpload={(file) => {
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                  message.error('Chọn ảnh JPG, PNG hoặc WebP không quá 5 MB.')
                  return Upload.LIST_IGNORE
                }
                return false
              }}
              onChange={({ fileList }) => setImageFiles(fileList)}>
              <Button icon={<UploadOutlined />}>Chọn ảnh</Button>
            </Upload>
          </Form.Item>
        </Form>
      </Modal>
    </main>
  )
}

export default ProductsPage
