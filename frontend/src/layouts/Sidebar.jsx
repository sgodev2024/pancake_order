import { CloseOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Button, Drawer, Layout, Menu } from 'antd'
import { createElement, useMemo, useState } from 'react'
import { getSetting } from '../api/settings.api.js'
import { preloadDefaultReport } from '../app/reportPreload.js'
import { useLocation, useNavigate } from 'react-router-dom'
import { filterMenuByPermissions } from '../auth/permissions.js'
import { useAuthStore } from '../auth/auth.store.js'
import { findParentMenuKey, MENU_ITEMS } from '../constants/menu.js'
import { useUiStore } from '../stores/ui.store.js'

const { Sider } = Layout

const warmDefaultReport = () => {
  preloadDefaultReport().catch(() => {
    // Normal route loading remains the fallback.
  })
}

const toAntMenuItems = (items, onReportTitleClick) =>
  items.map((item) => ({
    key: item.key,
    label: item.key === 'reports'
      ? <span onMouseEnter={warmDefaultReport} onFocus={warmDefaultReport}>{item.label}</span>
      : item.label,
    icon: item.icon ? createElement(item.icon) : undefined,
    onTitleClick: item.key === 'reports' ? () => onReportTitleClick(item) : undefined,
    children: item.children ? toAntMenuItems(item.children, onReportTitleClick) : undefined,
  }))

const Brand = ({ collapsed = false, onClose }) => {
  const { data } = useQuery({
    queryKey: ['settings', 'general'],
    queryFn: () => getSetting('general'),
    staleTime: 60 * 1000,
    retry: 1,
  })
  const logo = data?.image
  const logoAlt = data?.setting?.company_name || 'Logo hệ thống'

  return (
    <div className={`sidebar-brand${collapsed ? ' sidebar-brand--collapsed' : ''}`}>
      {logo ? (
        <img className="sidebar-brand__logo" src={logo} alt={logoAlt} />
      ) : (
        <span className="sidebar-brand__mark" aria-hidden="true">BM</span>
      )}
      {onClose && (
        <Button
          type="text"
          icon={<CloseOutlined />}
          onClick={onClose}
          aria-label="Đóng menu"
          className="sidebar-brand__close"
        />
      )}
    </div>
  )
}

const SidebarMenu = ({ collapsed = false, onNavigate }) => {
  const user = useAuthStore((state) => state.user)
  const location = useLocation()
  const navigate = useNavigate()
  const parentKey = findParentMenuKey(location.pathname)
  const [openKeys, setOpenKeys] = useState(() => (parentKey ? [parentKey] : []))
  const visibleMenu = useMemo(() => filterMenuByPermissions(MENU_ITEMS, user), [user])
  const menuItems = toAntMenuItems(visibleMenu, (item) => {
    warmDefaultReport()
    const firstReportPath = item.children?.[0]?.path
    if (!firstReportPath) return
    navigate(firstReportPath)
    onNavigate?.()
  })

  const handleClick = ({ key }) => {
    if (!key.startsWith('/')) return
    navigate(key)
    onNavigate?.()
  }

  return (
    <Menu
      mode="inline"
      inlineCollapsed={collapsed}
      items={menuItems}
      selectedKeys={[location.pathname]}
      openKeys={collapsed ? undefined : openKeys}
      onOpenChange={setOpenKeys}
      onClick={handleClick}
      className="sidebar-menu"
    />
  )
}

const Sidebar = ({ isMobile }) => {
  const collapsed = useUiStore((state) => state.sidebarCollapsed)
  const mobileOpen = useUiStore((state) => state.mobileSidebarOpen)
  const setMobileOpen = useUiStore((state) => state.setMobileSidebarOpen)

  if (isMobile) {
    return (
      <Drawer
        placement="left"
        size={280}
        open={mobileOpen}
        closable={false}
        onClose={() => setMobileOpen(false)}
        className="sidebar-drawer"
        styles={{ body: { padding: 0 } }}
      >
        <div className="sidebar-panel sidebar-panel--mobile">
          <Brand onClose={() => setMobileOpen(false)} />
          <SidebarMenu onNavigate={() => setMobileOpen(false)} />
        </div>
      </Drawer>
    )
  }

  return (
    <Sider
      width={248}
      collapsedWidth={76}
      collapsed={collapsed}
      trigger={null}
      theme="light"
      className="app-sidebar"
    >
      <div className="sidebar-panel">
        <Brand collapsed={collapsed} />
        <SidebarMenu collapsed={collapsed} />
      </div>
    </Sider>
  )
}

export default Sidebar
