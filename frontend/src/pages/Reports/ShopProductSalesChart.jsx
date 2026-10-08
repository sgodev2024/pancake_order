import {
  CrownFilled,
  FireOutlined,
  ShopOutlined,
  ShoppingOutlined,
  TrophyOutlined,
} from '@ant-design/icons'
import { Card, Empty, Radio, Select, Space, Spin, Tag, Tooltip } from 'antd'
import { useMemo, useState } from 'react'
import './shop-product-sales-chart.css'

const formatCurrency = (value) =>
  new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(
    Number(value) || 0,
  )

const formatCompact = (value) => {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  const absolute = Math.abs(amount)
  if (absolute >= 1_000_000_000) {
    return `${(amount / 1_000_000_000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tỷ`
  }
  if (absolute >= 1_000_000) {
    return `${(amount / 1_000_000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} tr`
  }
  return amount.toLocaleString('vi-VN')
}

const formatNumber = (value) => Number(value || 0).toLocaleString('vi-VN')

const RANK_BADGES = ['#f59e0b', '#64748b', '#b45309']

const ShopProductSalesChart = ({ data, loading = false, error = false, onRetry }) => {
  const products = useMemo(() => (Array.isArray(data?.products) ? data.products : []), [data])
  const topPairings = useMemo(() => (Array.isArray(data?.top_pairings) ? data.top_pairings : []), [data])

  const [selectedProductName, setSelectedProductName] = useState(null)
  const [viewMode, setViewMode] = useState('by_product') // 'by_product' | 'overview_cards' | 'top_pairings'

  const activeProduct = useMemo(() => {
    if (!products.length) return null
    if (selectedProductName) {
      const found = products.find((p) => p.product_name === selectedProductName)
      if (found) return found
    }
    return products[0]
  }, [products, selectedProductName])

  const productOptions = useMemo(
    () =>
      products.map((p) => ({
        value: p.product_name,
        label: `${p.product_name} (${formatCompact(p.total_revenue)})`,
      })),
    [products],
  )

  if (loading) {
    return (
      <Card className="shop-sales-card">
        <div className="shop-sales-loading">
          <Spin size="large" />
          <p style={{ marginTop: 12, color: '#64748b' }}>Đang phân tích doanh số cửa hàng theo sản phẩm...</p>
        </div>
      </Card>
    )
  }

  if (error) {
    return (
      <Card className="shop-sales-card">
        <div className="shop-sales-error">
          <p>Không thể tải dữ liệu biểu đồ doanh số cửa hàng.</p>
          {onRetry && (
            <button type="button" className="shop-sales-btn-retry" onClick={onRetry}>
              Thử lại
            </button>
          )}
        </div>
      </Card>
    )
  }

  if (!products.length) {
    return (
      <Card className="shop-sales-card">
        <Empty description="Chưa có dữ liệu bán hàng cho phạm vi và thời gian đã chọn" />
      </Card>
    )
  }

  const maxProductRevenue = activeProduct?.top_shop?.revenue || 1
  const overallChampion = topPairings[0]

  return (
    <section className="shop-sales-card" aria-label="Biểu đồ doanh số theo cửa hàng và sản phẩm">
      <div className="shop-sales-header">
        <div className="shop-sales-header__title">
          <div className="shop-sales-header__icon">
            <TrophyOutlined />
          </div>
          <div>
            <h3>Chi tiết Cửa hàng có doanh số cao nhất theo Sản phẩm</h3>
            <p className="shop-sales-header__subtitle">
              Phân tích trực quan doanh số và xếp hạng cửa hàng dẫn đầu theo từng mặt hàng
              {data?.from && data?.to && ` (${data.from} – ${data.to})`}
            </p>
          </div>
        </div>

        <div className="shop-sales-header__controls">
          <Radio.Group
            value={viewMode}
            onChange={(e) => setViewMode(e.target.value)}
            buttonStyle="solid"
            size="middle"
          >
            <Radio.Button value="by_product">So sánh theo sản phẩm</Radio.Button>
            <Radio.Button value="top_pairings">Top cặp Doanh số cao nhất</Radio.Button>
          </Radio.Group>
        </div>
      </div>

      {overallChampion && (
        <div className="shop-sales-champion-banner">
          <div className="champion-badge">
            <CrownFilled className="crown-icon" />
            <span>QUÁN QUÂN DOANH SỐ TOÀN HỆ THỐNG</span>
          </div>
          <div className="champion-content">
            <div className="champion-shop">
              <ShopOutlined /> Cửa hàng: <strong>{overallChampion.shop_name}</strong>
            </div>
            <div className="champion-product">
              <ShoppingOutlined /> Sản phẩm: <strong>{overallChampion.product_name}</strong>
            </div>
            <div className="champion-revenue">
              Doanh số dẫn đầu: <strong>{formatCurrency(overallChampion.revenue)}</strong>
              <span className="champion-qty">({formatNumber(overallChampion.quantity)} sản phẩm)</span>
            </div>
          </div>
        </div>
      )}

      {viewMode === 'by_product' && activeProduct && (
        <div className="shop-sales-detail-view">
          <div className="product-selector-bar">
            <label htmlFor="product-chart-select">
              <FireOutlined style={{ color: '#ef4444' }} /> Chọn sản phẩm cần xem chi tiết:
            </label>
            <Select
              id="product-chart-select"
              showSearch
              value={activeProduct.product_name}
              onChange={setSelectedProductName}
              options={productOptions}
              className="product-select-dropdown"
              placeholder="Chọn hoặc tìm kiếm sản phẩm"
              optionFilterProp="label"
            />
          </div>

          <div className="product-summary-strip">
            <div className="summary-item">
              <span className="summary-label">Tổng doanh số sản phẩm:</span>
              <strong className="summary-val summary-val--highlight">{formatCurrency(activeProduct.total_revenue)}</strong>
            </div>
            <div className="summary-item">
              <span className="summary-label">Tổng số lượng đã bán:</span>
              <strong className="summary-val">{formatNumber(activeProduct.total_quantity)} sản phẩm</strong>
            </div>
            <div className="summary-item">
              <span className="summary-label">Cửa hàng có doanh số cao nhất:</span>
              <strong className="summary-val summary-val--champion">
                👑 {activeProduct.top_shop?.shop_name} ({formatCompact(activeProduct.top_shop?.revenue)} - {activeProduct.top_shop?.share_percent}%)
              </strong>
            </div>
          </div>

          <div className="chart-bars-container">
            <div className="chart-bars-title">
              <h4>Biểu đồ so sánh doanh số giữa các Cửa hàng cho sản phẩm: &ldquo;{activeProduct.product_name}&rdquo;</h4>
              <span>Đơn vị: VNĐ</span>
            </div>

            <div className="horizontal-bars">
              {(activeProduct.shops || []).map((shop, index) => {
                const isLeader = index === 0
                const percentOfLeader = maxProductRevenue > 0 ? (shop.revenue / maxProductRevenue) * 100 : 0

                return (
                  <div key={shop.shop_id} className={`bar-row ${isLeader ? 'bar-row--leader' : ''}`}>
                    <div className="bar-row__label">
                      <span className="rank-indicator" style={{ background: RANK_BADGES[index] || '#94a3b8' }}>
                        {isLeader ? '👑 1' : index + 1}
                      </span>
                      <span className="shop-name-label" title={shop.shop_name}>
                        {shop.shop_name}
                      </span>
                      {isLeader && <Tag color="gold" className="leader-tag">Top 1 Doanh số</Tag>}
                    </div>

                    <div className="bar-row__track">
                      <div
                        className={`bar-row__fill ${isLeader ? 'bar-row__fill--leader' : ''}`}
                        style={{ width: `${Math.max(percentOfLeader, 2)}%` }}
                      >
                        <span className="bar-row__inside-text">
                          {formatCompact(shop.revenue)}
                        </span>
                      </div>
                    </div>

                    <div className="bar-row__meta">
                      <Tooltip title={`${formatNumber(shop.revenue)} VNĐ`}>
                        <strong className="meta-revenue">{formatCurrency(shop.revenue)}</strong>
                      </Tooltip>
                      <span className="meta-qty">{formatNumber(shop.quantity)} sp</span>
                      <span className="meta-share">{shop.share_percent}% thị phần</span>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      )}

      {viewMode === 'overview_cards' && (
        <div className="shop-sales-grid">
          {products.map((product) => {
            const topShop = product.top_shop
            return (
              <div key={product.product_name} className="product-leader-card">
                <div className="card-top">
                  <h4 title={product.product_name}>{product.product_name}</h4>
                  <span className="card-total-revenue">{formatCompact(product.total_revenue)}</span>
                </div>

                <div className="card-body">
                  <div className="top-shop-badge">
                    <span className="crown-icon">👑</span>
                    <div className="top-shop-info">
                      <span className="sub-title">Cửa hàng doanh số cao nhất:</span>
                      <strong className="top-shop-name">{topShop?.shop_name || '—'}</strong>
                    </div>
                  </div>

                  <div className="top-shop-metrics">
                    <div className="metric-box">
                      <span>Doanh số top 1</span>
                      <strong>{formatCompact(topShop?.revenue)}</strong>
                    </div>
                    <div className="metric-box">
                      <span>Thị phần</span>
                      <strong className="metric-share">{topShop?.share_percent}%</strong>
                    </div>
                    <div className="metric-box">
                      <span>Số lượng</span>
                      <strong>{formatNumber(topShop?.quantity)} sp</strong>
                    </div>
                  </div>

                  <div className="shops-mini-comparison">
                    <div className="mini-bar-title">So sánh các cửa hàng ({product.shops.length} shop):</div>
                    <div className="mini-bars">
                      {product.shops.slice(0, 4).map((s, idx) => (
                        <div key={s.shop_id} className="mini-bar-item">
                          <span className="mini-shop-name" title={s.shop_name}>
                            {idx === 0 ? '👑 ' : ''}{s.shop_name}
                          </span>
                          <div className="mini-bar-track">
                            <div
                              className={`mini-bar-fill ${idx === 0 ? 'mini-bar-fill--lead' : ''}`}
                              style={{ width: `${s.share_percent}%` }}
                            />
                          </div>
                          <span className="mini-shop-val">{formatCompact(s.revenue)}</span>
                        </div>
                      ))}
                      {product.shops.length > 4 && (
                        <div className="mini-more">+{product.shops.length - 4} cửa hàng khác...</div>
                      )}
                    </div>
                  </div>
                </div>

                <div className="card-footer">
                  <button
                    type="button"
                    className="view-detail-btn"
                    onClick={() => {
                      setSelectedProductName(product.product_name)
                      setViewMode('by_product')
                    }}
                  >
                    Xem chi tiết biểu đồ →
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {viewMode === 'top_pairings' && (
        <div className="top-pairings-table-wrap">
          <div className="top-pairings-title">
            <h4>Bảng xếp hạng Top Cặp Cửa hàng &amp; Sản phẩm đạt Doanh số cao nhất</h4>
            <span>Xếp hạng các cặp (Cửa hàng + Sản phẩm) mang lại doanh thu lớn nhất cho hệ thống</span>
          </div>

          <table className="top-pairings-table">
            <thead>
              <tr>
                <th style={{ width: 60, textAlign: 'center' }}>Hạng</th>
                <th>Cửa hàng</th>
                <th>Sản phẩm</th>
                <th style={{ textAlign: 'right' }}>Doanh số</th>
                <th style={{ textAlign: 'right' }}>Số lượng bán</th>
                <th style={{ textAlign: 'right' }}>Số đơn hàng</th>
                <th style={{ width: 140 }}>Biểu đồ tỷ lệ</th>
              </tr>
            </thead>
            <tbody>
              {topPairings.map((item, idx) => {
                const maxRevenue = topPairings[0]?.revenue || 1
                const barPercent = Math.round((item.revenue / maxRevenue) * 100)

                return (
                  <tr key={`${item.shop_id}-${item.product_name}`} className={idx === 0 ? 'row-top1' : ''}>
                    <td style={{ textAlign: 'center' }}>
                      <span className="table-rank-badge" style={{ background: RANK_BADGES[idx] || '#64748b' }}>
                        {idx === 0 ? '🏆 1' : idx + 1}
                      </span>
                    </td>
                    <td>
                      <Space>
                        <ShopOutlined style={{ color: '#1677ff' }} />
                        <strong>{item.shop_name}</strong>
                      </Space>
                    </td>
                    <td>
                      <span className="pairing-product-name">{item.product_name}</span>
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <strong className="pairing-revenue">{formatCurrency(item.revenue)}</strong>
                    </td>
                    <td style={{ textAlign: 'right' }}>{formatNumber(item.quantity)} sp</td>
                    <td style={{ textAlign: 'right' }}>{formatNumber(item.orders_count)} đơn</td>
                    <td>
                      <div className="table-bar-track">
                        <div
                          className={`table-bar-fill ${idx === 0 ? 'table-bar-fill--top' : ''}`}
                          style={{ width: `${barPercent}%` }}
                        />
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

export default ShopProductSalesChart

