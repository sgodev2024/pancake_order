import { Card } from 'antd'
import { useLocation } from 'react-router-dom'
import { getRouteMetadata } from '../../constants/menu.js'

const PlaceholderPage = () => {
  const { pathname } = useLocation()
  const route = getRouteMetadata(pathname)

  return (
    <Card className="placeholder-card">
      <p className="placeholder-card__eyebrow">Pancake Frontend V2</p>
      <h1>{route?.title || route?.label || 'Trang chức năng'}</h1>
      <p>Chức năng sẽ được triển khai ở Phase tiếp theo.</p>
    </Card>
  )
}

export default PlaceholderPage
