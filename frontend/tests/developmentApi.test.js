import assert from 'node:assert/strict'
import test from 'node:test'
import { developmentApi } from '../src/api/developmentApi.js'

test('loopback APIs keep their path and use the configured backend origin for Vite', () => {
  assert.deepEqual(developmentApi('http://127.0.0.1:8002/api/v1'), { baseURL: '/api/v1', target: 'http://127.0.0.1:8002' })
  assert.deepEqual(developmentApi('http://localhost:8082/api/v1/'), { baseURL: '/api/v1', target: 'http://localhost:8082' })
  assert.equal(developmentApi('https://api.example.com/api/v1'), null)
  assert.equal(developmentApi('/api/v1'), null)
  assert.equal(developmentApi(undefined), null)
})
