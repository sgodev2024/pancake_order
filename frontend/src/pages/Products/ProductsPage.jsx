import { FilterOutlined, ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Alert, Avatar, Button, Card, Empty, Image, Input, Space, Table } from 'antd'
import { useMemo, useState } from 'react'
import { getProducts } from '../../api/products.api.js'
import ShopSelect from '../../components/ShopSelect.jsx'
import { getApiErrorMessage } from '../../utils/response.js'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import './products.css'

const PRODUCT_PLACEHOLDER =
  'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22 viewBox=%220 0 48 48%22%3E%3Crect width=%2248%22 height=%2248%22 rx=%226%22 fill=%22%23f1f5f9%22/%3E%3Cpath d=%22M13 31l7-8 5 5 4-4 6 7H13z%22 fill=%22%2394a3b8%22/%3E%3Ccircle cx=%2220%22 cy=%2218%22 r=%223%22 fill=%22%2394a3b8%22/%3E%3C/svg%3E'
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

const getNestedValue = (source, path) => {
  if (!source || !path) return undefined
  return path.split('.').reduce((acc, key) => acc?.[key], source)
}

const getProductName = (product) => {
  const candidates = [
    product?.name,
    getNestedValue(product, 'pancake_full_data.product.name'),
    getNestedValue(product, 'pancake_full_data.variation_info.name'),
    getNestedValue(product, 'pancake_full_data.name'),
    'Sản phẩm',
  ]

  return candidates.find((value) => typeof value === 'string' && value.trim().length > 0) || 'Sản phẩm'
}

const toNumber = (value) => {
  if (value === null || value === undefined || value === '') return undefined

  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : undefined
  }

  if (typeof value !== 'string') return undefined

  const text = value.trim()
  if (!text) return undefined

  const normalized = text
    .replace(/\s+/g, '')
    .replace(/\.(?=\d{3}(?:\D|$))/g, '')
    .replace(/,/g, '')

  const number = Number(normalized)
  return Number.isFinite(number) ? number : undefined
}

const findFirstImageInObject = (source) => {
  if (!source || typeof source !== 'object') return undefined

  if (Array.isArray(source)) {
    for (const item of source) {
      const found = findFirstImageInObject(item)
      if (found) return found
    }
    return undefined
  }

  for (const [key, value] of Object.entries(source)) {
    const normalizedKey = String(key).toLowerCase()
    if (['image', 'images', 'thumbnail', 'thumbnail_url', 'avatar', 'media', 'url'].includes(normalizedKey)) {
      if (Array.isArray(value)) {
        const first = value.find((item) => typeof item === 'string' && item.trim().length > 0)
        if (first) return first
      }

      if (typeof value === 'string' && value.trim().length > 0) {
        return value
      }
    }

    if (typeof value === 'object') {
      const nested = findFirstImageInObject(value)
      if (nested) return nested
    }
  }

  return undefined
}

const findFirstPriceInObject = (source) => {
  if (!source || typeof source !== 'object') return undefined

  if (Array.isArray(source)) {
    for (const item of source) {
      const found = findFirstPriceInObject(item)
      if (found !== undefined) return found
    }
    return undefined
  }

  const directCandidates = [
    'price',
    'selling_price',
    'sale_price',
    'regular_price',
    'unit_price',
    'base_price',
    'final_price',
    'discount_price',
    'amount',
    'price_before_discount',
  ]

  for (const key of directCandidates) {
    const value = source[key]
    const numericValue = toNumber(value)
    if (numericValue !== undefined) return numericValue
  }

  for (const [key, value] of Object.entries(source)) {
    const normalizedKey = String(key).toLowerCase()
    if (/(price|amount|cost|unit_price|selling|regular|final|base|discount)/i.test(normalizedKey)) {
      const numericValue = toNumber(value)
      if (numericValue !== undefined) return numericValue
    }

    if (typeof value === 'object') {
      const nested = findFirstPriceInObject(value)
      if (nested !== undefined) return nested
    }
  }

  return undefined
}

const getProductImage = (product) => {
  const payload = product?.pancake_full_data ?? {}
  const candidates = [
    getNestedValue(product, 'pancake_full_data.variation_info.images'),
    getNestedValue(product, 'pancake_full_data.images'),
    getNestedValue(product, 'pancake_full_data.product.images'),
    getNestedValue(product, 'pancake_full_data.product.image'),
    getNestedValue(product, 'pancake_full_data.image'),
    getNestedValue(product, 'pancake_full_data.product.variation_info.images'),
    getNestedValue(product, 'pancake_full_data.product.variation_info.image'),
    findFirstImageInObject(payload),
    findFirstImageInObject(product),
  ]

  const firstImage = candidates.find((value) => {
    if (Array.isArray(value)) {
      return value.some((image) => typeof image === 'string' && image.trim().length > 0)
    }

    return typeof value === 'string' && value.trim().length > 0
  })

  if (Array.isArray(firstImage)) {
    return firstImage.find((image) => typeof image === 'string' && image.trim().length > 0) || PRODUCT_PLACEHOLDER
  }

  return typeof firstImage === 'string' && firstImage.trim().length > 0 ? firstImage : PRODUCT_PLACEHOLDER
}

const getProductPrice = (product) => {
  const payload = product?.pancake_full_data ?? {}
  const candidates = [
    getNestedValue(product, 'pancake_full_data.price'),
    getNestedValue(product, 'pancake_full_data.selling_price'),
    getNestedValue(product, 'pancake_full_data.sale_price'),
    getNestedValue(product, 'pancake_full_data.regular_price'),
    getNestedValue(product, 'pancake_full_data.unit_price'),
    getNestedValue(product, 'pancake_full_data.product.price'),
    getNestedValue(product, 'pancake_full_data.product.selling_price'),
    getNestedValue(product, 'pancake_full_data.product.unit_price'),
    getNestedValue(product, 'pancake_full_data.variation_info.price'),
    getNestedValue(product, 'pancake_full_data.variation_info.selling_price'),
    getNestedValue(product, 'pancake_full_data.price_before_discount'),
    findFirstPriceInObject(payload),
    findFirstPriceInObject(product),
  ]

  const foundValue = candidates.find((value) => value !== undefined && value !== null && value !== '')
  return foundValue ?? 0
}

const ProductsPage = () => {
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
        displayPrice: getProductPrice(product),
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

  const columns = useMemo(
    () => [
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
        render: (_, record) => <span className="products-name">{record.displayName}</span>,
      },
      {
        title: 'Cửa hàng',
        dataIndex: 'shop',
        key: 'shop',
        width: 220,
        render: (shop, record) => {
          const shopName = record?.shop?.name || shop?.name || '—'
          return shopName === '—' ? (
            '—'
          ) : (
            <Space size={8} className="products-shop">
              <Avatar size={32} className="products-shop__avatar" aria-hidden="true">
                {getInitial(shopName)}
              </Avatar>
              <span>{shopName}</span>
            </Space>
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
    ],
    [page, pageSize],
  )

  return (
    <main className="products-page">
      <Card title="Danh sách sản phẩm" className="products-card">
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
    </main>
  )
}

export default ProductsPage
