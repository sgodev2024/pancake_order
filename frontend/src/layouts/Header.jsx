import {
  BellOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuUnfoldOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { Avatar, Button, Dropdown, Layout, Tooltip } from 'antd'
import { useLocation, useNavigate } from 'react-router-dom'
import { getRouteMetadata } from '../constants/menu.js'
import { ROUTES } from '../constants/routes.js'

const { Header: AntHeader } = Layout

const getInitial = (name) => name?.trim()?.charAt(0)?.toUpperCase() || 'U'

const AppHeader = ({ collapsed, isMobile, onToggleSidebar, onLogout, user }) => {
  const location = useLocation()
  const navigate = useNavigate()
  const route = getRouteMetadata(location.pathname)
  const roleName = user?.role?.name || user?.role?.slug || 'Thành viên'
  const menuItems = [
    { key: 'profile', label: 'Thông tin cá nhân', icon: <UserOutlined /> },
    { type: 'divider' },
    { key: 'logout', label: 'Đăng xuất', icon: <LogoutOutlined />, danger: true },
  ]

  const handleMenuClick = ({ key }) => {
    if (key === 'profile') navigate(ROUTES.PROFILE)
    if (key === 'logout') onLogout()
  }

  return (
    <AntHeader className="app-header">
      <div className="app-header__left">
        <Tooltip title={isMobile ? 'Mở menu' : collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}>
          <Button
            type="text"
            icon={isMobile || collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
            onClick={onToggleSidebar}
            aria-label={isMobile ? 'Mở menu' : collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
            className="app-header__toggle"
          />
        </Tooltip>
        <span className="app-header__title">{route?.title || route?.label || 'Pancake V2'}</span>
      </div>

      <div className="app-header__right">
        <Tooltip title="Thông báo">
          <Button
            type="text"
            shape="circle"
            icon={<BellOutlined />}
            aria-label="Thông báo"
            className="app-header__notification"
          />
        </Tooltip>

        <Dropdown menu={{ items: menuItems, onClick: handleMenuClick }} trigger={['click']}>
          <button type="button" className="user-menu" aria-label="Mở menu người dùng">
            <Avatar className="user-menu__avatar">{getInitial(user?.name || user?.email)}</Avatar>
            <span className="user-menu__copy">
              <strong>{user?.name || user?.email || 'Người dùng'}</strong>
              <small>{roleName}</small>
            </span>
          </button>
        </Dropdown>
      </div>
    </AntHeader>
  )
}

export default AppHeader
