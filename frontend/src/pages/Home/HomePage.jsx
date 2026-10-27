import { useAuthStore } from '../../auth/auth.store.js'
import { isAdmin, isDirector } from '../../auth/permissions.js'
import ReportsOverviewPage from '../Reports/ReportsOverviewPage.jsx'
import WorkHomePage from './WorkHomePage.jsx'

export default function HomePage() {
  const user = useAuthStore((state) => state.user)
  return isAdmin(user) || isDirector(user) ? <ReportsOverviewPage /> : <WorkHomePage />
}
