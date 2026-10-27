import { Grid, Layout } from 'antd'
import { useEffect } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { logout } from '../api/auth.api.js'
import { preloadDefaultReport } from '../app/reportPreload.js'
import { isAdmin } from '../auth/permissions.js'
import { useAuthStore } from '../auth/auth.store.js'
import { ROUTES } from '../constants/routes.js'
import { useUiStore } from '../stores/ui.store.js'
import '../styles/layout.css'
import AppHeader from './Header.jsx'
import Sidebar from './Sidebar.jsx'

const { Content } = Layout

const AppLayout = () => {
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const clearSession = useAuthStore((state) => state.clearSession)
  const collapsed = useUiStore((state) => state.sidebarCollapsed)
  const toggleSidebar = useUiStore((state) => state.toggleSidebar)
  const setMobileSidebarOpen = useUiStore((state) => state.setMobileSidebarOpen)
  const screens = Grid.useBreakpoint()
  const isMobile = screens.lg === false

  useEffect(() => {
    if (!isAdmin(user)) return undefined

    const preload = () => {
      preloadDefaultReport().catch(() => {
        // A later route navigation can retry the normal lazy import.
      })
    }
    if ('requestIdleCallback' in window) {
      const idleId = window.requestIdleCallback(preload, { timeout: 1800 })
      return () => window.cancelIdleCallback(idleId)
    }

    const timeoutId = window.setTimeout(preload, 500)
    return () => window.clearTimeout(timeoutId)
  }, [user])

  const handleLogout = async () => {
    try {
      await logout()
    } catch {
      // Local session must still be cleared if server-side logout is unavailable.
    } finally {
      clearSession()
      navigate(ROUTES.LOGIN, { replace: true })
    }
  }

  const handleToggleSidebar = () => {
    if (isMobile) {
      setMobileSidebarOpen(true)
      return
    }

    toggleSidebar()
  }

  return (
    <Layout className="app-shell">
      <Sidebar isMobile={isMobile} />
      <Layout className="app-main">
        <AppHeader
          collapsed={collapsed}
          isMobile={isMobile}
          onToggleSidebar={handleToggleSidebar}
          onLogout={handleLogout}
          user={user}
        />
        <Content className="app-content">
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  )
}

export default AppLayout
