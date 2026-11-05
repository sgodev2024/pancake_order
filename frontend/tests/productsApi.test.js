import assert from 'node:assert/strict'
import test from 'node:test'
import apiClient from '../src/api/client.js'
import { createProduct, getProducts, updateProduct } from '../src/api/products.api.js'

const values = { name: 'Local', shop_id: '11', selling_price: 0, initial_quantity: 0 }

test('product list sends scoped filters and returns the normalized API fields', async () => {
  const original = apiClient.get
  const rows = [{ price: 150000, inventory_quantity: 3, virtual_inventory_quantity: 5 }]
  apiClient.get = async (url, options) => {
    assert.equal(url, '/products')
    assert.deepEqual(options.params, { shop_id: 11, page: 1 })
    return { data: { data: { products: rows } } }
  }
  try {
    assert.deepEqual(await getProducts({ shop_id: 11, search: '', page: 1 }), { products: rows })
  } finally { apiClient.get = original }
})

test('create without image keeps zero price and raw zero stock', async () => {
  const original = apiClient.post
  apiClient.post = async (url, payload) => {
    assert.equal(url, '/products')
    assert.deepEqual(payload, values)
    assert.ok(!Object.hasOwn(payload, 'pancake_product_id'))
    return { data: { success: true } }
  }
  try { assert.deepEqual(await createProduct(values), { success: true }) }
  finally { apiClient.post = original }
})

test('image create sends a multipart File and preserves zero quantity', async () => {
  const original = apiClient.post
  const file = new File(['image-content'], 'product.png', { type: 'image/png' })
  apiClient.post = async (url, payload) => {
    assert.equal(url, '/products')
    assert.ok(payload instanceof FormData)
    assert.equal(payload.get('initial_quantity'), '0')
    assert.equal(payload.get('selling_price'), '0')
    assert.equal(payload.get('image').name, 'product.png')
    assert.equal(payload.get('pancake_product_id'), null)
    return { data: { success: true } }
  }
  try { await createProduct({ ...values, image: file }) }
  finally { apiClient.post = original }
})

test('update without image uses PUT and never leaks id into the product body', async () => {
  const original = apiClient.put
  apiClient.put = async (url, payload) => {
    assert.equal(url, '/products/24')
    assert.deepEqual(payload, values)
    return { data: { success: true } }
  }
  try { await updateProduct({ id: 24, ...values }) }
  finally { apiClient.put = original }
})

test('image update uses PHP-compatible POST with PUT override', async () => {
  const original = apiClient.post
  apiClient.post = async (url, payload) => {
    assert.equal(url, '/products/24')
    assert.ok(payload instanceof FormData)
    assert.equal(payload.get('_method'), 'PUT')
    assert.equal(payload.get('image').name, 'new.webp')
    assert.equal(payload.get('id'), null)
    assert.equal(payload.get('initial_quantity'), '0')
    return { data: { success: true } }
  }
  try { await updateProduct({ id: 24, ...values, image: new File(['x'], 'new.webp', { type: 'image/webp' }) }) }
  finally { apiClient.post = original }
})
