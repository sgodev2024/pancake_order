import { Button, Card, Result } from 'antd'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import LoadingScreen from '../components/LoadingScreen.jsx'
import { ROUTES } from '../constants/routes.js'
import { useAuthStore } from './auth.store.js'
import { isAdmin, isDirector } from './permissions.js'

const useAuthGuardState = () => {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const isInitializing = useAuthStore((state) => state.isInitializing)
  const requirePasswordChange = useAuthStore((state) => state.requirePasswordChange)

  return { isAuthenticated, isInitializing, requirePasswordChange }
}

export const ProtectedRoute = () => {
  const auth = useAuthGuardState()
  const location = useLocation()

  if (auth.isInitializing) return <LoadingScreen />
  if (!auth.isAuthenticated) {
    return <Navigate to={ROUTES.LOGIN} replace state={{ from: location.pathname }} />
  }
  if (auth.requirePasswordChange) {
    return <Navigate to={ROUTES.CHANGE_FIRST_PASSWORD} replace />
  }

  return <Outlet />
}

export const AdminOnlyRoute = () => {
  const user = useAuthStore((state) => state.user)

  if (!isAdmin(user)) {
    return (
      <Card>
        <Result
          status="403"
          title="Bạn không có quyền truy cập"
          subTitle="Chỉ tài khoản admin mới có quyền truy cập màn hình này."
          extra={
            <Button type="primary" href={ROUTES.HOME}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  return <Outlet />
}

export const ReportsRoute = () => {
  const user = useAuthStore((state) => state.user)

  if (!isAdmin(user) && !isDirector(user)) {
    return (
      <Card>
        <Result
          status="403"
          title="Bạn không có quyền truy cập"
          subTitle="Chỉ tài khoản admin hoặc giám đốc mới có quyền truy cập màn hình này."
          extra={
            <Button type="primary" href={ROUTES.HOME}>
              Về Tổng quan
            </Button>
          }
        />
      </Card>
    )
  }

  return <Outlet />
}

export const GuestOnlyRoute = () => {
  const auth = useAuthGuardState()

  if (auth.isInitializing) return <LoadingScreen />
  if (auth.isAuthenticated && auth.requirePasswordChange) {
    return <Navigate to={ROUTES.CHANGE_FIRST_PASSWORD} replace />
  }
  if (auth.isAuthenticated) return <Navigate to={ROUTES.HOME} replace />

  return <Outlet />
}

export const PasswordChangeRoute = () => {
  const auth = useAuthGuardState()

  if (auth.isInitializing) return <LoadingScreen />
  if (!auth.isAuthenticated) return <Navigate to={ROUTES.LOGIN} replace />
  if (!auth.requirePasswordChange) return <Navigate to={ROUTES.HOME} replace />

  return <Outlet />
}
