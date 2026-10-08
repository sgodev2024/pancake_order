import { Outlet } from 'react-router-dom'
import '../pages/Login/login.css'

const AuthLayout = () => (
  <div className="auth-layout">
    <main className="auth-panel auth-panel--form">
      <div className="auth-content">
        <Outlet />
      </div>
    </main>

    <aside className="auth-panel auth-panel--brand" aria-label="Ban Mai Group">
      <div className="brand-grid" aria-hidden="true" />
      <div className="brand-lockup">
        <div className="brand-logo" aria-label="Ban Mai Group">
          <span className="brand-logo__main">BAN MAI</span>
          <span className="brand-logo__tag">GROUP</span>
        </div>
        <p>CÔNG TY TNHH BAN MAI GROUP</p>
      </div>
    </aside>
  </div>
)

export default AuthLayout
