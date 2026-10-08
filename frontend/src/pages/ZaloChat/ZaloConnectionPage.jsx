import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Button, Card, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd'
import { ApiOutlined, CheckCircleOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { Link } from 'react-router-dom'
import { zaloApi, zaloError, zaloStatus, zaloTime } from '../../api/zalo.api.js'
import { ROUTES } from '../../constants/routes.js'
import './zalo-chat.css'

export default function ZaloConnectionPage() {
 const { message } = App.useApp()
 const queryClient = useQueryClient()
 const [form] = Form.useForm()
 const [busy, setBusy] = useState(false)
 const [notice, setNotice] = useState(null)
 const [newAccount, setNewAccount] = useState(false)
 const [accountName, setAccountName] = useState('')
 const [grantAccount, setGrantAccount] = useState(null)
 const [grantValues, setGrantValues] = useState({})
 const config = useQuery({ queryKey: ['zalo', 'connection'], queryFn: zaloApi.configuration })
 const accounts = useQuery({ queryKey: ['zalo', 'accounts'], queryFn: zaloApi.accounts, enabled: Boolean(config.data?.enabled), refetchInterval: 15000, retry: false })
 const monitor = useQuery({ queryKey: ['zalo', 'monitoring'], queryFn: zaloApi.monitoring, enabled: Boolean(config.data?.enabled), refetchInterval: 15000, retry: false })
 const grants = useQuery({ queryKey: ['zalo', 'grants', grantAccount?.id], queryFn: () => zaloApi.grants(grantAccount.id), enabled: Boolean(grantAccount), retry: false })
 useEffect(() => { if (config.data) form.setFieldsValue({ ...config.data, api_key: '' }) }, [config.data, form])
 useEffect(() => { if (grants.data) setGrantValues(Object.fromEntries(grants.data.grants.map((g) => [g.user_id, g.access_level]))) }, [grants.data])
 const refresh = () => queryClient.invalidateQueries({ queryKey: ['zalo'] })
 const run = async (action) => {
  setBusy(true); setNotice(null)
  try {
   const body = await form.validateFields()
   if (action === 'test') { const result = await zaloApi.test(body); setNotice({ type: 'success', text: `API hoạt động. Có ${result.account_count} tài khoản. Kết nối API không đồng nghĩa tài khoản Zalo đang online.` }) }
   else { await zaloApi.save(body); form.setFieldValue('api_key', ''); await refresh(); message.success('Đã lưu cấu hình kết nối Zalo') }
  } catch (error) { if (!error.errorFields) setNotice({ type: 'error', text: zaloError(error) }) }
  finally { setBusy(false) }
 }
 const create = async () => { setBusy(true); try { await zaloApi.createAccount({ displayName: accountName.trim() }); setNewAccount(false); setAccountName(''); await refresh(); message.success('Đã tạo tài khoản. Cần worker đăng nhập Zalo để nhận/gửi tin thật.') } catch (e) { message.error(zaloError(e)) } finally { setBusy(false) } }
 const saveGrants = async () => { setBusy(true); try { await zaloApi.saveGrants(grantAccount.id, Object.entries(grantValues).filter(([, v]) => v).map(([user_id, access_level]) => ({ user_id: Number(user_id), access_level }))); setGrantAccount(null); await refresh(); message.success('Đã cập nhật phân quyền') } catch (e) { message.error(zaloError(e)) } finally { setBusy(false) } }
 return <section className="zalo-page">
  <div className="zalo-heading"><div><span className="zalo-eyebrow">ZALO CHAT / KẾT NỐI</span><h1>Kết nối Zalo</h1><p>Cấu hình dịch vụ API và quản lý tài khoản Zalo của doanh nghiệp.</p></div><Link to={ROUTES.ZALO_INBOX}><Button>Đi tới hộp thoại</Button></Link></div>
  {import.meta.env.VITE_ZALO_LOCAL_SIMULATION === 'true' && <Alert type="info" showIcon title="Đang dùng dịch vụ Java và dữ liệu thử nghiệm local. Chưa kết nối tài khoản Zalo thật." style={{ marginBottom: 16 }} />}
  {config.isError && <Alert type="error" title={zaloError(config.error)} />}
  <div className="zalo-connection-grid">
   <Card title={<Space><ApiOutlined />Cấu hình kết nối API</Space>} loading={config.isLoading}>
    <Form form={form} layout="vertical" initialValues={{ enabled: false }}>
     <Form.Item name="base_url" label="Địa chỉ dịch vụ Zalo" rules={[{ required: true, message: 'Nhập địa chỉ dịch vụ' }]}><Input placeholder="http://zalo-core:8080" /></Form.Item>
     <Form.Item name="api_key" label="API key" extra={config.data?.has_api_key ? 'Đã lưu khóa. Để trống để giữ nguyên; nhập khóa mới để thay thế.' : 'Dùng API key của service account tích hợp.'}><Input.Password autoComplete="new-password" placeholder={config.data?.has_api_key ? 'Đã lưu, không hiển thị lại' : 'cpa_…'} /></Form.Item>
     <Form.Item name="enabled" label="Bật kết nối" valuePropName="checked"><Switch /></Form.Item>
     <Space wrap><Button icon={<CheckCircleOutlined />} loading={busy} onClick={() => run('test')}>Kiểm tra kết nối</Button><Button type="primary" loading={busy} onClick={() => run('save')}>Lưu cấu hình</Button></Space>
    </Form>
    {notice && <Alert style={{ marginTop: 16 }} type={notice.type} title={notice.text} showIcon />}
   </Card>
   <Card title="Tình trạng hoạt động">
    <Tag color={config.data?.enabled ? 'green' : 'default'}>{config.data?.enabled ? 'Đã bật kết nối API' : 'Chưa bật kết nối'}</Tag>
    <p>Trạng thái tài khoản dựa trên heartbeat của worker Zalo. Tín hiệu quá 90 giây được xem là mất kết nối.</p>
    <div className="zalo-metrics"><div><strong>{accounts.data?.length ?? 0}</strong><span>Tài khoản</span></div><div><strong>{accounts.data?.filter((a) => a.sessionStatus === 'CONNECTED').length ?? 0}</strong><span>Đang online</span></div><div><strong>{monitor.data?.commands?.pending ?? 0}</strong><span>Tin chờ gửi</span></div></div>
    <Typography.Text type="secondary">Cập nhật: {zaloTime(monitor.data?.observedAt)}</Typography.Text>
    <p><Typography.Text type="secondary">Thay địa chỉ hoặc API key sẽ xóa phân quyền cũ; Admin cần cấp lại để bảo đảm đúng phạm vi tài khoản.</Typography.Text></p>
    {monitor.isError && <Alert type="warning" title={zaloError(monitor.error)} />}
    {(monitor.data?.alerts ?? []).map((a) => <Alert key={a.code} style={{ marginTop: 8 }} type="warning" title={a.title} description={a.detail} />)}
   </Card>
  </div>
  <Card title="Tài khoản Zalo" extra={<Space><Button icon={<ReloadOutlined />} onClick={refresh}>Làm mới</Button><Button type="primary" icon={<PlusOutlined />} disabled={!config.data?.enabled} onClick={() => setNewAccount(true)}>Thêm tài khoản</Button></Space>}>
   {accounts.isError && <Alert type="error" title={zaloError(accounts.error)} />}
   <Table rowKey="id" loading={accounts.isFetching} dataSource={accounts.data ?? []} pagination={false} scroll={{ x: 650 }} columns={[
    { title: 'Tài khoản', dataIndex: 'displayName' },
    { title: 'Trạng thái Zalo', dataIndex: 'sessionStatus', render: (s) => <Tag color={s === 'CONNECTED' ? 'green' : 'default'}>{zaloStatus(s)}</Tag> },
    { title: 'Tín hiệu cuối', dataIndex: 'lastHeartbeatAt', render: zaloTime },
    { title: 'Phân quyền', render: (_, row) => <Button onClick={() => { setGrantValues({}); setGrantAccount(row) }}>Cấp quyền nhân viên</Button> },
   ]} locale={{ emptyText: config.data?.enabled ? 'Chưa có tài khoản Zalo' : 'Lưu và bật kết nối API để xem tài khoản' }} />
  </Card>
  <Modal title="Thêm tài khoản Zalo" open={newAccount} onCancel={() => setNewAccount(false)} onOk={create} confirmLoading={busy} okButtonProps={{ disabled: !accountName.trim() }}><Input value={accountName} maxLength={160} onChange={(e) => setAccountName(e.target.value)} placeholder="Tên tài khoản" /><p>Tạo tài khoản để quản lý trong API. Phiên đăng nhập Zalo được quản lý bởi worker.</p></Modal>
  <Modal title={`Phân quyền — ${grantAccount?.displayName ?? ''}`} open={Boolean(grantAccount)} onCancel={() => setGrantAccount(null)} onOk={saveGrants} confirmLoading={busy} okButtonProps={{ disabled: !grants.data || grants.isFetching }} width={680}>
   <p>Admin luôn có quyền quản lý. Nhân viên chỉ truy cập tài khoản được cấp quyền dưới đây.</p>
   {grants.isError && <Alert type="error" title={zaloError(grants.error)} />}
   <Table rowKey="id" loading={grants.isFetching} dataSource={grants.data?.users ?? []} pagination={{ pageSize: 6 }} columns={[{ title: 'Nhân viên', render: (_, u) => <><strong>{u.name}</strong><br />{u.email}</> }, { title: 'Quyền', render: (_, u) => <Select style={{ width: 170 }} value={grantValues[u.id] || ''} onChange={(v) => setGrantValues((old) => ({ ...old, [u.id]: v }))} options={[{ value: '', label: 'Không truy cập' }, { value: 'VIEW', label: 'Chỉ xem' }, { value: 'CHAT', label: 'Xem và trả lời' }]} /> }]} />
  </Modal>
 </section>
}

