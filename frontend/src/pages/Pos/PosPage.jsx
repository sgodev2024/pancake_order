import { createRequestId } from '../../utils/requestId.js'
import { MinusOutlined, PlusOutlined, SearchOutlined, ShoppingCartOutlined, DeleteOutlined } from '@ant-design/icons'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Empty, Form, Image, Input, InputNumber, Pagination, Result, Select, Space, Spin, Typography } from 'antd'
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getProducts } from '../../api/products.api.js'
import { getCustomers } from '../../api/customers.api.js'
import { createOrder, getOrder } from '../../api/orders.api.js'
import { posShopOptionsQueryOptions } from '../../api/shops.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { canUsePos } from '../../auth/permissions.js'
import { ShopSelectInput } from '../../components/ShopSelect.jsx'
import QueryErrorAlert from '../../components/QueryErrorAlert.jsx'
import { getProductImage, PRODUCT_PLACEHOLDER } from '../../utils/products.js'
import { getApiErrorMessage } from '../../utils/response.js'
import { ROUTES } from '../../constants/routes.js'
import PosOrderReceipt from './PosOrderReceipt.jsx'
import { formatMoneyInput, isValidMoneyInput, parseMoneyInput } from './posMoneyInput.js'
import { calculatePosPaymentTotals, centsToAmount, money, optionalMoneyCents, POS_PAYMENT_OPTIONS } from './posTotals.js'
import './pos.css'

const INITIAL = { discount_amount: 0, shipping_fee: 0, surcharge: 0, payment_mode: 'cod' }
const PAGE_SIZE = 18

const PosPage = () => {
  const user = useAuthStore((state) => state.user)
  const allowed = canUsePos(user)
  // Never retain a previous actor's draft, even if both actors share the same shop.
  return <PosShopSession key={`${user?.id ?? 'signed-out'}:${allowed}`} user={user} allowed={allowed} />
}

const PosShopSession = ({ user, allowed }) => {
  const queryClient = useQueryClient()
  const [params] = useSearchParams()
  const orderId = params.get('order_id')
  const shopsQuery = useQuery({ ...posShopOptionsQueryOptions(user?.id), enabled: allowed && !orderId })
  const shops = shopsQuery.data ?? []
  const scopeKey = JSON.stringify(shops.map((shop) => String(shop.id)).sort())
  const [selection, setSelection] = useState({})
  const chosenShop = selection.scopeKey === scopeKey && shops.some((shop) => String(shop.id) === selection.shopId)
    ? selection.shopId : undefined
  const shopId = chosenShop ?? (shops.length === 1 ? String(shops[0].id) : undefined)
  const shopsReady = allowed && shopsQuery.isSuccess && shopsQuery.isFetchedAfterMount && !shopsQuery.isFetching
  const draftKey = JSON.stringify([scopeKey, shopId, allowed, orderId])
  const activeScope = useRef(null)
  useLayoutEffect(() => {
    activeScope.current = { draftKey, shopsReady }
    return () => { activeScope.current = null }
  }, [draftKey, shopsReady])
  useEffect(() => {
    if (!shopsReady || orderId) return
    const allowedIds = new Set(JSON.parse(scopeKey))
    queryClient.removeQueries({ predicate: (query) => {
      const [resource, context, actorId, queryShopId] = query.queryKey
      return ['products', 'customers'].includes(resource) && context === 'pos' && actorId === user?.id
        && queryShopId != null && !allowedIds.has(String(queryShopId))
    } })
  }, [orderId, queryClient, scopeKey, shopsReady, user?.id])
  const isCurrentScope = () => activeScope.current?.draftKey === draftKey && activeScope.current?.shopsReady
    && useAuthStore.getState().user?.id === user?.id

  return <PosDraft key={draftKey} user={user} allowed={allowed} orderId={orderId}
    shopsQuery={shopsQuery} shopsReady={shopsReady} shopId={shopId} isCurrentScope={isCurrentScope}
    onShopChange={(next) => {
      if (!isCurrentScope()) return
      if (next != null && !shops.some((shop) => String(shop.id) === String(next))) return
      setSelection({ scopeKey, shopId: next == null ? undefined : String(next) })
    }} />
}

