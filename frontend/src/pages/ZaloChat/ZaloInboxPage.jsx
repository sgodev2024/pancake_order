import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, App, Avatar, Badge, Button, Empty, Input, Modal, Segmented, Space, Spin, Tag } from 'antd'
import { ArrowLeftOutlined, MessageOutlined, ReloadOutlined, SearchOutlined, SendOutlined, TeamOutlined } from '@ant-design/icons'
import { Link } from 'react-router-dom'
import { zaloApi, zaloError, zaloStatus, zaloTime } from '../../api/zalo.api.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { ROUTES } from '../../constants/routes.js'
import './zalo-chat.css'

export default function ZaloInboxPage() {
 const { message } = App.useApp()
 const user = useAuthStore((s) => s.user)
 const queryClient = useQueryClient()
 const [accountId, setAccountId] = useState('')
 const [selectedId, setSelectedId] = useState('')
 const [search, setSearch] = useState('')
 const [query, setQuery] = useState('')
 const [filter, setFilter] = useState('Tất cả')
 const [mode, setMode] = useState('messages')
 const [drafts, setDrafts] = useState({})
 const [sending, setSending] = useState(false)
 const [sendError, setSendError] = useState('')
 const [details, setDetails] = useState(false)
 const [older, setOlder] = useState({ id: '', rows: [] })
 const [loadingOlder, setLoadingOlder] = useState(false)
 const [friendOffset, setFriendOffset] = useState(0)
 const [openingFriend, setOpeningFriend] = useState(false)
 const bottom = useRef(null)
 const accounts = useQuery({ queryKey: ['zalo', 'accounts'], queryFn: zaloApi.accounts, refetchInterval: 15000, retry: false })
 const activeAccount = (accounts.data ?? []).find((a) => a.id === accountId) ?? accounts.data?.[0]
 const activeId = activeAccount?.id
 const conversations = useQuery({ queryKey: ['zalo', 'conversations', activeId, query], queryFn: () => zaloApi.conversations(activeId, query), enabled: Boolean(activeId), refetchInterval: 10000, retry: false })
 const selected = (conversations.data ?? []).find((c) => c.id === selectedId && c.accountId === activeId)
 const messages = useQuery({ queryKey: ['zalo', 'messages', selected?.id], queryFn: () => zaloApi.messages(selected.id), enabled: Boolean(selected), refetchInterval: 5000, retry: false })
 const friends = useQuery({ queryKey: ['zalo', 'friends', activeId, friendOffset], queryFn: () => zaloApi.friends(activeId, friendOffset), enabled: Boolean(activeId) && mode === 'contacts', retry: false })
 const unread = (conversations.data ?? []).reduce((sum, c) => sum + c.unreadCount, 0)
 const rows = (conversations.data ?? []).filter((c) => filter !== 'Chưa đọc' || c.unreadCount > 0 || c.id === selectedId)
 const canChat = activeAccount?.accessLevel === 'CHAT'
 const draft = drafts[selected?.id] ?? ''
 const allMessages = [...new Map([...(older.id === selected?.id ? older.rows : []), ...(messages.data ?? [])].map((m) => [m.id, m])).values()].sort((a, b) => new Date(a.occurredAt) - new Date(b.occurredAt))
 const lastMessageId = messages.data?.at(-1)?.id
 useEffect(() => { const timer = setTimeout(() => setQuery(search.trim()), 350); return () => clearTimeout(timer) }, [search])
 useEffect(() => { bottom.current?.scrollIntoView({ block: 'end' }) }, [selected?.id, lastMessageId])
 useEffect(() => {
  if (!selected?.id || messages.isError || !messages.data) return
  let active = true
  zaloApi.read(selected.id).then(() => { if (active) queryClient.invalidateQueries({ queryKey: ['zalo', 'conversations'] }) }).catch((e) => { if (active) setSendError(zaloError(e)) })
  return () => { active = false }
 }, [selected?.id, Boolean(messages.data), queryClient])
 const refresh = () => queryClient.invalidateQueries({ queryKey: ['zalo'] })
 const switchAccount = (id) => { setAccountId(id); setSelectedId(''); setSearch(''); setQuery(''); setSendError(''); setOlder({ id: '', rows: [] }); setFriendOffset(0) }
 const choose = (id) => { setSelectedId(id); setSendError(''); setOlder({ id: '', rows: [] }); setMode('messages') }
 const send = async () => {
  const id = selected?.id
  if (!id || !draft.trim() || sending || !canChat) return
  setSending(true); setSendError('')
  try { await zaloApi.send(id, draft.trim()); setDrafts((old) => ({ ...old, [id]: '' })); await refresh(); message.success('Đã đưa tin vào hàng đợi. Trạng thái sẽ cập nhật khi worker xử lý.') }
  catch (e) { setSendError(zaloError(e)) }
  finally { setSending(false) }
 }
 const loadOlder = async () => {
  const id = selected.id; setLoadingOlder(true)
  try { const next = await zaloApi.messages(id, allMessages[0].occurredAt); setOlder((old) => ({ id, rows: [...(old.id === id ? old.rows : []), ...next] })); if (!next.length) message.info('Đã tải hết lịch sử hội thoại') }
  catch (e) { message.error(zaloError(e)) } finally { setLoadingOlder(false) }
 }
 const openFriend = async (friend) => {
  setOpeningFriend(true)
  try { const result = await zaloApi.openFriend(activeId, friend.friendRef); setSearch(''); setQuery(''); await refresh(); choose(result.conversationId) }
  catch (e) { message.error(zaloError(e)) } finally { setOpeningFriend(false) }
 }
 return <section className="zalo-page zalo-inbox-page">
  <div className="zalo-heading"><div><span className="zalo-eyebrow">ZALO OPERATIONS / WORKSPACE</span><h1>Tin nhắn &amp; danh bạ</h1><p>Hội thoại và bạn bè theo từng tài khoản Zalo được cấp quyền cho bạn.</p></div><Space wrap>{user?.role?.slug === 'admin' && <Link to={ROUTES.ZALO_CONNECTION}><Button>Kết nối Zalo</Button></Link>}<Button onClick={() => setDetails(true)} disabled={!activeAccount}>Chi tiết</Button><Button icon={<ReloadOutlined />} onClick={refresh}>Làm mới</Button></Space></div>
  {import.meta.env.VITE_ZALO_LOCAL_SIMULATION === 'true' && <Alert type="info" showIcon title="Môi trường thử nghiệm local: tin nhắn và trạng thái worker được giả lập, không gửi tới Zalo thật." style={{ marginBottom: 16 }} />}
  {accounts.isError && <Alert type="error" title={zaloError(accounts.error)} showIcon />}
  <nav className="zalo-account-tabs" aria-label="Tài khoản Zalo">{(accounts.data ?? []).map((a) => <button key={a.id} type="button" className={a.id === activeId ? 'active' : ''} onClick={() => switchAccount(a.id)}>{a.displayName}<span className={`zalo-status-dot ${a.sessionStatus === 'CONNECTED' ? 'online' : ''}`} /></button>)}</nav>
  {accounts.isLoading ? <Spin /> : !activeAccount ? !accounts.isError && <Empty description="Chưa có tài khoản Zalo được cấp quyền. Admin cần cấu hình kết nối và phân quyền." /> : <div className={`zalo-inbox ${selected ? 'thread-open' : ''}`}>
   <aside className="zalo-rail"><Avatar style={{ background: '#e5f4ff', color: '#10427a' }}>Z</Avatar><button type="button" title="Tin nhắn" aria-label="Tin nhắn" className={mode === 'messages' ? 'active' : ''} onClick={() => setMode('messages')}><MessageOutlined /></button><button type="button" title="Danh bạ" aria-label="Danh bạ" className={mode === 'contacts' ? 'active' : ''} onClick={() => { setMode('contacts'); setSelectedId('') }}><TeamOutlined /></button></aside>
   <aside className="zalo-conversation-list">
    <div className="zalo-list-head"><h2>{mode === 'messages' ? 'Tin nhắn' : 'Danh bạ'}</h2><Badge count={mode === 'messages' ? conversations.data?.length : friends.data?.total} showZero color="#156fbc" /></div>
    {mode === 'messages' ? <>
     <Input prefix={<SearchOutlined />} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Tìm hội thoại hoặc nội dung…" allowClear maxLength={160} />
     <div className="zalo-list-filter"><Segmented size="small" options={['Tất cả', 'Chưa đọc']} value={filter} onChange={setFilter} /><small>{unread} chưa đọc</small></div>
     {conversations.isLoading && <Spin />}{conversations.isError && <Alert type="error" title={zaloError(conversations.error)} />}
     {!conversations.isLoading && !conversations.isError && !rows.length && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có hội thoại" />}
     <div className="zalo-thread-rows">{rows.map((c) => <button type="button" key={c.id} className={`zalo-thread ${selected?.id === c.id ? 'selected' : ''}`} onClick={() => choose(c.id)}><Avatar src={c.avatarUrl} size={44}>{c.participant?.slice(0, 1)}</Avatar><div className="zalo-thread-copy"><strong>{c.participant}</strong><p>{c.lastMessagePreview || 'Chưa có tin nhắn'}</p><small>{zaloTime(c.lastMessageAt)}</small></div><Badge count={c.unreadCount} size="small" /></button>)}</div>
    </> : <>
     <p className="zalo-muted">Danh bạ đã đồng bộ: {zaloTime(friends.data?.syncedAt)}</p>
     {friends.isLoading && <Spin />}{friends.isError && <Alert type="error" title={zaloError(friends.error)} />}
     {(friends.data?.friends ?? []).map((f) => <div className="zalo-friend" key={f.friendRef}><Avatar src={f.avatarUrl}>{f.displayName?.slice(0, 1)}</Avatar><div><strong>{f.displayName}</strong><small>{f.maskedPhone || 'Chưa có số điện thoại'}</small></div><Button size="small" disabled={!canChat || openingFriend} onClick={() => openFriend(f)}>Chat</Button></div>)}
     {!friends.isLoading && !friends.isError && !friends.data?.total && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Chưa có danh bạ được đồng bộ" />}
     <Space><Button disabled={!friendOffset} onClick={() => setFriendOffset((v) => Math.max(0, v - 50))}>Trước</Button><Button disabled={friendOffset + 50 >= (friends.data?.total ?? 0)} onClick={() => setFriendOffset((v) => v + 50)}>Sau</Button></Space>
    </>}
   </aside>
   <main className="zalo-chat-panel">{!selected ? <div className="zalo-welcome"><div className="zalo-welcome-art"><span>•••</span><div><MessageOutlined /></div><span>✓</span></div><h2>Chào mừng đến với hộp thoại</h2><p>Chọn một cuộc trò chuyện ở cột bên trái để xem và trả lời tin nhắn.<br />Mỗi tài khoản được tách riêng theo quyền truy cập.</p><Space><Button icon={<TeamOutlined />} onClick={() => setMode('contacts')}>Xem danh bạ</Button><Button icon={<SearchOutlined />} onClick={() => { setMode('messages'); document.querySelector('.zalo-conversation-list input')?.focus() }}>Tìm hội thoại</Button></Space></div> : <>
    <header className="zalo-chat-header"><Button className="zalo-back" icon={<ArrowLeftOutlined />} onClick={() => setSelectedId('')} aria-label="Quay lại danh sách" /><Avatar src={selected.avatarUrl}>{selected.participant?.slice(0, 1)}</Avatar><div><strong>{selected.participant}</strong><small>{activeAccount.displayName} · {selected.threadType === 'GROUP' ? 'Nhóm' : 'Hội thoại cá nhân'}</small></div><Tag color={activeAccount.sessionStatus === 'CONNECTED' ? 'green' : 'default'}>{zaloStatus(activeAccount.sessionStatus)}</Tag></header>
    {messages.isError && <Alert type="error" title={zaloError(messages.error)} />}
    <div className="zalo-messages">{messages.isLoading ? <Spin /> : <><Button size="small" className="zalo-load-older" loading={loadingOlder} disabled={!allMessages.length} onClick={loadOlder}>Tải tin nhắn cũ hơn</Button>{allMessages.map((m) => <div key={m.id} className={`zalo-message ${m.direction === 'OUTBOUND' ? 'outbound' : 'inbound'}`}><div className="zalo-bubble">{m.messageType && m.messageType !== 'TEXT' && <Tag>{m.messageType}</Tag>}<p>{m.body}</p><small>{zaloTime(m.occurredAt)} · {zaloStatus(m.deliveryStatus)}</small>{m.failureCode && <small className="zalo-send-failed">{m.failureCode}</small>}</div></div>)}<div ref={bottom} /></>}</div>
    <footer className="zalo-composer">{sendError && <Alert type="error" title={sendError} closable onClose={() => setSendError('')} />}<div><Input.TextArea rows={2} maxLength={2000} value={draft} disabled={!canChat || messages.isError || accounts.isError || conversations.isError} placeholder={canChat ? 'Nhập tin nhắn… (Ctrl + Enter để gửi)' : 'Bạn có quyền chỉ xem hội thoại'} onChange={(e) => setDrafts((old) => ({ ...old, [selected.id]: e.target.value }))} onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send() } }} /><Button type="primary" icon={<SendOutlined />} loading={sending} disabled={!canChat || !draft.trim() || messages.isError || accounts.isError || conversations.isError} onClick={send}>Gửi</Button></div><small>Tin chỉ được xác nhận “Đã gửi” sau khi worker trả kết quả.</small></footer>
   </>}</main>
  </div>}
  <Modal title="Thông tin tài khoản Zalo" open={details} onCancel={() => setDetails(false)} footer={<Button onClick={() => setDetails(false)}>Đóng</Button>}><p><strong>{activeAccount?.displayName}</strong></p><p>Trạng thái: {zaloStatus(activeAccount?.sessionStatus)}</p><p>Tín hiệu cuối: {zaloTime(activeAccount?.lastHeartbeatAt)}</p><p>Quyền của bạn: {canChat ? 'Xem và trả lời' : 'Chỉ xem'}</p></Modal>
 </section>
}

