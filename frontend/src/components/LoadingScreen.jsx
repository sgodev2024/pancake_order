import { Spin } from 'antd'

const LoadingScreen = () => (
  <div className="loading-screen" role="status" aria-label="Đang tải phiên đăng nhập">
    <Spin size="large" />
  </div>
)

export default LoadingScreen
