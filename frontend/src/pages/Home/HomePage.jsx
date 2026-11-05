import { Navigate } from 'react-router-dom'
import { ROUTES } from '../../constants/routes.js'
import { useAuthStore } from '../../auth/auth.store.js'
import { canViewWarehouse, isAdmin, isDirector } from '../../auth/permissions.js'
import ReportsOverviewPage from '../Reports/ReportsOverviewPage.jsx'
import WorkHomePage from './WorkHomePage.jsx'

export default function HomePage() {
  const user = useAuthStore((state) => state.user)
  if (user?.role?.slug === 'warehouse' && canViewWarehouse(user)) return <Navigate to={ROUTES.WAREHOUSE} replace />
  return isAdmin(user) || isDirector(user) ? <ReportsOverviewPage /> : <WorkHomePage />
}
