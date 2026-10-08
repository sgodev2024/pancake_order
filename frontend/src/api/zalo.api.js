import api from './client.js'
const root = '/zalo-chat'
const data = (response) => response.data.data
export const zaloApi = {
 configuration: () => api.get(`${root}/connection`).then(data),
 save: (body) => api.put(`${root}/connection`, body).then(data),
 test: (body) => api.post(`${root}/connection/test`, body).then(data),
 monitoring: () => api.get(`${root}/monitoring`).then(data),
 accounts: () => api.get(`${root}/accounts`).then(data),
 createAccount: (body) => api.post(`${root}/accounts`, body).then(data),
 grants: (id) => api.get(`${root}/accounts/${id}/grants`).then(data),
 saveGrants: (id, grants) => api.put(`${root}/accounts/${id}/grants`, { grants }).then(data),
 conversations: (accountId, query) => api.get(`${root}/conversations`, { params: { accountId, query } }).then(data),
 messages: (id, before) => api.get(`${root}/conversations/${id}/messages`, { params: { before } }).then(data),
 send: (id, text) => api.post(`${root}/conversations/${id}/messages`, { text }).then(data),
 read: (id) => api.post(`${root}/conversations/${id}/read`).then(data),
 friends: (id, offset = 0) => api.get(`${root}/accounts/${id}/friends`, { params: { offset } }).then(data),
 openFriend: (id, friendRef) => api.post(`${root}/accounts/${id}/friends/open`, { friendRef }).then(data),
}
export const zaloError = (error) => error?.response?.data?.message || error?.message || 'Không thể xử lý yêu cầu Zalo.'
export const zaloTime = (value) => value ? new Date(value).toLocaleString('vi-VN') : 'Chưa ghi nhận'
export const zaloStatus = (value) => ({ CONNECTED: 'Đang online', OFFLINE: 'Mất tín hiệu', UNVERIFIED: 'Chưa có phiên Zalo', LOGGED_OUT: 'Cần đăng nhập lại', DISABLED: 'Đã tắt', PENDING: 'Chờ worker', LEASED: 'Đang xử lý', DELIVERED: 'Đã gửi', FAILED: 'Gửi lỗi', RECEIVED: 'Đã nhận', CANCELLED: 'Đã hủy' })[value] || value