const PosDraft = ({ user, allowed, orderId, shopsQuery, shopsReady, shopId, onShopChange, isCurrentScope }) => {
  const { message, modal } = App.useApp()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [form] = Form.useForm()
  const fields = Form.useWatch([], form) ?? INITIAL
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [customerSearch, setCustomerSearch] = useState('')
  const [customerTerm, setCustomerTerm] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState(null)
  const [page, setPage] = useState(1)
  const [cart, setCart] = useState([])
  const [created, setCreated] = useState(null)
  const pendingRequest = useRef(null)
  const [lockedRequest, setLockedRequest] = useState(false)
  const [paymentConfirmed, setPaymentConfirmed] = useState(false)
  const [moneyInputErrors, setMoneyInputErrors] = useState({})
  const shopDialog = useRef(null)
  const paymentDialog = useRef(null)
  const currentDraft = useRef(null)
  const draftFingerprint = JSON.stringify({ cart, fields, moneyInputErrors })
  useLayoutEffect(() => {
    currentDraft.current = draftFingerprint
    return () => { currentDraft.current = null }
  }, [draftFingerprint])
  useEffect(() => () => { shopDialog.current?.destroy(); paymentDialog.current?.destroy() }, [])

  useEffect(() => {
    const timer = setTimeout(() => { setAppliedSearch(search.trim()); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [search])
  useEffect(() => {
    const timer = setTimeout(() => setCustomerTerm(customerSearch.trim()), 300)
    return () => clearTimeout(timer)
  }, [customerSearch])

  const productsQuery = useQuery({
    queryKey: ['products', 'pos', user?.id, shopId, appliedSearch, page],
    queryFn: ({ signal }) => getProducts({ shop_id: shopId, search: appliedSearch, page, page_size: PAGE_SIZE }, { signal }),
    enabled: shopsReady && !!shopId && !orderId,
  })
  const customersQuery = useQuery({
    queryKey: ['customers', 'pos', user?.id, shopId, customerTerm],
    queryFn: ({ signal }) => getCustomers({ view: 'pos', shop_id: shopId, search: customerTerm, page: 1 }, { signal }),
    enabled: shopsReady && !!shopId && !orderId,
  })
  const customerOptions = useMemo(() => {
    if (!shopsReady) return []
    const customers = customersQuery.data?.customers ?? []
    const options = customers.map((customer) => ({
      value: customer.id,
      label: `${customer.name || 'Khách hàng'} · ${customer.phone || ''}`,
    }))
    if (selectedCustomer && !customers.some((customer) => String(customer.id) === String(selectedCustomer.id))) {
      options.unshift({
        value: selectedCustomer.id,
        label: `${selectedCustomer.name || 'Khách hàng'} · ${selectedCustomer.phone || ''}`,
      })
    }
    return options
  }, [customersQuery.data, selectedCustomer, shopsReady])
  const orderQuery = useQuery({
    queryKey: ['order-detail', user?.id, orderId], queryFn: () => getOrder(orderId), enabled: !!orderId,
    retry: false,
  })
  const totals = useMemo(() => calculatePosPaymentTotals(cart, fields, paymentConfirmed), [cart, fields, paymentConfirmed])
  const moneyInputError = Object.values(moneyInputErrors).find(Boolean)
  const totalsError = moneyInputError || totals.error
  const mutation = useMutation({
    mutationFn: (payload) => {
      if (!isCurrentScope()) throw new Error('Danh sách cửa hàng đã thay đổi. Vui lòng kiểm tra lại đơn hàng.')
      return createOrder(payload)
    },
    retry: false,
    onSuccess: (response) => {
      if (!isCurrentScope()) return
      setCreated(response.data)
      pendingRequest.current = null
      setLockedRequest(false)
      queryClient.invalidateQueries({ queryKey: ['products'] })
      queryClient.invalidateQueries({ queryKey: ['orders'] })
      queryClient.invalidateQueries({ queryKey: ['order-totals'] })
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      message.success(response.replayed ? 'Đã tìm thấy đơn đã tạo trước đó.' : 'Đã tạo đơn hàng.')
    },
    onError: (error) => {
      if (!isCurrentScope()) return
      const status = error?.status ?? error?.response?.status
      // A validation/authorization rejection cannot have committed an order.
      if ([401, 403, 404, 422].includes(status)) {
        pendingRequest.current = null
        setLockedRequest(false)
        setPaymentConfirmed(false)
      }
      const errors = error?.errors ?? error?.response?.data?.errors
      if (errors) form.setFields(Object.entries(errors).map(([name, messages]) => ({
        name: name.split('.'), errors: Array.isArray(messages) ? messages : [messages],
      })))
    },
  })
  const draftLocked = mutation.isPending || lockedRequest || !!created
  const disabled = draftLocked || !shopsReady || !shopId
  const resetDraft = () => {
    setCart([]); setCreated(null); pendingRequest.current = null; setLockedRequest(false)
    setPaymentConfirmed(false); setMoneyInputErrors({})
    form.resetFields(); mutation.reset(); setCustomerSearch(''); setCustomerTerm(''); setSelectedCustomer(null)
  }
  const changeShop = (next) => {
    if (draftLocked || !isCurrentScope() || String(next ?? '') === String(shopId ?? '')) return
    if (cart.length) shopDialog.current = modal.confirm({ title: 'Đổi shop và xóa giỏ hàng hiện tại?', onOk: () => onShopChange(next), okText: 'Đổi shop', cancelText: 'Giữ giỏ hàng' })
    else onShopChange(next)
  }
  const addProduct = (product) => {
    if (disabled) return
    setCart((items) => {
      const found = items.find((item) => item.product_id === product.id)
      return found ? items.map((item) => item.product_id === product.id ? { ...item, quantity: Math.min(1000000, item.quantity + 1) } : item)
        : [...items, { product_id: product.id, name: product.name, image: getProductImage(product), unit_price: product.price, quantity: 1 }]
    })
  }
  const setQuantity = (id, quantity) => {
    if (Number.isInteger(quantity) && quantity > 0) setCart((items) => items.map((item) => item.product_id === id ? { ...item, quantity } : item))
  }
  const submit = (values) => {
    if (!isCurrentScope()) return
    if (draftLocked || paymentDialog.current || pendingRequest.current) return
    if (!shopId || !cart.length) { message.error('Chọn cửa hàng và thêm sản phẩm vào giỏ.'); return }
    const submittedTotals = calculatePosPaymentTotals(cart, values)
    if (moneyInputError || submittedTotals.error) { message.error(moneyInputError || submittedTotals.error); return }
    const payload = {
      idempotency_key: createRequestId(), shop_id: Number(shopId),
      ...(values.customer_id
        ? { customer_id: values.customer_id }
        : { customer: { name: values.customer_name, phone: values.customer_phone } }),
      shipping_address: values.shipping_address,
      items: cart.map(({ product_id, quantity, unit_price }) => ({ product_id, quantity, unit_price })),
      discount_amount: centsToAmount(submittedTotals.discount), shipping_fee: centsToAmount(submittedTotals.shipping), surcharge: centsToAmount(submittedTotals.surcharge),
      payment_mode: values.payment_mode ?? 'cod',
      ...(values.payment_mode === 'bank_transfer_cod' ? { deposit_amount: centsToAmount(optionalMoneyCents(values.deposit_amount)) } : {}), note: values.note,
    }
    const send = (confirmed) => {
      if (!isCurrentScope()) return
      pendingRequest.current = { ...payload, ...(confirmed ? { payment_confirmed: true, confirmed_total: centsToAmount(submittedTotals.total) } : {}) }
      setPaymentConfirmed(confirmed)
      setLockedRequest(true)
      mutation.mutate(pendingRequest.current)
    }
    if (payload.payment_mode === 'cod') { send(false); return }
    const confirmedDraft = currentDraft.current
    let accepted = false
    paymentDialog.current = modal.confirm({
      title: payload.payment_mode === 'bank_transfer_cod' ? 'Xác nhận đã nhận chuyển khoản một phần' : payload.payment_mode === 'cash' ? 'Xác nhận đã thu đủ tiền mặt' : 'Xác nhận đã nhận đủ chuyển khoản',
      content: payload.payment_mode === 'bank_transfer_cod'
        ? `Đã nhận chuyển khoản: ${money(values.deposit_amount)}. Còn thu COD: ${money((submittedTotals.total - optionalMoneyCents(values.deposit_amount)) / 100)}. Chỉ xác nhận khi đã thực sự nhận khoản tiền này.`
        : `Số tiền cần xác nhận: ${money(submittedTotals.total / 100)}. Chỉ xác nhận khi bạn đã thực sự nhận đủ tiền.`,
      okText: payload.payment_mode === 'bank_transfer_cod' ? 'Đã nhận khoản chuyển' : 'Đã nhận đủ tiền', cancelText: 'Chưa nhận tiền',
      onCancel: () => { paymentDialog.current = null },
      onOk: () => {
        paymentDialog.current = null
        if (accepted || !isCurrentScope()) return
        if (currentDraft.current !== confirmedDraft) { message.error('Đơn hàng đã thay đổi. Vui lòng kiểm tra và xác nhận lại.'); return }
        accepted = true
        send(true)
      },
    })
  }

  if (orderId) return <main className="pos-page"><Card title="Chi tiết đơn hàng" extra={<Button onClick={() => navigate(ROUTES.POS_ORDERS)}>Danh sách đơn</Button>}>
    {orderQuery.isPending ? <Spin /> : orderQuery.isError ? <QueryErrorAlert error={orderQuery.error} onRetry={orderQuery.refetch} />
      : orderQuery.data?.origin === 'local_pos' ? <PosOrderReceipt order={orderQuery.data} /> : <Alert type="info" title="Đơn Pancake được xem trong danh sách đơn hàng." />}
  </Card></main>
  if (!allowed) return <Result status="403" title="Bạn không có quyền sử dụng POS" subTitle="Cần quyền tạo POS, xem sản phẩm và khách hàng." />

  const products = shopsReady ? productsQuery.data?.products ?? [] : []
  return <main className="pos-page">
    <div className="pos-heading"><div><Typography.Title level={3}>Bán hàng POS</Typography.Title><Typography.Text type="secondary">Tạo đơn Local · Giá bán được kiểm tra khi lưu</Typography.Text></div><Button onClick={() => navigate(ROUTES.POS_ORDERS)}>Danh sách đơn</Button></div>
    <div className="pos-layout">
      <Card className="pos-products" title="Sản phẩm">
        <div className="pos-product-filters"><ShopSelectInput shopsQuery={shopsQuery} value={shopsReady ? shopId : undefined}
          onChange={changeShop} disabled={draftLocked || !shopsReady} allowClear={(shopsQuery.data?.length ?? 0) > 1} />
          <Input prefix={<SearchOutlined />} value={search} disabled={!shopId || disabled} allowClear onChange={(event) => setSearch(event.target.value)} placeholder="Tên, mã sản phẩm hoặc barcode" aria-label="Tìm sản phẩm POS" /></div>
        {shopsQuery.isError ? <QueryErrorAlert error={shopsQuery.error} onRetry={shopsQuery.refetch} />
          : !shopsReady ? <Spin tip="Đang tải cửa hàng"><Empty description="Đang tải danh sách cửa hàng" /></Spin>
          : !shopsQuery.data.length ? <Empty description="Bạn chưa được phân công cửa hàng để bán hàng" />
          : !shopId ? <Empty description="Chọn cửa hàng để bắt đầu bán hàng" /> : productsQuery.isError ? <QueryErrorAlert error={productsQuery.error} onRetry={productsQuery.refetch} />
          : <Spin spinning={productsQuery.isFetching}><div className="pos-product-grid">{products.map((product) => <button type="button" className="pos-product" key={product.id} disabled={disabled || product.price == null} onClick={() => addProduct(product)}>
            <Image src={getProductImage(product)} fallback={PRODUCT_PLACEHOLDER} preview={false} alt={product.name} />
            <span className="pos-product-name">{product.name}</span><strong>{product.price == null ? 'Chưa có giá bán' : money(product.price)}</strong>
          </button>)}</div>{!products.length && !productsQuery.isPending && <Empty description="Không có sản phẩm phù hợp" />}</Spin>}
        {shopsReady && !!shopId && <Pagination current={page} pageSize={PAGE_SIZE} total={productsQuery.data?.total_items ?? 0} showSizeChanger={false} onChange={setPage} simple className="pos-pagination" />}
      </Card>
      <Card className="pos-order" title={<Space><ShoppingCartOutlined />{created ? 'Đơn hàng đã lưu' : `Đơn hàng · ${cart.reduce((sum, item) => sum + item.quantity, 0)} sản phẩm`}</Space>}>
        {created ? <><PosOrderReceipt order={created} /><Button type="primary" block size="large" style={{ marginTop: 20 }} onClick={resetDraft}>Tạo đơn mới</Button></> : <>
          {mutation.isError && <Alert type="error" showIcon title={getApiErrorMessage(mutation.error, 'Không thể tạo đơn.')}
            description={lockedRequest ? 'Giỏ hàng được giữ nguyên. Gửi lại đúng yêu cầu này để nhận kết quả, tránh tạo đơn trùng.' : 'Kiểm tra dữ liệu và thử lại.'} />}
          <Form form={form} layout="vertical" initialValues={INITIAL} disabled={disabled} onFinish={submit}>
            <Form.Item name="customer_id" label="Tìm khách hàng có sẵn (không bắt buộc)"
              extra="Chọn khách hàng đã có hoặc để trống để nhập thông tin khách mới.">
              <Select allowClear showSearch filterOption={false} disabled={!shopId || disabled} loading={customersQuery.isFetching}
                placeholder="Tìm theo tên hoặc số điện thoại" onSearch={setCustomerSearch}
                options={customerOptions}
                onChange={(id) => {
                  let customer = null
                  if (id !== undefined && id !== null) {
                    customer = customersQuery.data?.customers?.find((item) => String(item.id) === String(id))
                      ?? (String(selectedCustomer?.id) === String(id) ? selectedCustomer : null)
                  }
                  setSelectedCustomer(customer)
                  form.setFieldsValue({
                    customer_name: customer?.name || '',
                    customer_phone: customer?.phone || '',
                    shipping_address: customer?.address || '',
                  })
                  form.setFields([
                    { name: 'customer_name', errors: [] },
                    { name: 'customer_phone', errors: [] },
                  ])
                }} />
            </Form.Item>
            <div className="pos-two-fields">
              <Form.Item name="customer_name" label="Tên khách hàng"
                rules={fields.customer_id ? [] : [{ required: true, whitespace: true, message: 'Nhập tên khách hàng.' }, { max: 255 }]}>
                <Input maxLength={255} disabled={!!fields.customer_id} />
              </Form.Item>
              <Form.Item name="customer_phone" label="Số điện thoại"
                rules={fields.customer_id ? [] : [{ required: true, message: 'Nhập số điện thoại.' }, { pattern: /^(0\d{9,10}|\+84\d{9,10})$/, message: 'Số điện thoại không hợp lệ.' }]}>
                <Input maxLength={15} disabled={!!fields.customer_id} />
              </Form.Item>
            </div>
            {customersQuery.isError && <QueryErrorAlert error={customersQuery.error} onRetry={customersQuery.refetch} />}
            <Form.Item name="shipping_address" label="Địa chỉ giao hàng" rules={[{ required: true, whitespace: true, message: 'Nhập địa chỉ giao hàng.' }, { max: 1000 }]}><Input.TextArea autoSize={{ minRows: 1, maxRows: 3 }} /></Form.Item>
            <div className="pos-cart" aria-label="Giỏ hàng">{!cart.length ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chọn sản phẩm ở bên trái" /> : cart.map((item) => <div className="pos-cart-row" key={item.product_id}>
              <div className="pos-cart-name"><strong>{item.name}</strong><span>{money(item.unit_price)} / sản phẩm</span></div>
              <Space.Compact><Button aria-label={`Giảm ${item.name}`} disabled={disabled || item.quantity <= 1} icon={<MinusOutlined />} onClick={() => setQuantity(item.product_id, item.quantity - 1)} />
                <InputNumber aria-label={`Số lượng ${item.name}`} min={1} max={1000000} precision={0} controls={false} value={item.quantity} disabled={disabled} onChange={(value) => setQuantity(item.product_id, value)} />
                <Button aria-label={`Tăng ${item.name}`} disabled={disabled || item.quantity >= 1000000} icon={<PlusOutlined />} onClick={() => setQuantity(item.product_id, item.quantity + 1)} /></Space.Compact>
              <strong>{money(Number(item.unit_price) * item.quantity)}</strong><Button type="text" danger aria-label={`Xóa ${item.name}`} disabled={disabled} icon={<DeleteOutlined />} onClick={() => setCart((items) => items.filter((line) => line.product_id !== item.product_id))} />
            </div>)}</div>
            <div className="pos-adjustments">{[['discount_amount', 'Giảm giá đơn'], ['shipping_fee', 'Phí vận chuyển thu khách'], ['surcharge', 'Phụ thu']].map(([name, label]) => <Form.Item key={name} name={name} label={label}
              validateStatus={moneyInputErrors[name] ? 'error' : undefined} help={moneyInputErrors[name]}
              rules={[{ validator: (_, value) => optionalMoneyCents(value) === null ? Promise.reject(new Error('Nhập số tiền không âm, tối đa 2 chữ số thập phân.')) : Promise.resolve() }]}>
              <InputNumber className="pos-money-input" max={999999999999.99} precision={2} formatter={formatMoneyInput} parser={parseMoneyInput} suffix="đ"
                onInput={(text) => setMoneyInputErrors((errors) => ({ ...errors, [name]: isValidMoneyInput(text) && optionalMoneyCents(parseMoneyInput(text)) !== null ? undefined : 'Nhập số tiền không âm, tối đa 2 chữ số thập phân.' }))} />
            </Form.Item>)}</div>
            <Form.Item name="payment_mode" label="Hình thức thanh toán"><Select options={POS_PAYMENT_OPTIONS} /></Form.Item>
            {fields.payment_mode === 'bank_transfer_cod' && <Form.Item name="deposit_amount" label="Số tiền chuyển khoản đã nhận" preserve={false}
              rules={[{ required: true, message: 'Nhập số tiền đã nhận.' }, { validator: (_, value) => {
                const amount = optionalMoneyCents(value)
                const total = calculatePosPaymentTotals(cart, { ...fields, payment_mode: 'cod' }).total
                return amount !== null && amount > 0 && amount < total ? Promise.resolve() : Promise.reject(new Error('Số tiền phải lớn hơn 0 và nhỏ hơn tổng thanh toán.'))
              } }]}>
              <InputNumber className="pos-money-input" min={0} max={999999999999.99} precision={2} formatter={formatMoneyInput} parser={parseMoneyInput} suffix="đ" />
            </Form.Item>}
            {fields.payment_mode !== 'cod' && !paymentConfirmed && <Alert type="info" showIcon title="Chỉ ghi nhận đã thanh toán sau khi xác nhận số tiền thực nhận." />}
            <div className="pos-totals" aria-live="polite">{totalsError ? <Alert type="warning" title={totalsError} /> : <>
              <div><span>Tiền hàng</span><strong>{money(totals.subtotal / 100)}</strong></div>
              <div className="pos-total"><span>Tổng thanh toán</span><strong>{money(totals.total / 100)}</strong></div>
              <div><span>{fields.payment_mode === 'bank_transfer_cod' && !paymentConfirmed ? 'Chuyển khoản khai báo' : 'Đã thanh toán'}</span><strong>{money(totals.paid / 100)}</strong></div>
              <div><span>Còn phải thu</span><strong>{money(totals.remaining / 100)}</strong></div>
              <div><span>COD dự kiến</span><strong>{money(totals.cod / 100)}</strong></div>
            </>}</div>
            <Form.Item name="note" label="Ghi chú đơn hàng"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
            <Button type="primary" block size="large" htmlType="submit" icon={<ShoppingCartOutlined />} disabled={!shopId || !cart.length || !!totalsError || disabled}>Tạo đơn hàng</Button>
          </Form>
          {lockedRequest && <Button type="primary" block size="large" loading={mutation.isPending} disabled={mutation.isPending} onClick={() => mutation.mutate(pendingRequest.current)} style={{ marginTop: 12 }}>{mutation.isPending ? 'Đang tạo đơn...' : 'Gửi lại yêu cầu tạo đơn'}</Button>}
        </>}
      </Card>
    </div>
  </main>
}
export default PosPage
